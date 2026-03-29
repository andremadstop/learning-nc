<?php
declare(strict_types=1);

namespace OCP\DB\QueryBuilder {
    if (!interface_exists(IQueryBuilder::class)) {
        interface IQueryBuilder {
            public const PARAM_INT = 1;
            public const PARAM_INT_ARRAY = 101;
        }
    }
}

namespace OCP\Security {
    if (!interface_exists(ICrypto::class)) {
        interface ICrypto {
            public function encrypt(string $plaintext, string $secret = ''): string;
            public function decrypt(string $encryptedContent, string $secret = ''): string;
        }
    }
}

namespace OCP {
    if (!interface_exists(IDBConnection::class)) {
        interface IDBConnection {
            public function getQueryBuilder();

            public function executeQuery(string $sql, array $params = []);
        }
    }

    if (!interface_exists(ICacheFactory::class)) {
        interface ICacheFactory {
            public function createDistributed(string $appName);
        }
    }

    if (!interface_exists(IConfig::class)) {
        interface IConfig {
            public function getUserValue(string $userId, string $appName, string $key, string $default = '');

            public function getAppValue(string $appName, string $key, string $default = '');
        }
    }

    if (!interface_exists(IGroupManager::class)) {
        interface IGroupManager {
        }
    }

    if (!interface_exists(IUserManager::class)) {
        interface IUserManager {
        }
    }
}

namespace OCP\AppFramework\Db {
    if (!class_exists(Entity::class)) {
        class Entity implements \JsonSerializable {
            public int $id = 0;
            protected array $updatedFields = [];

            public function getId(): int { return $this->id; }
            public function setId(int $id): void { $this->id = $id; }
            public function jsonSerialize(): array { return ['id' => $this->id]; }

            public function __call(string $name, array $args) {
                // Support get/set magic for NC entities
                if (str_starts_with($name, 'get')) {
                    $field = lcfirst(substr($name, 3));
                    return $this->$field ?? null;
                }
                if (str_starts_with($name, 'set') && count($args) === 1) {
                    $field = lcfirst(substr($name, 3));
                    $this->$field = $args[0];
                    $this->updatedFields[$field] = true;
                }
                return null;
            }

            public static function fromRow(array $row): static {
                $entity = new static();
                foreach ($row as $key => $value) {
                    $prop = lcfirst(implode('', array_map('ucfirst', explode('_', $key))));
                    $entity->$prop = $value;
                }
                $entity->id = (int)($row['id'] ?? 0);
                return $entity;
            }
        }
    }

    if (!class_exists(QBMapper::class)) {
        abstract class QBMapper {
            protected $db;
            public function __construct($db) { $this->db = $db; }
        }
    }

    if (!class_exists(DoesNotExistException::class)) {
        class DoesNotExistException extends \Exception {
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface {
            public function emergency($message, array $context = []);

            public function alert($message, array $context = []);

            public function critical($message, array $context = []);

            public function error($message, array $context = []);

            public function warning($message, array $context = []);

            public function notice($message, array $context = []);

            public function info($message, array $context = []);

            public function debug($message, array $context = []);

            public function log($level, $message, array $context = []);
        }
    }
}

namespace OCA\Learning\Db {
    if (!class_exists(Analytics::class)) {
        class Analytics {
        }
    }

    if (!class_exists(AnalyticsMapper::class)) {
        class AnalyticsMapper {
            public function record(string $userId, ?int $poolId, string $metricType, string $metricValueJson): Analytics {
                return new Analytics();
            }

            public function findByUser(string $userId): array {
                return [];
            }

            public function findByUserAndPool(string $userId, int $poolId): array {
                return [];
            }

            public function findByType(string $metricType, ?string $userId = null): array {
                return [];
            }
        }
    }

    if (!class_exists(PoolMapper::class)) {
        class PoolMapper {
            public function find(int $poolId, string $userId) {
                return null;
            }
        }
    }

    if (!class_exists(PoolShareMapper::class)) {
        class PoolShareMapper {
            public function findByPoolAndUser(int $poolId, string $userId) {
                return null;
            }
        }
    }

    if (!class_exists(QuestionMapper::class)) {
        class QuestionMapper {
            public function findByPoolId(int $poolId): array {
                return [];
            }

            public function findByIds(array $ids): array {
                return [];
            }
        }
    }

    if (!class_exists(AnswerMapper::class)) {
        class AnswerMapper {
            public function findByQuestion(int $questionId): array {
                return [];
            }
        }
    }

    if (!class_exists(CourseMapper::class)) {
        class CourseMapper {
            public function findById(int $id): Course {
                return new Course();
            }

            public function findByInstructor(string $userId): array {
                return [];
            }
        }
    }

    if (!class_exists(CoursePoolMapper::class)) {
        class CoursePoolMapper {
            public function findByCourseAndPool(int $courseId, int $poolId): CoursePool {
                return new CoursePool();
            }

            public function findByCourse(int $courseId): array {
                return [];
            }
        }
    }

    if (!class_exists(CourseMemberMapper::class)) {
        class CourseMemberMapper {
            public function findByCourseAndUser(int $courseId, string $userId): CourseMember {
                return new CourseMember();
            }

            public function findByUser(string $userId): array {
                return [];
            }
        }
    }

    if (!class_exists(CurriculumScopeMapper::class)) {
        class CurriculumScopeMapper {
        }
    }

    if (!class_exists(Course::class)) {
        class Course implements \JsonSerializable {
            private int $id = 0;
            private string $instructorId = '';

            public function getId(): int {
                return $this->id;
            }

            public function setId(int $id): void {
                $this->id = $id;
            }

            public function getInstructorId(): string {
                return $this->instructorId;
            }

            public function setInstructorId(string $instructorId): void {
                $this->instructorId = $instructorId;
            }

            public function jsonSerialize(): array {
                return [
                    'id' => $this->id,
                    'instructor_id' => $this->instructorId,
                ];
            }
        }
    }

    if (!class_exists(CourseMember::class)) {
        class CourseMember implements \JsonSerializable {
            private int $courseId = 0;
            private string $userId = '';
            private string $role = 'student';

            public function getCourseId(): int {
                return $this->courseId;
            }

            public function setCourseId(int $courseId): void {
                $this->courseId = $courseId;
            }

            public function getUserId(): string {
                return $this->userId;
            }

            public function setUserId(string $userId): void {
                $this->userId = $userId;
            }

            public function getRole(): string {
                return $this->role;
            }

            public function setRole(string $role): void {
                $this->role = $role;
            }

            public function jsonSerialize(): array {
                return [
                    'course_id' => $this->courseId,
                    'user_id' => $this->userId,
                    'role' => $this->role,
                ];
            }
        }
    }

    if (!class_exists(CoursePool::class)) {
        class CoursePool {
            private int $courseId = 0;
            private int $poolId = 0;
            private bool $required = false;
            private bool $requiredEnforced = false;
            private ?string $filterExamKey = null;
            private ?string $filterChapterKey = null;
            private ?string $filterQuestionIds = null;

            public function getCourseId(): int {
                return $this->courseId;
            }

            public function setCourseId(int $courseId): void {
                $this->courseId = $courseId;
            }

            public function getPoolId(): int {
                return $this->poolId;
            }

            public function setPoolId(int $poolId): void {
                $this->poolId = $poolId;
            }

            public function getRequired(): bool {
                return $this->required;
            }

            public function setRequired(bool $required): void {
                $this->required = $required;
            }

            public function getRequiredEnforced(): bool {
                return $this->requiredEnforced;
            }

            public function setRequiredEnforced(bool $requiredEnforced): void {
                $this->requiredEnforced = $requiredEnforced;
            }

            public function getFilterExamKey(): ?string {
                return $this->filterExamKey;
            }

            public function setFilterExamKey(?string $filterExamKey): void {
                $this->filterExamKey = $filterExamKey;
            }

            public function getFilterChapterKey(): ?string {
                return $this->filterChapterKey;
            }

            public function setFilterChapterKey(?string $filterChapterKey): void {
                $this->filterChapterKey = $filterChapterKey;
            }

            public function getFilterQuestionIds(): ?string {
                return $this->filterQuestionIds;
            }

            public function setFilterQuestionIds(?string $filterQuestionIds): void {
                $this->filterQuestionIds = $filterQuestionIds;
            }
        }
    }

    if (!class_exists(Question::class)) {
        class Question implements \JsonSerializable {
            private int $id = 0;
            private int $poolId = 0;
            private string $userId = '';
            private string $text = '';
            private string $explanation = '';
            private string $difficulty = 'easy';
            private string $questionType = 'single';
            private ?string $pbqSubtype = null;
            private ?string $pbqConfig = null;

            public function getId(): int {
                return $this->id;
            }

            public function setId(int $id): void {
                $this->id = $id;
            }

            public function getPoolId(): int {
                return $this->poolId;
            }

            public function setPoolId(int $poolId): void {
                $this->poolId = $poolId;
            }

            public function getUserId(): string {
                return $this->userId;
            }

            public function setUserId(string $userId): void {
                $this->userId = $userId;
            }

            public function setText(string $text): void {
                $this->text = $text;
            }

            public function setExplanation(string $explanation): void {
                $this->explanation = $explanation;
            }

            public function setDifficulty(string $difficulty): void {
                $this->difficulty = $difficulty;
            }

            public function getQuestionType(): string {
                return $this->questionType;
            }

            public function setQuestionType(string $questionType): void {
                $this->questionType = $questionType;
            }

            public function getPbqSubtype(): ?string {
                return $this->pbqSubtype;
            }

            public function setPbqSubtype(?string $pbqSubtype): void {
                $this->pbqSubtype = $pbqSubtype;
            }

            public function getPbqConfig(): ?string {
                return $this->pbqConfig;
            }

            public function setPbqConfig(?string $pbqConfig): void {
                $this->pbqConfig = $pbqConfig;
            }

            public function jsonSerialize(): array {
                return [
                    'id' => $this->id,
                    'pool_id' => $this->poolId,
                    'user_id' => $this->userId,
                    'text' => $this->text,
                    'explanation' => $this->explanation,
                    'difficulty' => $this->difficulty,
                    'question_type' => $this->questionType,
                    'pbq_subtype' => $this->pbqSubtype,
                    'pbq_config' => $this->pbqConfig ? json_decode($this->pbqConfig, true) : null,
                ];
            }
        }
    }

    if (!class_exists(Answer::class)) {
        class Answer implements \JsonSerializable {
            private int $id = 0;
            private int $questionId = 0;
            private string $text = '';
            private bool $isCorrect = false;
            private int $position = 0;

            public function getId(): int {
                return $this->id;
            }

            public function setId(int $id): void {
                $this->id = $id;
            }

            public function setQuestionId(int $questionId): void {
                $this->questionId = $questionId;
            }

            public function setText(string $text): void {
                $this->text = $text;
            }

            public function setIsCorrect(bool $isCorrect): void {
                $this->isCorrect = $isCorrect;
            }

            public function setPosition(int $position): void {
                $this->position = $position;
            }

            public function jsonSerialize(): array {
                return [
                    'id' => $this->id,
                    'question_id' => $this->questionId,
                    'text' => $this->text,
                    'is_correct' => $this->isCorrect,
                    'position' => $this->position,
                ];
            }
        }
    }
}

namespace OCA\Learning\Service {
    if (!class_exists(BadgeService::class)) {
        class BadgeService {
            public function checkAndAward(string $userId, string $event, array $context = [], bool $persist = true): array {
                return [];
            }
        }
    }

    if (!class_exists(StreakService::class)) {
        class StreakService {
            public function getStreak(string $userId, bool $touch = false): array {
                return ['current_streak' => 0];
            }
        }
    }

    if (!class_exists(XpService::class)) {
        class XpService {
            public function applyMultiplier(int $baseXp, int $streak): int {
                return $baseXp;
            }

            public function calculateSessionXp(array $sessionData, int $streak): int {
                return 0;
            }

            public function incrementSessionXp(string $userId, int $sessionXp, int $streak): void {
            }

            public function calculateXp(string $userId): array {
                return ['level' => 1];
            }
        }
    }

    if (!class_exists(TranslationService::class)) {
        class TranslationService {
            public function normalizeLang(?string $lang): ?string {
                return $lang ?: null;
            }

            public function translateQuestions(array $questions, ?string $lang): array {
                return $questions;
            }
        }
    }

    if (!class_exists(RoleService::class)) {
        class RoleService {
            public function getRole(?string $userId): string {
                return 'student';
            }

            public function getInstructorGroup(): ?string {
                return null;
            }
        }
    }
}
