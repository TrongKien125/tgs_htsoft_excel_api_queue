<?php
/**
 * Plugin Name: TGS HTsoft Excel API Queue
 * Description: Nhận file XLS/XLSX qua REST API, xếp hàng và tự động nhập PNK/PBH/HTL/PNM/TNCC bằng các plugin nghiệp vụ TGS.
 * Version: 1.8.1
 * Author: TGS
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_HEIQ_Plugin
{
    const VERSION = '1.8.1';
    const DB_VERSION = '1.3.0';
    const DB_OPTION = 'tgs_heiq_db_version';
    const SETTINGS_OPTION = 'tgs_heiq_settings';
    const LEGACY_CRON_HOOK = 'tgs_heiq_process_queue';
    const LEGACY_CRON_CLEANUP_OPTION = 'tgs_heiq_legacy_cron_removed';
    const REST_NAMESPACE = 'tgs-excel-import/v1';
    const REST_ROUTE = '/files';
    const REST_ADMIN_SUBMIT_ROUTE = '/admin-submit';
    const DASHBOARD_VIEW = 'htsoft-excel-api-queue';
    const MAX_FILE_BYTES = 10485760;
    const DASHBOARD_REQUEST_LIMIT = 50;
    const DASHBOARD_FILE_LIMIT = 100;
    const DASHBOARD_VOUCHER_LIMIT = 1000;

    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        require_once __DIR__ . '/includes/class-xlsx-reader.php';
        require_once __DIR__ . '/vendor/shuchkin/simplexls/src/SimpleXLS.php';
        require_once __DIR__ . '/includes/class-excel-reader.php';
        require_once __DIR__ . '/includes/class-daily-log.php';
        require_once __DIR__ . '/includes/class-queue-processor.php';

        add_action('rest_api_init', array($this, 'register_rest_route'));
        add_action('init', array($this, 'maybe_install'));
        add_action('init', array($this, 'remove_legacy_schedule'), 1);
        add_action('init', array($this, 'maybe_recover_pending_logs'), 30);
        add_action('admin_post_tgs_heiq_generate_key', array($this, 'generate_api_key'));
        add_action('admin_notices', array($this, 'dependency_notice'));
        add_filter('tgs_shop_workflow_nav', array($this, 'add_to_workflow_nav'), 10, 2);
        add_filter('tgs_shop_dashboard_routes', array($this, 'register_dashboard_route'));
    }

    public static function activate()
    {
        self::install_schema();
        self::clear_legacy_schedules();
        update_site_option(self::LEGACY_CRON_CLEANUP_OPTION, self::VERSION);
    }

    public static function deactivate()
    {
        self::clear_legacy_schedules();
        delete_site_option(self::LEGACY_CRON_CLEANUP_OPTION);
    }

    public static function request_table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_excel_api_request';
    }

    public static function file_table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_excel_api_file';
    }

    public static function voucher_log_table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_excel_api_voucher_log';
    }

    public static function settings()
    {
        $defaults = array(
            'api_key_hash' => '',
            'api_key_last4' => '',
            'api_key_secret' => '',
            'actor_user_id' => 0,
            'tax_mode' => 'derive',
        );
        $settings = get_site_option(self::SETTINGS_OPTION, array());
        return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
    }

    public function maybe_install()
    {
        if (get_site_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install_schema();
        }
    }

    public function maybe_recover_pending_logs()
    {
        $is_log_date_navigation = is_admin()
            && isset($_GET['page'], $_GET['view'], $_GET['heiq_log_date'])
            && sanitize_key(wp_unslash($_GET['page'])) === 'tgs-shop-management'
            && sanitize_key(wp_unslash($_GET['view'])) === self::DASHBOARD_VIEW;
        if ($is_log_date_navigation) {
            return;
        }
        TGS_HEIQ_Daily_Log::recover_pending();
    }

    private static function install_schema()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $request_table = self::request_table();
        $file_table = self::file_table();
        $voucher_log_table = self::voucher_log_table();

        dbDelta("CREATE TABLE {$request_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_uuid char(36) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'receiving',
            total_files int(10) unsigned NOT NULL DEFAULT 0,
            accepted_files int(10) unsigned NOT NULL DEFAULT 0,
            duplicate_files int(10) unsigned NOT NULL DEFAULT 0,
            rejected_files int(10) unsigned NOT NULL DEFAULT 0,
            completed_files int(10) unsigned NOT NULL DEFAULT 0,
            failed_files int(10) unsigned NOT NULL DEFAULT 0,
            duplicate_names longtext NULL,
            request_json longtext NULL,
            response_json longtext NULL,
            log_archive_required tinyint(1) unsigned NOT NULL DEFAULT 0,
            log_file_date date NULL,
            log_archived_at datetime NULL,
            message text NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_uuid (request_uuid),
            KEY status (status),
            KEY log_archive_pending (log_archive_required, log_archived_at),
            KEY log_file_date (log_file_date),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$file_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            file_name varchar(255) NOT NULL,
            file_hash char(64) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(20) NOT NULL,
            stored_path text NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            sheet_name varchar(190) NOT NULL DEFAULT '',
            vouchers_total int(10) unsigned NOT NULL DEFAULT 0,
            vouchers_imported int(10) unsigned NOT NULL DEFAULT 0,
            vouchers_duplicate int(10) unsigned NOT NULL DEFAULT 0,
            vouchers_failed int(10) unsigned NOT NULL DEFAULT 0,
            result_json longtext NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY file_name (file_name),
            KEY request_id (request_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$voucher_log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            file_id bigint(20) unsigned NOT NULL,
            file_name varchar(255) NOT NULL,
            voucher_code varchar(190) NOT NULL DEFAULT '',
            site_code varchar(100) NOT NULL DEFAULT '',
            kind varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY request_id (request_id),
            KEY file_id (file_id),
            KEY voucher_code (voucher_code),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        update_site_option(self::DB_OPTION, self::DB_VERSION);
    }

    /** Xóa các event còn sót lại từ phiên bản dùng WP-Cron. */
    public function remove_legacy_schedule()
    {
        if (get_site_option(self::LEGACY_CRON_CLEANUP_OPTION) === self::VERSION) {
            return;
        }
        self::clear_legacy_schedules();
        update_site_option(self::LEGACY_CRON_CLEANUP_OPTION, self::VERSION);
    }

    private static function clear_legacy_schedules()
    {
        wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
        wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK, array('immediate'));
    }

    public function register_rest_route()
    {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'receive_files'),
            // Xác thực trong callback để request sai key cũng có log.
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, self::REST_ADMIN_SUBMIT_ROUTE, array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submit_file_from_admin'),
            'permission_callback' => array($this, 'authorize_admin_submit'),
        ));
    }

    public function authorize_request(WP_REST_Request $request)
    {
        $settings = self::settings();
        if ($settings['api_key_hash'] === '') {
            return new WP_Error('tgs_heiq_not_configured', 'API chưa được cấu hình.', array('status' => 503));
        }
        $api_key = trim((string) $request->get_header('x-api-key'));
        if ($api_key === '' || !wp_check_password($api_key, $settings['api_key_hash'])) {
            return new WP_Error('tgs_heiq_unauthorized', 'API key không hợp lệ.', array('status' => 401));
        }
        return true;
    }

    public function receive_files(WP_REST_Request $request)
    {
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $now = current_time('mysql');
        $files = $this->normalize_uploaded_files($request->get_file_params());
        $request_log = $this->build_request_log($request, $files);
        $wpdb->insert(self::request_table(), array(
            'request_uuid' => $uuid,
            'status' => 'receiving',
            'request_json' => wp_json_encode($request_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'log_archive_required' => 1,
            'created_at' => $now,
        ));
        $request_id = intval($wpdb->insert_id);
        if (!$request_id) {
            return new WP_Error('tgs_heiq_log_failed', 'Không thể tạo log request.', array('status' => 500));
        }

        $authorization = $this->authorize_request($request);
        if (is_wp_error($authorization)) {
            $message = $authorization->get_error_message();
            $data = $authorization->get_error_data();
            $data = is_array($data) ? $data : array();
            $data['request_id'] = $uuid;
            $response_log = array(
                'http_status' => intval($data['status'] ?? 500),
                'body' => array(
                    'code' => $authorization->get_error_code(),
                    'message' => $message,
                    'data' => $data,
                ),
            );
            $wpdb->update(self::request_table(), array(
                'status' => 'failed',
                'failed_files' => 1,
                'message' => $message,
                'response_json' => wp_json_encode($response_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => current_time('mysql'),
            ), array('id' => $request_id));
            TGS_HEIQ_Daily_Log::archive_request($request_id);
            return new WP_Error(
                $authorization->get_error_code(),
                $message,
                $data
            );
        }

        $total = count($files);
        $accepted = 0;
        $duplicates = array();
        $rejected = array();

        if (!$files) {
            $post_max = ini_get('post_max_size');
            $rejected[] = 'Không nhận được file. Kiểm tra multipart field files[] và giới hạn post_max_size (' . $post_max . ').';
        }

        foreach ($files as $upload) {
            $original_name = sanitize_file_name(wp_basename((string) $upload['name']));
            $kind = $this->kind_from_name($original_name);
            $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $upload_error = intval($upload['error']);
            $size = intval($upload['size']);

            if ($upload_error !== UPLOAD_ERR_OK) {
                $rejected[] = ($original_name ?: '(không tên)') . ': lỗi upload mã ' . $upload_error;
                continue;
            }
            if (!$kind) {
                $rejected[] = $original_name . ': tên file phải bắt đầu bằng PNK_, PBH_, HTL_, PNM_ hoặc TNCC_.';
                continue;
            }
            if (!in_array($extension, array('xls', 'xlsx'), true)) {
                $rejected[] = $original_name . ': chỉ hỗ trợ định dạng .xls và .xlsx.';
                continue;
            }
            if ($size <= 0 || $size > self::effective_upload_limit()) {
                $rejected[] = $original_name . ': dung lượng không hợp lệ hoặc vượt giới hạn ' . size_format(self::effective_upload_limit()) . '.';
                continue;
            }
            if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
                $rejected[] = $original_name . ': file tải lên không hợp lệ.';
                continue;
            }

            $inserted = $wpdb->insert(self::file_table(), array(
                'request_id' => $request_id,
                'file_name' => $original_name,
                'file_hash' => hash_file('sha256', $upload['tmp_name']),
                'file_size' => $size,
                'kind' => $kind,
                'stored_path' => '',
                'status' => 'queued',
                'created_at' => $now,
            ));
            if (!$inserted) {
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . self::file_table() . ' WHERE file_name = %s LIMIT 1',
                    $original_name
                ));
                if ($existing_id) {
                    $duplicates[] = $original_name;
                } else {
                    $rejected[] = $original_name . ': không thể ghi file vào hàng đợi.';
                }
                continue;
            }

            $file_id = intval($wpdb->insert_id);
            try {
                $queue_dir = self::queue_directory();
                $destination = $queue_dir . '/' . wp_generate_uuid4() . '-' . $original_name;
                if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                    throw new Exception('Không thể chuyển file vào thư mục hàng đợi.');
                }
                $wpdb->update(self::file_table(), array('stored_path' => $destination), array('id' => $file_id));
                $accepted++;
            } catch (Throwable $e) {
                // File chưa thật sự vào queue: bỏ row để lần gửi sau có thể thử lại
                // với cùng tên, đồng thời ghi lỗi ở cấp request.
                $wpdb->delete(self::file_table(), array('id' => $file_id));
                $rejected[] = $original_name . ': ' . $e->getMessage();
            }
        }

        $rejected_count = count($rejected);
        if ($accepted > 0) {
            // Đánh dấu chờ trước khi worker đồng bộ nhận file; trạng thái cuối
            // sẽ được cập nhật ngay trong cùng request API.
            $status = 'queued';
        } elseif ($duplicates && !$rejected) {
            $status = 'duplicate';
        } else {
            $status = 'failed';
        }
        $is_terminal = $accepted === 0;
        $request_messages = $rejected;
        if ($duplicates) {
            $request_messages[] = 'File trùng tên, đã bỏ qua: ' . implode(', ', $duplicates);
        }
        $wpdb->update(self::request_table(), array(
            'status' => $status,
            'total_files' => $total,
            'accepted_files' => $accepted,
            'duplicate_files' => count($duplicates),
            'rejected_files' => $rejected_count,
            'failed_files' => $rejected_count,
            'duplicate_names' => wp_json_encode($duplicates, JSON_UNESCAPED_UNICODE),
            'message' => implode(' | ', array_slice($request_messages, 0, 30)),
            'completed_at' => $is_terminal ? current_time('mysql') : null,
        ), array('id' => $request_id));

        if ($accepted > 0) {
            TGS_HEIQ_Queue_Processor::run();
            $final_status = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . self::request_table() . ' WHERE id = %d',
                $request_id
            ));
            if ($final_status) {
                $status = (string) $final_status;
            }
        }

        $success = !in_array($status, array('failed', 'partial'), true);
        $response_log = array(
            'success' => $success,
            'accepted' => $accepted > 0,
            'request_id' => $uuid,
            'status' => $status,
            'message' => $success
                ? 'File đã được tiếp nhận hoặc xử lý; BTauto không cần gửi lại.'
                : 'File có lỗi thực sự; kiểm tra nhật ký import.',
        );
        $wpdb->update(self::request_table(), array(
            'response_json' => wp_json_encode(array(
                'http_status' => 200,
                'body' => $response_log,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ), array('id' => $request_id));
        TGS_HEIQ_Daily_Log::archive_request($request_id);
        return new WP_REST_Response($response_log, 200);
    }

    /**
     * Log request phục vụ đối soát nhưng tuyệt đối không lưu API key hoặc nội
     * dung nhị phân của file Excel.
     */
    private function build_request_log(WP_REST_Request $request, array $files)
    {
        $file_logs = array();
        foreach ($files as $file) {
            $file_logs[] = array(
                'name' => sanitize_file_name(wp_basename((string) ($file['name'] ?? ''))),
                'type' => sanitize_text_field((string) ($file['type'] ?? '')),
                'size' => intval($file['size'] ?? 0),
                'upload_error' => intval($file['error'] ?? 0),
            );
        }

        return array(
            'method' => $request->get_method(),
            'route' => $request->get_route(),
            'received_at' => current_time('mysql'),
            'remote_ip' => sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => sanitize_text_field((string) $request->get_header('user-agent')),
            'content_type' => sanitize_text_field((string) $request->get_header('content-type')),
            'has_api_key' => trim((string) $request->get_header('x-api-key')) !== '',
            'files' => $file_logs,
        );
    }

    public function authorize_admin_submit()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('tgs_heiq_admin_forbidden', 'Bạn không có quyền chạy thử API.', array('status' => 403));
        }
        return true;
    }

    /**
     * Màn quản trị không tự xử lý file. Nó dispatch lại chính REST /files với
     * API key mới nhất, nên request/log/queue/import giống hệt BTauto gọi thật.
     */
    public function submit_file_from_admin(WP_REST_Request $request)
    {
        $settings = self::settings();
        $api_key = self::decrypt_secret($settings['api_key_secret']);
        if ($api_key === '' || !wp_check_password($api_key, $settings['api_key_hash'])) {
            return new WP_Error(
                'tgs_heiq_key_not_available',
                'Chưa lưu được API key mới nhất. Hãy bấm “Tạo lại API key” một lần rồi thử lại.',
                array('status' => 409)
            );
        }

        $api_request = new WP_REST_Request('POST', '/' . self::REST_NAMESPACE . self::REST_ROUTE);
        $api_request->set_header('x-api-key', $api_key);
        $api_request->set_file_params($request->get_file_params());
        return rest_do_request($api_request);
    }

    private function normalize_uploaded_files(array $params)
    {
        $normalized = array();
        foreach ($params as $upload) {
            if (!is_array($upload) || !isset($upload['name'])) {
                continue;
            }
            if (is_array($upload['name'])) {
                foreach ($upload['name'] as $index => $name) {
                    $normalized[] = array(
                        'name' => $name,
                        'type' => isset($upload['type'][$index]) ? $upload['type'][$index] : '',
                        'tmp_name' => isset($upload['tmp_name'][$index]) ? $upload['tmp_name'][$index] : '',
                        'error' => isset($upload['error'][$index]) ? $upload['error'][$index] : UPLOAD_ERR_NO_FILE,
                        'size' => isset($upload['size'][$index]) ? $upload['size'][$index] : 0,
                    );
                }
            } else {
                $normalized[] = $upload;
            }
        }
        return $normalized;
    }

    private function kind_from_name($file_name)
    {
        $upper = strtoupper($file_name);
        if (strpos($upper, 'PNK_') === 0) {
            return 'stock_in';
        }
        if (strpos($upper, 'PBH_') === 0) {
            return 'sale';
        }
        if (strpos($upper, 'HTL_') === 0) {
            return 'return';
        }
        if (strpos($upper, 'PNM_') === 0) {
            return 'purchase';
        }
        if (strpos($upper, 'TNCC_') === 0) {
            return 'sup_return';
        }
        return '';
    }

    public static function effective_upload_limit()
    {
        return min(self::MAX_FILE_BYTES, intval(wp_max_upload_size()));
    }

    private static function queue_directory()
    {
        $directory = WP_CONTENT_DIR . '/tgs-private-import-queue';
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new Exception('Không thể tạo thư mục hàng đợi.');
        }
        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
        $index = $directory . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        return $directory;
    }

    /**
     * Tự gắn vào Quản trị → Hệ thống của tgs_shop_management. Plugin chính đã
     * cung cấp filter nên không cần sửa bất kỳ file nào bên đó.
     */
    public function add_to_workflow_nav($workflow_nav, $current_view)
    {
        if (empty($workflow_nav['admin']['sections']) || !is_array($workflow_nav['admin']['sections'])) {
            return $workflow_nav;
        }

        $item = array(
            'view' => self::DASHBOARD_VIEW,
            'label' => 'Nhập Excel tự động (API)',
            'icon' => 'bx bx-cloud-upload',
            'capability' => 'manage_options',
        );

        foreach ($workflow_nav['admin']['sections'] as $key => $section) {
            if (isset($section['heading']) && $section['heading'] === 'Hệ thống') {
                $workflow_nav['admin']['sections'][$key]['items'][] = $item;
                return $workflow_nav;
            }
        }

        $workflow_nav['admin']['sections'][] = array(
            'heading' => 'Hệ thống',
            'icon' => 'bx bx-server',
            'items' => array($item),
        );
        return $workflow_nav;
    }

    public function register_dashboard_route($routes)
    {
        $routes[self::DASHBOARD_VIEW] = array(
            'Nhập Excel tự động qua API',
            __DIR__ . '/admin-views/excel-api-queue.php',
        );
        return $routes;
    }

    public function generate_api_key()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }
        check_admin_referer('tgs_heiq_generate_key');
        $key = 'heiq_' . bin2hex(random_bytes(24));
        update_site_option(self::SETTINGS_OPTION, array(
            'api_key_hash' => wp_hash_password($key),
            'api_key_last4' => substr($key, -4),
            'api_key_secret' => self::encrypt_secret($key),
            'actor_user_id' => get_current_user_id(),
            'tax_mode' => 'derive',
        ));
        set_transient('tgs_heiq_new_key_' . get_current_user_id(), $key, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tgs-shop-management',
            'view' => self::DASHBOARD_VIEW,
            'key-created' => 1,
        ), admin_url('admin.php')));
        exit;
    }

    private static function secret_key()
    {
        return hash('sha256', wp_salt('auth') . '|tgs-heiq-api-key', true);
    }

    private static function encrypt_secret($plain_text)
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain_text, $nonce, self::secret_key());
            return 's1:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain_text, 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) {
                return 'o1:' . base64_encode($iv . $tag . $cipher);
            }
        }
        throw new RuntimeException('Server thiếu Sodium/OpenSSL để lưu API key an toàn.');
    }

    private static function decrypt_secret($encrypted)
    {
        if (!is_string($encrypted) || strlen($encrypted) < 4) {
            return '';
        }
        $payload = base64_decode(substr($encrypted, 3), true);
        if ($payload === false) {
            return '';
        }
        if (strpos($encrypted, 's1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $nonce_size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($payload) <= $nonce_size) {
                return '';
            }
            $plain = sodium_crypto_secretbox_open(substr($payload, $nonce_size), substr($payload, 0, $nonce_size), self::secret_key());
            return $plain === false ? '' : $plain;
        }
        if (strpos($encrypted, 'o1:') === 0 && function_exists('openssl_decrypt')) {
            if (strlen($payload) <= 28) {
                return '';
            }
            $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
            return $plain === false ? '' : $plain;
        }
        return '';
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $new_key = get_transient('tgs_heiq_new_key_' . get_current_user_id());
        if ($new_key) {
            delete_transient('tgs_heiq_new_key_' . get_current_user_id());
        }
        $selected_log_date = TGS_HEIQ_Daily_Log::selected_date(
            isset($_GET['heiq_log_date']) ? sanitize_text_field(wp_unslash($_GET['heiq_log_date'])) : ''
        );
        $daily_log = TGS_HEIQ_Daily_Log::read_date($selected_log_date, self::DASHBOARD_REQUEST_LIMIT);
        $requests = array_slice($daily_log['requests'], 0, self::DASHBOARD_REQUEST_LIMIT);
        $files = array_slice($daily_log['files'], 0, self::DASHBOARD_FILE_LIMIT);
        $voucher_log_total = count($daily_log['vouchers']);
        $voucher_logs = array_slice($daily_log['vouchers'], 0, self::DASHBOARD_VOUCHER_LIMIT);
        $log_error = TGS_HEIQ_Daily_Log::last_error();
        $endpoint = rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
        $test_endpoint = rest_url(self::REST_NAMESPACE . self::REST_ADMIN_SUBMIT_ROUTE);
        $test_nonce = wp_create_nonce('wp_rest');
        $has_latest_key = self::decrypt_secret($settings['api_key_secret']) !== '';
        $curl_example = 'curl -X POST -H "X-API-Key: API_KEY" -F "files[]=@PNK_example.xlsx" "' . $endpoint . '"';
        $status_labels = array(
            'receiving' => 'Đang nhận', 'queued' => 'Đang chờ', 'processing' => 'Đang xử lý',
            'completed' => 'Hoàn tất', 'partial' => 'Một phần', 'failed' => 'Lỗi',
            'duplicate' => 'Trùng', 'imported' => 'Đã nhập', 'skipped' => 'Bỏ qua',
        );
        $kind_labels = array(
            'stock_in' => 'Nhập kho', 'sale' => 'Bán hàng', 'return' => 'Hàng trả lại',
            'purchase' => 'Nhập mua', 'sup_return' => 'Trả nhà cung cấp',
        );
        ?>
        <style>
            .tgs-heiq-page{--heiq-blue:#175cd3;--heiq-border:#e4e7ec;--heiq-text:#101828;--heiq-muted:#667085;color:var(--heiq-text);padding:4px 10px 32px;max-width:100%}
            .tgs-heiq-page *{box-sizing:border-box}.heiq-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:8px 0 22px}
            .heiq-hero h1{font-size:26px;line-height:1.25;margin:0 0 7px;color:var(--heiq-text)}.heiq-hero p{margin:0;color:var(--heiq-muted);font-size:14px}
            .heiq-hero-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}.heiq-live{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:999px;background:#ecfdf3;color:#027a48;font-weight:600;white-space:nowrap}.heiq-live:before{content:"";width:8px;height:8px;border-radius:50%;background:#12b76a}.heiq-date-form{display:flex;align-items:center;gap:7px}.heiq-date-form label{font-weight:600;color:#344054;white-space:nowrap}.heiq-date-form input{height:36px;border:1px solid #d0d5dd;border-radius:8px;padding:0 9px;background:#fff}.heiq-log-warning{display:flex;gap:9px;align-items:flex-start;margin:0 0 18px;padding:12px 14px;border:1px solid #fec84b;border-radius:9px;background:#fffaeb;color:#93370d}
            .heiq-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(330px,.65fr);gap:18px;margin-bottom:18px}.heiq-card{background:#fff;border:1px solid var(--heiq-border);border-radius:12px;padding:20px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
            .heiq-card h2{font-size:17px;margin:0 0 5px}.heiq-card-description{color:var(--heiq-muted);margin:0 0 16px;font-size:13px}.heiq-field-label{display:block;font-weight:600;margin-bottom:7px}
            .heiq-copy-row{display:flex;gap:8px;align-items:stretch}.heiq-copy-row input{min-width:0;flex:1;height:40px;border:1px solid #d0d5dd;border-radius:8px;background:#f9fafb;padding:0 12px;color:#344054;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.heiq-copy-row .button{height:40px;display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:0 14px;white-space:nowrap}
            .heiq-domain-note{display:flex;gap:7px;align-items:flex-start;margin:9px 0 0;color:var(--heiq-muted);font-size:12px}.heiq-api-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;background:#f9fafb;border-radius:8px;margin-bottom:14px}.heiq-key-state{display:flex;align-items:center;gap:8px;font-weight:600}.heiq-key-state:before{content:"";width:8px;height:8px;border-radius:50%;background:#98a2b3}.heiq-key-state.is-active:before{background:#12b76a}
            .heiq-key-form{display:flex;align-items:center;gap:10px}.heiq-key-form .button{margin:0;height:38px;border-radius:8px}.heiq-warning{margin:10px 0 0;color:#b54708;font-size:12px}.heiq-new-key{border:1px solid #abefc6;background:#ecfdf3;border-radius:9px;padding:12px;margin-bottom:14px}.heiq-new-key strong{display:block;color:#027a48;margin-bottom:8px}
            .heiq-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.heiq-fact{background:#fff;border:1px solid var(--heiq-border);border-radius:10px;padding:15px 17px}.heiq-fact-label{color:var(--heiq-muted);font-size:12px;margin-bottom:6px}.heiq-fact-value{font-weight:700;font-size:15px}.heiq-prefix{display:inline-block;padding:2px 7px;border-radius:5px;background:#eff4ff;color:#3538cd;margin-right:4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
            .heiq-curl{margin-top:14px}.heiq-curl summary{cursor:pointer;color:var(--heiq-blue);font-weight:600}.heiq-curl-row{margin-top:10px}.heiq-curl-row input{font-size:12px}
            .heiq-test-card{margin-bottom:18px}.heiq-test-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:16px}.heiq-real-run{display:inline-flex;padding:5px 9px;border-radius:6px;background:#fff4ed;color:#b93815;font-size:12px;font-weight:700;white-space:nowrap}.heiq-test-form{display:grid;grid-template-columns:minmax(260px,.8fr) minmax(320px,1.2fr) auto;gap:14px;align-items:end}.heiq-test-field label{display:block;font-weight:600;margin-bottom:7px}.heiq-test-field input[type=file]{width:100%;height:40px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;padding:6px 10px}.heiq-auto-key{height:40px;display:flex;align-items:center;gap:8px;border:1px solid #d0d5dd;border-radius:8px;background:#f9fafb;padding:0 12px;color:#344054}.heiq-auto-key.is-ready i{color:#12b76a}.heiq-auto-key.is-missing{color:#b42318;background:#fef3f2;border-color:#fecdca}.heiq-test-button{height:40px!important;border-radius:8px!important;display:inline-flex!important;align-items:center;gap:6px}.heiq-test-help{color:#b54708;font-size:12px;margin:9px 0 0}.heiq-test-result{display:none;margin-top:15px;padding:13px 15px;border-radius:9px;border:1px solid}.heiq-test-result.is-success{display:block;background:#ecfdf3;border-color:#abefc6;color:#05603a}.heiq-test-result.is-error{display:block;background:#fef3f2;border-color:#fecdca;color:#b42318}.heiq-test-result-title{font-weight:700;margin-bottom:7px}.heiq-test-details{display:flex;flex-wrap:wrap;gap:7px 18px;font-size:13px}.heiq-test-details span{white-space:nowrap}
            .heiq-table-card{padding:0;margin-bottom:18px;overflow:hidden}.heiq-table-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 20px;border-bottom:1px solid var(--heiq-border)}.heiq-table-heading h2{margin:0}.heiq-table-heading span{color:var(--heiq-muted);font-size:13px}.heiq-table-actions{display:flex;align-items:center;gap:12px}.heiq-row-limit-label{display:flex;align-items:center;gap:7px;margin:0;color:var(--heiq-muted);font-size:13px;white-space:nowrap}.heiq-row-limit{min-width:72px;height:34px;padding:2px 28px 2px 9px;border:1px solid #d0d5dd;border-radius:7px;background-color:#fff;color:#344054}.heiq-table-scroll{overflow:auto;padding:0 0 4px;transition:max-height .18s ease}.heiq-table-scroll[data-visible-rows="10"]{max-height:620px}.heiq-table-scroll thead tr:first-child th{position:sticky;top:0;z-index:3;background:#fff}.tgs-heiq-page table.widefat{border:0;box-shadow:none}.tgs-heiq-page table.widefat th{font-weight:600;color:#344054}.tgs-heiq-page table.widefat td,.tgs-heiq-page table.widefat th{vertical-align:middle}.heiq-empty{text-align:center!important;color:var(--heiq-muted)!important;padding:28px!important}
            .heiq-files-table{table-layout:fixed;min-width:1120px}.heiq-files-table .heiq-file-name{overflow-wrap:anywhere}.heiq-files-table .heiq-error-column{width:280px!important;max-width:280px}.heiq-error-preview{display:flex;align-items:center;min-width:0;gap:5px}.heiq-error-text{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#475467}.heiq-error-more{flex:0 0 auto;min-width:30px;padding:0 7px;border:0;background:#eff4ff;color:var(--heiq-blue);border-radius:5px;font-weight:700;line-height:24px;cursor:pointer}.heiq-error-more:hover,.heiq-error-more:focus{background:#dbe8ff;color:#004eae;outline:2px solid transparent}.heiq-error-empty{color:#98a2b3}.heiq-error-modal-body{max-height:min(62vh,620px);overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6;color:#344054;background:#f8fafc;border:1px solid var(--heiq-border);border-radius:8px;padding:14px}
            .heiq-request-table{min-width:1280px}.heiq-json-button{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border:1px solid #d0d5dd;border-radius:6px;background:#fff;color:#344054;font-size:12px;line-height:1.2;cursor:pointer;white-space:nowrap}.heiq-json-button:hover,.heiq-json-button:focus{border-color:#84adff;background:#eff4ff;color:var(--heiq-blue)}.heiq-json-empty{color:#98a2b3}.heiq-json-modal-body{max-height:min(65vh,650px);overflow:auto;margin:0;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55;color:#344054;background:#101828;border-radius:8px;padding:16px;color:#e4e7ec;font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
            .heiq-badge{display:inline-flex;padding:3px 9px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px;font-weight:600;white-space:nowrap}.heiq-badge-completed,.heiq-badge-imported{background:#ecfdf3;color:#027a48}.heiq-badge-failed{background:#fef3f2;color:#b42318}.heiq-badge-processing,.heiq-badge-queued,.heiq-badge-receiving{background:#eff8ff;color:#175cd3}.heiq-badge-partial,.heiq-badge-skipped{background:#fffaeb;color:#b54708}.heiq-badge-duplicate{background:#f4f3ff;color:#5925dc}
            .heiq-copy-feedback{min-height:18px;color:#027a48;font-size:12px;margin-top:5px}
            @media(max-width:1000px){.heiq-grid{grid-template-columns:1fr}.heiq-facts{grid-template-columns:1fr}.heiq-test-form{grid-template-columns:1fr}.heiq-test-button{justify-self:start}.heiq-hero{flex-direction:column}.heiq-hero-actions{justify-content:flex-start}.heiq-copy-row{flex-wrap:wrap}.heiq-copy-row input{flex-basis:100%}.heiq-table-heading{align-items:flex-start}.heiq-table-actions{align-items:flex-end;flex-direction:column;gap:6px}}
        </style>
        <div class="tgs-heiq-page">
            <header class="heiq-hero">
                <div><h1>Nhập Excel tự động qua API</h1><p>Tiếp nhận, xếp hàng và theo dõi các file HTsoft được gửi từ BTauto.</p></div>
                <div class="heiq-hero-actions">
                    <form class="heiq-date-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="tgs-shop-management">
                        <input type="hidden" name="view" value="<?php echo esc_attr(self::DASHBOARD_VIEW); ?>">
                        <label for="heiq-log-date">Ngày log</label>
                        <input id="heiq-log-date" name="heiq_log_date" type="date" min="<?php echo esc_attr(TGS_HEIQ_Daily_Log::minimum_date()); ?>" max="<?php echo esc_attr(TGS_HEIQ_Daily_Log::maximum_date()); ?>" value="<?php echo esc_attr($selected_log_date); ?>" onchange="this.form.submit()">
                        <noscript><button type="submit" class="button">Xem</button></noscript>
                    </form>
                    <span class="heiq-live">API đang hoạt động</span>
                </div>
            </header>

            <?php if (!empty($log_error['message'])) : ?>
                <div class="heiq-log-warning"><i class="bx bx-error-circle"></i><div><strong>Chưa ghi được file log.</strong> <?php echo esc_html($log_error['message']); ?><?php if (!empty($log_error['created_at'])) : ?> <small>(<?php echo esc_html($log_error['created_at']); ?>)</small><?php endif; ?> Dữ liệu chi tiết vẫn được giữ trong bảng chờ và hệ thống sẽ tự thử lại.</div></div>
            <?php endif; ?>

            <div class="heiq-grid">
                <section class="heiq-card">
                    <h2>Endpoint nhận file</h2>
                    <p class="heiq-card-description">Gửi <strong>multipart/form-data</strong> bằng field <code>files[]</code>.</p>
                    <label class="heiq-field-label" for="heiq-endpoint">URL endpoint</label>
                    <div class="heiq-copy-row"><input id="heiq-endpoint" type="text" readonly value="<?php echo esc_attr($endpoint); ?>"><button type="button" class="button heiq-copy-button" data-copy-target="heiq-endpoint"><i class="bx bx-copy"></i> Sao chép</button></div>
                    <p class="heiq-domain-note"><i class="bx bx-globe"></i><span>URL được tạo tự động từ domain hiện tại. Khi đưa code lên domain khác, endpoint sẽ tự thay đổi theo domain đó.</span></p>
                    <details class="heiq-curl"><summary>Xem lệnh cURL mẫu</summary><div class="heiq-copy-row heiq-curl-row"><input id="heiq-curl-example" type="text" readonly value="<?php echo esc_attr($curl_example); ?>"><button type="button" class="button heiq-copy-button" data-copy-target="heiq-curl-example"><i class="bx bx-copy"></i> Sao chép</button></div></details>
                </section>

                <section class="heiq-card">
                    <h2>Xác thực API</h2>
                    <p class="heiq-card-description">BTauto gửi key qua header <code>X-API-Key</code>.</p>
                    <?php if ($new_key) : ?>
                        <div class="heiq-new-key"><strong><i class="bx bx-check-circle"></i> API key mới — chỉ hiển thị lần này</strong><div class="heiq-copy-row"><input id="heiq-new-api-key" type="text" readonly value="<?php echo esc_attr($new_key); ?>"><button type="button" class="button button-primary heiq-copy-button" data-copy-target="heiq-new-api-key"><i class="bx bx-copy"></i> Sao chép key</button></div><div class="heiq-copy-feedback" aria-live="polite"></div></div>
                    <?php endif; ?>
                    <div class="heiq-api-meta"><span class="heiq-key-state <?php echo $settings['api_key_hash'] ? 'is-active' : ''; ?>"><?php echo $settings['api_key_hash'] ? 'Đã cấu hình' : 'Chưa cấu hình'; ?></span><?php if ($settings['api_key_hash']) : ?><span>Đuôi key: <code><?php echo esc_html($settings['api_key_last4']); ?></code></span><?php endif; ?></div>
                    <form class="heiq-key-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tgs_heiq_generate_key"><?php wp_nonce_field('tgs_heiq_generate_key'); ?><button type="submit" class="button button-primary"><i class="bx bx-key"></i> <?php echo $settings['api_key_hash'] ? 'Tạo lại API key' : 'Tạo API key'; ?></button></form>
                    <?php if ($settings['api_key_hash'] && !$new_key) : ?><p class="heiq-warning"><i class="bx bx-info-circle"></i> Vì bảo mật, key cũ chỉ được lưu dạng hash. Hãy tạo lại để nhận key đầy đủ và sao chép; key cũ sẽ ngừng hoạt động.</p><?php endif; ?>
                </section>
            </div>

            <div class="heiq-facts">
                <div class="heiq-fact"><div class="heiq-fact-label">QUY TẮC TÊN FILE</div><div class="heiq-fact-value"><span class="heiq-prefix">PNK_</span> Nhập kho &nbsp; <span class="heiq-prefix">PBH_</span> Bán hàng &nbsp; <span class="heiq-prefix">HTL_</span> Hàng trả &nbsp; <span class="heiq-prefix">PNM_</span> Nhập mua &nbsp; <span class="heiq-prefix">TNCC_</span> Trả NCC</div></div>
                <div class="heiq-fact"><div class="heiq-fact-label">GIỚI HẠN MỖI FILE</div><div class="heiq-fact-value"><?php echo esc_html(size_format(self::effective_upload_limit())); ?> <span style="font-weight:400;color:#667085">(.xls / .xlsx)</span></div></div>
                <div class="heiq-fact"><div class="heiq-fact-label">WORKSHEET</div><div class="heiq-fact-value">Luôn đọc sheet đầu tiên</div></div>
            </div>

            <section class="heiq-card heiq-test-card">
                <div class="heiq-test-head"><div><h2>Gọi thử API bằng file Excel</h2><p class="heiq-card-description" style="margin-bottom:0">Thực hiện đúng luồng nhận file của BTauto: tạo log, đưa vào queue và import tự động.</p></div><span class="heiq-real-run">GỌI API THẬT · CÓ IMPORT</span></div>
                <form id="heiq-api-test-form" class="heiq-test-form" data-endpoint="<?php echo esc_url($test_endpoint); ?>" data-nonce="<?php echo esc_attr($test_nonce); ?>">
                    <div class="heiq-test-field"><label>API key tự động</label><div class="heiq-auto-key <?php echo $has_latest_key ? 'is-ready' : 'is-missing'; ?>"><i class="bx <?php echo $has_latest_key ? 'bx-check-circle' : 'bx-error-circle'; ?>"></i><?php echo $has_latest_key ? 'Dùng key mới nhất · đuôi ' . esc_html($settings['api_key_last4']) : 'Cần tạo lại API key một lần'; ?></div></div>
                    <div class="heiq-test-field"><label for="heiq-test-file">File Excel</label><input id="heiq-test-file" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div>
                    <button id="heiq-test-submit" type="submit" class="button button-primary heiq-test-button" <?php disabled(!$has_latest_key); ?>><i class="bx bx-send"></i> Gọi API</button>
                </form>
                <p class="heiq-test-help"><i class="bx bx-error"></i> Đây là xử lý thật: file trùng sẽ bị bỏ qua; file mới hợp lệ sẽ được import vào dữ liệu nghiệp vụ như BTauto gửi.</p>
                <div id="heiq-test-result" class="heiq-test-result" aria-live="polite"><div id="heiq-test-result-title" class="heiq-test-result-title"></div><div id="heiq-test-result-details" class="heiq-test-details"></div></div>
            </section>

            <section class="heiq-card heiq-table-card"><div class="heiq-table-heading"><h2>Request trong ngày <?php echo esc_html(date_i18n('d/m/Y', strtotime($selected_log_date))); ?></h2><div class="heiq-table-actions"><span>Tối đa 50 request</span><label class="heiq-row-limit-label">Hiển thị <select class="heiq-row-limit" data-scroll-target="heiq-request-scroll"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select> dòng</label></div></div><div id="heiq-request-scroll" class="heiq-table-scroll" data-visible-rows="10">
                <table class="widefat striped heiq-request-table"><thead><tr><th>ID</th><th>Request ID</th><th>Trạng thái</th><th>Tổng</th><th>Nhận</th><th>Trùng</th><th>Lỗi</th><th>Thời gian</th><th>Request</th><th>Response</th><th>Thông báo</th></tr></thead><tbody>
                <?php if (!$requests) : ?><tr><td class="heiq-empty" colspan="11">Không có request trong file log của ngày đã chọn.</td></tr><?php endif; ?>
                <?php foreach ($requests as $row) : $status = (string) $row['status']; ?>
                    <tr><td><?php echo intval($row['id']); ?></td><td><code><?php echo esc_html($row['request_uuid']); ?></code></td><td><span class="heiq-badge heiq-badge-<?php echo esc_attr(sanitize_html_class($status)); ?>"><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?></span></td><td><?php echo intval($row['total_files']); ?></td><td><?php echo intval($row['accepted_files']); ?></td><td><?php echo intval($row['duplicate_files']); ?></td><td><?php echo intval($row['failed_files']); ?></td><td><?php echo esc_html($row['created_at']); ?></td><td><?php if (!empty($row['request_json'])) : ?><button type="button" class="heiq-json-button" data-json="<?php echo esc_attr($row['request_json']); ?>" data-json-title="Request — <?php echo esc_attr($row['request_uuid']); ?>"><i class="bx bx-upload"></i> Xem</button><?php else : ?><span class="heiq-json-empty">—</span><?php endif; ?></td><td><?php if (!empty($row['response_json'])) : ?><button type="button" class="heiq-json-button" data-json="<?php echo esc_attr($row['response_json']); ?>" data-json-title="Response — <?php echo esc_attr($row['request_uuid']); ?>"><i class="bx bx-download"></i> Xem</button><?php else : ?><span class="heiq-json-empty">—</span><?php endif; ?></td><td><?php echo esc_html($row['message']); ?></td></tr>
                <?php endforeach; ?></tbody></table>
            </div></section>

            <section class="heiq-card heiq-table-card"><div class="heiq-table-heading"><h2>File trong ngày</h2><div class="heiq-table-actions"><span>Tối đa 100 file</span><label class="heiq-row-limit-label">Hiển thị <select class="heiq-row-limit" data-scroll-target="heiq-file-scroll"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select> dòng</label></div></div><div id="heiq-file-scroll" class="heiq-table-scroll" data-visible-rows="10">
                <table class="widefat striped heiq-files-table"><thead><tr><th style="width:58px">ID</th><th style="width:72px">Request</th><th style="width:190px">File</th><th style="width:90px">Loại</th><th style="width:96px">Trạng thái</th><th style="width:78px">Sheet</th><th style="width:58px">Phiếu</th><th style="width:70px">Đã nhập</th><th style="width:58px">Trùng</th><th class="heiq-error-column">Lỗi</th><th style="width:130px">Hoàn tất</th></tr></thead><tbody>
                <?php if (!$files) : ?><tr><td class="heiq-empty" colspan="11">Không có file trong log của ngày đã chọn.</td></tr><?php endif; ?>
                <?php foreach ($files as $row) : $status = (string) $row['status']; $kind = (string) $row['kind']; $last_error = trim((string) $row['last_error']); $error_is_long = mb_strlen($last_error) > 90; $error_preview = $error_is_long ? mb_substr($last_error, 0, 90) : $last_error; ?>
                    <tr><td><?php echo intval($row['id']); ?></td><td><?php echo intval($row['request_id']); ?></td><td class="heiq-file-name"><strong><?php echo esc_html($row['file_name']); ?></strong></td><td><?php echo esc_html(isset($kind_labels[$kind]) ? $kind_labels[$kind] : $kind); ?></td><td><span class="heiq-badge heiq-badge-<?php echo esc_attr(sanitize_html_class($status)); ?>"><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?></span></td><td><?php echo esc_html($row['sheet_name']); ?></td><td><?php echo intval($row['vouchers_total']); ?></td><td><?php echo intval($row['vouchers_imported']); ?></td><td><?php echo intval($row['vouchers_duplicate']); ?></td><td class="heiq-error-column"><?php if ($last_error !== '') : ?><div class="heiq-error-preview"><span class="heiq-error-text"><?php echo esc_html($error_preview); ?></span><?php if ($error_is_long) : ?><button type="button" class="heiq-error-more" data-error="<?php echo esc_attr($last_error); ?>" data-file="<?php echo esc_attr($row['file_name']); ?>" aria-label="Xem đầy đủ lỗi của <?php echo esc_attr($row['file_name']); ?>" title="Xem chi tiết lỗi">…</button><?php endif; ?></div><?php else : ?><span class="heiq-error-empty">—</span><?php endif; ?></td><td><?php echo esc_html($row['completed_at']); ?></td></tr>
                <?php endforeach; ?></tbody></table>
            </div></section>

            <section class="heiq-card heiq-table-card"><div class="heiq-table-heading"><div><h2>Nhật ký phiếu trong ngày</h2><span>Chi tiết từng phiếu thuộc tối đa <?php echo esc_html(number_format_i18n(self::DASHBOARD_REQUEST_LIMIT)); ?> request mới nhất.</span></div><div class="heiq-table-actions"><span><?php echo $voucher_log_total > count($voucher_logs) ? 'Hiển thị ' . esc_html(number_format_i18n(count($voucher_logs))) . ' / ' . esc_html(number_format_i18n($voucher_log_total)) : 'Toàn bộ ' . esc_html(number_format_i18n($voucher_log_total)); ?> dòng</span><label class="heiq-row-limit-label">Hiển thị <select class="heiq-row-limit" data-scroll-target="heiq-voucher-scroll"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select> dòng</label></div></div><div id="heiq-voucher-scroll" class="heiq-table-scroll" data-visible-rows="10">
                <table class="widefat striped"><thead><tr><th>ID</th><th>Thời gian</th><th>File</th><th>Mã phiếu</th><th>Kho</th><th>Nghiệp vụ</th><th>Trạng thái</th><th>Lý do / kết quả</th></tr></thead><tbody>
                <?php if (!$voucher_logs) : ?><tr><td class="heiq-empty" colspan="8">Không có nhật ký phiếu trong ngày đã chọn.</td></tr><?php endif; ?>
                <?php foreach ($voucher_logs as $row) : $status = (string) $row['status']; $kind = (string) $row['kind']; ?>
                    <tr><td><?php echo intval($row['id']); ?></td><td><?php echo esc_html($row['created_at']); ?></td><td><strong><?php echo esc_html($row['file_name']); ?></strong></td><td><code><?php echo esc_html($row['voucher_code'] !== '' ? $row['voucher_code'] : '(không xác định)'); ?></code></td><td><?php echo esc_html($row['site_code']); ?></td><td><?php echo esc_html(isset($kind_labels[$kind]) ? $kind_labels[$kind] : $kind); ?></td><td><span class="heiq-badge heiq-badge-<?php echo esc_attr(sanitize_html_class($status)); ?>"><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?></span></td><td><?php echo esc_html($row['message']); ?></td></tr>
                <?php endforeach; ?></tbody></table>
            </div></section>

            <div class="modal fade" id="heiq-error-modal" tabindex="-1" aria-labelledby="heiq-error-modal-title" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title" id="heiq-error-modal-title">Chi tiết lỗi</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                        <div class="modal-body"><div id="heiq-error-modal-body" class="heiq-error-modal-body"></div></div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="heiq-json-modal" tabindex="-1" aria-labelledby="heiq-json-modal-title" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title" id="heiq-json-modal-title">Chi tiết API</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                        <div class="modal-body"><pre id="heiq-json-modal-body" class="heiq-json-modal-body"></pre></div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            function updateTableViewport(select){
                var scroll=document.getElementById(select.getAttribute('data-scroll-target'));
                if(!scroll){return;}
                var table=scroll.querySelector('table');
                if(!table){return;}
                var limit=parseInt(select.value,10)||10;
                var rows=Array.prototype.filter.call(table.querySelectorAll('tbody tr'),function(row){return window.getComputedStyle(row).display!=='none';});
                var height=table.tHead?table.tHead.getBoundingClientRect().height:0;
                rows.slice(0,limit).forEach(function(row){height+=row.getBoundingClientRect().height;});
                scroll.dataset.visibleRows=String(limit);
                scroll.style.maxHeight=Math.ceil(height+4)+'px';
            }
            function refreshTableViewports(){document.querySelectorAll('.heiq-row-limit').forEach(updateTableViewport);}
            document.querySelectorAll('.heiq-row-limit').forEach(function(select){select.addEventListener('change',function(){updateTableViewport(select);});});
            window.addEventListener('load',refreshTableViewports);
            window.addEventListener('resize',refreshTableViewports);
            setTimeout(refreshTableViewports,300);
            ['input','change'].forEach(function(eventName){document.addEventListener(eventName,function(event){if(event.target.closest&&event.target.closest('.heiq-table-scroll thead')){setTimeout(refreshTableViewports,0);}});});
            document.addEventListener('click',function(event){
                var jsonButton=event.target.closest('.heiq-json-button');
                if(jsonButton){
                    var jsonModalElement=document.getElementById('heiq-json-modal');
                    var jsonModalTitle=document.getElementById('heiq-json-modal-title');
                    var jsonModalBody=document.getElementById('heiq-json-modal-body');
                    if(!jsonModalElement||!jsonModalTitle||!jsonModalBody||typeof bootstrap==='undefined'){return;}
                    var raw=jsonButton.getAttribute('data-json')||'';
                    try{raw=JSON.stringify(JSON.parse(raw),null,2);}catch(ignore){}
                    jsonModalTitle.textContent=jsonButton.getAttribute('data-json-title')||'Chi tiết API';
                    jsonModalBody.textContent=raw;
                    bootstrap.Modal.getOrCreateInstance(jsonModalElement).show();
                    return;
                }
                var button=event.target.closest('.heiq-error-more');
                if(!button){return;}
                var modalElement=document.getElementById('heiq-error-modal');
                var modalTitle=document.getElementById('heiq-error-modal-title');
                var modalBody=document.getElementById('heiq-error-modal-body');
                if(!modalElement||!modalTitle||!modalBody||typeof bootstrap==='undefined'){return;}
                modalTitle.textContent='Chi tiết lỗi — '+(button.getAttribute('data-file')||'File Excel');
                modalBody.textContent=(button.getAttribute('data-error')||'').replace(/\s+\|\s+/g,'\n');
                bootstrap.Modal.getOrCreateInstance(modalElement).show();
            });
            function fallbackCopy(text){var area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();}
            document.querySelectorAll('.tgs-heiq-page .heiq-copy-button').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.getAttribute('data-copy-target'));if(!input){return;}var done=function(){var old=button.innerHTML;button.innerHTML='<i class="bx bx-check"></i> Đã sao chép';var feedback=button.closest('.heiq-new-key');if(feedback){var line=feedback.querySelector('.heiq-copy-feedback');if(line){line.textContent='Đã sao chép. Hãy dán key này vào BTauto và lưu ở nơi an toàn.';}}setTimeout(function(){button.innerHTML=old;},1800);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(input.value).then(done).catch(function(){fallbackCopy(input.value);done();});}else{fallbackCopy(input.value);done();}});});
            var testForm=document.getElementById('heiq-api-test-form');
            if(testForm){testForm.addEventListener('submit',function(event){
                event.preventDefault();
                var fileInput=document.getElementById('heiq-test-file');
                var button=document.getElementById('heiq-test-submit');
                var result=document.getElementById('heiq-test-result');
                var title=document.getElementById('heiq-test-result-title');
                var details=document.getElementById('heiq-test-result-details');
                function showError(message){result.className='heiq-test-result is-error';title.textContent='Gọi API thất bại';details.textContent=message;}
                if(!fileInput.files||!fileInput.files[0]){showError('Vui lòng chọn một file .xls hoặc .xlsx.');return;}
                var selectedFile=fileInput.files[0];
                var body=new FormData();body.append('files[]',selectedFile);
                var original=button.innerHTML;button.disabled=true;button.innerHTML='<i class="bx bx-loader-alt bx-spin"></i> Đang gọi API...';result.className='heiq-test-result';
                fetch(testForm.getAttribute('data-endpoint'),{method:'POST',headers:{'X-WP-Nonce':testForm.getAttribute('data-nonce')},body:body,credentials:'same-origin'})
                    .then(function(response){return response.json().catch(function(){return {};}).then(function(payload){if(!response.ok){throw new Error(payload.message||('HTTP '+response.status));}return payload;});})
                    .then(function(payload){
                        var isRejected=payload.success===false||payload.status==='failed'||payload.status==='partial';
                        result.className='heiq-test-result '+(isRejected?'is-error':'is-success');
                        title.textContent=isRejected?'API đã ghi log nhưng từ chối file':'Đã gọi API thật và ghi nhận request.';
                        details.textContent='';
                        [['File',selectedFile.name],['Request ID',payload.request_id],['Trạng thái',payload.status],['Đã nhận',payload.accepted?'Có':'Không']].forEach(function(item){var span=document.createElement('span');var strong=document.createElement('strong');strong.textContent=item[0]+': ';span.appendChild(strong);span.appendChild(document.createTextNode(item[1]===undefined?'':item[1]));details.appendChild(span);});
                        setTimeout(function(){window.location.reload();},1800);
                    })
                    .catch(function(error){showError(error.message||'Không thể gọi API.');})
                    .finally(function(){button.disabled=false;button.innerHTML=original;});
            });}
        })();
        </script>
        <?php
    }

    public function dependency_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $missing = array();
        if (!class_exists('TGS_IT_Excel_Parser') || !class_exists('TGS_IT_Voucher_Creator')) {
            $missing[] = 'tgs_internal_transfer';
        }
        if (!class_exists('TGS_HSI_Excel_Parser') || !class_exists('TGS_HSI_Voucher_Creator')) {
            $missing[] = 'tgs_htsoft_sales_import';
        }
        if (!class_exists('TGS_HMI_Parser') || !class_exists('TGS_HMI_Importer') || !class_exists('TGS_HMI_DB')) {
            $missing[] = 'tgs_htsoft_mua_import';
        }
        if ($missing) {
            echo '<div class="notice notice-error"><p><strong>TGS Excel API Queue:</strong> cần kích hoạt ' . esc_html(implode(', ', $missing)) . '.</p></div>';
        }
    }
}

register_activation_hook(__FILE__, array('TGS_HEIQ_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('TGS_HEIQ_Plugin', 'deactivate'));
add_action('plugins_loaded', array('TGS_HEIQ_Plugin', 'instance'), 20);
