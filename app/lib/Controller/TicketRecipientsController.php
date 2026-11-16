<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\TechnicalTicketRecipients;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * GitHub #59: who is told of a new technical ticket (the admin inbox). Picked from Nextcloud's
 * accounts, like the instructors (#60). The search offers administrators only: the notice
 * links to the admin inbox and names the ticket's subject, and only an administrator may open it.
 *
 * Admin-only. The routes carry no @NoAdminRequired, and every method checks again.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class TicketRecipientsController extends Controller {
    private const SEARCH_LIMIT = 20;
    private const MAX_SEARCH_LENGTH = 64;
    private const ADMIN_GROUP = 'admin';

    public function __construct(
        string $appName,
        IRequest $request,
        private TechnicalTicketRecipients $recipients,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The chosen people, with their display names and whether they would get the notice.
     *
     * @return DataResponse<Http::STATUS_OK, array{users: list<array{id: string, label: string, notified: bool}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    public function get(): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return new DataResponse(['users' => $this->describe($this->recipients->get())]);
    }

    /**
     * @param list<string> $userIds the chosen accounts; an empty list means nobody is notified
     * @return DataResponse<Http::STATUS_OK, array{users: list<array{id: string, label: string, notified: bool}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function save(array $userIds = []): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return new DataResponse(['users' => $this->describe($this->recipients->save($userIds))]);
    }

    /**
     * Administrators whose name or id matches, for the picker.
     *
     * @param string $search the typed text
     * @return DataResponse<Http::STATUS_OK, array{users: list<array{id: string, label: string}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $search = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        $term = mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH);
        if ($term === '') {
            return new DataResponse(['users' => []]);
        }
        $users = [];
        $admins = $this->groupManager->get(self::ADMIN_GROUP);
        foreach ($admins?->searchUsers($term, self::SEARCH_LIMIT) ?? [] as $user) {
            $id = $user->getUID();
            $users[] = ['id' => $id, 'label' => $this->label($user->getDisplayName(), $id)];
        }
        return new DataResponse(['users' => array_slice($users, 0, self::SEARCH_LIMIT)]);
    }

    /**
     * An id that does not resolve (deleted, or out of reach of LDAP) is shown as it is, so the
     * admin can see and remove it; notified = false says it gets no notice as things are.
     *
     * @param list<string> $userIds
     * @return list<array{id: string, label: string, notified: bool}>
     */
    private function describe(array $userIds): array {
        $out = [];
        foreach ($userIds as $id) {
            $user = $this->userManager->get($id);
            $out[] = [
                'id' => $id,
                'label' => $this->label($user?->getDisplayName(), $id),
                'notified' => $user !== null && $user->isEnabled() && $this->groupManager->isAdmin($id),
            ];
        }
        return $out;
    }

    private function label(?string $name, string $id): string {
        return is_string($name) && trim($name) !== '' ? $name : $id;
    }

    private function isAdmin(): bool {
        return $this->userId !== null && $this->userId !== '' && $this->groupManager->isAdmin($this->userId);
    }

    /** @return DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}> */
    private function forbidden(): DataResponse {
        return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
    }
}
