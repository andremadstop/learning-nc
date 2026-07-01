<?php
declare(strict_types=1);
namespace OCA\Learning\Command;

use OCA\Learning\BackgroundJob\ImportUsersJob;
use OCA\Learning\Service\AuditService;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to bulk-import Nextcloud users from a CSV file.
 *
 * Usage:
 *   php occ learning:import-users /path/to/users.csv
 *   php occ learning:import-users /path/to/users.csv --group=awo-schulung
 *
 * CSV format: username, display_name, email (header row required)
 *
 * Security model:
 *   - Synchronous path (≤50 rows): random password generated per user with
 *     bin2hex(random_bytes(12)), printed ONCE to stdout, then unset. NC stores
 *     only the bcrypt hash. Plaintext never written to disk or DB.
 *   - Async path (>50 rows): dispatches ImportUsersJob. Passwords are generated
 *     INSIDE the job — never serialised to oc_jobs table (which persists in DB).
 */
class ImportUsersCommand extends Command {
    private const SYNC_THRESHOLD = 50;

    public function __construct(
        private readonly IUserManager  $userManager,
        private readonly IGroupManager $groupManager,
        private readonly AuditService  $auditService,
        private readonly IJobList      $jobList,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('learning:import-users')
            ->setDescription('Import NC users from a CSV file and optionally assign to a NC group')
            ->addArgument(
                'csv_file',
                InputArgument::REQUIRED,
                'Path to CSV file (columns: username, display_name, email)'
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_OPTIONAL,
                'NC group GID to add all imported users to'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $csvFile  = (string)$input->getArgument('csv_file');
        $groupOpt = $input->getOption('group');
        $group    = is_string($groupOpt) ? $groupOpt : null;
        $adminUid = 'occ'; // occ runs as system — no NC session

        if (!is_file($csvFile)) {
            $output->writeln("<error>CSV file not found: $csvFile</error>");
            return self::FAILURE;
        }

        $csvContent = file_get_contents($csvFile);
        if ($csvContent === false) {
            $output->writeln('<error>Cannot read CSV file</error>');
            return self::FAILURE;
        }

        // Split into non-empty lines; first line is the header
        $allLines = array_values(array_filter(
            explode("\n", $csvContent),
            fn(string $l): bool => trim($l) !== ''
        ));

        $dataCount = count($allLines) - 1; // subtract header row

        if ($dataCount > self::SYNC_THRESHOLD) {
            // Async path: dispatch QueuedJob — passwords generated INSIDE the job
            $this->jobList->add(ImportUsersJob::class, [
                'csv_data'  => $csvContent,
                'group'     => $group,
                'admin_uid' => $adminUid,
            ]);
            $output->writeln("<info>Dispatched bulk import of $dataCount users as background job.</info>");
            $output->writeln('<comment>Users will be created with random passwords. Use NC admin panel to send password-reset emails.</comment>');
            return self::SUCCESS;
        }

        // Sync path: create users immediately, print each password once
        $dataRows = array_slice($allLines, 1);
        foreach ($dataRows as $line) {
            $cols = str_getcsv($line);
            $uid  = trim($cols[0] ?? '');
            $name = trim($cols[1] ?? $uid);
            if ($uid === '') {
                continue;
            }

            // Generate random password — 24 hex chars, printed once then discarded
            $password = bin2hex(random_bytes(12));
            $user     = $this->userManager->createUser($uid, $password);
            if (!($user instanceof IUser)) {
                unset($password);
                $output->writeln("<error>Failed to create user: $uid</error>");
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
            $output->writeln("<info>Created user: $uid  Password: $password</info>");
            unset($password); // clear plaintext from scope
        }

        return self::SUCCESS;
    }
}
