<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Formula-injection protection for CSV exports: a string cell starting with = + - @ gets a
 * leading tab, so a spreadsheet app shows it as text instead of evaluating it. Same rule as
 * CertificateReportController::csvLine and DataMobilityService::csvLine. Non-strings (numbers)
 * are left alone.
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
}
