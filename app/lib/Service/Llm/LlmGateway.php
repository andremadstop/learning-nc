<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\Provider\GeminiProvider;
use OCA\Learning\Service\Llm\Provider\LlmProviderInterface;
use OCA\Learning\Service\Llm\Provider\OllamaProvider;
use Psr\Log\LoggerInterface;

/**
 * The one send path for model calls (spec 2.1, Task B3).
 *
 *   begin(subject, purpose)  loads the connection snapshot once, checks that AI is on and that the
 *                            subject consented to exactly this recipient, and remembers the
 *                            subject's consent epoch. Throws before any context is built.
 *   send(session, request)   1. AI still on (fresh read; switching off beats an older snapshot)
 *                            2. subject: consent epoch unchanged since begin() and consent still
 *                               valid for the snapshot recipient (fresh reads); without a
 *                               subject (admin_test) only the fixed test message is accepted
 *                            3. adapter for the snapshot provider (5.7.0: gemini, ollama)
 *                            4. budget reserved — nothing that can fail on our side comes after it
 *                            5. exactly one adapter call, never a retry
 *                            6. budget slot released; metric + audit with metadata only
 *   testConnection(…)        the admin's connection test (Task B5): a fixed message to a candidate
 *                            connection that may not be saved yet, counted on the global budget.
 *
 * 5.7.0 scope: only Gemini and Ollama are routed. The openai_compatible and anthropic adapters stay
 * in the code for 5.8.0 but are unreachable from here; any other provider in a snapshot fails as
 * `config` before budget or network are touched.
 *
 * Nothing here logs or audits prompt, answer, key or the provider's error text: only purpose,
 * recipient, fingerprint prefix, model, token counts, latency and status/category.
 */
class LlmGateway {
    public const TEST_SYSTEM_PROMPT = 'This is a connection test. Reply with the single word OK.';
    public const TEST_USER_PROMPT = 'OK?';

    public function __construct(
        private ConnectionStore $connectionStore,
        private ConsentService $consent,
        private LlmBudget $budget,
        private LlmHttpClient $http,
        private ApiKeyStore $keys,
        private LlmOutputNormalizer $normalizer,
        private AuditService $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws LlmProviderException category disabled when AI is switched off
     * @throws LlmConsentException the subject has no valid consent for the current recipient
     * @throws \InvalidArgumentException without a subject for anything but admin_test
     */
    public function begin(?string $subjectUserId, LlmPurpose $purpose): LlmSession {
        $subject = $subjectUserId === '' ? null : $subjectUserId;
        if ($subject === null && $purpose !== LlmPurpose::AdminTest) {
            throw new \InvalidArgumentException('Only admin_test may run without a subject user');
        }

        $connection = $this->connectionStore->load();
        if (!self::isActive($connection)) {
            throw new LlmProviderException('disabled');
        }

        $epoch = null;
        if ($subject !== null) {
            // epoch first: a revocation between the two reads is then seen by one of them
            $epoch = $this->consent->epoch($subject);
            if (!$this->consent->isValidFor($subject, $connection)) {
                throw new LlmConsentException();
            }
        }

        return new LlmSession($connection, $subject, $purpose, $epoch);
    }

    /**
     * @throws LlmProviderException any category; consent as LlmConsentException
     */
    public function send(LlmSession $session, LlmRequest $request): LlmResult {
        if (!$this->connectionStore->isEnabledNow()) {
            throw new LlmProviderException('disabled');
        }

        $subject = $session->subjectUserId;
        if ($subject === null && !self::isFixedTestMessage($request)) {
            // spec 2.1: without a subject only the fixed test message, never user content
            throw new \InvalidArgumentException('Without a subject user only the fixed connection test message may be sent');
        }
        if ($subject !== null) {
            if ($session->consentEpoch === null || $this->consent->epoch($subject) !== $session->consentEpoch) {
                throw new LlmConsentException();
            }
            if (!$this->consent->isValidFor($subject, $session->connection)) {
                throw new LlmConsentException();
            }
        }

        $adapter = $this->adapterFor($session->connection, $this->keys);
        $ticket = $this->budget->reserve($subject, $session->purpose);

        return $this->dispatch($adapter, $session, $request, $ticket);
    }

    /**
     * Sends a fixed test message to a candidate connection. The candidate key, when given, is
     * used for this one request and never stored; without one, the key stored for the candidate's
     * endpoint is used. Independent of whether AI is switched on: the admin tests before saving.
     *
     * @return array{ok: bool, model: ?string, latencyMs: int, category: ?string, detail: ?string}
     */
    public function testConnection(LlmConnection $candidate, ?string $candidateKey): array {
        $candidateKey = $candidateKey !== null && trim($candidateKey) !== '' ? trim($candidateKey) : null;
        $session = new LlmSession($candidate, null, LlmPurpose::AdminTest);
        $request = new LlmRequest(self::TEST_SYSTEM_PROMPT, self::TEST_USER_PROMPT, 0.0, 128);
        $start = microtime(true);

        try {
            $keys = $candidateKey !== null ? self::withCandidateKey($candidateKey) : $this->keys;
            $adapter = $this->adapterFor($candidate, $keys);
            $ticket = $this->budget->reserve(null, LlmPurpose::AdminTest);
            $result = $this->dispatch($adapter, $session, $request, $ticket);
        } catch (LlmProviderException $e) {
            $detail = $e->adminDetail !== '' ? $e->adminDetail : null;
            if ($detail !== null && $candidateKey !== null) {
                $detail = str_replace($candidateKey, '[redacted]', $detail);
            }
            return [
                'ok' => false,
                'model' => null,
                'latencyMs' => self::elapsedMs($start),
                'category' => $e->category,
                'detail' => $detail,
            ];
        }

        return [
            'ok' => true,
            'model' => $result->model,
            'latencyMs' => self::elapsedMs($start),
            'category' => null,
            'detail' => null,
        ];
    }

    /**
     * The only place an adapter is called. Releases the concurrency slot whatever happens; a
     * release that fails is logged and left to the slot TTL, so it can neither turn a good answer
     * into an error nor hide the provider's error.
     */
    private function dispatch(LlmProviderInterface $adapter, LlmSession $session, LlmRequest $request, LlmBudgetTicket $ticket): LlmResult {
        $start = microtime(true);
        try {
            $result = $adapter->complete($session->connection, $request);
            $this->record($session, 'ok', null, $result, self::elapsedMs($start));
            return $result;
        } catch (LlmProviderException $e) {
            $this->record($session, 'error', $e->category, null, self::elapsedMs($start));
            throw $e;
        } catch (\Throwable $e) {
            // adapters only throw LlmProviderException; anything else is a bug, recorded without its message
            $this->record($session, 'error', 'internal', null, self::elapsedMs($start));
            throw $e;
        } finally {
            try {
                $this->budget->release($ticket);
            } catch (\Throwable) {
                $this->logger->warning('LLM budget slot could not be released; it expires after its TTL', ['app' => 'learning']);
            }
        }
    }

    /**
     * 5.7.0 routes only the backends 5.5 already had.
     *
     * @throws LlmProviderException category config for any other provider
     */
    private function adapterFor(LlmConnection $connection, ApiKeyStore $keys): LlmProviderInterface {
        return match ($connection->provider) {
            'gemini' => new GeminiProvider($this->http, $keys, $this->normalizer),
            'ollama' => new OllamaProvider($this->http, $this->normalizer),
            default => throw new LlmProviderException('config', '', 'Provider is not available in this release'),
        };
    }

    /** Metric (log) and audit event, metadata only. */
    private function record(LlmSession $session, string $status, ?string $category, ?LlmResult $result, int $latencyMs): void {
        $c = $session->connection;
        $model = $result !== null ? $result->model : $c->model;
        $tokensIn = $result !== null ? $result->tokensIn : 0;
        $tokensOut = $result !== null ? $result->tokensOut : 0;

        $metric = [
            'app' => 'learning',
            'metric' => 'llm_api_call',
            'purpose' => $session->purpose->value,
            'provider' => $c->provider,
            'model' => $model,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
        $audit = [
            'purpose' => $session->purpose->value,
            'recipient' => $session->recipient()->name,
            'recipient_fp' => substr($c->recipientFingerprint(), 0, 12),
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'status' => $status,
        ];
        if ($category !== null) {
            $metric['category'] = $category;
            $audit['category'] = $category;
        }

        try {
            $this->logger->info('llm_api_call', $metric);
        } catch (\Throwable) {
            // a broken log sink must not change the outcome of the call
        }
        // AuditService::logEvent never throws
        $this->audit->logEvent('ai_call', $session->subjectUserId ?? 'system', $audit);
    }

    /** A key store that answers with the candidate key and cannot write anything. */
    private static function withCandidateKey(string $candidateKey): ApiKeyStore {
        return new class($candidateKey) extends ApiKeyStore {
            public function __construct(private string $candidateKey) {
            }

            public function get(LlmConnection $c): ?string {
                return $this->candidateKey;
            }

            public function apply(LlmConnection $c, array $keyAction): void {
                throw new \LogicException('A connection test never stores a key');
            }

            public function migrateLegacyGeminiKey(): string {
                throw new \LogicException('A connection test never migrates a key');
            }
        };
    }

    private static function isFixedTestMessage(LlmRequest $r): bool {
        return $r->systemPrompt === self::TEST_SYSTEM_PROMPT && $r->userPrompt === self::TEST_USER_PROMPT;
    }

    private static function isActive(LlmConnection $c): bool {
        return $c->enabled && $c->provider !== 'disabled';
    }

    private static function elapsedMs(float $start): int {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
