<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * An HTTP Range header (RFC 9110 §14) for a resource of $size bytes, for the question media
 * endpoint (audio seeking). Same rules as VideoStreamController::parseRange(): a single
 * `bytes=start-end`, `bytes=start-` or `bytes=-suffix`; an end past the last byte is clamped;
 * anything else — other units, several ranges, start after end, start at or past the end — is
 * unsatisfiable (416).
 */
final class ByteRange {
    private function __construct() {
    }

    /**
     * @return array{0: int, 1: int, 2: bool}|false [start, end, partial]; no header = the whole
     *         file as a non-partial answer; false = 416
     */
    public static function parse(string $header, int $size): array|false {
        $header = trim($header);
        if ($header === '') {
            return [0, max(0, $size - 1), false];
        }
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $m) !== 1) {
            return false;
        }
        [$all, $startStr, $endStr] = $m;
        if ($startStr === '' && $endStr === '') {
            return false;
        }
        if ($startStr === '') {
            $suffix = (int)$endStr;
            if ($suffix <= 0) {
                return false;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int)$startStr;
            $end = $endStr === '' ? $size - 1 : (int)$endStr;
        }
        if ($end > $size - 1) {
            $end = $size - 1;
        }
        if ($start < 0 || $start > $end || $start >= $size) {
            return false;
        }
        return [$start, $end, true];
    }
}
