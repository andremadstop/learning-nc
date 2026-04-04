<?php
declare(strict_types=1);

namespace OCA\Learning\Search;

use OCA\Learning\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class PoolSearchProvider implements IProvider {
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;

    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator, IDBConnection $db) {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
    }

    public function getId(): string {
        return Application::APP_ID . '_pools';
    }

    public function getName(): string {
        return $this->l10n->t('Pools');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return str_starts_with($route, Application::APP_ID . '.') ? -10 : 45;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = $this->resolveTerm($query);
        if (mb_strlen($term) < 2) {
            return SearchResult::complete($this->getName(), []);
        }

        $offset = max(0, (int)($query->getCursor() ?? 0));
        $limit = max(1, min(50, (int)$query->getLimit()));
        $entries = [];

        foreach ($this->findPools($term, $user->getUID(), $limit, $offset) as $row) {
            $entry = new SearchResultEntry(
                $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
                (string)$row['name'],
                $this->buildSubline($row),
                $this->buildPoolUrl((int)$row['id'])
            );

            if (method_exists($entry, 'addAttribute')) {
                $entry->addAttribute('type', 'pool');
                $entry->addAttribute('poolId', (string)$row['id']);
            }

            $entries[] = $entry;
        }

        $nextCursor = count($entries) === $limit ? $offset + $limit : null;
        return SearchResult::paginated($this->getName(), $entries, $nextCursor);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findPools(string $term, string $userId, int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $like = '%' . $this->db->escapeLikeParameter($term) . '%';

        $qb->selectDistinct('p.id')
            ->addSelect('p.name', 'p.description', 'p.handbook_title', 'p.chapter_title')
            ->from('learning_pools', 'p')
            ->leftJoin('p', 'learning_pool_shares', 's', $expr->andX(
                $expr->eq('s.pool_id', 'p.id'),
                $expr->eq('s.shared_with', $qb->createNamedParameter($userId)),
                $expr->eq('s.share_type', $qb->createNamedParameter('user'))
            ))
            ->leftJoin('p', 'learning_course_pools', 'cp', $expr->eq('cp.pool_id', 'p.id'))
            ->leftJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
            ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
                $expr->eq('cm.course_id', 'c.id'),
                $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
            ))
            ->where($expr->orX(
                $expr->eq('p.user_id', $qb->createNamedParameter($userId)),
                $expr->isNotNull('s.id'),
                $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
                $expr->isNotNull('cm.id')
            ))
            ->andWhere($expr->orX(
                $expr->iLike('p.name', $qb->createNamedParameter($like)),
                $expr->iLike('p.description', $qb->createNamedParameter($like))
            ))
            ->orderBy('p.updated_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    private function resolveTerm(ISearchQuery $query): string {
        if (method_exists($query, 'getTerm')) {
            return trim((string)$query->getTerm());
        }

        if (method_exists($query, 'getFilter')) {
            $termFilter = $query->getFilter('term');
            if ($termFilter !== null && method_exists($termFilter, 'get')) {
                return trim((string)$termFilter->get());
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildSubline(array $row): string {
        $parts = [];
        $description = trim((string)($row['description'] ?? ''));
        if ($description !== '') {
            $parts[] = mb_substr($description, 0, 120);
        }
        $handbookTitle = trim((string)($row['handbook_title'] ?? ''));
        if ($handbookTitle !== '') {
            $parts[] = $handbookTitle;
        }
        $chapterTitle = trim((string)($row['chapter_title'] ?? ''));
        if ($chapterTitle !== '') {
            $parts[] = $chapterTitle;
        }

        return $parts !== []
            ? implode(' · ', array_slice($parts, 0, 2))
            : $this->l10n->t('Open pool in Learning');
    }

    private function buildPoolUrl(int $poolId): string {
        $base = rtrim($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'), '/');
        return $base . '/pools?poolId=' . rawurlencode((string)$poolId);
    }
}
