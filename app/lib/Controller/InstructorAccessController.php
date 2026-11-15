<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * GitHub #60: who counts as an instructor app-wide (may create courses and question pools):
 * several Nextcloud groups and individual people, picked from Nextcloud's own groups and
 * accounts instead of one typed group name.
 *
 * Admin-only. The routes carry no @NoAdminRequired, and every method checks again, so a
 * route or annotation change cannot open the instructor list or the account search.
 */
class InstructorAccessController extends Controller {
    private const SEARCH_LIMIT = 20;
    private const MAX_SEARCH_LENGTH = 64;

    public function __construct(
        string $appName,
        IRequest $request,
        private RoleService $roleService,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    public function get(): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return new DataResponse($this->describe(
            $this->roleService->getInstructorGroups(),
            $this->roleService->getInstructorUsers()
        ));
    }

    /**
     * @param array<mixed> $groupIds
     * @param array<mixed> $userIds
     */
    public function save(array $groupIds = [], array $userIds = []): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        $saved = $this->roleService->saveInstructors($groupIds, $userIds);
        return new DataResponse($this->describe($saved['groups'], $saved['users']));
    }

    /**
     * Groups and accounts for the picker. Uses the group and user managers, not the sharing
     * search: "restrict sharing to own groups" must not hide groups from the administrator.
     */
    public function search(string $search = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        $term = mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH);
        if ($term === '') {
            return new DataResponse(['users' => [], 'groups' => []]);
        }

        $groups = [];
        foreach ($this->groupManager->search($term, self::SEARCH_LIMIT) as $group) {
            $id = $group->getGID();
            $groups[] = ['id' => $id, 'label' => $this->label($group->getDisplayName(), $id)];
        }
        $users = [];
        foreach ($this->userManager->searchDisplayName($term, self::SEARCH_LIMIT) as $user) {
            $id = $user->getUID();
            $users[] = ['id' => $id, 'label' => $this->label($user->getDisplayName(), $id)];
        }

        return new DataResponse([
            'users' => array_slice($users, 0, self::SEARCH_LIMIT),
            'groups' => array_slice($groups, 0, self::SEARCH_LIMIT),
        ]);
    }

    /**
     * Ids with their display names. An id that does not resolve (deleted, or an LDAP group out
     * of reach) is shown as it is, so the admin can see and remove it.
     *
     * @param list<string> $groupIds
     * @param list<string> $userIds
     * @return array{groups: list<array{id: string, label: string}>, users: list<array{id: string, label: string}>}
     */
    private function describe(array $groupIds, array $userIds): array {
        $groups = [];
        foreach ($groupIds as $id) {
            $group = $this->groupManager->get($id);
            $groups[] = ['id' => $id, 'label' => $this->label($group?->getDisplayName(), $id)];
        }
        $users = [];
        foreach ($userIds as $id) {
            $user = $this->userManager->get($id);
            $users[] = ['id' => $id, 'label' => $this->label($user?->getDisplayName(), $id)];
        }
        return ['groups' => $groups, 'users' => $users];
    }

    private function label(?string $name, string $id): string {
        return is_string($name) && trim($name) !== '' ? $name : $id;
    }

    private function isAdmin(): bool {
        return $this->userId !== null && $this->userId !== '' && $this->groupManager->isAdmin($this->userId);
    }

    private function forbidden(): DataResponse {
        return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
    }
}
