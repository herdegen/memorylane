<?php

namespace App\Services;

class GedcomParserService
{
    /**
     * Parse a GEDCOM file and return structured data.
     *
     * @return array{individuals: array, families: array}
     */
    public function parse(string $content): array
    {
        $content = $this->normalizeEncoding($content);
        $lines = preg_split('/\r?\n/', $content);
        $individuals = [];
        $families = [];

        $currentRecord = null;
        $currentType = null;
        $currentSubTag = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (! preg_match('/^(\d+)\s+(?:(@[^@]+@)\s+)?(\w+)(?:\s+(.*))?$/', $line, $matches)) {
                continue;
            }

            $level = (int) $matches[1];
            $xrefId = $matches[2] ?: null;
            $tag = $matches[3];
            $value = $matches[4] ?? '';

            if ($level === 0) {
                // Save previous record
                if ($currentRecord && $currentType === 'INDI') {
                    $individuals[$currentRecord['id']] = $currentRecord;
                } elseif ($currentRecord && $currentType === 'FAM') {
                    $families[$currentRecord['id']] = $currentRecord;
                }

                $currentSubTag = null;

                if ($tag === 'INDI') {
                    $currentType = 'INDI';
                    $currentRecord = [
                        'id' => $xrefId,
                        'name' => '',
                        'given_name' => '',
                        'surname' => '',
                        'sex' => 'U',
                        'birth_date' => null,
                        'birth_place' => null,
                        'death_date' => null,
                        'death_place' => null,
                    ];
                } elseif ($tag === 'FAM') {
                    $currentType = 'FAM';
                    $currentRecord = [
                        'id' => $xrefId,
                        'husband_id' => null,
                        'wife_id' => null,
                        'children_ids' => [],
                        'marriage_date' => null,
                        'marriage_place' => null,
                    ];
                } else {
                    $currentType = null;
                    $currentRecord = null;
                }

                continue;
            }

            if (! $currentRecord) {
                continue;
            }

            if ($currentType === 'INDI') {
                $this->parseIndiLine($currentRecord, $level, $tag, $value, $currentSubTag);
            } elseif ($currentType === 'FAM') {
                $this->parseFamLine($currentRecord, $level, $tag, $value, $currentSubTag);
            }
        }

        // Save last record
        if ($currentRecord && $currentType === 'INDI') {
            $individuals[$currentRecord['id']] = $currentRecord;
        } elseif ($currentRecord && $currentType === 'FAM') {
            $families[$currentRecord['id']] = $currentRecord;
        }

        return [
            'individuals' => $individuals,
            'families' => $families,
        ];
    }

    private function parseIndiLine(array &$record, int $level, string $tag, string $value, ?string &$subTag): void
    {
        if ($level === 1) {
            $subTag = $tag;

            switch ($tag) {
                case 'NAME':
                    $record['name'] = str_replace('/', '', $value);
                    if (preg_match('/\/(.+?)\//', $value, $m)) {
                        $record['surname'] = $m[1];
                    }
                    if (preg_match('/^(.+?)\s*\//', $value, $m)) {
                        $record['given_name'] = trim($m[1]);
                    }
                    break;
                case 'SEX':
                    $record['sex'] = in_array($value, ['M', 'F']) ? $value : 'U';
                    break;
            }
        } elseif ($level === 2 && $subTag) {
            switch ($tag) {
                case 'DATE':
                    if ($subTag === 'BIRT') {
                        $record['birth_date'] = $this->parseGedcomDate($value);
                    } elseif ($subTag === 'DEAT') {
                        $record['death_date'] = $this->parseGedcomDate($value);
                    }
                    break;
                case 'PLAC':
                    if ($subTag === 'BIRT') {
                        $record['birth_place'] = $value;
                    } elseif ($subTag === 'DEAT') {
                        $record['death_place'] = $value;
                    }
                    break;
            }
        }
    }

    private function parseFamLine(array &$record, int $level, string $tag, string $value, ?string &$subTag): void
    {
        if ($level === 1) {
            $subTag = $tag;

            switch ($tag) {
                case 'HUSB':
                    $record['husband_id'] = $value;
                    break;
                case 'WIFE':
                    $record['wife_id'] = $value;
                    break;
                case 'CHIL':
                    $record['children_ids'][] = $value;
                    break;
            }
        } elseif ($level === 2 && $subTag === 'MARR') {
            switch ($tag) {
                case 'DATE':
                    $record['marriage_date'] = $this->parseGedcomDate($value);
                    break;
                case 'PLAC':
                    $record['marriage_place'] = $value;
                    break;
            }
        }
    }

    /**
     * Parse GEDCOM date formats into Y-m-d or null.
     * Handles: "15 MAR 1950", "MAR 1950", "1950", "ABT 1950", "BEF 1950", etc.
     */
    public function parseGedcomDate(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') {
            return null;
        }

        // Remove qualifiers
        $dateStr = preg_replace('/^(ABT|BEF|AFT|EST|CAL|FROM|TO|BET|AND|INT)\s+/i', '', $dateStr);

        $months = [
            'JAN' => '01', 'FEB' => '02', 'MAR' => '03', 'APR' => '04',
            'MAY' => '05', 'JUN' => '06', 'JUL' => '07', 'AUG' => '08',
            'SEP' => '09', 'OCT' => '10', 'NOV' => '11', 'DEC' => '12',
        ];

        // Full date: 15 MAR 1950
        if (preg_match('/^(\d{1,2})\s+([A-Z]{3})\s+(\d{4})$/i', $dateStr, $m)) {
            $month = $months[strtoupper($m[2])] ?? null;
            if ($month) {
                return sprintf('%04d-%s-%02d', $m[3], $month, $m[1]);
            }
        }

        // Month+Year: MAR 1950
        if (preg_match('/^([A-Z]{3})\s+(\d{4})$/i', $dateStr, $m)) {
            $month = $months[strtoupper($m[1])] ?? null;
            if ($month) {
                return sprintf('%04d-%s-01', $m[2], $month);
            }
        }

        // Year only: 1950
        if (preg_match('/^(\d{4})$/', $dateStr, $m)) {
            return sprintf('%04d-01-01', $m[1]);
        }

        return null;
    }

    private function normalizeEncoding(string $content): string
    {
        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $converted = iconv($encoding, 'UTF-8//TRANSLIT//IGNORE', $content);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $content;
    }
}
