<?php
/**
 * Reader chung cho XLS (BIFF 97-2003) và XLSX, luôn trả worksheet đầu tiên
 * theo cùng cấu trúc rows[][] mà các parser nghiệp vụ đang sử dụng.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HEIQ_Excel_Reader
{
    const MAX_ROWS = 100000;
    const MAX_COLUMNS = 300;

    public static function read_first_sheet($file_path, $original_name = '')
    {
        $name_for_extension = $original_name !== '' ? $original_name : $file_path;
        $extension = strtolower(pathinfo($name_for_extension, PATHINFO_EXTENSION));
        if ($extension === 'xlsx') {
            return TGS_HEIQ_XLSX_Reader::read_first_sheet($file_path);
        }
        if ($extension === 'xls') {
            return self::read_xls_first_sheet($file_path);
        }
        throw new Exception('Chỉ hỗ trợ định dạng .xls và .xlsx.');
    }

    private static function read_xls_first_sheet($file_path)
    {
        if (!class_exists('Shuchkin\\SimpleXLS')) {
            throw new Exception('Thiếu thư viện đọc file .xls.');
        }
        if (!is_file($file_path) || !is_readable($file_path)) {
            throw new Exception('File hàng đợi không tồn tại hoặc không đọc được.');
        }

        $xls = Shuchkin\SimpleXLS::parseFile($file_path);
        if (!$xls) {
            $detail = trim((string) Shuchkin\SimpleXLS::parseError());
            throw new Exception('File không phải XLS hợp lệ hoặc đã bị hỏng.' . ($detail ? ' ' . $detail : ''));
        }

        $rows = $xls->rows(0, self::MAX_ROWS + 1);
        if (count($rows) > self::MAX_ROWS) {
            throw new Exception('Worksheet vượt quá ' . number_format(self::MAX_ROWS) . ' dòng.');
        }
        if (!$rows) {
            throw new Exception('Worksheet đầu tiên không có dữ liệu.');
        }

        foreach ($rows as $row_index => $row) {
            if (count($row) > self::MAX_COLUMNS) {
                throw new Exception('Worksheet vượt quá ' . self::MAX_COLUMNS . ' cột.');
            }
            foreach ($row as $column_index => $value) {
                if ($value === null) {
                    $rows[$row_index][$column_index] = '';
                } elseif (is_bool($value)) {
                    $rows[$row_index][$column_index] = $value ? 'TRUE' : 'FALSE';
                }
            }
        }

        $sheet_name = trim((string) $xls->sheetName(0));
        return array(
            'sheet_name' => $sheet_name !== '' ? $sheet_name : 'Sheet1',
            'rows' => $rows,
        );
    }
}
