<?php
declare(strict_types=1);

// Nextcloud's global template helper (lib/private/legacy/template/functions.php), for unit tests that
// render a template from app/templates without the server: print the value HTML-escaped.
if (!function_exists('p')) {
    function p(mixed $string): void {
        print(htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8'));
    }
}
