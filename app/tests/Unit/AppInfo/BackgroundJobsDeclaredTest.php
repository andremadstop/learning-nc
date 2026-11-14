<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\AppInfo;

use OCA\Learning\Repair\RemoveRetiredJobs;
use PHPUnit\Framework\TestCase;

/**
 * Audit #133: info.xml declared 1 of 7 recurring jobs; the others were only added by
 * Application::boot() on every request. Every TimedJob must now be declared, so Nextcloud
 * registers it on install and on each upgrade (Installer::installApp, AppManager::upgradeApp).
 */
class BackgroundJobsDeclaredTest extends TestCase {

    /**
     * TimedJobs that are deliberately not declared, with the reason.
     *
     * @var array<string, string>
     */
    private const NOT_DECLARED = [
        // retired, removed from the job list by RemoveRetiredJobs
        'OCA\Learning\BackgroundJob\NotificationJob' => 'retired',
        // one-off: scheduled by the LegacyAiTaskCleanup repair step only when there is work
        'OCA\Learning\BackgroundJob\LegacyAiTaskCleanupJob' => 'scheduled on demand',
    ];

    private function appRoot(): string {
        return dirname(__DIR__, 3);
    }

    public function testEveryTimedJobIsDeclaredInInfoXml(): void {
        $timed = [];
        foreach (glob($this->appRoot() . '/lib/BackgroundJob/*.php') ?: [] as $file) {
            if (preg_match('/\bclass\s+\w+\s+extends\s+TimedJob\b/', (string)file_get_contents($file))) {
                $timed[] = 'OCA\Learning\BackgroundJob\\' . basename($file, '.php');
            }
        }
        $expected = array_values(array_diff($timed, array_keys(self::NOT_DECLARED)));
        sort($expected);

        $info = simplexml_load_file($this->appRoot() . '/appinfo/info.xml');
        $this->assertNotFalse($info);
        $declared = array_map('strval', $info->xpath('/info/background-jobs/job') ?: []);
        sort($declared);

        $this->assertCount(7, $expected, 'the TimedJob scan found an unexpected number of classes');
        $this->assertSame($expected, $declared);
    }

    public function testRetiredJobsAreNotDeclared(): void {
        $info = simplexml_load_file($this->appRoot() . '/appinfo/info.xml');
        $this->assertNotFalse($info);
        $declared = array_map('strval', $info->xpath('/info/background-jobs/job') ?: []);
        foreach (RemoveRetiredJobs::RETIRED_JOBS as $job) {
            $this->assertNotContains($job, $declared);
        }
    }

    public function testBootNoLongerTouchesTheJobList(): void {
        $source = (string)file_get_contents($this->appRoot() . '/lib/AppInfo/Application.php');
        $boot = substr($source, (int)strpos($source, 'public function boot('));
        $boot = substr($boot, 0, (int)strpos($boot, "\n    }\n"));
        $this->assertStringNotContainsString('IJobList', $boot);
        $this->assertStringNotContainsString('jobList', $boot);
    }
}
