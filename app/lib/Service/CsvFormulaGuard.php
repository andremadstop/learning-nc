<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The one CSV writer of the app's exports (audit #138, #139).
 *
 * Formula-injection protection: a string cell starting with = + - @ gets a leading tab, so a
 * spreadsheet app shows it as text instead of evaluating it. Non-strings (numbers) are left alone.
 *
 * Encoding: RFC 4180 with an explicit empty escape character — a quote is always doubled, a
 * backslash is an ordinary character. That is what ImportController::csvRecords() reads back,
 * and PHP 8.4 deprecates relying on fputcsv's default escape ("\\"), whose output tore a field
 * containing \" apart.
 */
final class CsvFormulaGuard {
    private function __construct() {
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, mixed>
     */
    public static function row(array $fields): array {
        return array_map(static function ($f) {
            if (is_string($f) && $f !== '' && in_array($f[0], ['=', '+', '-', '@'], true)) {
                return "\t" . $f;
            }
            return $f;
        }, $fields);
    }

    /**
     * Write one protected row to an open stream.
     *
     * @param resource $handle
     * @param array<int, mixed> $fields
     */
    public static function writeRow($handle, array $fields): void {
        fputcsv($handle, self::row($fields), ',', '"', '');
    }

    /**
     * One protected row as a CSV line without the trailing newline.
     *
     * @param array<int, mixed> $fields
     */
    public static function line(array $fields): string {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        self::writeRow($handle, $fields);
        rewind($handle);
        $line = rtrim((string)stream_get_contents($handle), "\n");
        fclose($handle);
        return $line;
    }
}
