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

namespace OCP\DB {
    if (!class_exists(Exception::class)) {
        // Minimal stub of OCP\DB\Exception so unit tests can simulate a UNIQUE-constraint violation
        // (the real class wraps a Doctrine exception; tests only need getReason()).
        class Exception extends \Exception {
            public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 7;
            private int $reason;
            public function __construct(int $reason = 0, string $message = '') {
                parent::__construct($message);
                $this->reason = $reason;
            }
            public function getReason(): int {
                return $this->reason;
            }
        }
    }
}

namespace OCP\AppFramework {
    if (!class_exists(App::class)) {
        class App {
            public function __construct(string $appName = '') {}
        }
    }

    if (!interface_exists(IBootstrap::class)) {
        interface IBootstrap {
        }
    }
}

namespace OCP\AppFramework\Bootstrap {
    if (!interface_exists(IBootContext::class)) {
        interface IBootContext {
            public function getServerContainer();
        }
    }

    if (!interface_exists(IRegistrationContext::class)) {
        interface IRegistrationContext {
            public function registerDashboardWidget(string $widgetClass): void;
            public function registerNotifierService(string $notifierClass): void;
            public function registerEventListener(string $eventClass, string $listenerClass): void;
            public function registerSearchProvider(string $providerClass): void;
            public function registerService(string $className, callable $factory): void;
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

            public function escapeLikeParameter(string $input): string;
        }
    }

    if (!interface_exists(ICacheFactory::class)) {
        interface ICacheFactory {
            public function createDistributed(string $appName);
        }
    }

    if (!interface_exists(IL10N::class)) {
        interface IL10N {
            public function t(string $text, array $parameters = []): string;
        }
    }

    if (!interface_exists(IURLGenerator::class)) {
        interface IURLGenerator {
            public function imagePath(string $appName, string $image): string;

            public function linkToRoute(string $route, array $parameters = []): string;

            public function linkToRouteAbsolute(string $route, array $parameters = []): string;

            public function getAbsoluteURL(string $url): string;

            public function getBaseUrl(): string;
        }
    }

    if (!interface_exists(IUser::class)) {
        interface IUser {
            public function getUID(): string;
            public function getDisplayName(): string;
        }
    }

    if (!interface_exists(IConfig::class)) {
        interface IConfig {
            public function getUserValue(string $userId, string $appName, string $key, string $default = '');

            public function getAppValue(string $appName, string $key, string $default = '');

            public function setUserValue(string $userId, string $appName, string $key, string $value);

            /**
             * @return string[]
             */
            public function getUserKeys(string $userId, string $appName): array;
        }
    }

    if (!interface_exists(IGroupManager::class)) {
        interface IGroupManager {
        }
    }

    if (!interface_exists(IUserManager::class)) {
        interface IUserManager {
            public function get(string $uid): ?IUser;
        }
    }

    if (!interface_exists(IRequest::class)) {
        interface IRequest {
            public function getParam(string $key, $default = null);
            public function getMethod();
        }
    }
}

namespace OCP\Notification {
    if (!interface_exists(INotification::class)) {
        interface INotification {
            public function setApp(string $app): self;
            public function setUser(string $user): self;
            public function setObject(string $type, string $id): self;
            public function setSubject(string $subject, array $parameters = []): self;
            public function setDateTime(\DateTime $dateTime): self;
            public function setParsedSubject(string $subject): self;
            public function setLink(string $link): self;
            public function setIcon(string $icon): self;
            public function getApp(): string;
            public function getSubject(): string;
            public function getSubjectParameters(): array;
        }
    }

    if (!interface_exists(IManager::class)) {
        interface IManager {
            public function createNotification(): INotification;
            public function notify(INotification $notification): void;
            public function getCount(INotification $notification): int;
        }
    }

    if (!class_exists(UnknownNotificationException::class)) {
        class UnknownNotificationException extends \Exception {
        }
    }

    if (!interface_exists(INotifier::class)) {
        interface INotifier {
            public function getID(): string;
            public function getName(): string;
            public function prepare(INotification $notification, string $languageCode): INotification;
        }
    }
}

namespace OCP {
    if (!class_exists(Defaults::class)) {
        class Defaults {
            public function getName(): string { return ''; }
            public function getLogo(bool $useSvg = true): string { return ''; }
            public function getBaseUrl(): string { return ''; }
            public function getColorPrimary(): string { return ''; }
        }
    }
}

namespace OCP\AppFramework\Utility {
    if (!interface_exists(ITimeFactory::class)) {
        interface ITimeFactory {
            public function getTime(): int;
            public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
        }
    }
}

namespace OCP\L10N {
    if (!interface_exists(IFactory::class)) {
        interface IFactory {
            public function get(string $app, ?string $lang = null);
        }
    }
}

namespace OCP\Activity {
    if (!interface_exists(IManager::class)) {
        interface IManager {
        }
    }
}

namespace OCP\AppFramework {
    if (!class_exists(Controller::class)) {
        class Controller {
            protected string $appName;
            protected $request;

            public function __construct(string $appName, $request) {
                $this->appName = $appName;
                $this->request = $request;
            }
        }
    }

    if (!class_exists(Http::class)) {
        class Http {
            public const STATUS_OK = 200;
            public const STATUS_BAD_REQUEST = 400;
            public const STATUS_UNAUTHORIZED = 401;
            public const STATUS_FORBIDDEN = 403;
            public const STATUS_NOT_FOUND = 404;
            public const STATUS_TOO_MANY_REQUESTS = 429;
            public const STATUS_SERVICE_UNAVAILABLE = 503;
        }
    }
}

namespace OCP\AppFramework\Http {
    if (!class_exists(DataResponse::class)) {
        class DataResponse {
            private $data;
            private int $status;

            public function __construct($data = [], int $status = 200) {
                $this->data = $data;
                $this->status = $status;
            }

            public function getData() {
                return $this->data;
            }

            public function getStatus(): int {
                return $this->status;
            }
        }
    }

    // Base Response so methods typed `: Response` may return JSONResponse|DataDownloadResponse.
    if (!class_exists(Response::class)) {
        class Response {
            protected int $status = 200;
            // Throttle tracking (test-only): the real Response::throttle() flags the brute-force
            // middleware; here we just record that the branch was taken so controller tests can assert it.
            protected bool $throttled = false;
            /** @var array<string, mixed> */
            protected array $throttleMetadata = [];
            public function setStatus(int $status): self { $this->status = $status; return $this; }
            public function getStatus(): int { return $this->status; }
            public function render() { return ''; }
            /** @return array<string, string> */
            public function getHeaders(): array { return []; }
            /** @param array<string, mixed> $metadata */
            public function throttle(array $metadata = []): self {
                $this->throttled = true;
                $this->throttleMetadata = $metadata;
                return $this;
            }
            public function isThrottled(): bool { return $this->throttled; }
            /** @return array<string, mixed> */
            public function getThrottleMetadata(): array { return $this->throttleMetadata; }
        }
    }

    if (!class_exists(JSONResponse::class)) {
        class JSONResponse extends Response {
            private $data;

            public function __construct($data = [], int $status = 200) {
                $this->data = $data;
                $this->status = $status;
            }

            public function getData() {
                return $this->data;
            }
        }
    }

    if (!class_exists(DataDownloadResponse::class)) {
        class DataDownloadResponse extends Response {
            private $data;
            private string $filename;
            private string $contentType;

            public function __construct($data, string $filename, string $contentType) {
                $this->data = $data;
                $this->filename = $filename;
                $this->contentType = $contentType;
            }

            public function render() {
                return $this->data;
            }

            /** @return array<string, string> */
            public function getHeaders(): array {
                return [
                    'Content-Type' => $this->contentType,
                    'Content-Disposition' => 'attachment; filename="' . $this->filename . '"',
                ];
            }
        }
    }
}

namespace OCP\AppFramework\Http\Attributes {
    if (!class_exists(UserRateLimit::class)) {
        #[\Attribute]
        class UserRateLimit {
            public function __construct(int $limit = 0, int $period = 0) {}
        }
    }
    // Phase 157: public verify route security attributes (autoloaded only on reflection at runtime;
    // stubbed here so any direct reference in unit context resolves).
    if (!class_exists(PublicPage::class)) {
        #[\Attribute]
        class PublicPage {}
    }
    if (!class_exists(NoCSRFRequired::class)) {
        #[\Attribute]
        class NoCSRFRequired {}
    }
    if (!class_exists(BruteForceProtection::class)) {
        #[\Attribute]
        class BruteForceProtection {
            public function __construct(string $action = '') {}
        }
    }
    if (!class_exists(AnonRateLimit::class)) {
        #[\Attribute]
        class AnonRateLimit {
            public function __construct(int $limit = 0, int $period = 0) {}
        }
    }
}

namespace OCP\AppFramework\Http\Template {
    if (!class_exists(PublicTemplateResponse::class)) {
        // Mirrors OCP\AppFramework\Http\Template\PublicTemplateResponse (extends TemplateResponse →
        // Response). The controller builds one with (app, template, params); tests read getParams()
        // + isThrottled(). Header title is stored for completeness.
        class PublicTemplateResponse extends \OCP\AppFramework\Http\Response {
            private string $appName;
            private string $templateName;
            /** @var array<string, mixed> */
            private array $params;
            private string $headerTitle = '';

            /**
             * @param array<string, mixed> $params
             * @param array<string, string> $headers
             */
            public function __construct(string $appName, string $templateName, array $params = [], $status = 200, array $headers = []) {
                $this->appName = $appName;
                $this->templateName = $templateName;
                $this->params = $params;
                $this->status = $status;
            }

            /** @return array<string, mixed> */
            public function getParams(): array { return $this->params; }
            public function getTemplateName(): string { return $this->templateName; }
            public function setHeaderTitle(string $title): self { $this->headerTitle = $title; return $this; }
            public function getHeaderTitle(): string { return $this->headerTitle; }
        }
    }
}

namespace OCP\Search {
    if (!interface_exists(IProvider::class)) {
        interface IProvider {
            public function getId(): string;
            public function getName(): string;
            public function getOrder(string $route, array $routeParameters): int;
            public function search(\OCP\IUser $user, ISearchQuery $query): SearchResult;
        }
    }

    if (!interface_exists(ISearchQuery::class)) {
        interface ISearchQuery {
            public function getTerm();
            public function getCursor();
            public function getLimit();
            public function getFilter(string $name);
        }
    }

    if (!class_exists(SearchResult::class)) {
        class SearchResult {
            public string $name;
            /** @var array<int, mixed> */
            public array $entries;
            public ?int $cursor;
            public bool $complete;

            public function __construct(string $name, array $entries = [], ?int $cursor = null, bool $complete = false) {
                $this->name = $name;
                $this->entries = $entries;
                $this->cursor = $cursor;
                $this->complete = $complete;
            }

            public static function complete(string $name, array $entries = []): self {
                return new self($name, $entries, null, true);
            }

            public static function paginated(string $name, array $entries = [], ?int $cursor = null): self {
                return new self($name, $entries, $cursor, false);
            }
        }
    }

    if (!class_exists(SearchResultEntry::class)) {
        class SearchResultEntry {
            public string $icon;
            public string $title;
            public string $subline;
            public string $url;
            /** @var array<string, string> */
            public array $attributes = [];

            public function __construct(string $icon, string $title, string $subline, string $url) {
                $this->icon = $icon;
                $this->title = $title;
                $this->subline = $subline;
                $this->url = $url;
            }

            public function addAttribute(string $name, string $value): void {
                $this->attributes[$name] = $value;
            }
        }
    }
}

namespace OCP\AppFramework\Db {
    if (!class_exists(Entity::class)) {
        class Entity implements \JsonSerializable {
            // Mirror NC's real Entity: a fresh, unpersisted entity has a null id.
            // (Was `int $id = 0`, which made new entities report id 0 and pushed
            //  "id === null ? insert : update" logic down the update path in tests.)
            public ?int $id = null;
            protected array $updatedFields = [];

            public function getId(): ?int { return $this->id; }
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
            public function insert($entity) { return $entity; }
            public function update($entity) { return $entity; }
            public function insertOrUpdate($entity) { return $entity; }
            public function delete($entity) { return $entity; }
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
