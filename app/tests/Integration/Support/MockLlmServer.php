<?php
/**
 * Mock of an OpenAI-compatible LLM endpoint for integration tests.
 *
 * This file is both the helper class and the router script of PHP's built-in server:
 *   php -S 127.0.0.1:18080 tests/Integration/Support/MockLlmServer.php
 * (scripts/nc-integration.sh starts it inside the Nextcloud container).
 *
 * Every request — any path, any method — is appended to LOG as one JSON line
 * {"method","path","body"}. POST .../chat/completions gets a minimal chat.completion
 * answer, POST .../api/generate a minimal Ollama answer (5.7.0 routes only Gemini and
 * Ollama, so integration tests reach this mock as an Ollama endpoint: ollamaBaseUrl());
 * everything else gets 404 with the request's text fields quoted in the error message.
 *
 * The log is the measurement consent tests rely on ("0 requests"), so it never fails
 * silently: LOG must be created explicitly (reset() or the run script) before the server
 * logs into it, a missing/unreadable log throws in requests(), a failed write throws in
 * reset(), and the server answers 500 — never a normal reply — if it cannot log a request.
 */
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Support;

final class MockLlmServer {
	public const HOST = '127.0.0.1';
	public const PORT = 18080;
	public const LOG = '/tmp/llm-requests.log';
	public const REPLY = 'mock reply';
	/** Optional reply override for the next answers, written by setReply(), removed by reset(). */
	public const REPLY_FILE = '/tmp/llm-reply.txt';

	/** Base URL to configure as an OpenAI-compatible provider. */
	public static function baseUrl(): string {
		return 'http://' . self::HOST . ':' . self::PORT . '/v1';
	}

	/** Base URL to configure as an Ollama connection (allowInternalNetwork required). */
	public static function ollamaBaseUrl(): string {
		return 'http://' . self::HOST . ':' . self::PORT;
	}

	/** @return list<array{method: string, path: string, body: string}> */
	public static function requests(): array {
		if (!is_file(self::LOG) || !is_readable(self::LOG)) {
			throw new \RuntimeException('MockLlmServer: request log ' . self::LOG . ' is missing or unreadable');
		}
		$lines = file(self::LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			throw new \RuntimeException('MockLlmServer: could not read request log ' . self::LOG);
		}
		$out = [];
		foreach ($lines as $line) {
			$out[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
		}
		return $out;
	}

	/** Creates or empties the log and restores the default reply. */
	public static function reset(): void {
		if (file_put_contents(self::LOG, '') !== 0 || !is_file(self::LOG)) {
			throw new \RuntimeException('MockLlmServer: could not reset request log ' . self::LOG);
		}
		if (is_file(self::REPLY_FILE) && !unlink(self::REPLY_FILE)) {
			throw new \RuntimeException('MockLlmServer: could not remove reply override ' . self::REPLY_FILE);
		}
	}

	/** Every following answer carries $text instead of REPLY, until reset(). */
	public static function setReply(string $text): void {
		if (file_put_contents(self::REPLY_FILE, $text) !== strlen($text)) {
			throw new \RuntimeException('MockLlmServer: could not write reply override ' . self::REPLY_FILE);
		}
	}

	/** The text the server answers with now. */
	public static function reply(): string {
		if (!is_file(self::REPLY_FILE)) {
			return self::REPLY;
		}
		$text = file_get_contents(self::REPLY_FILE);
		return $text === false ? self::REPLY : $text;
	}

	/** Router entry point (built-in server only). */
	public static function handle(): void {
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$body = (string)file_get_contents('php://input');

		$line = json_encode(['method' => $method, 'path' => $path, 'body' => $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
		// Warnings must not reach the body: output would send headers with status 200.
		ini_set('display_errors', '0');
		http_response_code(500);   // stays 500 unless the request was logged
		header('Content-Type: application/json');
		// Never answer a request that was not logged: a missing log is not created here.
		if (!is_file(self::LOG) || file_put_contents(self::LOG, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
			echo json_encode(['error' => ['message' => 'mock: could not log request to ' . self::LOG, 'type' => 'mock_log_error']]);
			error_log('MockLlmServer: could not log request to ' . self::LOG);
			return;
		}
		http_response_code(200);

		if ($method === 'POST' && str_ends_with($path, '/api/generate')) {
			$request = json_decode($body, true);
			echo json_encode([
				'model' => is_array($request) && is_string($request['model'] ?? null) ? $request['model'] : 'mock-model',
				'response' => self::reply(),
				'done' => true,
				'prompt_eval_count' => 0,
				'eval_count' => 0,
			], JSON_UNESCAPED_SLASHES);
			return;
		}

		if ($method !== 'POST' || !str_ends_with($path, '/chat/completions')) {
			// Quotes the request's text fields, last first (the user's part comes last in every
			// request format, so it survives the short excerpt an admin sees), as some providers
			// quote the input in their errors: a test can then check that a provider's error text
			// never reaches a log (NoContentInLogsTest).
			$texts = [];
			$decoded = json_decode($body, true);
			if (is_array($decoded)) {
				array_walk_recursive($decoded, static function (mixed $v) use (&$texts): void {
					if (is_string($v)) {
						$texts[] = $v;
					}
				});
			}
			http_response_code(404);
			echo json_encode(['error' => [
				'message' => 'mock: not found: ' . implode(' | ', array_reverse($texts)),
				'type' => 'invalid_request_error',
			]]);
			return;
		}

		$request = json_decode($body, true);
		$model = is_array($request) && is_string($request['model'] ?? null) ? $request['model'] : 'mock-model';
		echo json_encode([
			'id' => 'chatcmpl-mock',
			'object' => 'chat.completion',
			'created' => time(),
			'model' => $model,
			'choices' => [[
				'index' => 0,
				'message' => ['role' => 'assistant', 'content' => self::reply()],
				'finish_reason' => 'stop',
			]],
			'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
		], JSON_UNESCAPED_SLASHES);
	}
}

if (PHP_SAPI === 'cli-server') {
	MockLlmServer::handle();
}
