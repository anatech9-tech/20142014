<?php
/**
 * Native Excel Generator - Minimal but CORRECT OOXML.
 * Generates .xlsx files that open perfectly in Excel/LibreOffice/Google Sheets.
 */

class NativeExcel {
    private $sheets = [];
    private $current_sheet = 0;

    public function __construct() {
        $this->sheets[0] = [
            'name' => 'Sheet1',
            'cells' => [],
            'merges' => [],
            'col_widths' => [],
            'right_to_left' => false,
        ];
    }

    public function setTitle($name) {
        $this->sheets[$this->current_sheet]['name'] = $name;
    }

    public function setRightToLeft($rtl = true) {
        $this->sheets[$this->current_sheet]['right_to_left'] = $rtl;
    }

    public function setColumnWidth($col, $width) {
        $this->sheets[$this->current_sheet]['col_widths'][$col] = $width;
    }

    public function setCellValue($ref, $value) {
        if (!isset($this->sheets[$this->current_sheet]['cells'][$ref])) {
            $this->sheets[$this->current_sheet]['cells'][$ref] = ['value' => '', 'style' => 0];
        }
        $this->sheets[$this->current_sheet]['cells'][$ref]['value'] = (string)$value;
    }

    public function setStyle($ref, $style) {
        if (!isset($this->sheets[$this->current_sheet]['cells'][$ref])) {
            $this->sheets[$this->current_sheet]['cells'][$ref] = ['value' => '', 'style' => 0];
        }
        // Accept both integer and array style formats
        if (is_int($style)) {
            $this->sheets[$this->current_sheet]['cells'][$ref]['style'] = $style;
        } else {
            // Map array options to style IDs
            $id = 0;
            $hasBold = !empty($style['bold']);
            $hasFill = !empty($style['fill']);
            $isWhite = (!empty($style['font_color']) && $style['font_color'] === 'FFFFFFFF');

            if ($isWhite && $hasBold) $id = 3;      // white text on dark bg
            elseif ($hasFill && $hasBold) $id = 2;   // bold + light fill
            elseif ($hasBold) $id = 1;               // bold only

            $this->sheets[$this->current_sheet]['cells'][$ref]['style'] = $id;
        }
    }

    public function mergeCells($range) {
        $this->sheets[$this->current_sheet]['merges'][] = $range;
    }

    private function colLetter($num) {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }
        return $letter;
    }

    private function parseRef($ref) {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $col = 0;
        for ($i = 0; $i < strlen($m[1]); $i++) {
            $col = $col * 26 + (ord($m[1][$i]) - 64);
        }
        return [$col, (int)$m[2]];
    }

    /**
     * Build a valid .xlsx file and return its binary content.
     */
    public function build() {
        $shared = [];
        $sharedIndex = [];

        // Collect all shared strings
        foreach ($this->sheets as $sheet) {
            foreach ($sheet['cells'] as $cell) {
                $v = $cell['value'];
                if ($v === '' || (is_numeric($v) && $v == $v)) continue;
                if (!isset($sharedIndex[$v])) {
                    $sharedIndex[$v] = count($shared);
                    $shared[] = $v;
                }
            }
        }

        // Build XML parts
        $parts = [];

        // [Content_Types].xml
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $ct .= '<Default Extension="xml" ContentType="application/xml"/>';
        $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        for ($i = 0; $i < count($this->sheets); $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $parts['[Content_Types].xml'] = $ct;

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels .= '</Relationships>';
        $parts['_rels/.rels'] = $rels;

        // xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $wb .= '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $wb .= '<sheet name="' . htmlspecialchars($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $wb .= '</sheets>';
        $wb .= '</workbook>';
        $parts['xl/workbook.xml'] = $wb;

        // xl/_rels/workbook.xml.rels
        $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wbr .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 0; $i < count($this->sheets); $i++) {
            $wbr .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rid = count($this->sheets) + 1;
        $wbr .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rid++;
        $wbr .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $wbr .= '</Relationships>';
        $parts['xl/_rels/workbook.xml.rels'] = $wbr;

        // xl/styles.xml - minimal but valid
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $styles .= '<fonts count="4">';
        $styles .= '<font><sz val="11"/><name val="Calibri"/></font>'; // 0: default
        $styles .= '<font><b/><sz val="11"/><name val="Calibri"/></font>'; // 1: bold
        $styles .= '<font><b/><sz val="14"/><name val="Calibri"/></font>'; // 2: bold big
        $styles .= '<font><b/><sz val="14"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'; // 3: bold big white
        $styles .= '</fonts>';
        $styles .= '<fills count="4">';
        $styles .= '<fill><patternFill patternType="none"/></fill>';
        $styles .= '<fill><patternFill patternType="gray125"/></fill>';
        $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>';
        $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>';
        $styles .= '</fills>';
        $styles .= '<borders count="1">';
        $styles .= '<border><left/><right/><top/><bottom/><diagonal/></border>';
        $styles .= '</borders>';
        $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $styles .= '<cellXfs count="6">';
        $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'; // 0: default
        $styles .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'; // 1: bold
        $styles .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'; // 2: bold + fill
        $styles .= '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'; // 3: white + dark fill
        // §v13: سبک‌های wrap برای سلول‌های چندخطی (مثل چند نام در یک شبا)
        $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'; // 4: wrap
        $styles .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="center" horizontal="center"/></xf>'; // 5: header wrap
        $styles .= '</cellXfs>';
        $styles .= '</styleSheet>';
        $parts['xl/styles.xml'] = $styles;

        // xl/sharedStrings.xml
        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ss .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $str) {
            $ss .= '<si><t>' . htmlspecialchars($str, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $ss .= '</sst>';
        $parts['xl/sharedStrings.xml'] = $ss;

        // xl/worksheets/sheet*.xml
        foreach ($this->sheets as $i => $sheet) {
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
            $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            $sheetXml .= '<sheetViews>';
            $sheetXml .= '<sheetView workbookViewId="0"';
            if ($sheet['right_to_left']) $sheetXml .= ' rightToLeft="1"';
            $sheetXml .= '/>';
            $sheetXml .= '</sheetViews>';

            // Cols
            if (!empty($sheet['col_widths'])) {
                // §v13: کلید عرض می‌تواند شمارهٔ ستون (1,2,3) یا حرف ستون (A,B,C) باشد
                // و تعداد ستون‌ها دیگر روی ۱۰ ثابت نیست.
                $byNum = [];
                foreach ($sheet['col_widths'] as $k => $w) {
                    if (is_int($k) || ctype_digit((string)$k)) {
                        $byNum[(int)$k] = $w;
                    } else {
                        $n = 0; $k = strtoupper((string)$k);
                        for ($x = 0; $x < strlen($k); $x++) $n = $n * 26 + (ord($k[$x]) - 64);
                        if ($n > 0) $byNum[$n] = $w;
                    }
                }
                if ($byNum) {
                    ksort($byNum);
                    $sheetXml .= '<cols>';
                    foreach ($byNum as $c => $w) {
                        $sheetXml .= '<col min="' . $c . '" max="' . $c . '" width="' . (float)$w . '" customWidth="1"/>';
                    }
                    $sheetXml .= '</cols>';
                }
            }

            $sheetXml .= '<sheetData>';

            // Group cells by row
            $rows = [];
            foreach ($sheet['cells'] as $ref => $data) {
                list($col, $row) = $this->parseRef($ref);
                $rows[$row][$col] = $data;
            }
            ksort($rows);

            foreach ($rows as $rowNum => $cells) {
                ksort($cells);
                $sheetXml .= '<row r="' . $rowNum . '">';
                foreach ($cells as $colNum => $data) {
                    $v = $data['value'];
                    $s = $data['style'];
                    $ref = $this->colLetter($colNum) . $rowNum;

                    // سلول چندخطی بدون سبک → سبک wrap تا خطوط داخل سلول دیده شوند
                    if ($s === 0 && strpos($v, "\n") !== false) $s = 4;

                    $isNum = is_numeric($v) && $v !== '' && $v == (string)(float)$v;
                    $type = $isNum ? 'n' : 's';

                    $sheetXml .= '<c r="' . $ref . '" t="' . $type . '"';
                    if ($s > 0) $sheetXml .= ' s="' . $s . '"';
                    $sheetXml .= '>';

                    if ($isNum) {
                        $sheetXml .= '<v>' . $v . '</v>';
                    } else {
                        $idx = isset($sharedIndex[$v]) ? $sharedIndex[$v] : 0;
                        $sheetXml .= '<v>' . $idx . '</v>';
                    }
                    $sheetXml .= '</c>';
                }
                $sheetXml .= '</row>';
            }

            $sheetXml .= '</sheetData>';

            // Merges
            if (!empty($sheet['merges'])) {
                $sheetXml .= '<mergeCells count="' . count($sheet['merges']) . '">';
                foreach ($sheet['merges'] as $m) {
                    $sheetXml .= '<mergeCell ref="' . $m . '"/>';
                }
                $sheetXml .= '</mergeCells>';
            }

            $sheetXml .= '</worksheet>';
            $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $sheetXml;
        }

        // Create ZIP
        $tmp_dir = __DIR__ . '/../uploads/excel_tmp/';
        if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0777, true);
        $tmp = $tmp_dir . md5(uniqid()) . '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        foreach ($parts as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();

        $content = file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    public function download($filename) {
        $content = $this->build();
        if (empty($content)) {
            header('Content-Type: text/plain');
            echo 'Error generating Excel file';
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}
