<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\DauBotService;
use PHPUnit\Framework\TestCase;

/**
 * Built-in bot-correction scenes list the correct option first in every catalog template.
 * The scenario must not hand that order to the learner, or "top option twice" always passes.
 * The order stays deterministic per node so save/resume shows the same scene.
 */
final class DauBotOptionOrderTest extends TestCase {
    private const SCENE_TYPES = ['default', 'firewall', 'dns', 'routing', 'nat', 'ghostline'];

    public function testCorrectOptionIsNotAlwaysFirst(): void {
        $service = new DauBotService();
        $errorFirst = 0;
        $fixFirst = 0;
        $total = 0;
        foreach (self::SCENE_TYPES as $type) {
            for ($i = 0; $i < 20; $i++) {
                $scenario = $service->buildBotCorrectionScenario(['id' => $type . '-node-' . $i, 'type' => $type], []);
                $total++;
                $errorFirst += $scenario['error_options'][0]['id'] === $scenario['expected_error_id'] ? 1 : 0;
                $fixFirst += $scenario['fix_options'][0]['id'] === $scenario['expected_fix_id'] ? 1 : 0;
            }
        }

        $this->assertLessThan($total, $errorFirst, 'expected error option is first in every scene');
        $this->assertLessThan($total, $fixFirst, 'expected fix option is first in every scene');
        // roughly a third with three options; far from "always"
        $this->assertLessThan(intdiv($total * 2, 3), $errorFirst);
        $this->assertLessThan(intdiv($total * 2, 3), $fixFirst);
    }

    public function testOrderIsStablePerNodeAndKeepsAllOptions(): void {
        $service = new DauBotService();
        foreach (self::SCENE_TYPES as $type) {
            $node = ['id' => $type . '-resume', 'type' => $type];
            $first = $service->buildBotCorrectionScenario($node, []);
            $second = $service->buildBotCorrectionScenario($node, []);

            $this->assertSame($first['error_options'], $second['error_options']);
            $this->assertSame($first['fix_options'], $second['fix_options']);
            $ids = array_column($first['error_options'], 'id');
            $this->assertContains($first['expected_error_id'], $ids);
            $this->assertCount(count(array_unique($ids)), $ids);
            $this->assertContains($first['expected_fix_id'], array_column($first['fix_options'], 'id'));
        }
    }
}
