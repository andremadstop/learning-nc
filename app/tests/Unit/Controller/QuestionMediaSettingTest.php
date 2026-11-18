<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #66: "Automatisch abspielen" and "Töne" of the question player are user settings on the
 * server (they hold on every device). PUT /api/settings/personal/question-media stores the fields
 * it is sent, GET /api/settings/personal reports both: autoplay off and sounds 'auto' (follows
 * autoplay) by default.
 */
#[AllowMockObjectsWithoutExpectations]
final class QuestionMediaSettingTest extends TestCase {
    /** @var array<string, string> */
    private array $stored = [];

    private function controller(?string $userId = 'lena'): SettingsController {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, $default = '') => $this->stored[$key] ?? $default
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, $value): void {
                $this->assertSame('lena', $uid);
                $this->assertSame('learning', $app);
                $this->stored[$key] = (string)$value;
            }
        );
        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            $this->createMock(\OCA\Learning\Service\Llm\ConnectionStore::class),
            $this->createMock(\OCA\Learning\Service\Llm\ApiKeyStore::class),
            $this->createMock(\OCA\Learning\Service\RoleService::class),
            $userId
        );
    }

    public function testDefaultsAreAutoplayOffAndSoundsFollowingIt(): void {
        $data = $this->controller()->getPersonal()->getData();
        $this->assertSame('no', $data['question_media_autoplay']);
        $this->assertSame('auto', $data['answer_sounds']);
    }

    public function testAutoplayAloneIsStoredAndSoundsStayUntouched(): void {
        $response = $this->controller()->saveQuestionMedia(true);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['question_media_autoplay' => 'yes', 'answer_sounds' => 'auto'], $response->getData());
        $this->assertSame(['question_media_autoplay' => 'yes'], $this->stored);
        $this->assertSame('yes', $this->controller()->getPersonal()->getData()['question_media_autoplay']);
    }

    public function testSoundsAloneIsStoredAsAnExplicitChoice(): void {
        $this->stored['question_media_autoplay'] = 'yes';

        $data = $this->controller()->saveQuestionMedia(null, 'no')->getData();

        $this->assertSame(['question_media_autoplay' => 'yes', 'answer_sounds' => 'no'], $data);
        $this->controller()->saveQuestionMedia(null, 'auto');
        $this->assertSame('auto', $this->stored['answer_sounds']);
    }

    public function testAnUnknownSoundsValueIsRefusedAndNothingIsStored(): void {
        $response = $this->controller()->saveQuestionMedia(true, 'loud');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame([], $this->stored);
    }

    public function testWithoutAUserNothingIsStored(): void {
        $this->assertSame(401, $this->controller(null)->saveQuestionMedia(true, 'yes')->getStatus());
        $this->assertSame([], $this->stored);
    }
}
