<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Cleans the text a model returned before anyone sees it (spec 2.2), for every provider:
 * byte order mark, literal \uXXXX escapes, double-encoded UTF-8 (mojibake) and reasoning
 * blocks in <think>…</think>.
 *
 * The encoding part is the logic of GeminiProvider::normalizeModelTextOutput; without <think>
 * tags the result is byte-identical (pinned by LlmOutputNormalizerTest), so switching Gemini to
 * this class does not change live output. The old copy goes away with GeminiProvider in B3.
 */
class LlmOutputNormalizer {

    public function normalize(string $text): string {
        if (preg_match('#</?think\b#i', $text) !== 1) {
            return $this->normalizeEncoding($text);
        }
        return $this->normalizeEncoding($this->stripReasoning($text));
    }

    /**
     * Removes reasoning so that only the answer remains:
     * - every closed <think>…</think> block;
     * - an unclosed <think> at the start: the output was cut off inside the reasoning, so
     *   there is no answer at all (empty result → the adapter reports `empty`);
     * - text before an orphan </think>: chat templates that open <think> in the prompt
     *   return only the closing tag.
     */
    private function stripReasoning(string $text): string {
        $stripped = preg_replace('#<think\b[^>]*>.*?</think\s*>#is', '', $text);
        if (!is_string($stripped)) {
            return '';
        }
        if (preg_match('#^(?:\xEF\xBB\xBF)?\s*<think\b#i', $stripped) === 1) {
            return '';
        }
        $close = strripos($stripped, '</think');
        if ($close !== false) {
            $end = strpos($stripped, '>', $close);
            $stripped = $end === false ? '' : substr($stripped, $end + 1);
        }
        return $stripped;
    }

    private function normalizeEncoding(string $text): string {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', trim($text));
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        $normalized = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $matches): string {
            $decoded = json_decode('"\\u' . $matches[1] . '"', true);
            return is_string($decoded) ? $decoded : $matches[0];
        }, $normalized) ?? $normalized;

        if ($this->looksLikeMojibake($normalized)) {
            $converted = mb_convert_encoding($normalized, 'Windows-1252', 'UTF-8');
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8') && !$this->looksLikeMojibake($converted)) {
                return trim($converted);
            }
        }

        return trim($normalized);
    }

    private function looksLikeMojibake(string $text): bool {
        return preg_match('/(?:Ã.|â.|Â.|Ð.|Ñ.)/u', $text) === 1;
    }
}
