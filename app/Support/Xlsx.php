<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads and writes plain .xlsx workbooks with PHP's zip and XML extensions, so no
 * spreadsheet library is needed. Reading returns cell values only: text, numbers and
 * booleans (dates arrive as Excel serial numbers; see fromSerial). Writing makes one sheet
 * per call with a bold, frozen header row and an autofilter. CSV files are read too.
 */
class Xlsx
{
    /** Excel counts days from 1899-12-30 (it treats 1900 as a leap year). */
    private const EPOCH = '1899-12-30';

    /**
     * The rows of one sheet, each a list of cell values indexed from 0 (gaps are null).
     * $sheet is a sheet name or a 1-based position; null takes the first sheet.
     *
     * @return list<list<string|int|float|bool|null>>
     */
    public static function read(string $path, string|int|null $sheet = null, ?string $extension = null): array
    {
        if (strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return self::readCsv($path);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not an Excel (.xlsx) file.');
        }

        try {
            $target = self::sheetPath($zip, $sheet);
            $strings = self::sharedStrings($zip);
            $xml = $zip->getFromName($target);
            if ($xml === false) {
                throw new RuntimeException('The sheet could not be read.');
            }

            return self::cells($xml, $strings);
        } finally {
            $zip->close();
        }
    }

    /**
     * A one-sheet workbook as bytes. $types gives each column's kind: string, integer,
     * decimal, money, boolean, date or datetime. Dates may be DateTimeInterface or text.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<string>  $types
     * @param  array<string, list<list<string>>>  $extraSheets  more sheets of plain text, by name
     */
    public static function write(string $sheetName, array $headers, iterable $rows, array $types = [], array $extraSheets = []): string
    {
        $sheets = [$sheetName => self::sheetXml($headers, $rows, $types)];
        foreach ($extraSheets as $name => $lines) {
            $sheets[$name] = self::sheetXml(array_shift($lines) ?? [], $lines, []);
        }

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);

        $names = array_keys($sheets);
        $overrides = $workbookSheets = $rels = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $workbookSheets .= '<sheet name="'.self::esc(self::sheetName($name)).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $sheets[$name]);
        }
        $styles = count($names) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            ."<Relationship Id=\"rId{$styles}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
            .'</Relationships>');
        // Styles: 0 plain, 1 bold header, 2 date, 3 date and time, 4 money, 5 decimal.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF2F4F7"/></patternFill></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="6"><xf/>'
            .'<xf fontId="1" fillId="2" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" applyNumberFormat="1"/>'
            .'<xf numFmtId="4" applyNumberFormat="1"/>'
            .'<xf numFmtId="2" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>');
        $zip->close();

        $bytes = file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** An Excel serial number as a date (Y-m-d) or, with $withTime, a date and time. */
    public static function fromSerial(float $serial, bool $withTime = false): string
    {
        $seconds = (int) round($serial * 86400);
        $date = (new DateTimeImmutable(self::EPOCH))->modify("+{$seconds} seconds");

        return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    public static function toSerial(DateTimeInterface $date): float
    {
        $epoch = new DateTimeImmutable(self::EPOCH, $date->getTimezone());
        $seconds = $date->getTimestamp() + $date->getOffset() - ($epoch->getTimestamp() + $epoch->getOffset());

        return $seconds / 86400;
    }

    /** "A" -> 0, "AB" -> 27. */
    public static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n - 1;
    }

    /** 0 -> "A", 27 -> "AB". */
    public static function columnLetter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** @return list<list<string|null>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = fgets($handle) ?: '';
        rewind($handle);
        // Excel saves CSV with ";" where the comma is the decimal separator.
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => $v === null || $v === '' ? null : $v, $row);
        }
        fclose($handle);
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
        }

        return $rows;
    }

    /** Where the wanted sheet lives inside the zip. */
    /**
     * The workbook's sheet names, in order; a CSV file has one, "CSV".
     *
     * @return list<string>
     */
    public static function sheets(string $path, ?string $extension = null): array
    {
        if (strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return ['CSV'];
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not an Excel (.xlsx) file.');
        }

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            if ($workbook === false) {
                throw new RuntimeException('This is not an Excel (.xlsx) file.');
            }
            $names = [];
            foreach (self::xml($workbook)->sheets->sheet as $s) {
                $names[] = (string) $s['name'];
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    private static function sheetPath(ZipArchive $zip, string|int|null $sheet): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('This is not an Excel (.xlsx) file.');
        }

        $targets = [];
        foreach (self::xml($rels)->Relationship as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $sheets = [];
        foreach (self::xml($workbook)->sheets->sheet as $s) {
            $id = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheets[] = ['name' => (string) $s['name'], 'target' => $targets[$id] ?? null];
        }

        $found = match (true) {
            $sheet === null || $sheet === '' => $sheets[0] ?? null,
            is_int($sheet) || ctype_digit($sheet) => $sheets[(int) $sheet - 1] ?? null,
            default => collect($sheets)->first(fn ($s) => strcasecmp($s['name'], trim($sheet)) === 0),
        };
        if (! $found || ! $found['target']) {
            throw new RuntimeException($sheet ? "The file has no sheet \"{$sheet}\"." : 'The file has no sheets.');
        }

        $target = ltrim($found['target'], '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach (self::xml($xml)->si as $si) {
            // Rich text keeps its pieces in <r><t>; phonetic hints (<rPh>) are left out.
            $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
        }

        return $strings;
    }

    /**
     * Streams the sheet's cells, so large sheets don't build one huge tree.
     *
     * @param  list<string>  $strings
     * @return list<list<string|int|float|bool|null>>
     */
    private static function cells(string $xml, array $strings): array
    {
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

        $rows = [];
        $rowIndex = -1;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'row') {
                $r = $reader->getAttribute('r');
                $rowIndex = $r !== null ? (int) $r - 1 : $rowIndex + 1;
                $rows[$rowIndex] ??= [];
            } elseif ($reader->localName === 'c') {
                $ref = $reader->getAttribute('r');
                $col = $ref !== null && preg_match('/^([A-Z]+)/', $ref, $m) ? self::columnIndex($m[1]) : count($rows[$rowIndex] ?? []);
                $type = $reader->getAttribute('t') ?? 'n';
                $cell = self::xml($reader->readOuterXml());
                $raw = isset($cell->v) ? (string) $cell->v : null;

                $rows[$rowIndex][$col] = match ($type) {
                    's' => $strings[(int) $raw] ?? null,
                    'inlineStr' => isset($cell->is->t) ? (string) $cell->is->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($cell->is->r ?? [], false))),
                    'b' => $raw === null ? null : $raw === '1',
                    'str', 'e' => $raw,
                    default => $raw === null || $raw === '' ? null : (str_contains($raw, '.') || stripos($raw, 'e') !== false ? (float) $raw : (int) $raw),
                };
            }
        }
        $reader->close();

        if (! $rows) {
            return [];
        }

        // Fill gaps so every row is a plain list.
        $out = [];
        for ($i = 0, $last = max(array_keys($rows)); $i <= $last; $i++) {
            $row = $rows[$i] ?? [];
            $width = $row ? max(array_keys($row)) + 1 : 0;
            $out[] = array_map(fn ($c) => $row[$c] ?? null, $width ? range(0, $width - 1) : []);
        }

        return $out;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<string>  $types
     */
    private static function sheetXml(array $headers, iterable $rows, array $types): string
    {
        $widths = array_map(fn ($h) => mb_strlen((string) $h), $headers);
        $body = self::rowXml(1, $headers, [], true, $widths);
        $n = 1;
        foreach ($rows as $row) {
            $body .= self::rowXml(++$n, $row, $types, false, $widths);
        }

        $cols = '';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(max($w + 2, 8), 60).'" customWidth="1"/>';
        }
        $last = self::columnLetter(max(count($headers), 1) - 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols ? "<cols>{$cols}</cols>" : '')
            ."<sheetData>{$body}</sheetData>"
            .($headers ? "<autoFilter ref=\"A1:{$last}{$n}\"/>" : '')
            .'</worksheet>';
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<string>  $types
     * @param  list<int>  $widths  grows to fit the longest value per column
     */
    private static function rowXml(int $n, array $values, array $types, bool $header, array &$widths): string
    {
        $cells = '';
        foreach (array_values($values) as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = self::columnLetter($i).$n;
            $type = $header ? 'header' : ($types[$i] ?? 'string');

            if (in_array($type, ['date', 'datetime'], true) && ! $value instanceof DateTimeInterface && strtotime((string) $value) !== false) {
                $value = new DateTimeImmutable((string) $value);
            }

            if ($value instanceof DateTimeInterface) {
                $style = $type === 'datetime' ? 3 : 2;
                $cells .= "<c r=\"{$ref}\" s=\"{$style}\"><v>".self::toSerial($value).'</v></c>';
                $len = $style === 3 ? 16 : 10;
            } elseif (is_bool($value) || $type === 'boolean') {
                $cells .= "<c r=\"{$ref}\" t=\"b\"><v>".(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0).'</v></c>';
                $len = 5;
            } elseif (! $header && in_array($type, ['integer', 'decimal', 'money'], true) && is_numeric($value)) {
                $style = ['integer' => '', 'decimal' => ' s="5"', 'money' => ' s="4"'][$type];
                $cells .= "<c r=\"{$ref}\"{$style}><v>".(0 + $value).'</v></c>';
                $len = strlen((string) $value) + 3;
            } else {
                $text = (string) $value;
                $cells .= "<c r=\"{$ref}\" t=\"inlineStr\"".($header ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';
                $len = mb_strlen($text);
            }
            $widths[$i] = max($widths[$i] ?? 0, $len);
        }

        return "<row r=\"{$n}\">{$cells}</row>";
    }

    /** Sheet names: at most 31 characters, none of []:*?/\ */
    private static function sheetName(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name)) ?: 'Sheet1', 0, 31);
    }

    private static function esc(string $text): string
    {
        // Control characters other than tab and newlines are not allowed in XML.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($doc === false) {
            throw new RuntimeException('The file is damaged and could not be read.');
        }

        return $doc;
    }
}
