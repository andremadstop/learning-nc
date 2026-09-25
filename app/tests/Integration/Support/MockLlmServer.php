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
 * answer; everything else gets 404.
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

	/** Base URL to configure as an OpenAI-compatible provider. */
	public static function baseUrl(): string {
		return 'http://' . self::HOST . ':' . self::PORT . '/v1';
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

	/** Creates or empties the log. */
	public static function reset(): void {
		if (file_put_contents(self::LOG, '') !== 0 || !is_file(self::LOG)) {
			throw new \RuntimeException('MockLlmServer: could not reset request log ' . self::LOG);
		}
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

		if ($method !== 'POST' || !str_ends_with($path, '/chat/completions')) {
			http_response_code(404);
			echo json_encode(['error' => ['message' => 'mock: not found', 'type' => 'invalid_request_error']]);
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
				'message' => ['role' => 'assistant', 'content' => self::REPLY],
				'finish_reason' => 'stop',
			]],
			'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
		], JSON_UNESCAPED_SLASHES);
	}
}

if (PHP_SAPI === 'cli-server') {
	MockLlmServer::handle();
}
