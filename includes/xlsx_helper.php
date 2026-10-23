<?php
/**
 * Standalone XLSX & CSV Helper
 * Supports reading .xlsx / .csv files and generating genuine .xlsx / .csv sample templates
 * Uses native PHP ZipArchive and SimpleXML without third-party dependencies.
 */

class XlsxHelper {
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_EXPANDED_BYTES = 33554432;
    private const MAX_ROWS = 10001;
    private const MAX_COLUMNS = 128;
    private const MAX_CELL_BYTES = 100000;

    private static function checkInputFile(string $path): void {
        if (!is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Choose an import file of 5 MB or smaller.');
        }
    }

    /** Inspect sizes before inflation. Never expand or resolve XML entities. */
    private static function validateArchive(ZipArchive $zip): void {
        if ($zip->numFiles > 1000) throw new InvalidArgumentException('The workbook has too many entries. Split it into smaller imports.');
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || $stat['size'] < 0 || $stat['size'] > self::MAX_EXPANDED_BYTES - $expanded) {
                throw new InvalidArgumentException('The expanded workbook exceeds 32 MB. Split it into smaller imports.');
            }
            $expanded += $stat['size'];
        }
    }

    private static function readXml(string $xml): SimpleXMLElement {
        // Reject UTF-16/NUL input too, so declarations cannot evade the check.
        if (str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new InvalidArgumentException('The workbook contains unsupported XML declarations.');
        }
        $previous = libxml_use_internal_errors(true);
        try { $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if ($parsed === false) throw new InvalidArgumentException('The workbook contains invalid XML.');
        return $parsed;
    }

    private static function checkRow(array $row): void {
        if (count($row) > self::MAX_COLUMNS) throw new InvalidArgumentException('Use at most 128 import columns.');
        foreach ($row as $cell) {
            if (strlen((string)$cell) > self::MAX_CELL_BYTES || !mb_check_encoding((string)$cell, 'UTF-8')) {
                throw new InvalidArgumentException('Use UTF-8 text and keep each cell at most 100,000 bytes.');
            }
        }
    }

    /**
     * Read an Excel file (.xlsx) or CSV file (.csv) and return rows as an array of arrays
     * First row is typically headers.
     *
     * @param string $filePath Absolute path to the uploaded file
     * @return array Array of rows
     */
    public static function read($filePath, bool $preserveRows = false) {
        self::checkInputFile($filePath);

        // Try reading as XLSX if ZipArchive is supported
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                try {
                    self::validateArchive($zip);
                    $rows = self::parseXlsxZip($zip, $preserveRows);
                } finally { $zip->close(); }
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        // Fallback or explicit CSV reading
        return self::readCsv($filePath, $preserveRows);
    }

    /**
     * Parse open ZipArchive of an .xlsx file
     */
    private static function parseXlsxZip(ZipArchive $zip, bool $preserveRows = false) {
        // 1. Read Shared Strings (if present)
        $sharedStrings = [];
        $ssIndex = $zip->locateName('xl/sharedStrings.xml');
        if ($ssIndex !== false) {
            $ssXml = $zip->getFromIndex($ssIndex);
            if ($ssXml) {
                $sxml = self::readXml($ssXml);
                if ($sxml && isset($sxml->si)) {
                    foreach ($sxml->si as $si) {
                        if (isset($si->t)) {
                            $sharedStrings[] = (string)$si->t;
                        } elseif (isset($si->r)) {
                            // Rich text run
                            $runText = '';
                            foreach ($si->r as $r) {
                                if (isset($r->t)) {
                                    $runText .= (string)$r->t;
                                }
                            }
                            $sharedStrings[] = $runText;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }
        }

        // 2. Locate worksheet (usually xl/worksheets/sheet1.xml)
        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
        if($preserveRows){
            // Exam imports promise the first worksheet, which need not
            // be sheet1.xml after sheets have been reordered in Excel.
            $workbookXml=$zip->getFromName('xl/workbook.xml');$relationshipXml=$zip->getFromName('xl/_rels/workbook.xml.rels');
            if($workbookXml!==false&&$relationshipXml!==false){
                $workbook=self::readXml($workbookXml);
                $relationships=self::readXml($relationshipXml);
                if($workbook&&$relationships&&isset($workbook->sheets->sheet)){
                    $attributes=$workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');$id=(string)($attributes['id']??'');
                    foreach($relationships->Relationship as $relation)if((string)$relation['Id']===$id){
                        $target=(string)$relation['Target'];$target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
                        if(preg_match('#^xl/worksheets/sheet\d+\.xml$#i',$target))$sheetIndex=$zip->locateName($target);
                        break;
                    }
                }
            }
        }
        if ($sheetIndex === false) {
            // Find first sheet XML in worksheets/
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name)) {
                    $sheetIndex = $i;
                    break;
                }
            }
        }

        if ($sheetIndex === false) {
            return [];
        }

        $sheetXml = $zip->getFromIndex($sheetIndex);
        if (!$sheetXml) {
            return [];
        }

        $sxml = self::readXml($sheetXml);
        if (!$sxml || !isset($sxml->sheetData)) {
            return [];
        }

        $rows = [];
        $lastRow = 0;
        foreach ($sxml->sheetData->row as $rowElem) {
            if (!preg_match('/^[1-9][0-9]{0,4}$/', (string)$rowElem['r'])) throw new InvalidArgumentException('Invalid worksheet row number.');
            $rowNum = (int)$rowElem['r'];
            if ($rowNum > self::MAX_ROWS || $rowNum <= $lastRow) throw new InvalidArgumentException('Keep ordered worksheet rows within the first 10,001 rows.');
            $lastRow = $rowNum;
            $rowData = [];
            $maxColIdx = 0;

            foreach ($rowElem->c as $cell) {
                $cellRef = (string)$cell['r'];
                if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,4})$/', $cellRef, $cellMatch)
                    || (int)$cellMatch[2] !== $rowNum) throw new InvalidArgumentException('Invalid worksheet cell position.');
                $colLetters = $cellMatch[1];
                $colIdx = self::columnLetterToIndex($colLetters);
                if ($colIdx >= self::MAX_COLUMNS || array_key_exists($colIdx, $rowData)) throw new InvalidArgumentException('Use unique cells within the first 128 columns.');

                if ($colIdx > $maxColIdx) {
                    $maxColIdx = $colIdx;
                }

                $type = (string)$cell['t'];
                $val = '';

                if ($type === 's') {
                    // Shared string
                    $sIndex = (int)$cell->v;
                    $val = $sharedStrings[$sIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    // Inline string
                    if (isset($cell->is->t)) {
                        $val = (string)$cell->is->t;
                    } elseif (isset($cell->is->r)) {
                        $runText = '';
                        foreach ($cell->is->r as $r) {
                            if (isset($r->t)) $runText .= (string)$r->t;
                        }
                        $val = $runText;
                    }
                } else {
                    // Numeric / formula result / raw string
                    $val = isset($cell->v) ? (string)$cell->v : '';
                }

                $rowData[$colIdx] = trim($val);
            }
            self::checkRow($rowData);

            // Fill gaps with empty strings up to max column
            $completeRow = [];
            for ($i = 0; $i <= $maxColIdx; $i++) {
                $completeRow[$i] = $rowData[$i] ?? '';
            }

            // Only add if row is not entirely empty
            $hasContent = false;
            foreach ($completeRow as $c) {
                if ($c !== '') {
                    $hasContent = true;
                    break;
                }
            }

            if ($preserveRows) {
                while (count($rows) < $rowNum - 1) $rows[] = [];
                $rows[] = $completeRow;
            } elseif ($hasContent) {
                $rows[] = $completeRow;
            }
        }

        return $rows;
    }

    /**
     * Convert Excel column letters (A, B, ..., Z, AA, AB, ...) to 0-based column index
     */
    public static function columnLetterToIndex($column) {
        $column = strtoupper($column);
        $length = strlen($column);
        $index = 0;
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Convert 0-based column index to Excel column letter (0 => A, 1 => B, 26 => AA)
     */
    public static function indexToColumnLetter($index) {
        $index++;
        $column = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $column = chr(65 + $mod) . $column;
            $index = intval(($index - $mod) / 26);
        }
        return $column;
    }

    /**
     * Read CSV file with auto-delimiter detection and UTF-8 BOM handling
     */
    public static function readCsv($filePath, bool $preserveRows = false) {
        self::checkInputFile($filePath);
        $rows = [];
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Strip UTF-8 BOM if present
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        // Normalize newlines
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        // Detect delimiter from first non-empty line
        $firstLine = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }

        $delimiter = ',';
        if ($firstLine !== '') {
            $commaCount = substr_count($firstLine, ',');
            $semiCount = substr_count($firstLine, ';');
            $tabCount = substr_count($firstLine, "\t");
            if ($semiCount > $commaCount && $semiCount > $tabCount) {
                $delimiter = ';';
            } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
                $delimiter = "\t";
            }
        }

        $tempHandle = fopen('php://memory', 'r+');
        fwrite($tempHandle, $content);
        rewind($tempHandle);

        while (($data = fgetcsv($tempHandle, 0, $delimiter, '"', '')) !== false) {
            // Trim all cell values
            $data = array_map(static fn($value)=>trim((string)($value??'')), $data);
            self::checkRow($data);
            $hasContent = false;
            foreach ($data as $d) {
                if ($d !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent || $preserveRows) {
                $rows[] = $data;
                if (count($rows) > self::MAX_ROWS) {
                    fclose($tempHandle);
                    throw new InvalidArgumentException('Import at most 10,000 data rows plus headings.');
                }
            }
        }

        fclose($tempHandle);
        return $rows;
    }

    /**
     * Generate genuine .xlsx workbook binary data
     *
     * @param array $headers Array of column header strings
     * @param array $rows Array of data rows (arrays)
     * @param string $sheetName Worksheet title
     * @return string Binary contents of the .xlsx file
     */
    public static function generateXlsx(array $headers, array $rows, $sheetName = 'Questions', array $numericColumns = [], bool $importLayout = false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Unable to create temporary XLSX file.");
        }

        // 1. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
              . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
              . '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 2. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                      . '<Default Extension="xml" ContentType="application/xml"/>'
                      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                      . '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $cleanSheetName = htmlspecialchars(preg_replace('/[\\\\\/?*\[\]]/', '', $sheetName));
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                  . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                  . '<sheets>'
                  . '<sheet name="' . $cleanSheetName . '" sheetId="1" r:id="rId1"/>'
                  . '</sheets>'
                  . '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml (Simple styles with bold font for header row)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2">'
                . '<font><sz val="11"/><name val="Arial"/></font>'
                . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
                . '</fonts>'
                . '<fills count="3">'
                . '<fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D6EFD"/></patternFill></fill>'
                . '</fills>'
                . '<borders count="1">'
                . '<border><left/><right/><top/><bottom/><diagonal/></border>'
                . '</borders>'
                . '<cellStyleXfs count="1">'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
                . '</cellStyleXfs>'
                . '<cellXfs count="'.($importLayout?3:2).'">'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"'.($importLayout?' applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>':'/>')
                . ($importLayout?'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>':'')
                . '</cellXfs>'
                . '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                  . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if($importLayout){
            $sheetXml.='<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="2" ySplit="1" topLeftCell="C2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols>';
            foreach($headers as $i=>$header){$width=$i>=10&&$i<=21?44:match($i){0=>16,1=>24,2,3=>28,4=>22,5,6=>18,7=>20,8=>12,9=>20,default=>18};$sheetXml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';}
            $sheetXml.='</cols>';
        }
        $sheetXml.='<sheetData>';

        $rowNum = 1;

        // Write Header Row
        if (!empty($headers)) {
            $sheetXml .= '<row r="' . $rowNum . '" spans="1:' . count($headers) . '"'.($importLayout?' ht="44" customHeight="1"':'').'>';
            foreach ($headers as $colIdx => $h) {
                $cellRef = self::indexToColumnLetter($colIdx) . $rowNum;
                $val = htmlspecialchars($h, ENT_XML1, 'UTF-8');
                $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . $val . '</t></is></c>';
            }
            $sheetXml .= '</row>';
            $rowNum++;
        }

        // Write Data Rows
        foreach ($rows as $row) {
            $sheetXml .= '<row r="' . $rowNum . '"'.($importLayout?' ht="90" customHeight="1"':'').'>';
            foreach ($row as $colIdx => $val) {
                $cellRef = self::indexToColumnLetter($colIdx) . $rowNum;
                $valStr = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                if(in_array($colIdx,$numericColumns,true)&&preg_match('/^-?\d+(?:\.\d+)?$/',(string)$val))$sheetXml.='<c r="'.$cellRef.'"'.($importLayout?' s="2"':'').'><v>'.$valStr.'</v></c>';
                else $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"'.($importLayout?' s="2"':'').'><is><t>' . $valStr . '</t></is></c>';
            }
            $sheetXml .= '</row>';
            $rowNum++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();
        $binary = file_get_contents($tempFile);
        @unlink($tempFile);

        return $binary;
    }

    /**
     * Generate CSV string with UTF-8 BOM
     */
    public static function generateCsv(array $headers, array $rows) {
        $fp = fopen('php://temp', 'r+');
        // Write UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        if (!empty($headers)) {
            fputcsv($fp, $headers, ',', '"', '');
        }
        foreach ($rows as $r) {
            fputcsv($fp, $r, ',', '"', '');
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    /**
     * Get Sample Template Headers and Data for MCQs
     */
    public static function getMcqSample() {
        $headers = [
            'Class',
            'Subject',
            'Chapter',
            'Topic',
            'Cognitive Level',
            'Question (English)',
            'Question (Nepali)',
            'Option A (English)',
            'Option A (Nepali)',
            'Option B (English)',
            'Option B (Nepali)',
            'Option C (English)',
            'Option C (Nepali)',
            'Option D (English)',
            'Option D (Nepali)',
            'Correct Option',
            'Marks'
        ];

        $rows = [
            [
                'Class 10',
                'Science',
                'Force and Motion',
                'Gravitation',
                'Knowledge (K)',
                'What is the standard value of acceleration due to gravity on the Earth\'s surface?',
                'पृथ्वीको सतहमा गुरुत्व प्रवेग (g) को प्रमाणिक मान कति हुन्छ?',
                '9.8 m/s^2',
                '९.८ मिटर/सेकेन्ड^२',
                '8.9 m/s^2',
                '८.९ मिटर/सेकेन्ड^२',
                '9.8 m/s',
                '९.८ मिटर/सेकेन्ड',
                '10 m/s^3',
                '१० मिटर/सेकेन्ड^३',
                'A',
                '1'
            ],
            [
                'Class 10',
                'Science',
                'Force and Motion',
                'Weightlessness',
                'Understanding (U)',
                'Under what condition does a falling body experience complete weightlessness?',
                'कुन अवस्थामा खसिरहेको वस्तुले पूर्ण तौलविहीनताको अनुभव गर्दछ?',
                'When moving at constant velocity',
                'स्थिर गतिमा गुड्दा',
                'During free fall under gravity without air resistance',
                'हावाको अवरोध बिना गुरुत्व अन्तर्गत स्वतन्त्र खसाइ हुँदा',
                'When moving in upward direction',
                'माथितिर उड्दा',
                'When placed in deep water',
                'गहिरो पानीमा राख्दा',
                'B',
                '1'
            ],
            [
                'Class 10',
                'Mathematics',
                'Sets',
                'Cardinality of Sets',
                'Application (A)',
                'In a survey of 100 students, 60 like Tea and 50 like Coffee. If 20 like both, how many like neither?',
                '१०० जना विद्यार्थीको सर्वेक्षणमा ६० जनाले चिया र ५० जनाले कफी मन पराउँछन्। यदि २० जनाले दुवै मन पराउँछन् भने कुनै पनि मन नपराउने कति जना छन्?',
                '10',
                '१०',
                '15',
                '१५',
                '20',
                '२०',
                '30',
                '३०',
                'A',
                '1'
            ],
            [
                'Class 10',
                'Science',
                'Pressure',
                'Hydraulic Machine',
                'Higher Ability (HA)',
                'If the cross-sectional area of the narrow piston is 0.02 m^2 and that of the wide piston is 1.0 m^2 in a hydraulic lift, what force on the narrow piston is required to lift a 20,000 N car?',
                'हाइड्रोलिक लिफ्टमा सानो पिस्टनको क्षेत्रफल ०.०२ m^२ र ठूलो पिस्टनको १.० m^२ भएमा २०,००० N को गाडी उचाल्न सानो पिस्टनमा कति बल लगाउनुपर्छ?',
                '200 N',
                '२०० N',
                '400 N',
                '४०० N',
                '800 N',
                '८०० N',
                '1,000 N',
                '१००० N',
                'B',
                '1'
            ]
        ];

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Get mode-specific HTML source examples for MCQ or subjective imports.
     */
    public static function getHtmlSample(string $type): array {
        $sample = $type === 'mcq' ? self::getMcqSample() : self::getOtherSample();
        foreach ($sample['headers'] as &$header) {
            if (preg_match('/Question \(|Option [ABCD] \(|Model Answer \(/', $header)) $header .= ' HTML';
        }
        unset($header);
        if ($type === 'mcq') {
            $sample['headers'][] = 'Model Answer (English) HTML';
            $sample['headers'][] = 'Model Answer (Nepali) HTML';
            $sample['rows'] = [
                ['Class 10', 'Mathematics', 'Algebra', 'Quadratic Equations', 'Application (A)',
                 '<p>Solve <strong>the equation</strong> \\(x^2=4\\). What is the positive root?</p>',
                 '<p>\\(x^2=4\\) को धनात्मक मूल कुन हो?</p>',
                 '<p>\\(1\\)</p>', '१', '<p><strong>\\(2\\)</strong></p>', '२', '<p>\\(4\\)</p>', '४', '<p>\\(8\\)</p>', '८', 'B', '1',
                 '<p>Taking square roots gives \\(x=\\pm 2\\); the positive root is <strong>2</strong>.</p>', '<p>धनात्मक मूल २ हो।</p>'],
                ['Class 10', 'Mathematics', 'Algebra', 'Fractions', 'Knowledge (K)',
                 '<p>What is \\(\\frac{1}{2}+\\frac{1}{2}\\)?</p>', '<p>\\(\\frac{1}{2}+\\frac{1}{2}\\) कति हुन्छ?</p>',
                 '<p>\\(1\\)</p>', '१', '<p>\\(2\\)</p>', '२', '<p>\\(\\frac{1}{2}\\)</p>', 'आधा', '<p>\\(0\\)</p>', '०', 'A', '1',
                 '<p>\\[\\frac{1+1}{2}=1\\]</p>', '<p>उत्तर १ हो।</p>'],
            ];
        } else {
            $sample['rows'] = [
                ['Class 10', 'Mathematics', 'Algebra', 'Quadratic Equations', 'Short', 'Application (A)',
                 '<p>Solve \\(x^2-5x+6=0\\). <strong>Show your steps.</strong></p>', '<p>\\(x^2-5x+6=0\\) हल गर्नुहोस्।</p>', '2',
                 '<ol><li>Factor: \\((x-2)(x-3)=0\\).</li><li>So \\(x=2\\) or \\(x=3\\).</li></ol>', '<p>मूलहरू २ र ३ हुन्।</p>'],
                ['Class 10', 'Mathematics', 'Algebra', 'Fractions', 'Very Short', 'Knowledge (K)',
                 '<p>Write \\(\\frac{1}{2}\\) as a decimal.</p>', '<p>\\(\\frac{1}{2}\\) लाई दशमलवमा लेख्नुहोस्।</p>', '1',
                 '<p><strong>0.5</strong></p>', '<p>०.५</p>'],
            ];
        }
        return $sample;
    }

    /** Get normal-text sample headers and data for subjective questions. */
    public static function getOtherSample() {
        $headers = [
            'Class',
            'Subject',
            'Chapter',
            'Topic',
            'Question Type',
            'Cognitive Level',
            'Question (English)',
            'Question (Nepali)',
            'Marks',
            'Model Answer (English)',
            'Model Answer (Nepali)'
        ];

        $rows = [
            [
                'Class 10',
                'Science',
                'Force and Motion',
                'Gravitational Constant',
                'Very Short',
                'Knowledge (K)',
                'Define Universal Gravitational Constant (G) and state its SI unit.',
                'विश्वव्यापी गुरुत्वाकर्षण स्थिर अङ्क (G) को परिभाषा लेखी यसको SI एकाइ उल्लेख गर्नुहोस्।',
                '1',
                'The force of attraction between two unit masses placed at unit distance apart is called Universal Gravitational Constant. Its SI unit is N m^2/kg^2.',
                'एकाइ दुरीमा रहेका दुई एकाइ पिण्डहरू बीच उत्पन्न हुने गुरुत्वाकर्षण बललाई विश्वव्यापी गुरुत्वाकर्षण स्थिर अङ्क भनिन्छ। यसको SI एकाइ N m^२/kg^२ हो।'
            ],
            [
                'Class 10',
                'Science',
                'Force and Motion',
                'Acceleration Due to Gravity',
                'Short',
                'Understanding (U)',
                'Why does acceleration due to gravity (g) vary from poles to equator on the surface of the Earth?',
                'पृथ्वीको सतहमा ध्रुवबाट भूमध्यरेखातिर जाँदा गुरुत्वप्रवेग (g) को मान किन घट्दै जान्छ? कारण दिनुहोस्।',
                '2',
                'Earth is not a perfect sphere; its polar radius is less than its equatorial radius. Since g is inversely proportional to the square of radius (g ∝ 1/R^2), g is maximum at the poles and minimum at the equator.',
                'पृथ्वी पूर्ण गोलाकार नभई ध्रुवमा केही थेप्चिएको र भूमध्यरेखामा फुकेको छ। अर्धव्यासको वर्गसँग गुरुत्वप्रवेग व्युत्क्रमानुपातिक हुने हुनाले ध्रुवमा बढी र भूमध्यरेखामा कम हुन्छ।'
            ],
            [
                'Class 10',
                'Science',
                'Pressure',
                'Pascal\'s Law',
                'Short',
                'Application (A)',
                'State Pascal\'s law of liquid pressure and write any two devices based on it.',
                'पास्कलको तरल चापको नियम लेख्नुहोस् र यसमा आधारित कुनै दुई उपकरणहरूको नाम दिनुहोस्।',
                '2',
                'When pressure is applied to an enclosed liquid, it is transmitted equally and undiminished in all directions. Devices: Hydraulic brake, Hydraulic lift.',
                'कुनै बन्द भाँडोमा रहेको तरल पदार्थमा बाहिरबाट चाप दिइयो भने त्यो चाप चारैतिर समान रूपमा लम्ब भएर प्रसारण हुन्छ। उपकरणहरू: हाइड्रोलिक ब्रेक, हाइड्रोलिक लिफ्ट।'
            ],
            [
                'Class 10',
                'Science',
                'Force and Motion',
                'Newton\'s Law',
                'Long',
                'Higher Ability (HA)',
                'State Newton\'s Universal Law of Gravitation. Prove that the force of attraction between two bodies is F = G(m1 * m2)/d^2. What happens to the force if the distance between them is doubled?',
                'न्युटनको गुरुत्वाकर्षण सम्बन्धी विश्वव्यापी नियम लेख्नुहोस्। दुई पिण्डहरू बीच लाग्ने बल F = G(m१ * m२)/d^२ हुन्छ भनी प्रमाणित गर्नुहोस्। पिण्डहरू बीचको दुरी दोब्बर बनाउँदा बलमा के असर पर्छ?',
                '4',
                'Every mass attracts every other mass directly proportional to the product of masses and inversely proportional to square of distance. If distance is doubled, force becomes one-fourth (F/4).',
                'ब्रह्माण्डमा रहेका कुनै दुई पिण्डहरू बीचको पारस्परिक आकर्षण बल तिनीहरूको पिण्डको गुणनफलसँग समानुपातिक र केन्द्रहरू बीचको दुरीको वर्गसँग व्युत्क्रमानुपातिक हुन्छ। दुरी दोब्बर गर्दा बल एक चौथाइ (१/४) हुन्छ।'
            ]
        ];

        return ['headers' => $headers, 'rows' => $rows];
    }
}
