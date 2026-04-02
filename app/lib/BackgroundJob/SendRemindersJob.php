<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class SendRemindersJob extends TimedJob {
    private ReminderService $reminderService;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        ReminderService $reminderService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->reminderService = $reminderService;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $hour = (int)(new \DateTimeImmutable('now'))->format('G');
        $dueSent = 0;
        $streakSent = 0;
        $examSent = 0;

        try {
            $examSent = $this->reminderService->sendExamReminders();
        } catch (\Throwable $e) {
            $this->logger->warning('SendRemindersJob: exam reminders failed: {error}', [
                'error' => $e->getMessage(),
                'app' => 'learning',
            ]);
        }

        if ($hour === 9) {
            try {
                $dueSent = $this->reminderService->sendDueCardReminders();
            } catch (\Throwable $e) {
                $this->logger->warning('SendRemindersJob: due reminders failed: {error}', [
                    'error' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }

        if ($hour === 20) {
            try {
                $streakSent = $this->reminderService->sendStreakWarnings();
            } catch (\Throwable $e) {
                $this->logger->warning('SendRemindersJob: streak reminders failed: {error}', [
                    'error' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }

        $this->logger->info('SendRemindersJob: hour={hour} due={due} streak={streak} exam={exam}', [
            'hour' => $hour,
            'due' => $dueSent,
            'streak' => $streakSent,
            'exam' => $examSent,
            'app' => 'learning',
        ]);
    }
}
