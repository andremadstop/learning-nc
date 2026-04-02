<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\DuelService;
use OCA\Learning\Service\GameshowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class SseController extends Controller {
    private const STREAM_TIMEOUT_SECONDS = 120;
    private const POLL_INTERVAL_SECONDS = 1;
    private const KEEPALIVE_INTERVAL_SECONDS = 30;

    public function __construct(
        string $appName,
        IRequest $request,
        private DuelService $duelService,
        private GameshowService $gameshowService,
        private ?string $userId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function duelStream(string $code, ?string $lang = null): Response {
        if ($this->userId === null) {
            return new Response(Http::STATUS_UNAUTHORIZED);
        }

        try {
            $initialState = $this->duelService->getState($code, $this->userId, $lang);
        } catch (\Throwable $e) {
            return new Response(Http::STATUS_BAD_REQUEST);
        }

        return $this->createStreamResponse(
            $initialState,
            fn (): array => $this->duelService->getState($code, $this->userId, $lang),
            ['finished', 'expired', 'declined', 'canceled']
        );
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function gameshowStream(string $code, ?string $lang = null): Response {
        if ($this->userId === null) {
            return new Response(Http::STATUS_UNAUTHORIZED);
        }

        try {
            $initialState = $this->gameshowService->getState($code, $this->userId, $lang);
        } catch (\Throwable $e) {
            return new Response(Http::STATUS_BAD_REQUEST);
        }

        return $this->createStreamResponse(
            $initialState,
            fn (): array => $this->gameshowService->getState($code, $this->userId, $lang),
            ['finished', 'expired']
        );
    }

    private function createStreamResponse(array $initialState, callable $stateResolver, array $terminalStatuses): Response {
        return new class(
            $initialState,
            $stateResolver,
            $terminalStatuses,
            self::STREAM_TIMEOUT_SECONDS,
            self::POLL_INTERVAL_SECONDS,
            self::KEEPALIVE_INTERVAL_SECONDS
        ) extends Response implements ICallbackResponse {
            private ?string $lastHash = null;

            public function __construct(
                private array $initialState,
                private $stateResolver,
                private array $terminalStatuses,
                private int $streamTimeoutSeconds,
                private int $pollIntervalSeconds,
                private int $keepaliveIntervalSeconds
            ) {
                parent::__construct(Http::STATUS_OK, [
                    'Content-Type' => 'text/event-stream; charset=utf-8',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Connection' => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            public function callback(IOutput $output): void {
                @set_time_limit(0);
                ignore_user_abort(true);
                @ini_set('zlib.output_compression', '0');

                if (function_exists('apache_setenv')) {
                    @apache_setenv('no-gzip', '1');
                }

                $startedAt = time();
                $lastKeepaliveAt = $startedAt;
                $state = $this->initialState;

                while (!connection_aborted()) {
                    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        $this->emitEvent($output, 'error', ['message' => 'Failed to encode SSE payload']);
                        return;
                    }

                    $hash = hash('sha256', $json);
                    if ($hash !== $this->lastHash) {
                        $this->lastHash = $hash;
                        $this->emitRaw($output, "event: state\ndata: {$json}\n\n");
                    }

                    $status = $state['status'] ?? null;
                    if (is_string($status) && in_array($status, $this->terminalStatuses, true)) {
                        $this->emitEvent($output, 'close', []);
                        return;
                    }

                    $now = time();
                    if (($now - $lastKeepaliveAt) >= $this->keepaliveIntervalSeconds) {
                        $lastKeepaliveAt = $now;
                        $this->emitRaw($output, ": keepalive\n\n");
                    }

                    if (($now - $startedAt) >= $this->streamTimeoutSeconds) {
                        return;
                    }

                    sleep($this->pollIntervalSeconds);
                    if (connection_aborted()) {
                        return;
                    }

                    try {
                        $state = ($this->stateResolver)();
                    } catch (\Throwable $e) {
                        $this->emitEvent($output, 'error', ['message' => $e->getMessage() ?: 'Failed to load state']);
                        return;
                    }
                }
            }

            private function emitEvent(IOutput $output, string $event, array $payload): void {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    $json = '{}';
                }

                $this->emitRaw($output, "event: {$event}\ndata: {$json}\n\n");
            }

            private function emitRaw(IOutput $output, string $payload): void {
                $output->setOutput($payload);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        };
    }
}
