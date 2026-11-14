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

    // Signatures from NC 33's lib/public/DB/QueryBuilder/IFunctionBuilder.php / IQueryFunction.php
    // (count() only — add further functions when a test needs them).
    if (!interface_exists(IQueryFunction::class)) {
        interface IQueryFunction {
            public function __toString(): string;
        }
    }

    if (!interface_exists(IFunctionBuilder::class)) {
        interface IFunctionBuilder {
            public function count($count = '', $alias = ''): IQueryFunction;
        }
    }

    if (!interface_exists(IQueryBuilder::class)) {
        interface IQueryBuilder {
            // Values as NC 33 resolves them (Doctrine ParameterType / ArrayParameterType / Types),
            // so a test that compares a literal type tests Nextcloud, not the stub (audit #199).
            public const PARAM_NULL = 0;
            public const PARAM_INT = 1;
            public const PARAM_STR = 2;
            public const PARAM_BOOL = 'boolean';
            public const PARAM_INT_ARRAY = 101;
            public const PARAM_STR_ARRAY = 102;

            // Phase 160: full DML/query surface so PHPUnit can mock/configure each method
            public function expr(): IExpressionBuilder;
            public function func(): IFunctionBuilder;
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
    // Signatures from NC 33's lib/public/DB/IResult.php (the read surface tests mock).
    if (!interface_exists(IResult::class)) {
        interface IResult {
            public function closeCursor(): bool;

            public function fetch(int $fetchMode = \PDO::FETCH_ASSOC);

            public function fetchOne();
        }
    }

    if (!class_exists(Exception::class)) {
        // Minimal stub of OCP\DB\Exception so unit tests can simulate a UNIQUE-constraint violation.
        // The real class wraps a Doctrine exception and takes (message, code, previous); this stub
        // takes the reason directly because tests only need getReason(). The REASON_* values are
        // the real NC 33 ones (audit #199: UNIQUE was 7 here, which is FOREIGN_KEY in Nextcloud).
        class Exception extends \Exception {
            public const REASON_DATABASE_OBJECT_NOT_FOUND = 4;
            public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 14;
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
}

namespace OCP\AppFramework\Bootstrap {
    // Mirrors NC's OCP\AppFramework\Bootstrap\IBootstrap. It used to sit in OCP\AppFramework,
    // so Application (and every path reading Application::APP_ID) could not load in unit tests.
    if (!interface_exists(IBootstrap::class)) {
        interface IBootstrap {
            public function register(IRegistrationContext $context): void;
            public function boot(IBootContext $context): void;
        }
    }

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
            public const PLATFORM_MYSQL = 'mysql';
            public const PLATFORM_ORACLE = 'oracle';
            public const PLATFORM_POSTGRES = 'postgres';
            public const PLATFORM_SQLITE = 'sqlite';

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

            public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string;
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

            public function deleteAppValue(string $appName, string $key);

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

    // 5.6.0 (Task A2): ConnectionStore/ApiKeyStore use the typed app config API. Only the methods
    // they call; signatures copied verbatim from lib/public/IAppConfig.php in the nextcloud:33
    // gate image (sha256:df735d59…).
    if (!interface_exists(IAppConfig::class)) {
        interface IAppConfig {
            public function hasKey(string $app, string $key, ?bool $lazy = false): bool;

            public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string;

            public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;

            public function deleteKey(string $app, string $key): void;

            public function clearCache(bool $reload = false): void;
        }
    }

    // Phase 160: IGroup — AssignmentService::expandGroup() calls groupManager->get()->getUsers()
    if (!interface_exists(IGroup::class)) {
        interface IGroup {
            /** @return IUser[] */
            public function getUsers(): array;
            public function addUser(IUser $user): void;
            // GitHub #35: CourseEnrollmentService mirrors core's GroupPlugin. Signatures copied
            // from NC 33's lib/public/IGroup.php.
            public function getGID(): string;
            public function hideFromCollaboration(): bool;
            // Bounded group expansion (NC 33 lib/public/IGroup.php).
            public function searchUsers(string $search, ?int $limit = null, ?int $offset = null): array;
        }
    }

    if (!interface_exists(IGroupManager::class)) {
        interface IGroupManager {
            // Phase 160: AssignmentService uses get() (NC 33 API, not getGroup())
            public function get(string $gid): ?IGroup;
            // Phase 161: AuditExportController gates the auditor export via isInGroup($uid, $group).
            // Codeberg #6: signatures relaxed to match the real OCP\IGroupManager, which
            // declares `isInGroup($userId, $group)` and `isAdmin($userId)` with no types at
            // all. A stub stricter than the interface it stands in for is its own defect —
            // same shape as the IOutput stub corrected in 920e2be. Verified against
            // /var/www/html/lib/public/IGroupManager.php on NC 34.
            public function isInGroup($userId, $group);
            // RoleService::isInstructor() treats Nextcloud admins as instructors.
            public function isAdmin($userId);
            // GitHub #35: "share with group members only" check (NC 33 lib/public/IGroupManager.php).
            public function getUserGroupIds(IUser $user): array;
        }
    }

    if (!interface_exists(IUserManager::class)) {
        interface IUserManager {
            public function get(string $uid): ?IUser;
            // Phase 160: ImportUsersCommand/Job call createUser()
            public function createUser(string $uid, string $password): IUser|false;
            public function userExists(string $uid): bool;
            // 5.7.0 C1: MigrateLlmConfiguration walks all users for legacy TaskProcessing tasks.
            // Signature copied from NC 33's lib/public/IUserManager.php (no types there).
            public function callForAllUsers(\Closure $callback, $search = '');
        }
    }

    if (!interface_exists(IRequest::class)) {
        interface IRequest {
            public function getParam(string $key, $default = null);
            public function getMethod();
            public function getParams(): array;
            // Signature copied from NC 33's lib/public/IRequest.php.
            public function getUploadedFile(string $key);
            public function getHeader(string $name): string;
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
            public function getUser(): string;
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
        class UnknownNotificationException extends \InvalidArgumentException {
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

    // Minimal OutputInterface for CommandTester: collects the output for getDisplay().
    if (!class_exists(StubConsoleOutput::class)) {
        class StubConsoleOutput implements \Symfony\Component\Console\Output\OutputInterface {
            public string $buffer = '';
            public function writeln(string|array $messages, int $options = 0): void {
                $this->write($messages, true, $options);
            }
            public function write(string|array $messages, bool $newline = false, int $options = 0): void {
                foreach ((array)$messages as $message) {
                    $this->buffer .= $message . ($newline ? "\n" : '');
                }
            }
        }
    }

    // Like Symfony's CommandTester, getStatusCode()/getDisplay() report the last execute(); before
    // audit #199 they always returned 0 and '', so an assertion on them passed for a failing command.
    if (!class_exists(CommandTester::class)) {
        class CommandTester {
            private ?int $statusCode = null;
            private ?StubConsoleOutput $output = null;

            public function __construct(private \Symfony\Component\Console\Command\Command $command) {}

            public function execute(array $input, array $options = []): int {
                $this->output = new StubConsoleOutput();
                return $this->statusCode = $this->command->run(new StubConsoleInput($input), $this->output);
            }

            public function getDisplay(): string {
                if ($this->output === null) {
                    throw new \RuntimeException('Output not initialized, did you execute the command before requesting the display?');
                }
                return $this->output->buffer;
            }

            public function getStatusCode(): int {
                if ($this->statusCode === null) {
                    throw new \RuntimeException('Status code not initialized, did you execute the command before requesting the status code?');
                }
                return $this->statusCode;
            }
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

    // 5.6.0 Task A3: thrown by NC's Client/DnsPinMiddleware when a target violates local access
    // rules (verbatim from lib/public/Http/Client/LocalServerException.php, NC 33).
    if (!class_exists(LocalServerException::class)) {
        class LocalServerException extends \RuntimeException {
        }
    }
}

namespace OCP\Activity {
    if (!interface_exists(IManager::class)) {
        interface IManager {
        }
    }

    if (!interface_exists(IEvent::class)) {
        interface IEvent {
            public function setApp(string $app): self;
            public function setType(string $type): self;
            public function setAffectedUser(string $user): self;
            public function setSubject(string $subject, array $parameters = []): self;
            public function setParsedSubject(string $subject): self;
            public function setIcon(string $icon): self;
            public function setLink(string $link): self;
            public function getApp(): string;
            public function getSubject(): string;
            public function getSubjectParameters(): array;
        }
    }

    // Signature mirrors the real OCP\Activity\IProvider: $language is untyped there and the
    // method declares no return type, so the app's narrowed `mixed $language ... : IEvent`
    // stays a legal override under both this stub and the shipped interface.
    if (!interface_exists(IProvider::class)) {
        interface IProvider {
            public function parse($language, IEvent $event, ?IEvent $previousEvent = null);
        }
    }
}

namespace OCP\Activity\Exceptions {
    // Codeberg #4: NC 30 introduced this as the contract for "provider does not know this event".
    // It extends \InvalidArgumentException in NC itself, so the migration is backwards compatible
    // — but NC 39 will log a bare \InvalidArgumentException as an error, not just a warning.
    if (!class_exists(UnknownActivityException::class)) {
        class UnknownActivityException extends \InvalidArgumentException {
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
            public const STATUS_CONFLICT = 409;
            public const STATUS_TOO_MANY_REQUESTS = 429;
            public const STATUS_INTERNAL_SERVER_ERROR = 500;
            public const STATUS_SERVICE_UNAVAILABLE = 503;
        }
    }
}

namespace OCP\AppFramework\Http {
    // Base Response so methods typed `: Response` may return JSONResponse|DataDownloadResponse.
    if (!class_exists(Response::class)) {
        class Response {
            protected int $status = 200;
            // Throttle tracking (test-only): the real Response::throttle() flags the brute-force
            // middleware; here we just record that the branch was taken so controller tests can assert it.
            protected bool $throttled = false;
            /** @var array<string, mixed> */
            protected array $throttleMetadata = [];
            /** @var array<string, string> */
            protected array $headers = [];
            // GitHub #35: signature of OCP\AppFramework\Http\Response::__construct (NC 33), so a
            // streamed response built as `new class(...) extends Response` carries its headers.
            /** @param array<string, string> $headers */
            public function __construct(int $status = 200, array $headers = []) {
                $this->status = $status;
                $this->headers = $headers;
            }
            public function setStatus(int $status): self { $this->status = $status; return $this; }
            public function getStatus(): int { return $this->status; }
            public function render() { return ''; }
            /** @return $this */
            public function addHeader($name, $value) { $this->headers[(string)$name] = (string)$value; return $this; }
            /** @return array<string, string> */
            public function getHeaders(): array { return $this->headers; }
            /** @param array<string, mixed> $metadata */
            public function throttle(array $metadata = []): self {
                $this->throttled = true;
                $this->throttleMetadata = $metadata;
                return $this;
            }
            public function isThrottled(): bool { return $this->throttled; }
            /** @return array<string, mixed> */
            public function getThrottleMetadata(): array { return $this->throttleMetadata; }
            protected ?EmptyContentSecurityPolicy $csp = null;
            public function setContentSecurityPolicy(EmptyContentSecurityPolicy $csp): self { $this->csp = $csp; return $this; }
            public function getContentSecurityPolicy(): EmptyContentSecurityPolicy {
                if ($this->csp === null) {
                    $this->csp = new EmptyContentSecurityPolicy();
                }
                return $this->csp;
            }
        }
    }

    // Declared after Response: like the real class it extends Response (audit #199), so a method
    // typed `: Response` that returns a DataResponse behaves as on Nextcloud.
    if (!class_exists(DataResponse::class)) {
        class DataResponse extends Response {
            private $data;

            /** @param array<string, string> $headers */
            public function __construct(mixed $data = [], int $statusCode = 200, array $headers = []) {
                parent::__construct($statusCode, $headers);
                $this->data = $data;
            }

            public function getData() {
                return $this->data;
            }
        }
    }

    // GitHub #35: streamed responses (DocumentController::download). Signatures copied from NC 33
    // lib/public/AppFramework/Http/IOutput.php and ICallbackResponse.php.
    if (!interface_exists(IOutput::class)) {
        interface IOutput {
            public function setOutput($out);
            public function setReadfile($path);
            public function setHeader($header);
            public function getHttpResponseCode();
            public function setHttpResponseCode($code);
            public function setCookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite = 'Lax');
        }
    }
    if (!interface_exists(ICallbackResponse::class)) {
        interface ICallbackResponse {
            public function callback(IOutput $output);
        }
    }

    // CSP stubs: the real classes build the header string; tests only need the domains a controller
    // added (test-only accessor getAllowed()). Method names mirror OCP, which PHPStan checks.
    if (!class_exists(EmptyContentSecurityPolicy::class)) {
        class EmptyContentSecurityPolicy {
            /** @var array<string, list<string>> */
            protected array $allowed = [];
            /** @return $this */
            public function addAllowedScriptDomain($domain) { $this->allowed['script'][] = (string)$domain; return $this; }
            /** @return $this */
            public function addAllowedFrameDomain($domain) { $this->allowed['frame'][] = (string)$domain; return $this; }
            /** @return $this */
            public function addAllowedChildSrcDomain($domain) { $this->allowed['child'][] = (string)$domain; return $this; }
            /** @return $this */
            public function addAllowedConnectDomain($domain) { $this->allowed['connect'][] = (string)$domain; return $this; }
            /** @return $this */
            public function addAllowedImageDomain($domain) { $this->allowed['image'][] = (string)$domain; return $this; }
            /** @return list<string> */
            public function getAllowed(string $kind): array { return $this->allowed[$kind] ?? []; }
        }
    }
    if (!class_exists(ContentSecurityPolicy::class)) {
        class ContentSecurityPolicy extends EmptyContentSecurityPolicy {
            /** @var array<string, list<string>> */
            protected array $allowed = ['script' => ["'self'"]];
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

            // string $data like NC 33: under strict_types a json_encode() false is a TypeError there,
            // and the stub used to accept it (audit #199).
            /** @param array<string, string> $headers */
            public function __construct(string $data, string $filename, string $contentType, int $status = 200, array $headers = []) {
                parent::__construct($status, $headers);
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
            public const RENDER_AS_GUEST = 'guest';
            public const RENDER_AS_BLANK = '';
            public const RENDER_AS_BASE = 'base';
            public const RENDER_AS_USER = 'user';
            public const RENDER_AS_ERROR = 'error';
            public const RENDER_AS_PUBLIC = 'public';

            private string $appName;
            private string $templateName;
            /** @var array<string, mixed> */
            private array $params;
            private string $renderAs;

            /**
             * @param array<string, mixed> $params
             * @param array<string, string> $headers
             */
            public function __construct(string $appName, string $templateName, array $params = [], string $renderAs = 'user', int $status = 200, array $headers = []) {
                parent::__construct($status, $headers);
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
        // Mirrors OCP\AppFramework\Http\Template\PublicTemplateResponse, which extends TemplateResponse
        // (audit #199: the stub extended Response directly). The controller builds one with
        // (app, template, params); tests read getParams() + isThrottled().
        class PublicTemplateResponse extends \OCP\AppFramework\Http\TemplateResponse {
            private string $headerTitle = '';

            /**
             * @param array<string, mixed> $params
             * @param array<string, string> $headers
             */
            public function __construct(string $appName, string $templateName, array $params = [], int $status = 200, array $headers = []) {
                parent::__construct($appName, $templateName, $params, 'public', $status, $headers);
            }

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

namespace OCP\Migration {
    if (!interface_exists(IOutput::class)) {
        // Signatures copied verbatim from NC 34's lib/public/Migration/IOutput.php. The first
        // draft here declared stricter types than the real interface, which is exactly how a
        // stub stops testing reality: an implementation the stub accepts would have been a
        // fatal error against the actual API, and the suite would still have been green.
        interface IOutput {
            public function debug(string $message): void;

            public function info($message);

            public function warning($message);

            public function startProgress($max = 0);

            public function advance($step = 1, $description = '');

            public function finishProgress();
        }
    }

    if (!interface_exists(IRepairStep::class)) {
        // Signatures copied verbatim from NC 33's lib/public/Migration/IRepairStep.php.
        interface IRepairStep {
            public function getName();

            public function run(IOutput $output);
        }
    }

    if (!class_exists(SimpleMigrationStep::class)) {
        abstract class SimpleMigrationStep {
            public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
            }

            public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
                return null;
            }

            public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
            }
        }
    }
}

namespace OCP\Lock {
    // KeyService::init() serialises concurrent issuer-key creation (web button double-click).
    if (!interface_exists(ILockingProvider::class)) {
        interface ILockingProvider {
            public const LOCK_SHARED = 1;
            public const LOCK_EXCLUSIVE = 2;

            public function acquireLock(string $path, int $type, ?string $readablePath = null): void;

            public function releaseLock(string $path, int $type): void;
        }
    }

    if (!class_exists(LockedException::class)) {
        class LockedException extends \Exception {
            public function __construct(string $path, ?\Exception $previous = null, ?string $existingLock = null, ?string $readablePath = null) {
                parent::__construct('"' . $path . '" is locked', 0, $previous);
            }
        }
    }
}

namespace OCP\Files {
    // PoolGeneratorService takes the root folder for file-based generation (unused in text tests).
    if (!interface_exists(IRootFolder::class)) {
        interface IRootFolder {
            public function getUserFolder($userId);
        }
    }
    // 2026-11 audit (S14): DocumentService::scanFolder walks a folder listing. Subset of NC 33's
    // lib/public/Files/{Node,Folder,NotFoundException}.php (constants copied verbatim).
    if (!interface_exists(Node::class)) {
        interface Node {
            public const TYPE_FILE = 'file';
            public const TYPE_FOLDER = 'dir';

            public function getType();

            public function getName();

            public function getSize($includeMounts = true);

            // GitHub #35: the material download refuses unreadable and view-only shared files
            // (NC 33 lib/public/Files/Node.php).
            public function getStorage();

            public function isReadable();
        }
    }
    if (!interface_exists(Folder::class)) {
        interface Folder extends Node {
            public function get($path);

            public function getDirectoryListing();
        }
    }
    // GitHub #35: the material download reads a file node (NC 33 lib/public/Files/File.php; the
    // mime type is declared on FileInfo there).
    if (!interface_exists(File::class)) {
        interface File extends Node {
            public function getContent();

            public function fopen($mode);

            public function getMimeType();
        }
    }
    if (!class_exists(NotFoundException::class)) {
        class NotFoundException extends \Exception {
        }
    }
}

namespace OCP\Files\Storage {
    // GitHub #35: view-only shares (NC 33 lib/public/Files/Storage/{IStorage,ISharedStorage}.php,
    // subset).
    if (!interface_exists(IStorage::class)) {
        interface IStorage {
            public function instanceOfStorage(string $class);
        }
    }
    if (!interface_exists(ISharedStorage::class)) {
        interface ISharedStorage extends IStorage {
            public function getShare(): \OCP\Share\IShare;
        }
    }
}

namespace OCP\TaskProcessing\Exception {
    // 5.7.0 C1: TaskProcessing manager errors (NC 33 lib/public/TaskProcessing/Exception/Exception.php).
    if (!class_exists(Exception::class)) {
        class Exception extends \Exception {
        }
    }
    if (!class_exists(NotFoundException::class)) {
        class NotFoundException extends Exception {
        }
    }
}

namespace OCP\TaskProcessing {
    // 5.7.0 C1: MigrateLlmConfiguration cancels and deletes tasks of the removed legacy path.
    // Signatures copied from NC 33's lib/public/TaskProcessing/{Task,IManager}.php (subset).
    if (!class_exists(Task::class)) {
        class Task {
            public const STATUS_CANCELLED = 5;
            public const STATUS_FAILED = 4;
            public const STATUS_SUCCESSFUL = 3;
            public const STATUS_RUNNING = 2;
            public const STATUS_SCHEDULED = 1;
            public const STATUS_UNKNOWN = 0;

            protected ?int $id = null;
            protected int $status = self::STATUS_UNKNOWN;

            final public function __construct(
                protected readonly string $taskTypeId,
                protected array $input,
                protected readonly string $appId,
                protected readonly ?string $userId,
                protected readonly ?string $customId = '',
            ) {
            }

            final public function getId(): ?int {
                return $this->id;
            }

            final public function setId(?int $id): void {
                $this->id = $id;
            }

            final public function getStatus(): int {
                return $this->status;
            }

            final public function setStatus(int $status): void {
                $this->status = $status;
            }

            final public function getAppId(): string {
                return $this->appId;
            }

            final public function getUserId(): ?string {
                return $this->userId;
            }
        }
    }

    if (!interface_exists(IManager::class)) {
        interface IManager {
            /** @return list<Task> */
            public function getUserTasksByApp(?string $userId, string $appId, ?string $customId = null): array;

            public function cancelTask(int $id): void;

            public function deleteTask(Task $task): void;

            public function getTask(int $id): Task;
        }
    }
}

namespace OCP\TextProcessing {
    // 5.7.0 C1: the TextProcessing queue of NC <= 29 (deprecated since 30, still in NC 33-35).
    // Signatures copied from NC 33's lib/public/TextProcessing/{Task,IManager}.php (subset).
    if (!class_exists(Task::class)) {
        class Task {
            public const STATUS_FAILED = 4;
            public const STATUS_SUCCESSFUL = 3;
            public const STATUS_RUNNING = 2;
            public const STATUS_SCHEDULED = 1;
            public const STATUS_UNKNOWN = 0;

            protected ?int $id = null;
            protected int $status = self::STATUS_UNKNOWN;

            final public function __construct(
                protected string $type,
                protected string $input,
                protected string $appId,
                protected ?string $userId,
                protected string $identifier = '',
            ) {
            }

            final public function getId(): ?int {
                return $this->id;
            }

            final public function setId(?int $id): void {
                $this->id = $id;
            }

            final public function getStatus(): int {
                return $this->status;
            }

            final public function setStatus(int $status): void {
                $this->status = $status;
            }

            final public function getUserId(): ?string {
                return $this->userId;
            }
        }
    }

    if (!interface_exists(IManager::class)) {
        interface IManager {
            /** @return list<Task> */
            public function getUserTasksByApp(string $userId, string $appId, ?string $identifier = null): array;

            public function deleteTask(Task $task): void;
        }
    }
}

namespace OCP\Settings {
    // AdminSettings (NC 33 lib/public/Settings/ISettings.php).
    if (!interface_exists(ISettings::class)) {
        interface ISettings {
            /** @return \OCP\AppFramework\Http\TemplateResponse */
            public function getForm();

            /** @return string|null */
            public function getSection();

            /** @return int */
            public function getPriority();
        }
    }
}

// GitHub #35: member picker and group enrolment follow the instance's sharing rules. Signatures
// copied from NC 33's lib/public/Collaboration/Collaborators/ISearch.php, Share/IShare.php and
// Share/IManager.php (only what CourseEnrollmentService uses).
namespace OCP\Collaboration\Collaborators {
    if (!interface_exists(ISearch::class)) {
        interface ISearch {
            public function search($search, array $shareTypes, $lookup, $limit, $offset);
            public function registerPlugin(array $pluginInfo);
        }
    }
}

namespace OCP\Share {
    if (!interface_exists(IShare::class)) {
        interface IShare {
            public const TYPE_USER = 0;
            public const TYPE_GROUP = 1;
            public const TYPE_EMAIL = 4;

            // GitHub #35: the "download" permission of a view-only share (NC 33 IShare.php).
            public function getAttributes(): ?IAttributes;
        }
    }
    if (!interface_exists(IAttributes::class)) {
        interface IAttributes {
            public function getAttribute(string $scope, string $key): mixed;
        }
    }

    if (!interface_exists(IManager::class)) {
        interface IManager {
            public function shareWithGroupMembersOnly(): bool;
            public function shareWithGroupMembersOnlyExcludeGroupsList(): array;
            public function allowGroupSharing(): bool;
            // Enumeration settings (NC 33 lib/public/Share/IManager.php).
            public function allowEnumeration(): bool;
            public function limitEnumerationToGroups(): bool;
            public function limitEnumerationToPhone(): bool;
        }
    }
}

// GitHub #35: the dashboard widget's exam-date parsing is tested through getItemsV2(). Signatures
// copied from NC 33's lib/public/Dashboard/{IWidget,IAPIWidgetV2,IIconWidget}.php and
// Dashboard/Model/{WidgetItem,WidgetItems}.php.
namespace OCP\Dashboard {
    if (!interface_exists(IWidget::class)) {
        interface IWidget {
            public function getId(): string;
            public function getTitle(): string;
            public function getOrder(): int;
            public function getIconClass(): string;
            public function getUrl(): ?string;
            public function load(): void;
        }
    }
    if (!interface_exists(IAPIWidgetV2::class)) {
        interface IAPIWidgetV2 extends IWidget {
            public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): \OCP\Dashboard\Model\WidgetItems;
        }
    }
    if (!interface_exists(IIconWidget::class)) {
        interface IIconWidget extends IWidget {
            public function getIconUrl(): string;
        }
    }
}

namespace OCP\Dashboard\Model {
    if (!class_exists(WidgetItem::class)) {
        final class WidgetItem {
            public function __construct(
                private string $title = '',
                private string $subtitle = '',
                private string $link = '',
                private string $iconUrl = '',
                private string $sinceId = '',
                private string $overlayIconUrl = '',
            ) {}
            public function getTitle(): string { return $this->title; }
            public function getSubtitle(): string { return $this->subtitle; }
            public function getLink(): string { return $this->link; }
            public function getIconUrl(): string { return $this->iconUrl; }
            public function getSinceId(): string { return $this->sinceId; }
            public function getOverlayIconUrl(): string { return $this->overlayIconUrl; }
        }
    }
    if (!class_exists(WidgetItems::class)) {
        class WidgetItems {
            public function __construct(
                private array $items = [],
                private string $emptyContentMessage = '',
                private string $halfEmptyContentMessage = '',
            ) {}
            public function getItems(): array { return $this->items; }
            public function getEmptyContentMessage(): string { return $this->emptyContentMessage; }
            public function getHalfEmptyContentMessage(): string { return $this->halfEmptyContentMessage; }
        }
    }
}
