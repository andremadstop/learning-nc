<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Oversight;
use OCA\Learning\Db\OversightMapper;
use OCA\Learning\Service\RoleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Wave-0 RED tests for RoleService::isTeamLeadForGroup / getTeamLeadGroups (RBAC-03).
 *
 * Strategy: inject a mocked OversightMapper so tests are fully unit-isolated.
 * The two positive-case tests (trueWhenRowExists, mappsOversightRows) are intentionally
 * RED at Wave 0 — skeleton methods return false/[] regardless of mock setup.
 * They flip GREEN in 163-03 when real queries are wired.
 *
 * The two false/empty cases (falseForForeignGroup, emptyWhenNoRows) are GREEN now and
 * must stay GREEN after 163-03 (skeleton returns same value as real impl for those inputs).
 */
class RoleServiceTest extends TestCase {

    private const COURSE_ID = 5;

    /** Construct a RoleService with all non-OversightMapper deps inert. */
    private function makeService(OversightMapper $oversightMapper): RoleService {
        return new RoleService(
            $this->createMock(IGroupManager::class),
            $this->createMock(IConfig::class),
            $this->createMock(IDBConnection::class),
            'learning',
            $oversightMapper
        );
    }

    // ── isTeamLeadForGroup ──────────────────────────────────────────────────

    /**
     * RBAC-03 (RED): isTeamLeadForGroup must return true when OversightMapper confirms
     * an exact (course, lead, group) row. Skeleton returns false → RED until 163-03.
     */
    public function testIsTeamLeadForGroupTrueWhenRowExists(): void {
        $oversight = $this->createMock(OversightMapper::class);
        $oversight->method('existsForLeadGroupCourse')
            ->with(self::COURSE_ID, 'lead1', 'dept-x')
            ->willReturn(true);

        $service = $this->makeService($oversight);

        $this->assertTrue(
            $service->isTeamLeadForGroup(self::COURSE_ID, 'lead1', 'dept-x'),
            'isTeamLeadForGroup must return true when oversight row exists — RED until 163-03'
        );
    }

    /**
     * RBAC-03 (GREEN): isTeamLeadForGroup must return false when OversightMapper finds no row.
     * Skeleton always returns false → GREEN now, must stay GREEN after 163-03.
     */
    public function testIsTeamLeadForGroupFalseForForeignGroup(): void {
        $oversight = $this->createMock(OversightMapper::class);
        $oversight->method('existsForLeadGroupCourse')->willReturn(false);

        $service = $this->makeService($oversight);

        $this->assertFalse(
            $service->isTeamLeadForGroup(self::COURSE_ID, 'lead1', 'wrong-dept'),
            'isTeamLeadForGroup must return false when no oversight row exists'
        );
    }

    /**
     * RBAC-03 (RED): wrong course — even though the lead has the group in another course,
     * isTeamLeadForGroup must return false for the wrong course.
     * Tests exact-triple semantics (course_id is part of the key).
     * RED until 163-03.
     */
    public function testIsTeamLeadForGroupFalseForWrongCourse(): void {
        $oversight = $this->createMock(OversightMapper::class);
        $oversight->method('existsForLeadGroupCourse')
            ->with(self::COURSE_ID, 'lead1', 'dept-x')
            ->willReturn(false); // wrong course_id
        $oversight->method('existsForLeadGroupCourse')
            ->with(99, 'lead1', 'dept-x')
            ->willReturn(true); // row exists for course 99, not for COURSE_ID

        $service = $this->makeService($oversight);

        $this->assertFalse(
            $service->isTeamLeadForGroup(self::COURSE_ID, 'lead1', 'dept-x'),
            'isTeamLeadForGroup must be course-scoped — false for wrong course_id'
        );
    }

    // ── getTeamLeadGroups ───────────────────────────────────────────────────

    /**
     * RBAC-03 (RED): getTeamLeadGroups must map OversightMapper::findByLead rows to
     * {course_id, group_id} structs. Skeleton returns [] → RED until 163-03.
     */
    public function testGetTeamLeadGroupsMapsOversightRows(): void {
        $row1 = new Oversight();
        $row1->setCourseId(self::COURSE_ID);
        $row1->setScopeGroupId('dept-a');

        $row2 = new Oversight();
        $row2->setCourseId(7);
        $row2->setScopeGroupId('dept-b');

        $oversight = $this->createMock(OversightMapper::class);
        $oversight->method('findByLead')
            ->with('lead1')
            ->willReturn([$row1, $row2]);

        $service = $this->makeService($oversight);
        $result = $service->getTeamLeadGroups('lead1');

        $this->assertCount(2, $result, 'should return one scope per oversight row — RED until 163-03');
        $this->assertSame(self::COURSE_ID, $result[0]['course_id']);
        $this->assertSame('dept-a', $result[0]['group_id']);
        $this->assertSame(7, $result[1]['course_id']);
        $this->assertSame('dept-b', $result[1]['group_id']);
    }

    /**
     * RBAC-03 (GREEN): getTeamLeadGroups returns [] when lead has no oversight rows.
     * Skeleton returns [] → GREEN now, must stay GREEN after 163-03.
     */
    public function testGetTeamLeadGroupsReturnsEmptyWhenNoRows(): void {
        $oversight = $this->createMock(OversightMapper::class);
        $oversight->method('findByLead')->willReturn([]);

        $service = $this->makeService($oversight);

        $this->assertSame([], $service->getTeamLeadGroups('unknown-lead'));
    }

    /**
     * RBAC-03 scope isolation: getTeamLeadGroups calls findByLead with the EXACT lead uid
     * (not a wildcard). Another lead's rows must not appear.
     * GREEN now (mock correctly wired), stays GREEN after 163-03.
     */
    public function testGetTeamLeadGroupsIsScopedToLead(): void {
        $row = new Oversight();
        $row->setCourseId(self::COURSE_ID);
        $row->setScopeGroupId('dept-a');

        $oversight = $this->createMock(OversightMapper::class);
        // Only lead1 gets a row; lead2 query returns []
        $oversight->method('findByLead')
            ->willReturnMap([
                ['lead1', [$row]],
                ['lead2', []],
            ]);

        $service = $this->makeService($oversight);

        $this->assertNotEmpty($service->getTeamLeadGroups('lead1'));
        $this->assertSame([], $service->getTeamLeadGroups('lead2'),
            'getTeamLeadGroups must be scoped to the given lead uid only'
        );
    }
}
