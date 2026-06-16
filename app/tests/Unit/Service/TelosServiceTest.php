<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\EncryptionService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TelosServiceTest extends TestCase {
    private TelosService $service;
    /** @var UserTelosMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $mapper;
    /** @var EncryptionService&\PHPUnit\Framework\MockObject\MockObject */
    private $encryption;

    protected function setUp(): void {
        $this->mapper = $this->createMock(UserTelosMapper::class);
        $gemini = $this->createMock(GeminiService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $db = new FakeDbConnection();
        $userManager = $this->createMock(\OCP\IUserManager::class);
        $this->encryption = $this->createMock(EncryptionService::class);

        $this->service = new TelosService(
            $this->mapper,
            $gemini,
            $logger,
            $db,
            $userManager,
            $this->encryption
        );
    }

    public function testSaveTelosEncryptsBioAndTelosJson(): void {
        // No existing entity — new insert
        $this->mapper->method('findByUserIdOrNull')->willReturn(null);

        $insertedEntity = new UserTelos();
        $insertedEntity->setId(1);
        $this->mapper->method('insert')->willReturnCallback(function (UserTelos $e) use ($insertedEntity) {
            // Copy fields to our return entity
            $insertedEntity->setUserId($e->getUserId());
            $insertedEntity->setTelosJson($e->getTelosJson());
            $insertedEntity->setBio($e->getBio());
            $insertedEntity->setOnboardingCompleted($e->getOnboardingCompleted());
            return $insertedEntity;
        });

        // Track encrypt calls
        $encryptCalls = [];
        $this->encryption->method('encrypt')
            ->willReturnCallback(function (?string $val) use (&$encryptCalls) {
                $encryptCalls[] = $val;
                return $val !== null ? 'enc_' . $val : null;
            });

        // decrypt for entityToArray return
        $this->encryption->method('decrypt')
            ->willReturnCallback(function (?string $val) {
                if ($val === null) {
                    return null;
                }
                return str_starts_with($val, 'enc_') ? substr($val, 4) : $val;
            });

        $telosData = ['role' => 'student', 'target_cert' => 'CompTIA N+'];
        $this->service->saveTelos('user1', $telosData, ['bio' => 'My bio text']);

        // Verify encrypt was called with the telos JSON string
        $telosJson = json_encode($telosData, JSON_UNESCAPED_UNICODE);
        $this->assertContains($telosJson, $encryptCalls, 'encrypt() must be called with telos JSON string');

        // Verify encrypt was called with bio
        $this->assertContains('My bio text', $encryptCalls, 'encrypt() must be called with bio');
    }

    public function testSaveTelosDoesNotEncryptHelpOfferOrHelpWanted(): void {
        $this->mapper->method('findByUserIdOrNull')->willReturn(null);

        $insertedEntity = new UserTelos();
        $insertedEntity->setId(1);
        $this->mapper->method('insert')->willReturnCallback(function (UserTelos $e) use ($insertedEntity) {
            $insertedEntity->setUserId($e->getUserId());
            $insertedEntity->setTelosJson($e->getTelosJson());
            $insertedEntity->setHelpOffer($e->getHelpOffer());
            $insertedEntity->setHelpWanted($e->getHelpWanted());
            $insertedEntity->setOnboardingCompleted($e->getOnboardingCompleted());
            return $insertedEntity;
        });

        $encryptCalls = [];
        $this->encryption->method('encrypt')
            ->willReturnCallback(function (?string $val) use (&$encryptCalls) {
                $encryptCalls[] = $val;
                return $val !== null ? 'enc_' . $val : null;
            });
        $this->encryption->method('decrypt')
            ->willReturnCallback(fn(?string $v) => $v !== null && str_starts_with($v, 'enc_') ? substr($v, 4) : $v);

        $helpOffer = json_encode(['networking', 'linux'], JSON_UNESCAPED_UNICODE);
        $helpWanted = json_encode(['security'], JSON_UNESCAPED_UNICODE);

        $this->service->saveTelos('user1', ['role' => 'test'], [
            'help_offer' => ['networking', 'linux'],
            'help_wanted' => ['security'],
        ]);

        // help_offer and help_wanted JSON strings should NOT appear in encrypt calls
        $this->assertNotContains($helpOffer, $encryptCalls, 'encrypt() must NOT be called with help_offer');
        $this->assertNotContains($helpWanted, $encryptCalls, 'encrypt() must NOT be called with help_wanted');
    }

    public function testGetTelosDecryptsBioAndTelosJson(): void {
        $entity = new UserTelos();
        $entity->setId(1);
        $entity->setUserId('user1');
        $entity->setTelosJson('encrypted_telos');
        $entity->setBio('encrypted_bio');
        $entity->setVisibility('course');
        $entity->setOnboardingCompleted(true);
        $entity->setCreatedAt(1000);
        $entity->setUpdatedAt(2000);

        $this->mapper->method('findByUserIdOrNull')->with('user1')->willReturn($entity);

        $decryptCalls = [];
        $this->encryption->method('decrypt')
            ->willReturnCallback(function (?string $val) use (&$decryptCalls) {
                $decryptCalls[] = $val;
                if ($val === 'encrypted_bio') {
                    return 'My bio';
                }
                if ($val === 'encrypted_telos') {
                    return json_encode(['role' => 'developer'], JSON_UNESCAPED_UNICODE);
                }
                return $val;
            });

        $result = $this->service->getTelos('user1');

        $this->assertNotNull($result);
        $this->assertSame('My bio', $result['bio']);
        $this->assertSame(['role' => 'developer'], $result['telos']);

        // Verify decrypt was called for bio and telos_json
        $this->assertContains('encrypted_bio', $decryptCalls, 'decrypt() must be called with encrypted bio');
        $this->assertContains('encrypted_telos', $decryptCalls, 'decrypt() must be called with encrypted telos_json');
    }
}
