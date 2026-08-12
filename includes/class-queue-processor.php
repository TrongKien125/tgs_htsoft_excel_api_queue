<?php
/**
 * Worker xử lý tuần tự các file đã được REST endpoint đưa vào hàng đợi.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HEIQ_Queue_Processor
{
    const STALE_MINUTES = 30;
    const LOCK_NAME = 'tgs_htsoft_excel_api_queue_worker';
    const LOCK_WAIT_SECONDS = 300;

    private static $active_file = null;
    private static $shutdown_registered = false;
    private static $memory_reserve = '';

    public static function run()
    {
        global $wpdb;

        self::prepare_fatal_logger();
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        // Mỗi API request chờ lock để bảo đảm request đồng thời vẫn được xử lý
        // tuần tự. Không dùng WP-Cron và không để file mới mắc lại trong queue.
        $locked = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            self::LOCK_NAME,
            self::LOCK_WAIT_SECONDS
        ));
        if ((string) $locked !== '1') {
            return false;
        }

        try {
            self::recover_stale_files();
            while (true) {
                $file = self::claim_next_file();
                if (!$file) {
                    break;
                }
                self::process_file($file);
                self::refresh_request(intval($file['request_id']));
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
        }
        return true;
    }

    private static function recover_stale_files()
    {
        global $wpdb;
        $table = TGS_HEIQ_Plugin::file_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'queued', started_at = NULL,
                    last_error = 'Worker trước bị gián đoạn; đã đưa lại vào hàng đợi.'
              WHERE status = 'processing' AND started_at < %s",
            $cutoff
        ));
    }

    private static function claim_next_file()
    {
        global $wpdb;
        $table = TGS_HEIQ_Plugin::file_table();
        $row = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        if (!$row) {
            return null;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'processing', attempts = attempts + 1, started_at = %s
              WHERE id = %d AND status = 'queued'",
            current_time('mysql'),
            $row['id']
        ));
        if ($updated !== 1) {
            return null;
        }
        $row['status'] = 'processing';
        return $row;
    }

    private static function process_file(array $file)
    {
        global $wpdb;
        $table = TGS_HEIQ_Plugin::file_table();
        $path = (string) $file['stored_path'];
        $actor_id = intval(TGS_HEIQ_Plugin::settings()['actor_user_id']);
        $previous_user_id = get_current_user_id();
        $wpdb->delete(TGS_HEIQ_Plugin::voucher_log_table(), array('file_id' => intval($file['id'])));
        self::$active_file = $file;

        try {
            self::assert_dependencies($actor_id);
            wp_set_current_user($actor_id);
            self::assert_legacy_xls_memory_safe($file);
            $excel = TGS_HEIQ_Excel_Reader::read_first_sheet($path, $file['file_name']);
            $wpdb->update($table, array('sheet_name' => $excel['sheet_name']), array('id' => $file['id']));

            if ($file['kind'] === 'stock_in') {
                $result = self::import_stock_in($excel, $file);
            } else {
                $result = self::import_sale_or_return($excel, $file, $file['kind']);
            }

            $wpdb->update($table, array(
                'sheet_name' => $excel['sheet_name'],
                'status' => $result['status'],
                'vouchers_total' => $result['total'],
                'vouchers_imported' => $result['imported'],
                'vouchers_duplicate' => $result['duplicate'],
                'vouchers_failed' => $result['failed'],
                'result_json' => wp_json_encode($result, JSON_UNESCAPED_UNICODE),
                'last_error' => $result['errors'] ? implode(' | ', array_slice($result['errors'], 0, 30)) : '',
                'completed_at' => current_time('mysql'),
            ), array('id' => $file['id']));
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            self::restore_blog_context();
            self::log_voucher($file, '', '', 'failed', $e->getMessage());
            $wpdb->update($table, array(
                'status' => 'failed',
                'vouchers_failed' => 1,
                'last_error' => $e->getMessage(),
                'result_json' => wp_json_encode(array('errors' => array($e->getMessage())), JSON_UNESCAPED_UNICODE),
                'completed_at' => current_time('mysql'),
            ), array('id' => $file['id']));
        } finally {
            self::$active_file = null;
            wp_set_current_user($previous_user_id);
            self::restore_blog_context();
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
            $wpdb->update($table, array('stored_path' => ''), array('id' => $file['id']));
        }
    }

    private static function import_stock_in(array $excel, array $file)
    {
        $candidates = self::stock_in_candidates($excel['rows']);
        $parser = new TGS_IT_Excel_Parser();
        $parsed = $parser->parse_and_group(
            array($excel['sheet_name'] => $excel['rows']),
            $excel['sheet_name']
        );

        $result = self::empty_result();
        $new_keys = array();
        foreach ($parsed['tabs'] as $voucher) {
            $new_keys[$voucher['voucher_code'] . '|' . $voucher['site_code']] = true;
        }
        foreach ($candidates['mapped'] as $key => $candidate) {
            if (!isset($new_keys[$key])) {
                $result['duplicate']++;
                self::log_voucher($file, $candidate['voucher_code'], $candidate['site_code'], 'duplicate', 'Phiếu đã tồn tại nên parser bỏ qua.');
            }
        }
        foreach ($candidates['unmapped'] as $candidate) {
            $result['skipped']++;
            $message = 'Kho chưa được ánh xạ tới website.';
            $result['warnings'][] = $candidate['voucher_code'] . ': ' . $message . ' (' . $candidate['site_code'] . ')';
            self::log_voucher($file, $candidate['voucher_code'], $candidate['site_code'], 'skipped', $message);
        }

        $result['total'] = count($candidates['mapped']) + count($candidates['unmapped']);
        foreach ($parsed['tabs'] as $voucher) {
            try {
                $creator = new TGS_IT_Voucher_Creator(intval($voucher['blog_id']));
                $creator->create_vouchers(
                    $voucher['voucher_code'],
                    $voucher['site_code'],
                    $voucher['items'],
                    'Nhập tự động từ API — file ' . $file['file_name']
                );
                $result['imported']++;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'imported', 'Đã tạo phiếu nhập kho thành công.');
            } catch (TGS_IT_Duplicate_Voucher_Exception $e) {
                $result['duplicate']++;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'duplicate', $e->getMessage());
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $voucher['voucher_code'] . ': ' . $e->getMessage();
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'failed', $e->getMessage());
            }
        }

        // Parser PNK tự loại phiếu đã có trong tracker. Không có phiếu mới vẫn là
        // một lần xử lý hợp lệ; trạng thái hoàn tất giúp file không bị thử vô hạn.
        return self::finalize_result($result);
    }

    private static function import_sale_or_return(array $excel, array $file, $kind)
    {
        $no_warehouse_candidates = self::sale_no_warehouse_candidates($excel['rows'], $kind);
        $parser = new TGS_HSI_Excel_Parser();
        $parsed = $parser->parse($excel['rows'], $kind);
        $settings = TGS_HEIQ_Plugin::settings();
        $result = self::empty_result();
        $result['total'] = count($parsed['vouchers']) + count($no_warehouse_candidates);

        foreach ($parsed['vouchers'] as $voucher) {
            if (!empty($voucher['already_imported'])) {
                $result['duplicate']++;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'duplicate', 'Phiếu đã được nhập trước đó.');
                continue;
            }
            if (!empty($voucher['missing_skus'])) {
                $result['skipped']++;
                $message = 'Thiếu SKU ' . implode(', ', $voucher['missing_skus']);
                $result['warnings'][] = $voucher['voucher_code'] . ': ' . $message;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'skipped', $message);
                continue;
            }

            try {
                $creator = new TGS_HSI_Voucher_Creator(
                    intval($voucher['blog_id']),
                    $kind,
                    $settings['tax_mode'],
                    $file['file_name'],
                    $excel['sheet_name']
                );
                $creator->create($voucher);
                $result['imported']++;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'imported', $kind === 'return' ? 'Đã tạo phiếu hàng trả lại.' : 'Đã tạo phiếu bán hàng.');
            } catch (TGS_HSI_Duplicate_Exception $e) {
                $result['duplicate']++;
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'duplicate', $e->getMessage());
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $voucher['voucher_code'] . ': ' . $e->getMessage();
                self::log_voucher($file, $voucher['voucher_code'], $voucher['site_code'], 'failed', $e->getMessage());
            }
        }

        if (!empty($parsed['skipped']['no_warehouse'])) {
            foreach ($no_warehouse_candidates as $candidate) {
                $result['skipped']++;
                $message = sprintf('Kho %s chưa được ánh xạ tới website.', $candidate['site_code']);
                $result['warnings'][] = $candidate['voucher_code'] . ': ' . $message;
                self::log_voucher($file, $candidate['voucher_code'], $candidate['site_code'], 'skipped', $message);
            }
            // Dự phòng file có cấu trúc tiêu đề mới mà bộ dò chi tiết chưa nhận ra.
            if (!$no_warehouse_candidates) {
                foreach ($parsed['skipped']['no_warehouse'] as $warehouse => $count) {
                    $result['skipped']++;
                    $result['total']++;
                    $message = sprintf('Kho chưa ánh xạ (%d dòng); không xác định được mã phiếu.', $count);
                    $result['warnings'][] = sprintf('Kho chưa ánh xạ: %s (%d dòng)', $warehouse, $count);
                    self::log_voucher($file, '', $warehouse, 'skipped', $message);
                }
            }
        }
        if ($result['total'] === 0 && $result['skipped'] === 0) {
            throw new Exception('Sheet đầu tiên không có phiếu phù hợp với loại file.');
        }
        return self::finalize_result($result);
    }

    /**
     * Giữ lại mã phiếu bị parser bán hàng bỏ qua vì kho chưa ánh xạ. Parser
     * nghiệp vụ chỉ trả thống kê theo kho, trong khi màn hình log cần từng phiếu.
     */
    private static function sale_no_warehouse_candidates(array $rows, $kind)
    {
        $aliases = array(
            'voucher' => array('so phieu', 'so ct', 'so chung tu', 'so hoa don'),
            'sku' => array('ma hang', 'ma sp', 'ma san pham', 'sku'),
            'warehouse' => array('kho', 'ma kho'),
            'is_return' => array('tra lai', 'hang tra lai', 'la tra lai', 'return'),
            'reason' => array('ly do', 'ma ly do'),
            'quantity' => array('so luong', 'sl', 'sl dvcb'),
        );
        $required = array('voucher', 'sku', 'warehouse', 'quantity');
        $columns = null;
        $header_index = -1;

        foreach ($rows as $index => $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $found = array();
            foreach ($row as $column_index => $cell) {
                $normalized = TGS_HSI_Excel_Parser::norm($cell);
                foreach ($aliases as $field => $names) {
                    if (!isset($found[$field]) && in_array($normalized, $names, true)) {
                        $found[$field] = $column_index;
                    }
                }
            }
            if (!array_diff($required, array_keys($found))) {
                $columns = $found;
                $header_index = $index;
                break;
            }
        }
        if ($columns === null) {
            return array();
        }

        $site_map = TGS_HSI_Excel_Parser::site_map();
        $candidates = array();
        foreach ($rows as $index => $row) {
            if ($index <= $header_index || !is_array($row)) {
                continue;
            }
            $voucher_code = trim((string) self::row_field($row, $columns, 'voucher'));
            $sku = trim((string) self::row_field($row, $columns, 'sku'));
            if ($voucher_code === '' || $sku === '') {
                continue;
            }

            $flag = TGS_HSI_Excel_Parser::norm(self::row_field($row, $columns, 'is_return'));
            if (in_array($flag, array('true', '1', 'x', 'co', 'yes', 'y'), true)) {
                $row_kind = 'return';
            } elseif (in_array($flag, array('false', '0', 'khong', 'no', 'n'), true)) {
                $row_kind = 'sale';
            } else {
                $reason = TGS_HSI_Excel_Parser::norm(self::row_field($row, $columns, 'reason'));
                $row_kind = ($reason !== '' && strpos($reason, 'nth') === 0)
                    || TGS_HSI_Excel_Parser::number(self::row_field($row, $columns, 'quantity')) < 0
                    ? 'return' : 'sale';
            }
            if ($row_kind !== $kind) {
                continue;
            }

            $raw_site = trim((string) self::row_field($row, $columns, 'warehouse'));
            $site_code = TGS_HSI_Excel_Parser::normalize_site_code($raw_site);
            if (isset($site_map[$site_code])) {
                continue;
            }
            $display_site = $raw_site !== '' ? $raw_site : $site_code;
            $candidates[$voucher_code . '|' . $display_site] = array(
                'voucher_code' => $voucher_code,
                'site_code' => $display_site,
            );
        }
        return array_values($candidates);
    }

    private static function row_field(array $row, array $columns, $field)
    {
        if (!isset($columns[$field])) {
            return '';
        }
        return isset($row[$columns[$field]]) ? $row[$columns[$field]] : '';
    }

    /**
     * Parser chuyển kho loại phiếu đã tồn tại trước khi trả kết quả. Quét nhẹ
     * các cột cố định để giữ lại danh sách đó cho nhật ký của plugin này.
     */
    private static function stock_in_candidates(array $rows)
    {
        global $wpdb;
        $deployment_table = $wpdb->base_prefix . 'global_deployment_shops';
        $site_codes = $wpdb->get_col(
            "SELECT tgs_site_code FROM {$deployment_table} WHERE is_active = 1 AND is_deleted = 0"
        );
        $site_map = array_fill_keys(array_map('strval', $site_codes), true);
        $mapped = array();
        $unmapped = array();
        $header_found = false;

        foreach ($rows as $row) {
            if (!$header_found) {
                if (count($row) < 10) {
                    continue;
                }
                $first = mb_strtolower(trim((string) self::row_cell($row, 0)));
                $second = mb_strtolower(trim((string) self::row_cell($row, 1)));
                $header_found = (strpos($first, 'xác nhận') !== false || strpos($first, 'confirmed') !== false)
                    && (strpos($second, 'phiếu') !== false || strpos($second, 'voucher') !== false);
                continue;
            }

            $confirmed = strtoupper(trim((string) self::row_cell($row, 0)));
            $voucher_code = trim((string) self::row_cell($row, 1));
            $raw_site = trim((string) self::row_cell($row, 6));
            $sku = trim((string) self::row_cell($row, 7));
            if (($confirmed !== 'TRUE' && $confirmed !== '1') || $voucher_code === '' || $sku === '') {
                continue;
            }
            $site_code = ltrim($raw_site, '0');
            if ($site_code === '') {
                $site_code = '0';
            }
            $candidate = array('voucher_code' => $voucher_code, 'site_code' => $site_code);
            $key = $voucher_code . '|' . $site_code;
            if (isset($site_map[$site_code])) {
                $mapped[$key] = $candidate;
            } else {
                $candidate['site_code'] = $raw_site !== '' ? $raw_site : $site_code;
                $unmapped[$key] = $candidate;
            }
        }

        return array('mapped' => $mapped, 'unmapped' => array_values($unmapped));
    }

    private static function row_cell(array $row, $index)
    {
        return isset($row[$index]) ? $row[$index] : '';
    }

    private static function log_voucher(array $file, $voucher_code, $site_code, $status, $message)
    {
        global $wpdb;
        $wpdb->insert(TGS_HEIQ_Plugin::voucher_log_table(), array(
            'request_id' => intval($file['request_id']),
            'file_id' => intval($file['id']),
            'file_name' => (string) $file['file_name'],
            'voucher_code' => (string) $voucher_code,
            'site_code' => (string) $site_code,
            'kind' => (string) $file['kind'],
            'status' => (string) $status,
            'message' => (string) $message,
            'created_at' => current_time('mysql'),
        ));
    }

    /**
     * PHP memory_limit có thể kết thúc tiến trình trước khi khối catch chạy.
     * Giữ sẵn một vùng nhớ nhỏ để shutdown handler vẫn cập nhật được log.
     */
    private static function prepare_fatal_logger()
    {
        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            register_shutdown_function(array(__CLASS__, 'handle_fatal_shutdown'));
        }
        self::$memory_reserve = str_repeat('R', 1024 * 1024);
    }

    public static function handle_fatal_shutdown()
    {
        self::$memory_reserve = '';
        $error = error_get_last();
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!self::$active_file || !$error || !in_array(intval($error['type']), $fatal_types, true)) {
            return;
        }

        global $wpdb;
        $file = self::$active_file;
        $message = sprintf(
            'PHP fatal: %s tại %s:%d',
            isset($error['message']) ? $error['message'] : 'Không xác định',
            isset($error['file']) ? wp_basename($error['file']) : 'không xác định',
            isset($error['line']) ? intval($error['line']) : 0
        );
        $wpdb->update(TGS_HEIQ_Plugin::file_table(), array(
            'status' => 'failed',
            'vouchers_failed' => 1,
            'last_error' => $message,
            'result_json' => wp_json_encode(array('errors' => array($message)), JSON_UNESCAPED_UNICODE),
            'completed_at' => current_time('mysql'),
        ), array('id' => intval($file['id'])));
        self::log_voucher($file, '', '', 'failed', $message);
        self::refresh_request(intval($file['request_id']));
        self::$active_file = null;
    }

    private static function empty_result()
    {
        return array(
            'status' => 'completed',
            'total' => 0,
            'imported' => 0,
            'duplicate' => 0,
            'skipped' => 0,
            'failed' => 0,
            'warnings' => array(),
            'errors' => array(),
        );
    }

    private static function finalize_result(array $result)
    {
        // Bỏ qua là kết quả xử lý hợp lệ (ví dụ thiếu ánh xạ/SKU) và đã được
        // ghi rõ ở voucher log. Chỉ lỗi thực sự khi tạo phiếu mới làm file
        // partial/failed, để BTauto nhận completed và không gửi lại file đã xử lý.
        if ($result['failed'] > 0) {
            $result['status'] = ($result['imported'] > 0 || $result['duplicate'] > 0) ? 'partial' : 'failed';
        }
        return $result;
    }

    private static function assert_dependencies($actor_id)
    {
        $required = array(
            'TGS_IT_Excel_Parser', 'TGS_IT_Voucher_Creator',
            'TGS_HSI_Excel_Parser', 'TGS_HSI_Voucher_Creator', 'TGS_HSI_Money',
        );
        foreach ($required as $class) {
            if (!class_exists($class)) {
                throw new Exception('Thiếu dependency: ' . $class . '. Hãy kích hoạt hai plugin nghiệp vụ.');
            }
        }
        if (!class_exists('TGS_Money')) {
            $path = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-money.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('TGS_Money')) {
            throw new Exception('Thiếu TGS_Money từ tgs_shop_management.');
        }
        if (class_exists('TGS_HSI_DB')) {
            TGS_HSI_DB::maybe_install();
        }
        if ($actor_id <= 0 || !get_userdata($actor_id) || !user_can($actor_id, 'manage_options')) {
            throw new Exception('Tài khoản chạy worker chưa được cấu hình hoặc không còn quyền manage_options.');
        }
    }

    /**
     * SimpleXLS bung toàn bộ BIFF vào mảng PHP. Chặn sớm file .xls có ước tính
     * vượt RAM còn lại để request vẫn trả JSON và ghi được log thay vì PHP OOM.
     */
    private static function assert_legacy_xls_memory_safe(array $file)
    {
        if (strtolower(pathinfo((string) $file['file_name'], PATHINFO_EXTENSION)) !== 'xls') {
            return;
        }
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit <= 0) {
            return;
        }
        $available = max(0, $limit - memory_get_usage(true) - 2 * MB_IN_BYTES);
        $estimated = intval($file['file_size']) * 30;
        if ($estimated > $available) {
            throw new Exception(sprintf(
                'File XLS cần khoảng %s RAM nhưng PHP chỉ còn %s. Hãy gửi file qua BTauto để tự chuyển sang XLSX.',
                size_format($estimated),
                size_format($available)
            ));
        }
    }

    private static function refresh_request($request_id)
    {
        global $wpdb;
        $request_table = TGS_HEIQ_Plugin::request_table();
        $file_table = TGS_HEIQ_Plugin::file_table();
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$request_table} WHERE id = %d",
            $request_id
        ), ARRAY_A);
        if (!$request) {
            return;
        }

        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS total FROM {$file_table} WHERE request_id = %d GROUP BY status",
            $request_id
        ), OBJECT_K);
        $queued = intval(isset($counts['queued']) ? $counts['queued']->total : 0);
        $processing = intval(isset($counts['processing']) ? $counts['processing']->total : 0);
        $completed = intval(isset($counts['completed']) ? $counts['completed']->total : 0);
        $partial = intval(isset($counts['partial']) ? $counts['partial']->total : 0);
        $failed = intval(isset($counts['failed']) ? $counts['failed']->total : 0) + intval($request['rejected_files']);

        if ($queued > 0) {
            $status = 'queued';
        } elseif ($processing > 0) {
            $status = 'processing';
        } elseif ($partial > 0 || ($failed > 0 && $completed > 0)) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } else {
            $status = 'completed';
        }

        $wpdb->update($request_table, array(
            'status' => $status,
            'completed_files' => $completed,
            'failed_files' => $failed + $partial,
            'started_at' => $request['started_at'] ?: current_time('mysql'),
            'completed_at' => in_array($status, array('completed', 'partial', 'failed'), true) ? current_time('mysql') : null,
        ), array('id' => $request_id));
    }

    private static function restore_blog_context()
    {
        while (function_exists('ms_is_switched') && ms_is_switched()) {
            restore_current_blog();
        }
    }
}
