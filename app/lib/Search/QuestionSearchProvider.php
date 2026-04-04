<?php
declare(strict_types=1);

namespace OCA\Learning\Search;

use OCA\Learning\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class QuestionSearchProvider implements IProvider {
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;

    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator, IDBConnection $db) {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
    }

    public function getId(): string {
        return Application::APP_ID . '_questions';
    }

    public function getName(): string {
        return $this->l10n->t('Questions');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return str_starts_with($route, Application::APP_ID . '.') ? -5 : 50;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = $this->resolveTerm($query);
        if (mb_strlen($term) < 2) {
            return SearchResult::complete($this->getName(), []);
        }

        $offset = max(0, (int)($query->getCursor() ?? 0));
        $limit = max(1, min(50, (int)$query->getLimit()));
        $entries = [];

        foreach ($this->findQuestions($term, $user->getUID(), $limit, $offset) as $row) {
            $entry = new SearchResultEntry(
                $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
                $this->buildTitle($row),
                $this->buildSubline($row),
                $this->buildPoolUrl((int)$row['pool_id'])
            );

            if (method_exists($entry, 'addAttribute')) {
                $entry->addAttribute('type', 'question');
                $entry->addAttribute('questionId', (string)$row['id']);
                $entry->addAttribute('poolId', (string)$row['pool_id']);
            }

            $entries[] = $entry;
        }

        $nextCursor = count($entries) === $limit ? $offset + $limit : null;
        return SearchResult::paginated($this->getName(), $entries, $nextCursor);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findQuestions(string $term, string $userId, int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $like = '%' . $this->db->escapeLikeParameter($term) . '%';

        $qb->selectDistinct('q.id')
            ->addSelect('q.pool_id', 'q.text', 'q.explanation', 'q.chapter_title', 'q.exam_key', 'p.name AS pool_name')
            ->from('learning_questions', 'q')
            ->innerJoin('q', 'learning_pools', 'p', $expr->eq('q.pool_id', 'p.id'))
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
                $expr->eq('q.user_id', $qb->createNamedParameter($userId)),
                $expr->isNotNull('s.id'),
                $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
                $expr->isNotNull('cm.id')
            ))
            ->andWhere($expr->orX(
                $expr->iLike('q.text', $qb->createNamedParameter($like)),
                $expr->iLike('q.explanation', $qb->createNamedParameter($like))
            ))
            ->orderBy('q.updated_at', 'DESC')
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
    private function buildTitle(array $row): string {
        return mb_substr(trim((string)($row['text'] ?? '')), 0, 120);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildSubline(array $row): string {
        $parts = [];
        $poolName = trim((string)($row['pool_name'] ?? ''));
        if ($poolName !== '') {
            $parts[] = $poolName;
        }
        $chapterTitle = trim((string)($row['chapter_title'] ?? ''));
        if ($chapterTitle !== '') {
            $parts[] = $chapterTitle;
        }
        $examKey = trim((string)($row['exam_key'] ?? ''));
        if ($examKey !== '') {
            $parts[] = $examKey;
        }
        $explanation = trim((string)($row['explanation'] ?? ''));
        if ($explanation !== '') {
            $parts[] = mb_substr($explanation, 0, 100);
        }

        return $parts !== []
            ? implode(' · ', array_slice($parts, 0, 3))
            : $this->l10n->t('Open question in Learning');
    }

    private function buildPoolUrl(int $poolId): string {
        $base = rtrim($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'), '/');
        return $base . '/pools?poolId=' . rawurlencode((string)$poolId);
    }
}
