<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\StoryProgress;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit: the skill-check questions a scene offered are remembered in choices_json, so the
 * submit endpoints can refuse every other question ID. The marker entry must never reach the
 * readers of the choice history (API, narrator prompts, score), and writing the history back
 * (advanceProgress) must drop it.
 */
final class StoryProgressTest extends TestCase {
	public function testPendingSkillQuestionsRoundTripWithoutEnteringTheHistory(): void {
		$progress = new StoryProgress();
		$history = [['scene_id' => 's1', 'choice_id' => 'c1', 'skill_result' => 'success', 'correct_count' => 2, 'total_count' => 3]];
		$progress->setChoicesJson(json_encode($history));

		$progress->setPendingSkill(['scene_id' => 's2', 'choices' => ['c2' => [11, 12, 13]]]);

		$this->assertSame($history, $progress->getChoicesDecoded());
		$this->assertSame(['scene_id' => 's2', 'choices' => ['c2' => [11, 12, 13]]], $progress->getPendingSkill());
	}

	public function testReplacingThePendingSetKeepsOnlyOneMarker(): void {
		$progress = new StoryProgress();
		$progress->setChoicesJson('[]');

		$progress->setPendingSkill(['scene_id' => 's1', 'choices' => ['a' => [1]]]);
		$progress->setPendingSkill(['scene_id' => 's1', 'choices' => ['a' => [2]]]);

		$this->assertSame(['scene_id' => 's1', 'choices' => ['a' => [2]]], $progress->getPendingSkill());
		$this->assertCount(1, json_decode((string)$progress->getChoicesJson(), true));
		$this->assertSame([], $progress->getChoicesDecoded());
	}

	public function testWritingTheHistoryBackDropsThePendingSet(): void {
		$progress = new StoryProgress();
		$progress->setChoicesJson('[]');
		$progress->setPendingSkill(['scene_id' => 's1', 'choices' => ['a' => [1]]]);

		// What advanceProgress() does: decode, append, encode.
		$choices = $progress->getChoicesDecoded();
		$choices[] = ['scene_id' => 's1', 'choice_id' => 'a'];
		$progress->setChoicesJson(json_encode($choices));

		$this->assertNull($progress->getPendingSkill());
		$this->assertSame([['scene_id' => 's1', 'choice_id' => 'a']], $progress->getChoicesDecoded());
	}

	public function testALegacyHistoryHasNoPendingSet(): void {
		$progress = new StoryProgress();
		$this->assertNull($progress->getPendingSkill());
		$progress->setChoicesJson(json_encode([['scene_id' => 's1', 'choice_id' => 'a']]));
		$this->assertNull($progress->getPendingSkill());
	}
}
