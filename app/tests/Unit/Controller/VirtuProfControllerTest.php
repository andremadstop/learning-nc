<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Phase 153 Plan 02 Wave-0 scaffold for MIGR-01 / MIGR-02.
 *
 * Documents the first-touch-coercion contract that VirtuProfController::getSkin()
 * must satisfy after Plan 03 lands the rewrite (RESEARCH.md Pattern 1):
 *
 *   1. Existing user with at least one `learning.*` user_config key but no
 *      `virtuprof_skin` row → write 'nova' (Zero-Change-Default for legacy
 *      users; existing user just never picked a skin).
 *
 *   2. Brand-new user with zero `learning.*` user_config keys → write
 *      'prof_lern_classic' (new-user default per v4.4.0 milestone decision).
 *
 *   3. User with an existing `virtuprof_skin` row → return the row value
 *      directly without scanning getUserKeys (fast path).
 *
 * The three test methods below are MARKED SKIPPED because Plan 02 only ships
 * the scaffold — the production getSkin() rewrite lands in Plan 03. Plan 03's
 * <done> criteria includes removing the `markTestSkipped()` calls and binding
 * the mocks to a real instantiated VirtuProfController.
 *
 * Why this file lands skipped (not RED): the v4.4.0 milestone's pre-push gate
 * is "all tests GREEN, no permanent RED". A skipped test discovers + reports
 * "skipped (1)" rather than "failure (1)" — keeps the suite green while still
 * committing a reviewable scaffold that Plan 03 picks up verbatim.
 *
 * @group migr-01-02-pending
 */
class VirtuProfControllerTest extends TestCase {
	public function testGetSkin_existingUser_resolvesNova(): void {
		$this->markTestSkipped(
			'Pending Plan 03 implementation. Scaffold contract: existing user '
			. '(any learning.* user_config key present) with no virtuprof_skin '
			. 'row → first-touch-coercion writes "nova" via setUserValue.'
		);

		// Plan 03 fills this in:
		// $configMock = $this->createMock(\OCP\IConfig::class);
		// $configMock->method('getUserValue')->willReturn(''); // sentinel
		// $configMock->method('getUserKeys')
		//     ->with('user-A', 'learning')
		//     ->willReturn(['consent_ai', 'interface_language']);
		// $configMock->expects($this->once())
		//     ->method('setUserValue')
		//     ->with('user-A', 'learning', 'virtuprof_skin', 'nova');
		// // ... instantiate VirtuProfController with mocked dependencies
		// $payload = $controller->state();
		// $this->assertSame('nova', $payload['skin']);
	}

	public function testGetSkin_newUser_resolvesProfLernClassic(): void {
		$this->markTestSkipped(
			'Pending Plan 03 implementation. Scaffold contract: brand-new user '
			. '(zero learning.* user_config keys) → first-touch-coercion writes '
			. '"prof_lern_classic" via setUserValue.'
		);

		// Plan 03 fills this in:
		// $configMock = $this->createMock(\OCP\IConfig::class);
		// $configMock->method('getUserValue')->willReturn(''); // sentinel
		// $configMock->method('getUserKeys')
		//     ->with('user-B', 'learning')
		//     ->willReturn([]);
		// $configMock->expects($this->once())
		//     ->method('setUserValue')
		//     ->with('user-B', 'learning', 'virtuprof_skin', 'prof_lern_classic');
		// $payload = $controller->state();
		// $this->assertSame('prof_lern_classic', $payload['skin']);
	}

	public function testGetSkin_existingRow_returnsRowValueWithoutKeyScan(): void {
		$this->markTestSkipped(
			'Pending Plan 03 implementation. Scaffold contract: user with an '
			. 'existing virtuprof_skin row → fast path returns the row value '
			. 'directly without calling getUserKeys.'
		);

		// Plan 03 fills this in:
		// $configMock = $this->createMock(\OCP\IConfig::class);
		// $configMock->method('getUserValue')->willReturn('kosmologe');
		// $configMock->expects($this->never())->method('getUserKeys'); // fast path
		// $payload = $controller->state();
		// $this->assertSame('kosmologe', $payload['skin']);
	}
}
