<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The one budget for model calls (Task B2, spec 5.1), reserved by the gateway before every send.
 *
 * Buckets (learning_llm_budget):
 *   g:d             instance per UTC day, appconfig ai_global_daily_limit (default 5000)
 *   u:<uid>:d       per user and UTC day, 100
 *   u:<uid>:m       per user and minute, 10
 *   u:<uid>:inflight  at most 3 concurrent calls per user, one row per slot; an orphaned slot
 *                     expires 120 s after its own acquisition
 * admin_test counts only on g:d. A reservation that fails part-way gives back what it took, so a
 * rejected call costs nothing. release() frees the concurrency slot; the counts stay used.
 *
 * Replaces the memcache counters in GeminiService::checkRateLimit (removed in B4), including
 * their global per-minute limit: the instance limit is now per day.
 */
class LlmBudget {
    public const USER_PER_MINUTE = 10;
    public const USER_PER_DAY = 100;
    public const USER_INFLIGHT = 3;
    public const INFLIGHT_TTL = 120;
    public const GLOBAL_PER_DAY_DEFAULT = 5000;
    public const GLOBAL_LIMIT_KEY = 'ai_global_daily_limit';
    public const GLOBAL_BUCKET = 'g:d';

    /** Longest uid that is stored readable: 'u:' + uid + ':inflight' must fit STRING(64). */
    private const MAX_PLAIN_UID = 53;

    public function __construct(
        private LlmBudgetStore $store,
        private IAppConfig $appConfig,
        private ITimeFactory $time,
    ) {
    }

    /**
     * Bucket name for a user. Long uids are replaced by a hash prefixed with '#', which never
     * occurs in a Nextcloud uid, so a hashed name cannot collide with a readable one.
     */
    public static function userBucket(string $uid, string $suffix): string {
        $id = strlen($uid) <= self::MAX_PLAIN_UID ? $uid : '#' . substr(hash('sha256', $uid), 0, 48);
        return 'u:' . $id . ':' . $suffix;
    }

    /**
     * @throws LlmProviderException category rate_limit when any bucket is exhausted
     * @throws \InvalidArgumentException without a user for anything but admin_test
     */
    public function reserve(?string $uid, LlmPurpose $purpose): LlmBudgetTicket {
        $isAdminTest = $purpose === LlmPurpose::AdminTest;
        if (!$isAdminTest && ($uid === null || $uid === '')) {
            throw new \InvalidArgumentException('Only admin_test may run without a subject user');
        }

        $now = $this->time->getTime();
        $day = intdiv($now, 86400) * 86400;
        $minute = intdiv($now, 60) * 60;

        /** @var list<array{0:string,1:int,2:int,3:int}> $counts bucket, window, window length, limit */
        $counts = [[self::GLOBAL_BUCKET, $day, 86400, $this->globalLimit()]];
        $slot = null;
        if (!$isAdminTest) {
            /** @var string $uid */
            $counts[] = [self::userBucket($uid, 'd'), $day, 86400, self::USER_PER_DAY];
            $counts[] = [self::userBucket($uid, 'm'), $minute, 60, self::USER_PER_MINUTE];
            $slot = self::userBucket($uid, 'inflight');
        }

        /** @var list<array{0:string,1:int}> $taken */
        $taken = [];
        $held = null;
        try {
            foreach ($counts as [$bucket, $window, $length, $limit]) {
                if (!$this->store->tryIncrementCount($bucket, $window, $length, $limit, $now)) {
                    throw new LlmProviderException('rate_limit');
                }
                $taken[] = [$bucket, $window];
            }
            if ($slot !== null) {
                $held = $this->store->tryAcquireSlot($slot, self::USER_INFLIGHT, $now, self::INFLIGHT_TTL);
                if ($held === null) {
                    throw new LlmProviderException('rate_limit');
                }
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($taken) as [$bucket, $window]) {
                $this->store->decrementCount($bucket, $window);
            }
            throw $e;
        }

        return new LlmBudgetTicket($isAdminTest ? null : $uid, $purpose, $slot, $held['index'] ?? null, $held['token'] ?? null);
    }

    /**
     * Frees the concurrency slot. Idempotent per ticket; the ticket counts as released only once
     * the store has given the slot back, so a release that failed can be retried.
     */
    public function release(LlmBudgetTicket $ticket): void {
        if ($ticket->isReleased()) {
            return;
        }
        if ($ticket->slotBucket !== null && $ticket->slotIndex !== null && $ticket->slotToken !== null) {
            $this->store->releaseSlot($ticket->slotBucket, $ticket->slotIndex, $ticket->slotToken);
        }
        $ticket->markReleased();
    }

    private function globalLimit(): int {
        // stored as a string like the other ai_* keys; anything but a positive integer → default
        $raw = trim($this->appConfig->getValueString('learning', self::GLOBAL_LIMIT_KEY, ''));
        $limit = preg_match('/^[0-9]{1,9}$/', $raw) === 1 ? (int)$raw : 0;
        return $limit >= 1 ? $limit : self::GLOBAL_PER_DAY_DEFAULT;
    }
}
