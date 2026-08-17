<?php
declare(strict_types=1);

namespace OCP\DB\QueryBuilder {
    // Phase 160: IExpressionBuilder — needed so AssignmentService tests can mock expr()->eq() etc.
    if (!interface_exists(IExpressionBuilder::class)) {
        interface IExpressionBuilder {
            public function eq(mixed $left, mixed $right): mixed;
            public function neq(mixed $left, mixed $right): mixed;
            public function lte(mixed $left, mixed $right): mixed;
            public function gte(mixed $left, mixed $right): mixed;
            public function gt(mixed $left, mixed $right): mixed;
            public function in(mixed $left, mixed $right): mixed;
            public function like(mixed $left, mixed $right): mixed;
            public function iLike(mixed $left, mixed $right): mixed;
            public function isNull(string $field): mixed;
            public function isNotNull(string $field): mixed;
            public function andX(mixed ...$conditions): mixed;
            public function orX(mixed ...$conditions): mixed;
        }
    }

    if (!interface_exists(IQueryBuilder::class)) {
        interface IQueryBuilder {
            public const PARAM_INT = 1;
            public const PARAM_INT_ARRAY = 101;
            // Phase 160: additional type constants used by AssignmentService/MigrationService
            public const PARAM_STR = 16;
            public const PARAM_NULL = 4;
            public const PARAM_BOOL = 5;
            // Phase 163: PARAM_STR_ARRAY for chunked IN queries (CertificateMapper, AssignmentService)
            public const PARAM_STR_ARRAY = 102;

            // Phase 160: full DML/query surface so PHPUnit can mock/configure each method
            public function expr(): IExpressionBuilder;
            public function insert(string $table): self;
            public function update(string $table): self;
            public function delete(string $table): self;
            public function select(mixed ...$fields): self;
            public function addSelect(mixed ...$fields): self;
            public function from(string $table, ?string $alias = null): self;
            public function values(array $values): self;
            public function set(string $field, mixed $value): self;
            public function where(mixed $condition): self;
            public function andWhere(mixed $condition): self;
            public function orWhere(mixed $condition): self;
            public function orderBy(string $field, ?string $direction = null): self;
            public function addOrderBy(string $field, ?string $direction = null): self;
            public function setMaxResults(int $limit): self;
            public function setFirstResult(int $offset): self;
            public function createNamedParameter(mixed $value, mixed $type = null): mixed;
            public function createPositionalParameter(mixed $value, mixed $type = null): mixed;
            public function createFunction(string $expression): string;
            public function execute(): mixed;
            public function executeQuery(): mixed;
            public function executeStatement(): int;
            public function getLastInsertId(): int;
        }
    }
}

namespace OCP\DB {
    if (!class_exists(Exception::class)) {
        // Minimal stub of OCP\DB\Exception so unit tests can simulate a UNIQUE-constraint violation
        // (the real class wraps a Doctrine exception; tests only need getReason()).
        class Exception extends \Exception {
            public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 7;
            // Mirrors the real OCP\DB\Exception value — UninstallCommand uses it to tell an
            // absent table apart from a query that failed for any other reason.
            public const REASON_DATABASE_OBJECT_NOT_FOUND = 4;
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

    // Phase 160: AuditService uses ISecureRandom for pepper generation (constructor param).
    if (!interface_exists(ISecureRandom::class)) {
        interface ISecureRandom {
            public function generate(int $length, string $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'): string;
        }
    }
}

namespace OCP {
    if (!interface_exists(IDBConnection::class)) {
        interface IDBConnection {
            public function getQueryBuilder();

            public function executeQuery(string $sql, array $params = []);

            public function escapeLikeParameter(string $input): string;

            // Phase 160: logComplianceEvent uses transactions for CAS serialization.
            public function beginTransaction(): void;

            public function commit(): void;

            public function rollBack(): void;

            public function inTransaction(): bool;
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
            // Phase 160: ClassbookController uses getEMailAddress() with null-coalescing
            public function getEMailAddress(): ?string;
            // Phase 160: ImportUsersCommand/Job call setDisplayName on created users
            public function setDisplayName(string $displayName): bool;
        }
    }

    if (!interface_exists(IConfig::class)) {
        interface IConfig {
            public function getUserValue(string $userId, string $appName, string $key, string $default = '');

            public function getAppValue(string $appName, string $key, string $default = '');

            public function setAppValue(string $appName, string $key, string $value);

            /**
             * @param mixed $default
             * @return mixed
             */
            public function getSystemValue(string $key, $default = '');

            public function setUserValue(string $userId, string $appName, string $key, string $value);

            /**
             * @return string[]
             */
            public function getUserKeys(string $userId, string $appName): array;
        }
    }

    // Phase 160: IGroup — AssignmentService::expandGroup() calls groupManager->get()->getUsers()
    if (!interface_exists(IGroup::class)) {
        interface IGroup {
            /** @return IUser[] */
            public function getUsers(): array;
            public function addUser(IUser $user): void;
        }
    }

    if (!interface_exists(IGroupManager::class)) {
        interface IGroupManager {
            // Phase 160: AssignmentService uses get() (NC 33 API, not getGroup())
            public function get(string $gid): ?IGroup;
            // Phase 161: AuditExportController gates the auditor export via isInGroup($uid, $group).
            public function isInGroup(string $userId, string $group): bool;
        }
    }

    if (!interface_exists(IUserManager::class)) {
        interface IUserManager {
            public function get(string $uid): ?IUser;
            // Phase 160: ImportUsersCommand/Job call createUser()
            public function createUser(string $uid, string $password): IUser|false;
            public function userExists(string $uid): bool;
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

// ─── Symfony Console stubs ───────────────────────────────────────────────────
// Nextcloud provides symfony/console at runtime but it is not in the app's own
// vendor/. Unit tests that instantiate OCC commands need these minimal stubs.

namespace Symfony\Component\Console\Input {
    if (!interface_exists(InputInterface::class)) {
        interface InputInterface {
            public function getArgument(string $name): mixed;
            public function getOption(string $name): mixed;
            public function hasArgument(string $name): bool;
            public function hasOption(string $name): bool;
        }
    }

    if (!class_exists(InputArgument::class)) {
        class InputArgument {
            public const REQUIRED = 1;
            public const OPTIONAL = 2;
            public const IS_ARRAY = 4;
        }
    }

    if (!class_exists(InputOption::class)) {
        class InputOption {
            public const VALUE_NONE = 1;
            public const VALUE_REQUIRED = 2;
            public const VALUE_OPTIONAL = 4;
            public const VALUE_IS_ARRAY = 8;
        }
    }
}

namespace Symfony\Component\Console\Output {
    if (!interface_exists(OutputInterface::class)) {
        interface OutputInterface {
            public function writeln(string|array $messages, int $options = 0): void;
            public function write(string|array $messages, bool $newline = false, int $options = 0): void;
        }
    }
}

namespace Symfony\Component\Console\Command {
    if (!class_exists(Command::class)) {
        abstract class Command {
            public const SUCCESS = 0;
            public const FAILURE = 1;
            public const INVALID = 2;

            public function __construct(?string $name = null) {
                $this->configure();
            }

            public function setName(string $name): static { return $this; }
            public function setDescription(string $description): static { return $this; }

            public function addArgument(
                string $name,
                int $mode = 0,
                string $description = '',
                mixed $default = null
            ): static { return $this; }

            public function addOption(
                string $name,
                string|array|null $shortcut = null,
                int $mode = 0,
                string $description = '',
                mixed $default = null
            ): static { return $this; }

            protected function configure(): void {}

            abstract protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int;

            public function run(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                return $this->execute($input, $output);
            }
        }
    }
}

namespace Symfony\Component\Console\Tester {
    // Minimal InputInterface for CommandTester: maps flat array to argument/option access.
    // Arguments use bare key ('csv_file'); options use '--option-name' prefix.
    if (!class_exists(StubConsoleInput::class)) {
        class StubConsoleInput implements \Symfony\Component\Console\Input\InputInterface {
            public function __construct(private array $data) {}
            public function getArgument(string $name): mixed { return $this->data[$name] ?? null; }
            public function getOption(string $name): mixed { return $this->data['--' . $name] ?? null; }
            public function hasArgument(string $name): bool { return array_key_exists($name, $this->data); }
            public function hasOption(string $name): bool { return array_key_exists('--' . $name, $this->data); }
        }
    }

    // Minimal OutputInterface for CommandTester: discards all output.
    if (!class_exists(StubConsoleOutput::class)) {
        class StubConsoleOutput implements \Symfony\Component\Console\Output\OutputInterface {
            public function writeln(string|array $messages, int $options = 0): void {}
            public function write(string|array $messages, bool $newline = false, int $options = 0): void {}
        }
    }

    if (!class_exists(CommandTester::class)) {
        class CommandTester {
            public function __construct(private \Symfony\Component\Console\Command\Command $command) {}

            public function execute(array $input, array $options = []): int {
                return $this->command->run(new StubConsoleInput($input), new StubConsoleOutput());
            }

            public function getDisplay(): string { return ''; }
            public function getStatusCode(): int { return 0; }
        }
    }
}

// ─── OCP BackgroundJob stubs ─────────────────────────────────────────────────
namespace OCP\BackgroundJob {
    // Phase 160: IJobList — ImportUsersCommand dispatches bulk import via jobList->add()
    if (!interface_exists(IJobList::class)) {
        interface IJobList {
            public function add(string $job, mixed $argument = null): void;
            public function has(string $job, mixed $argument = null): bool;
            public function remove(string $job, mixed $argument = null): void;
        }
    }

    // Phase 160: QueuedJob base class — ImportUsersJob extends it; NC 33 requires ITimeFactory
    if (!class_exists(QueuedJob::class)) {
        abstract class QueuedJob {
            public function __construct(\OCP\AppFramework\Utility\ITimeFactory $timeFactory) {}
            abstract protected function run(mixed $argument): void;
        }
    }

    // Phase 161: TimedJob base class — AuditCheckpointJob extends it (weekly setInterval).
    // NC 33 ctor takes ITimeFactory; setInterval() is called from the subclass ctor.
    if (!class_exists(TimedJob::class)) {
        abstract class TimedJob {
            protected int $interval = 0;
            public function __construct(\OCP\AppFramework\Utility\ITimeFactory $time) {}
            public function setInterval(int $seconds): void { $this->interval = $seconds; }
            public function getInterval(): int { return $this->interval; }
            abstract protected function run(mixed $argument): void;
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

// ─── OCP HTTP client stubs ───────────────────────────────────────────────────
// Phase 161 (AUDIT-06): AuditVerifyCommand injects IClientService for warning-only anchor
// consistency checks. Guarded so a concurrent 161-04 addition never fatals on redeclare.
namespace OCP\Http\Client {
    if (!interface_exists(IResponse::class)) {
        interface IResponse {
            /** @return string|resource */
            public function getBody();
            public function getStatusCode(): int;
            /** @return array<string, string> */
            public function getHeaders(): array;
            public function getHeader(string $key): string;
        }
    }

    if (!interface_exists(IClient::class)) {
        interface IClient {
            /** @param array<string, mixed> $options */
            public function get(string $uri, array $options = []): IResponse;
            /** @param array<string, mixed> $options */
            public function post(string $uri, array $options = []): IResponse;
        }
    }

    if (!interface_exists(IClientService::class)) {
        interface IClientService {
            public function newClient(): IClient;
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
            public const STATUS_CREATED = 201;
            public const STATUS_NO_CONTENT = 204;
            public const STATUS_PARTIAL_CONTENT = 206;
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

    // Phase 161: AuditExportController::page() returns a TemplateResponse('learning','audit-export-page',
    // [...], 'user'). Tests read getParams()/getTemplateName()/getStatus(); no NC template engine needed.
    if (!class_exists(TemplateResponse::class)) {
        class TemplateResponse extends Response {
            private string $appName;
            private string $templateName;
            /** @var array<string, mixed> */
            private array $params;
            private string $renderAs;

            /** @param array<string, mixed> $params */
            public function __construct(string $appName, string $templateName, array $params = [], string $renderAs = 'user') {
                $this->appName = $appName;
                $this->templateName = $templateName;
                $this->params = $params;
                $this->renderAs = $renderAs;
            }

            /** @return array<string, mixed> */
            public function getParams(): array { return $this->params; }
            public function getTemplateName(): string { return $this->templateName; }
            public function getRenderAs(): string { return $this->renderAs; }
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
            protected string $tableName = '';
            protected string $entityClass = '';
            public function __construct($db, string $tableName = '', string $entityClass = '') {
                $this->db = $db;
                $this->tableName = $tableName;
                $this->entityClass = $entityClass;
            }
            public function getTableName(): string { return $this->tableName; }
            // Phase 163: findEntities — executes QB, iterates fetch(), creates entities via fromRow().
            // Mirrors real QBMapper semantics so CertificateMapper/OversightMapper unit tests work.
            protected function findEntities($qb): array {
                $result = $qb->executeQuery();
                if ($result === null) {
                    return [];
                }
                $entities = [];
                $cls = $this->entityClass;
                while (($row = $result->fetch()) !== false) {
                    if (!is_array($row)) {
                        break;
                    }
                    $entities[] = $cls !== '' ? $cls::fromRow($row) : $row;
                }
                if (method_exists($result, 'closeCursor')) {
                    $result->closeCursor();
                }
                return $entities;
            }
            // findEntity — returns first hit or throws DoesNotExistException.
            protected function findEntity($qb) {
                $entities = $this->findEntities($qb);
                if ($entities === []) {
                    throw new DoesNotExistException('Entity not found');
                }
                return $entities[0];
            }
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
