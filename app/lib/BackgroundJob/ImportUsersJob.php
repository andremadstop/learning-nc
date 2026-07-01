<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Background job for bulk NC user import (dispatched when CSV > 50 rows).
 *
 * SECURITY: Passwords are generated INSIDE this job — never in job args.
 * Job args persist in the oc_jobs DB table; storing plaintext passwords there
 * would expose them to any DB read. The 'csv_data' field contains ONLY:
 * username, display_name, email.
 *
 * Arg schema: ['csv_data' => string, 'group' => string|null, 'admin_uid' => string]
 * No 'password' key — verified by ImportUsersJobTest::testPasswordsNotInJobArgs.
 */
class ImportUsersJob extends QueuedJob {
    public function __construct(
        private readonly IUserManager  $userManager,
        private readonly IGroupManager $groupManager,
        private readonly AuditService  $auditService,
        ITimeFactory $timeFactory,
    ) {
        parent::__construct($timeFactory);
    }

    /**
     * @param array<string, mixed> $argument
     */
    protected function run(mixed $argument): void {
        $csvData  = (string)($argument['csv_data'] ?? '');
        $groupVal = $argument['group'] ?? null;
        $group    = is_string($groupVal) && $groupVal !== '' ? $groupVal : null;
        $adminUid = (string)($argument['admin_uid'] ?? 'occ');

        // Split into non-empty lines; first line is the header
        $allLines = array_values(array_filter(
            explode("\n", $csvData),
            fn(string $l): bool => trim($l) !== ''
        ));

        // Skip header row
        $dataRows = array_slice($allLines, 1);

        foreach ($dataRows as $line) {
            $cols = str_getcsv($line);
            $uid  = trim($cols[0] ?? '');
            $name = trim($cols[1] ?? $uid);
            if ($uid === '') {
                continue;
            }

            // Password generated here — never from job args (security: args persist in oc_jobs)
            $password = bin2hex(random_bytes(12));
            $user     = $this->userManager->createUser($uid, $password);
            unset($password); // clear immediately after createUser hashes it

            if (!($user instanceof IUser)) {
                continue;
            }

            if ($name !== '') {
                $user->setDisplayName($name);
            }

            if ($group !== null) {
                $grp = $this->groupManager->get($group);
                $grp?->addUser($user);
            }

            $this->auditService->logEvent('user.imported', $adminUid, ['username' => $uid]);
        }
    }
}
