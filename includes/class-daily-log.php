<?php
/**
 * Lưu lịch sử API theo ngày dưới dạng JSON Lines được bọc PHP.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_HEIQ_Daily_Log
{
    const SCHEMA_VERSION = 1;
    const RETENTION_DAYS = 90;
    const CLEANUP_OPTION = 'tgs_heiq_log_cleanup_date';
    const ERROR_OPTION = 'tgs_heiq_log_write_error';
    const FILE_PREFIX = 'excel-api-';
    const FILE_SUFFIX = '.jsonl.php';
    const PHP_GUARD = '<?php exit; ?>';

    public static function archive_request($request_id)
    {
        global $wpdb;
        $request_id = intval($request_id);
        if ($request_id <= 0) {
            return false;
        }

        $request = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_HEIQ_Plugin::request_table() . ' WHERE id = %d',
            $request_id
        ), ARRAY_A);
        if (!$request || empty($request['log_archive_required'])) {
            return true;
        }
        if (!empty($request['log_archived_at'])) {
            return true;
        }
        if (!in_array((string) $request['status'], array('completed', 'partial', 'failed', 'duplicate'), true)) {
            return false;
        }

        $files = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . TGS_HEIQ_Plugin::file_table() . ' WHERE request_id = %d ORDER BY id ASC',
            $request_id
        ), ARRAY_A);
        $vouchers = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . TGS_HEIQ_Plugin::voucher_log_table() . ' WHERE request_id = %d ORDER BY id ASC',
            $request_id
        ), ARRAY_A);

        $log_date = self::date_from_mysql($request['created_at']);
        $record = self::build_record($request, $files, $vouchers, $log_date);
        $error = '';
        if (!self::append_record($log_date, $record, $error)) {
            self::remember_error($error ?: 'Không thể ghi file log JSONL.');
            return false;
        }

        $marked = $wpdb->update(TGS_HEIQ_Plugin::request_table(), array(
            'log_archived_at' => current_time('mysql'),
            'log_file_date' => $log_date,
            'request_json' => null,
            'response_json' => null,
        ), array('id' => $request_id));
        if ($marked === false) {
            self::remember_error('Đã ghi JSONL nhưng chưa đánh dấu được trạng thái archive trong DB.');
            return false;
        }

        $wpdb->delete(TGS_HEIQ_Plugin::voucher_log_table(), array('request_id' => $request_id));
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . TGS_HEIQ_Plugin::file_table() . " SET result_json = NULL, last_error = '' WHERE request_id = %d",
            $request_id
        ));
        delete_site_option(self::ERROR_OPTION);
        self::cleanup_old_logs();
        return true;
    }

    public static function recover_pending()
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            'SELECT id FROM ' . TGS_HEIQ_Plugin::request_table()
            . " WHERE log_archive_required = 1 AND log_archived_at IS NULL"
            . " AND status IN ('completed','partial','failed','duplicate') ORDER BY id ASC LIMIT 20"
        );
        foreach ((array) $ids as $id) {
            self::archive_request($id);
        }
    }

    public static function read_date($date, $record_limit = 0)
    {
        $date = self::normalize_date($date);
        $record_limit = max(0, intval($record_limit));
        $result = array('date' => $date, 'requests' => array(), 'files' => array(), 'vouchers' => array());
        $path = self::file_path($date, false);
        if (!$path || !is_file($path) || !is_readable($path)) {
            return $result;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return $result;
        }
        $records = array();
        $record_lines = array();
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || strpos($line, '<?php') === 0) {
                continue;
            }
            if ($record_limit > 0) {
                if (!preg_match('/"request_uuid":"([^"]+)"/', $line, $matches)) {
                    continue;
                }
                $request_uuid = (string) $matches[1];
                // Gán lại ở cuối để bản duplicate mới nhất được giữ lại đúng thứ tự.
                unset($record_lines[$request_uuid]);
                $record_lines[$request_uuid] = $line;
                while (count($record_lines) > $record_limit) {
                    array_shift($record_lines);
                }
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || empty($record['request_uuid'])) {
                continue;
            }
            // Nếu tiến trình chết sau append nhưng trước khi đánh dấu DB, lần
            // phục hồi có thể append lại. Bản cuối là bản đầy đủ mới nhất.
            $records[(string) $record['request_uuid']] = $record;
        }
        fclose($handle);

        // File log có thể rất lớn. Với màn quản trị, chỉ JSON-decode số record
        // cuối được yêu cầu thay vì giải mã toàn bộ ngày rồi mới array_slice().
        foreach ($record_lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || empty($record['request_uuid'])) {
                continue;
            }
            $records[(string) $record['request_uuid']] = $record;
        }

        foreach ($records as $record) {
            $request = isset($record['request']) && is_array($record['request']) ? $record['request'] : array();
            $request['request_json'] = wp_json_encode($record['request_payload'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $request['response_json'] = wp_json_encode($record['response'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result['requests'][] = $request;
            foreach ((array) ($record['files'] ?? array()) as $file) {
                if (is_array($file)) {
                    $result['files'][] = $file;
                }
            }
            foreach ((array) ($record['vouchers'] ?? array()) as $voucher) {
                if (is_array($voucher)) {
                    $result['vouchers'][] = $voucher;
                }
            }
        }

        usort($result['requests'], array(__CLASS__, 'sort_id_desc'));
        usort($result['files'], array(__CLASS__, 'sort_id_desc'));
        usort($result['vouchers'], array(__CLASS__, 'sort_id_desc'));
        return $result;
    }

    public static function selected_date($raw = '')
    {
        return self::normalize_date($raw);
    }

    public static function minimum_date()
    {
        return wp_date('Y-m-d', current_time('timestamp') - (self::RETENTION_DAYS - 1) * DAY_IN_SECONDS);
    }

    public static function maximum_date()
    {
        return wp_date('Y-m-d', current_time('timestamp'));
    }

    public static function last_error()
    {
        $error = get_site_option(self::ERROR_OPTION, array());
        return is_array($error) ? $error : array();
    }

    private static function build_record(array $request, array $files, array $vouchers, $log_date)
    {
        $request_payload = self::decode_json($request['request_json'] ?? '');
        $response = self::decode_json($request['response_json'] ?? '');
        unset(
            $request['request_json'],
            $request['response_json'],
            $request['log_archive_required'],
            $request['log_archived_at'],
            $request['log_file_date']
        );
        foreach ($files as &$file) {
            $file['result'] = self::decode_json($file['result_json'] ?? '');
            unset($file['stored_path'], $file['result_json']);
        }
        unset($file);

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'request_uuid' => (string) $request['request_uuid'],
            'log_date' => $log_date,
            'archived_at' => current_time('mysql'),
            'request' => $request,
            'request_payload' => $request_payload,
            'response' => $response,
            'files' => array_values($files),
            'vouchers' => array_values($vouchers),
        );
    }

    private static function append_record($date, array $record, &$error)
    {
        $path = self::file_path($date, true);
        if (!$path) {
            $error = 'Không tạo được thư mục log trong plugin.';
            return false;
        }
        $handle = @fopen($path, 'c+b');
        if (!$handle) {
            $error = 'Không mở được file log để ghi: ' . $path;
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            $error = 'Không khóa được file log để ghi.';
            return false;
        }

        $needle = '"request_uuid":"' . (string) $record['request_uuid'] . '"';
        rewind($handle);
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, $needle) !== false) {
                flock($handle, LOCK_UN);
                fclose($handle);
                return true;
            }
        }
        $stat = fstat($handle);
        if (!$stat || intval($stat['size']) === 0) {
            fseek($handle, 0, SEEK_SET);
            if (!self::write_all($handle, self::PHP_GUARD . "\n")) {
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
                fclose($handle);
                $error = 'Không ghi được lớp bảo vệ PHP cho file log.';
                return false;
            }
        }
        fseek($handle, 0, SEEK_END);
        $record_offset = ftell($handle);
        $json = wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = $json !== false && self::write_all($handle, $json . "\n");
        if (!$written && $record_offset !== false) {
            ftruncate($handle, $record_offset);
        }
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($written === false) {
            $error = 'Không ghi được record JSONL.';
            return false;
        }
        return true;
    }

    private static function write_all($handle, $content)
    {
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private static function file_path($date, $create)
    {
        $date = self::normalize_date($date);
        $year = substr($date, 0, 4);
        $month = substr($date, 5, 2);
        $directory = dirname(__DIR__) . '/logs/' . $year . '/' . $month;
        if ($create && !self::ensure_directory($directory)) {
            return '';
        }
        return $directory . '/' . self::FILE_PREFIX . $date . self::FILE_SUFFIX;
    }

    private static function ensure_directory($directory)
    {
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return false;
        }
        return is_dir($directory) && is_writable($directory);
    }

    private static function normalize_date($date)
    {
        $today = self::maximum_date();
        $date = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $today;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return $today;
        }
        if ($date < self::minimum_date() || $date > $today) {
            return $today;
        }
        return $date;
    }

    private static function date_from_mysql($value)
    {
        $date = substr((string) $value, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : self::maximum_date();
    }

    private static function decode_json($value)
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function sort_id_desc($left, $right)
    {
        return intval($right['id'] ?? 0) <=> intval($left['id'] ?? 0);
    }

    private static function remember_error($message)
    {
        update_site_option(self::ERROR_OPTION, array(
            'message' => (string) $message,
            'created_at' => current_time('mysql'),
        ));
    }

    private static function cleanup_old_logs()
    {
        $today = self::maximum_date();
        if (get_site_option(self::CLEANUP_OPTION) === $today) {
            return;
        }
        $cutoff = self::minimum_date();
        $pattern = dirname(__DIR__) . '/logs/*/*/' . self::FILE_PREFIX . '*' . self::FILE_SUFFIX;
        foreach ((array) glob($pattern) as $path) {
            $name = basename($path);
            if (!preg_match('/^excel-api-(\d{4}-\d{2}-\d{2})\.jsonl\.php$/', $name, $matches)) {
                continue;
            }
            if ($matches[1] < $cutoff && is_file($path)) {
                @unlink($path);
            }
        }
        update_site_option(self::CLEANUP_OPTION, $today);
    }
}
