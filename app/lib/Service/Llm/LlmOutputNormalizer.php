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

    /**
     * A real think tag: the name `think` followed by whitespace, `/` or `>` (so `<thinking>` and
     * `<think-aloud>` are other tags). Possessive and without a lazy body, so a scan over a
     * response of the spec's 2 MB limit stays linear instead of hitting pcre.backtrack_limit.
     */
    private const THINK_TAG = '#<(/?)think(?=[\s/>])[^>]*+>#i';

    public function normalize(string $text): string {
        // Tags are looked for after \uXXXX decoding, otherwise an escaped block would only
        // become visible reasoning in normalizeEncoding(), after filtering had finished.
        $decoded = $this->decodeUnicodeEscapes($text);
        if (preg_match(self::THINK_TAG, $decoded) !== 1) {
            // No reasoning: exactly the legacy GeminiProvider path (parity test).
            return $this->normalizeEncoding($text);
        }
        return $this->normalizeEncoding($this->stripReasoning($decoded));
    }

    /**
     * Removes reasoning so that only the answer remains, by a depth-aware scan over the tags:
     * - every closed <think>…</think> block, nested blocks included;
     * - an unclosed <think> wherever it starts: the output was cut off inside the reasoning,
     *   so everything from there on goes (at the start: no answer at all → adapter `empty`).
     *   A literal mention of the opening tag therefore also ends the answer — deliberately;
     * - text before an orphan </think> that precedes every opening tag: chat templates that
     *   open <think> in the prompt return only the closing tag. Such a mention cannot be told
     *   apart from a literal one, and leaking reasoning is worse than cutting a mention.
     *   A closing tag after a completed block is ordinary answer text and is kept.
     */
    private function stripReasoning(string $text): string {
        if (preg_match_all(self::THINK_TAG, $text, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return '';
        }
        $answer = '';
        $position = 0;
        $depth = 0;
        $seenOpening = false;
        foreach ($tags as $tag) {
            [$raw, $offset] = $tag[0];
            $isClosing = $tag[1][0] === '/';
            if (!$isClosing && str_ends_with($raw, '/>')) {
                // <think/>: an empty block; drop the tag itself, nothing else to hide
                if ($depth === 0) {
                    $answer .= substr($text, $position, $offset - $position);
                    $position = $offset + strlen($raw);
                }
                continue;
            }
            if (!$isClosing) {
                if ($depth === 0) {
                    $answer .= substr($text, $position, $offset - $position);
                }
                $depth++;
                $seenOpening = true;
                continue;
            }
            if ($depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $position = $offset + strlen($raw);
                }
            } elseif (!$seenOpening) {
                $answer = '';
                $position = $offset + strlen($raw);
                $seenOpening = true;
            }
        }
        if ($depth > 0) {
            return $answer;
        }
        return $answer . substr($text, $position);
    }

    private function decodeUnicodeEscapes(string $text): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $matches): string {
            $decoded = json_decode('"\\u' . $matches[1] . '"', true);
            return is_string($decoded) ? $decoded : $matches[0];
        }, $text) ?? $text;
    }

    private function normalizeEncoding(string $text): string {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', trim($text));
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        $normalized = $this->decodeUnicodeEscapes($normalized);

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
