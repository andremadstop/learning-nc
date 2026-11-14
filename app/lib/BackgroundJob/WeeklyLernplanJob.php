<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * WeeklyLernplanJob — Phase 27: Auto-Trigger (TRIG-03)
 *
 * NC TimedJob with a 7-day interval (counted from its last run, not tied to a weekday).
 * For active users, finds their weakest pool and generates a refreshed
 * /Learning/Zusammenfassungen/ note.
 *
 * The job is idempotent: if the note already exists,
 * NoteGeneratorService.generateSummary() overwrites it (same filename → same topic).
 *
 * Batch size is capped to avoid long-running jobs on large installations. The last user id of a
 * full batch is kept as the app value weekly_lernplan_cursor, so the next run continues after it
 * and wraps to the start once the list is through (audit #7: before, every run took the same
 * alphabetically first 200 users, and everyone after them never got a note).
 */
class WeeklyLernplanJob extends TimedJob {
    private IDBConnection $db;
    private NoteGeneratorService $noteGeneratorService;
    private LernprofilService $lernprofilService;
    private LoggerInterface $logger;
    private LlmGateway $gateway;
    private IAppConfig $appConfig;

    /** App value holding the last user id of the previous full batch ('' = start from the beginning). */
    public const CURSOR_KEY = 'weekly_lernplan_cursor';

    /** Maximum users to process per job run — prevents long-running jobs. */
    private const BATCH_SIZE = 200;

    /** Minimum error-rate to be considered "weak enough" to generate a note. */
    private const MIN_ERROR_RATE = 20.0;

    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        NoteGeneratorService $noteGeneratorService,
        LernprofilService $lernprofilService,
        LoggerInterface $logger,
        LlmGateway $gateway,
        IAppConfig $appConfig
    ) {
        parent::__construct($time);
        // Run once per week (7 days)
        $this->setInterval(7 * 24 * 3600);
        $this->db = $db;
        $this->noteGeneratorService = $noteGeneratorService;
        $this->lernprofilService = $lernprofilService;
        $this->logger = $logger;
        $this->gateway = $gateway;
        $this->appConfig = $appConfig;
    }

    protected function run($argument): void {
        $users = $this->getActiveUsers();
        $processed = 0;
        $skipped = 0;
        $skippedConsent = 0;
        $errors = 0;

        foreach ($users as $userId) {
            try {
                $outcome = $this->processUser($userId);
                if ($outcome === 'generated') {
                    $processed++;
                } elseif ($outcome === 'skipped_consent') {
                    $skippedConsent++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning('WeeklyLernplanJob: failed for user {user}: {error}', [
                    'user' => $userId,
                    'error' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }

        $this->logger->info(
            'WeeklyLernplanJob: processed={processed} skipped={skipped} skipped_consent={skippedConsent} errors={errors}',
            ['processed' => $processed, 'skipped' => $skipped, 'skippedConsent' => $skippedConsent, 'errors' => $errors, 'app' => 'learning']
        );
    }

    /**
     * Generate a summary note for the user's weakest pool, if any.
     *
     * 5.5.2: users without valid AI consent are skipped before any learning data is read — the
     * note sends the weakest topic and wrongly answered questions to the AI provider.
     * 5.7.0: the weekly_note session is begun here, before that data is read, and the note is
     * sent with it — snapshot and consent epoch are those of the moment the assembly started.
     *
     * @return string 'generated' | 'skipped' (no weak pool / no data / AI off) | 'skipped_consent'
     */
    private function processUser(string $userId): string {
        try {
            $session = $this->gateway->begin($userId, LlmPurpose::WeeklyNote);
        } catch (LlmConsentException $e) {
            return 'skipped_consent';
        } catch (LlmProviderException $e) {
            if ($e->category === 'disabled') {
                // AI switched off by the admin: nothing to send, no error of this job
                return 'skipped';
            }
            throw $e;
        }

        $weakTopics = $this->lernprofilService->getWeakestTopics($userId, null, 1);

        if (empty($weakTopics)) {
            return 'skipped';
        }

        $weakest = $weakTopics[0];

        // Skip if error rate is too low — no meaningful weak point to summarise
        if ((float)$weakest['error_rate'] < self::MIN_ERROR_RATE) {
            return 'skipped';
        }

        $poolId = (int)$weakest['pool_id'];

        // NoteGeneratorService.generateSummary() writes to /Learning/Zusammenfassungen/
        // and is idempotent (filename = topic slug → overwrites existing file).
        // 5.7.0 (spec 2.4): sent as weekly_note. Consent revoked (or the recipient changed) after the
        // check above: the gateway sent nothing — a consent skip, not an error.
        try {
            $this->noteGeneratorService->generateSummary($userId, $poolId, null, LlmPurpose::WeeklyNote, $session);
        } catch (LlmConsentException $e) {
            return 'skipped_consent';
        }

        $this->logger->debug('WeeklyLernplanJob: generated note for user {user} pool {pool}', [
            'user' => $userId,
            'pool' => $poolId,
            'app' => 'learning',
        ]);

        return 'generated';
    }

    /**
     * Return the next batch of user IDs that have at least one learning session in the last 60 days.
     *
     * This avoids regenerating notes for dormant accounts. The batch starts after the stored
     * cursor; a full batch moves the cursor to its last user id, a short one (the end of the
     * list) resets it, so the next run starts from the beginning again.
     *
     * @return list<string>
     */
    private function getActiveUsers(): array {
        $cutoff = time() - (60 * 86400);
        $cursor = $this->appConfig->getValueString('learning', self::CURSOR_KEY, '');

        $users = $this->fetchActiveUserBatch($cutoff, $cursor);
        if ($users === [] && $cursor !== '') {
            // the previous batch ended exactly at the end of the list: wrap around now
            $users = $this->fetchActiveUserBatch($cutoff, '');
        }

        $next = count($users) >= self::BATCH_SIZE ? (string)end($users) : '';
        $this->appConfig->setValueString('learning', self::CURSOR_KEY, $next);

        return $users;
    }

    /** @return list<string> */
    private function fetchActiveUserBatch(int $cutoff, string $after): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
           ->from('learning_sessions')
           ->where($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($cutoff)));
        if ($after !== '') {
            $qb->andWhere($qb->expr()->gt('user_id', $qb->createNamedParameter($after)));
        }
        $qb->orderBy('user_id', 'ASC')
           ->setMaxResults(self::BATCH_SIZE);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_values(array_map('strval', array_column($rows, 'user_id')));
    }
}
