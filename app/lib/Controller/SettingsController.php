<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, IConfig $config, ?string $userId) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @AdminRequired
     */
    public function getAdmin(): DataResponse {
        return new DataResponse([
            'daily_challenge_enabled' => $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes'),
            'default_language' => $this->config->getAppValue('learning', 'default_language', 'de'),
            'max_import_size_mb' => (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2'),
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes'),
            'allow_course_instructor_fallback' => $this->config->getAppValue('learning', 'allow_course_instructor_fallback', 'no'),
            'exam_attempt_limit_per_day' => (int)$this->config->getAppValue('learning', 'exam_attempt_limit_per_day', '5'),
            'exam_attempt_cooldown_minutes' => (int)$this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', '10'),
        ]);
    }

    /**
     * @AdminRequired
     */
    public function saveAdmin(
        string $daily_challenge_enabled,
        string $default_language,
        int $max_import_size_mb,
        string $gamification_enabled,
        string $allow_course_instructor_fallback = 'no',
        int $exam_attempt_limit_per_day = 5,
        int $exam_attempt_cooldown_minutes = 10
    ): DataResponse {
        $this->config->setAppValue('learning', 'daily_challenge_enabled', $daily_challenge_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'default_language', in_array($default_language, ['de', 'en'], true) ? $default_language : 'de');
        $this->config->setAppValue('learning', 'max_import_size_mb', (string)max(1, min(10, $max_import_size_mb)));
        $this->config->setAppValue('learning', 'gamification_enabled', $gamification_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'allow_course_instructor_fallback', $allow_course_instructor_fallback === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'exam_attempt_limit_per_day', (string)max(1, min(50, $exam_attempt_limit_per_day)));
        $this->config->setAppValue('learning', 'exam_attempt_cooldown_minutes', (string)max(0, min(1440, $exam_attempt_cooldown_minutes)));

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * @NoAdminRequired
     */
    public function getPersonal(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        return new DataResponse([
            'daily_challenge' => $this->config->getUserValue($this->userId, 'learning', 'daily_challenge', 'yes'),
            'ui_language' => $this->config->getUserValue($this->userId, 'learning', 'ui_language', ''),
            'notifications_enabled' => $this->config->getUserValue($this->userId, 'learning', 'notifications_enabled', 'yes'),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function savePersonal(string $daily_challenge, string $ui_language, string $notifications_enabled): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        $this->config->setUserValue($this->userId, 'learning', 'daily_challenge', $daily_challenge === 'yes' ? 'yes' : 'no');
        $this->config->setUserValue($this->userId, 'learning', 'ui_language', in_array($ui_language, ['de', 'en', ''], true) ? $ui_language : '');
        $this->config->setUserValue($this->userId, 'learning', 'notifications_enabled', $notifications_enabled === 'yes' ? 'yes' : 'no');

        return new DataResponse(['status' => 'ok']);
    }
}
