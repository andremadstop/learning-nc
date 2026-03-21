<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\LernprofilService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * WeeklyLernplanJob — Phase 27: Auto-Trigger (TRIG-03)
 *
 * NC BackgroundJob that runs weekly (Sunday, ~00:00).
 * For every active user, finds their weakest pool and generates a
 * refreshed /Learning/Zusammenfassungen/ note so the user finds
 * fresh material at the start of the week.
 *
 * TRIG-03: Jeden Sonntag wird /Learning/Lernplan.md automatisch
 *          aktualisiert — der User findet Montagmorgens einen frischen Plan.
 *
 * The job is idempotent: if the note already exists for this week,
 * NoteGeneratorService.generateSummary() overwrites it (same filename → same topic).
 *
 * Batch size is capped to avoid long-running jobs on large installations.
 */
class WeeklyLernplanJob extends TimedJob {
    private IDBConnection $db;
    private NoteGeneratorService $noteGeneratorService;
    private LernprofilService $lernprofilService;
    private LoggerInterface $logger;

    /** Maximum users to process per job run — prevents long-running jobs. */
    private const BATCH_SIZE = 200;

    /** Minimum error-rate to be considered "weak enough" to generate a note. */
    private const MIN_ERROR_RATE = 20.0;

    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        NoteGeneratorService $noteGeneratorService,
        LernprofilService $lernprofilService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        // Run once per week (7 days)
        $this->setInterval(7 * 24 * 3600);
        $this->db = $db;
        $this->noteGeneratorService = $noteGeneratorService;
        $this->lernprofilService = $lernprofilService;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $users = $this->getActiveUsers();
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($users as $userId) {
            try {
                $generated = $this->processUser($userId);
                if ($generated) {
                    $processed++;
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
            'WeeklyLernplanJob: processed={processed} skipped={skipped} errors={errors}',
            ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors, 'app' => 'learning']
        );
    }

    /**
     * Generate a summary note for the user's weakest pool, if any.
     *
     * @return bool True if a note was generated, false if skipped (no weak pools / no data).
     */
    private function processUser(string $userId): bool {
        $weakTopics = $this->lernprofilService->getWeakestTopics($userId, null, 1);

        if (empty($weakTopics)) {
            return false;
        }

        $weakest = $weakTopics[0];

        // Skip if error rate is too low — no meaningful weak point to summarise
        if ((float)$weakest['error_rate'] < self::MIN_ERROR_RATE) {
            return false;
        }

        $poolId = (int)$weakest['pool_id'];

        // NoteGeneratorService.generateSummary() writes to /Learning/Zusammenfassungen/
        // and is idempotent (filename = topic slug → overwrites existing file).
        $this->noteGeneratorService->generateSummary($userId, $poolId, null);

        $this->logger->debug('WeeklyLernplanJob: generated note for user {user} pool {pool}', [
            'user' => $userId,
            'pool' => $poolId,
            'app' => 'learning',
        ]);

        return true;
    }

    /**
     * Return a list of user IDs that have at least one learning session in the last 60 days.
     *
     * This avoids regenerating notes for dormant accounts.
     */
    private function getActiveUsers(): array {
        $cutoff = time() - (60 * 86400);

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
           ->from('learning_sessions')
           ->where($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($cutoff)))
           ->orderBy('user_id', 'ASC')
           ->setMaxResults(self::BATCH_SIZE);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_column($rows, 'user_id');
    }
}
