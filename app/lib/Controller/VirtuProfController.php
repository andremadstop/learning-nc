<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class VirtuProfController extends Controller {
    private IConfig $config;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, IConfig $config, ?string $userId) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getState(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        );

        return new DataResponse([
            'dismissed' => is_array($dismissed) ? array_values($dismissed) : [],
            'enabled' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'yes') === 'yes',
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function dismiss(string $triggerId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $triggerId = trim($triggerId);
        if ($triggerId === '') {
            return new DataResponse(['error' => 'Trigger ID is required'], Http::STATUS_BAD_REQUEST);
        }

        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        );

        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        if (!in_array($triggerId, $dismissed, true)) {
            $dismissed[] = $triggerId;
            $this->config->setUserValue($this->userId, 'learning', 'virtuprof_dismissed', json_encode(array_values($dismissed)));
        }

        return new DataResponse(['ok' => true, 'dismissed' => array_values($dismissed)]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function setEnabled(bool $enabled): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_enabled', $enabled ? 'yes' : 'no');

        return new DataResponse(['ok' => true, 'enabled' => $enabled]);
    }
}
