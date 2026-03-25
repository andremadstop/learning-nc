<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * UserTelos — Personal learning profile (Mini-Telos).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getTelosJson()
 * @method void setTelosJson(?string $json)
 * @method string|null getBio()
 * @method void setBio(?string $bio)
 * @method string|null getHelpOffer()
 * @method void setHelpOffer(?string $json)
 * @method string|null getHelpWanted()
 * @method void setHelpWanted(?string $json)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class UserTelos extends Entity {
    protected string $userId = '';
    protected ?string $telosJson = null;
    protected ?string $bio = null;
    protected ?string $helpOffer = null;
    protected ?string $helpWanted = null;
    protected string $visibility = 'private';
    protected int $onboardingCompleted = 0;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('telosJson', 'string');
        $this->addType('bio', 'string');
        $this->addType('helpOffer', 'string');
        $this->addType('helpWanted', 'string');
        $this->addType('visibility', 'string');
        $this->addType('onboardingCompleted', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function getOnboardingCompleted(): bool {
        return (int)$this->onboardingCompleted === 1;
    }

    public function setOnboardingCompleted(bool $completed): void {
        $this->onboardingCompleted = $completed ? 1 : 0;
        $this->markFieldUpdated('onboardingCompleted');
    }

    /**
     * Decode telos_json into array.
     * @return array<string, mixed>
     */
    public function getTelosData(): array {
        $json = $this->getTelosJson();
        if ($json === null || $json === '') {
            return [];
        }
        return json_decode($json, true) ?? [];
    }

    /**
     * Encode array into telos_json.
     * @param array<string, mixed> $data
     */
    public function setTelosData(array $data): void {
        $this->setTelosJson(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return string[]
     */
    public function getHelpOfferList(): array {
        $json = $this->getHelpOffer();
        if ($json === null || $json === '') {
            return [];
        }
        return json_decode($json, true) ?? [];
    }

    /**
     * @return string[]
     */
    public function getHelpWantedList(): array {
        $json = $this->getHelpWanted();
        if ($json === null || $json === '') {
            return [];
        }
        return json_decode($json, true) ?? [];
    }
}
