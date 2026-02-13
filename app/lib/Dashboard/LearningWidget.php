<?php
declare(strict_types=1);
namespace OCA\Learning\Dashboard;

use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDBConnection;
use OCP\IURLGenerator;

class LearningWidget implements IAPIWidgetV2, IIconWidget {
    private IDBConnection $db;
    private IURLGenerator $urlGenerator;

    public function __construct(IDBConnection $db, IURLGenerator $urlGenerator) {
        $this->db = $db;
        $this->urlGenerator = $urlGenerator;
    }

    public function getId(): string {
        return 'learning';
    }

    public function getTitle(): string {
        return 'Learning';
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-category-education';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->imagePath('core', 'actions/star.svg');
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('learning.page.index');
    }

    public function load(): void {
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $now = time();
        $items = [];
        $totalDue = 0;

        $qb = $this->db->getQueryBuilder();
        $qb->select('p.id', 'p.name', $qb->createFunction('COUNT(l.id) as due_count'))
           ->from('learning_leitner_items', 'l')
           ->innerJoin('l', 'learning_pools', 'p', 'l.pool_id = p.id')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($now)))
           ->groupBy('p.id', 'p.name')
           ->having($qb->createFunction('COUNT(l.id) > 0'))
           ->orderBy('due_count', 'DESC')
           ->setMaxResults($limit);

        $result = $qb->execute();
        $pools = $result->fetchAll();
        $result->closeCursor();

        $appUrl = $this->urlGenerator->linkToRouteAbsolute('learning.page.index');

        foreach ($pools as $pool) {
            $dueCount = (int)$pool['due_count'];
            $totalDue += $dueCount;
            $items[] = new WidgetItem(
                $pool['name'],
                $dueCount . ($dueCount === 1 ? ' question due' : ' questions due'),
                $appUrl,
                '',
                (string)$pool['id']
            );
        }

        if (count($items) < $limit) {
            $remaining = $limit - count($items);
            $poolIds = array_map(fn($p) => (int)$p['id'], $pools);

            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('p.id', 'p.name',
                   $qb2->createFunction('COUNT(l.id) as total'),
                   $qb2->createFunction('SUM(CASE WHEN l.box = 5 THEN 1 ELSE 0 END) as mastered'))
               ->from('learning_leitner_items', 'l')
               ->innerJoin('l', 'learning_pools', 'p', 'l.pool_id = p.id')
               ->where($qb2->expr()->eq('l.user_id', $qb2->createNamedParameter($userId)))
               ->groupBy('p.id', 'p.name')
               ->setMaxResults($remaining);

            if (!empty($poolIds)) {
                $qb2->andWhere($qb2->expr()->notIn('p.id', $qb2->createNamedParameter($poolIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
            }

            $result2 = $qb2->execute();
            $extraPools = $result2->fetchAll();
            $result2->closeCursor();

            foreach ($extraPools as $pool) {
                $total = (int)$pool['total'];
                $mastered = (int)$pool['mastered'];
                $pct = $total > 0 ? round($mastered / $total * 100) : 0;
                $items[] = new WidgetItem(
                    $pool['name'],
                    $pct . '% mastered (' . $mastered . '/' . $total . ')',
                    $appUrl,
                    '',
                    (string)$pool['id']
                );
            }
        }

        $emptyMsg = $totalDue > 0
            ? ''
            : 'All caught up! No questions due right now.';

        return new WidgetItems($items, $emptyMsg);
    }
}
