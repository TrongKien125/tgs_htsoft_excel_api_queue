<?php
/**
 * Đọc worksheet đầu tiên của file XLSX bằng ZipArchive + SimpleXML.
 * Không kéo thêm vendor vì worker chỉ cần dữ liệu thô giống SheetJS header:1.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HEIQ_XLSX_Reader
{
    const RELATIONSHIPS_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const MAX_ROWS = 100000;
    const MAX_COLUMNS = 300;

    public static function read_first_sheet($file_path)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('Máy chủ thiếu PHP extension ZipArchive.');
        }
        if (!is_file($file_path) || !is_readable($file_path)) {
            throw new Exception('File hàng đợi không tồn tại hoặc không đọc được.');
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            throw new Exception('File không phải XLSX hợp lệ hoặc đã bị hỏng.');
        }

        try {
            $shared_strings = self::read_shared_strings($zip);
            $sheet = self::first_sheet_meta($zip);
            $xml = $zip->getFromName($sheet['path']);
            if ($xml === false) {
                throw new Exception('Không đọc được worksheet đầu tiên.');
            }

            return array(
                'sheet_name' => $sheet['name'],
                'rows' => self::parse_sheet_xml($xml, $shared_strings),
            );
        } finally {
            $zip->close();
        }
    }

    private static function read_shared_strings(ZipArchive $zip)
    {
        $strings = array();
        $xml_data = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml_data === false) {
            return $strings;
        }

        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) {
            return $strings;
        }

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private static function first_sheet_meta(ZipArchive $zip)
    {
        $workbook_data = $zip->getFromName('xl/workbook.xml');
        $rels_data = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook_data === false || $rels_data === false) {
            throw new Exception('File XLSX thiếu thông tin workbook.');
        }

        $workbook = simplexml_load_string($workbook_data, 'SimpleXMLElement', LIBXML_NONET);
        $rels = simplexml_load_string($rels_data, 'SimpleXMLElement', LIBXML_NONET);
        // Không dùng empty() với SimpleXMLElement: một node <sheet> chỉ có
        // attributes thường bị PHP coi là "empty" dù node thực sự tồn tại.
        if ($workbook === false || $rels === false || count($workbook->sheets->sheet) < 1) {
            throw new Exception('File Excel không có worksheet.');
        }

        $rel_map = array();
        foreach ($rels->Relationship as $rel) {
            $rel_map[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $sheet = $workbook->sheets->sheet[0];
        $attrs = $sheet->attributes(self::RELATIONSHIPS_NS);
        $rid = (string) $attrs['id'];
        if ($rid === '' || !isset($rel_map[$rid])) {
            throw new Exception('Không xác định được worksheet đầu tiên.');
        }

        $target = ltrim($rel_map[$rid], '/');
        if (strpos($target, 'xl/') !== 0) {
            $target = 'xl/' . $target;
        }

        return array('name' => (string) $sheet['name'], 'path' => $target);
    }

    private static function parse_sheet_xml($xml_data, $shared_strings)
    {
        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false || !isset($xml->sheetData)) {
            throw new Exception('Worksheet đầu tiên không có dữ liệu.');
        }

        $rows = array();
        foreach ($xml->sheetData->row as $xml_row) {
            if (count($rows) >= self::MAX_ROWS) {
                throw new Exception('Worksheet vượt quá ' . number_format(self::MAX_ROWS) . ' dòng.');
            }

            $cells = array();
            foreach ($xml_row->c as $cell) {
                $index = self::column_index((string) $cell['r']);
                if ($index >= self::MAX_COLUMNS) {
                    throw new Exception('Worksheet vượt quá ' . self::MAX_COLUMNS . ' cột.');
                }

                $type = (string) $cell['t'];
                $value = '';
                if (isset($cell->v)) {
                    $raw = (string) $cell->v;
                    if ($type === 's') {
                        $value = isset($shared_strings[(int) $raw]) ? $shared_strings[(int) $raw] : '';
                    } elseif ($type === 'b') {
                        $value = $raw === '1' ? 'TRUE' : 'FALSE';
                    } else {
                        $value = $raw;
                    }
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } elseif (isset($cell->is->r)) {
                    foreach ($cell->is->r as $run) {
                        $value .= (string) $run->t;
                    }
                }
                $cells[$index] = $value;
            }

            if (!$cells) {
                $rows[] = array();
                continue;
            }

            $row = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $index => $value) {
                $row[$index] = $value;
            }
            $rows[] = $row;
        }

        if (!$rows) {
            throw new Exception('Worksheet đầu tiên không có dữ liệu.');
        }
        return $rows;
    }

    private static function column_index($cell_reference)
    {
        preg_match('/^([A-Z]+)/i', $cell_reference, $matches);
        $letters = strtoupper(isset($matches[1]) ? $matches[1] : 'A');
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }
}
