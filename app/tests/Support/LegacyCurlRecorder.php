<?php
declare(strict_types=1);

/*
 * Records what the 5.5 providers (OCA\Learning\Service\GeminiProvider / OllamaProvider) hand to
 * cURL, so their request payloads can be pinned as fixtures before the provider adapters replace
 * them (5.6.0 Task A5). The old classes call curl_* unqualified inside OCA\Learning\Service, so
 * functions of the same name in that namespace take precedence over the global ones.
 *
 * Only the two legacy providers call cURL in that namespace. While the recorder is not armed,
 * every shim passes straight through to the real extension function.
 *
 * Must be loaded before any legacy provider runs (PHP caches how an unqualified call resolves);
 * the legacy fixture test require_once's it at file level, which happens during test collection.
 */

namespace OCA\Learning\Tests\Support {
    final class LegacyCurlRecorder {
        public static bool $armed = false;
        /** @var list<array{url: string, options: array<int, mixed>}> */
        public static array $requests = [];
        public static string $responseBody = '';
        public static int $responseStatus = 200;

        public static function arm(string $responseBody, int $status = 200): void {
            self::$armed = true;
            self::$requests = [];
            self::$responseBody = $responseBody;
            self::$responseStatus = $status;
        }

        public static function disarm(): void {
            self::$armed = false;
            self::$requests = [];
        }
    }

    /** Stands in for a CurlHandle while the recorder is armed. */
    final class LegacyCurlHandle {
        public function __construct(public int $index) {
        }
    }
}

namespace OCA\Learning\Service {
    use OCA\Learning\Tests\Support\LegacyCurlHandle;
    use OCA\Learning\Tests\Support\LegacyCurlRecorder;

    if (!function_exists(__NAMESPACE__ . '\\curl_init')) {
        function curl_init(?string $url = null): mixed {
            if (!LegacyCurlRecorder::$armed) {
                return \curl_init($url);
            }
            LegacyCurlRecorder::$requests[] = ['url' => (string)$url, 'options' => []];
            return new LegacyCurlHandle(count(LegacyCurlRecorder::$requests) - 1);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\curl_setopt_array')) {
        /** @param array<int, mixed> $options */
        function curl_setopt_array(mixed $handle, array $options): bool {
            if (!$handle instanceof LegacyCurlHandle) {
                return \curl_setopt_array($handle, $options);
            }
            LegacyCurlRecorder::$requests[$handle->index]['options'] = $options + LegacyCurlRecorder::$requests[$handle->index]['options'];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\curl_exec')) {
        function curl_exec(mixed $handle): string|bool {
            if (!$handle instanceof LegacyCurlHandle) {
                return \curl_exec($handle);
            }
            return LegacyCurlRecorder::$responseBody;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\curl_getinfo')) {
        function curl_getinfo(mixed $handle, ?int $option = null): mixed {
            if (!$handle instanceof LegacyCurlHandle) {
                return \curl_getinfo($handle, $option);
            }
            return $option === CURLINFO_HTTP_CODE ? LegacyCurlRecorder::$responseStatus : [];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\curl_error')) {
        function curl_error(mixed $handle): string {
            if (!$handle instanceof LegacyCurlHandle) {
                return \curl_error($handle);
            }
            return '';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\curl_close')) {
        function curl_close(mixed $handle): void {
            if (!$handle instanceof LegacyCurlHandle) {
                \curl_close($handle);
            }
        }
    }
}
