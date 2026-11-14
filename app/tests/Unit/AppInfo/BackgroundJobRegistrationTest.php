<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\AppInfo;

use OCA\Learning\Repair\RemoveRetiredJobs;
use PHPUnit\Framework\TestCase;

/**
 * Audit #206: Application::boot() ran IJobList::has() for eight jobs on every request; only
 * SendRemindersJob was declared in info.xml. Every timed job is now declared there (Nextcloud
 * registers it on install and upgrade), and boot() no longer touches the job list.
 */
class BackgroundJobRegistrationTest extends TestCase {
    /** Timed jobs that are registered on demand, not for every installation. */
    private const ON_DEMAND = [
        'OCA\Learning\BackgroundJob\LegacyAiTaskCleanupJob', // by the LegacyAiTaskCleanup repair step
    ];

    private const APP = __DIR__ . '/../../..';

    /** @return list<string> */
    private function declaredJobs(): array {
        $xml = simplexml_load_string((string)file_get_contents(self::APP . '/appinfo/info.xml'));
        $this->assertNotFalse($xml);
        $jobs = [];
        foreach ($xml->{'background-jobs'}->job ?? [] as $job) {
            $jobs[] = trim((string)$job);
        }
        return $jobs;
    }

    public function testEveryTimedJobIsDeclaredInInfoXml(): void {
        $timed = [];
        foreach (glob(self::APP . '/lib/BackgroundJob/*.php') ?: [] as $file) {
            if (preg_match('/\bextends\s+TimedJob\b/', (string)file_get_contents($file)) === 1) {
                $timed[] = 'OCA\Learning\BackgroundJob\\' . basename($file, '.php');
            }
        }
        $this->assertNotEmpty($timed);

        $missing = array_values(array_diff($timed, $this->declaredJobs(), self::ON_DEMAND));
        $this->assertSame([], $missing, 'timed jobs missing from <background-jobs>');
    }

    public function testEveryDeclaredJobExistsAndNoneIsRetired(): void {
        foreach ($this->declaredJobs() as $job) {
            $this->assertFileExists(self::APP . '/lib/BackgroundJob/' . substr($job, strrpos($job, '\\') + 1) . '.php', $job);
            $this->assertNotContains($job, RemoveRetiredJobs::RETIRED_JOBS);
        }
    }

    public function testBootDoesNotTouchTheJobList(): void {
        $source = (string)file_get_contents(self::APP . '/lib/AppInfo/Application.php');
        $boot = substr($source, (int)strpos($source, 'public function boot('));
        $boot = substr($boot, 0, (int)strpos($boot, "\n    }\n"));

        $this->assertStringNotContainsString('IJobList', $boot);
        $this->assertStringNotContainsString('Theming', $boot);
    }
}
