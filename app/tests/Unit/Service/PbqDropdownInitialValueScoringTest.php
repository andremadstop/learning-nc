<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\TrainingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit #186: a dropdown row shows its preset initial_value as selected, but only a change is
 * submitted. An untouched row whose initial_value is the correct answer scored 0 — unlike
 * switch_config/routing_config, which fall back to 'initial'. Both scorers (Training, Leitner).
 */
final class PbqDropdownInitialValueScoringTest extends TestCase {

    /** @return array<string, array{class-string}> */
    public static function scorers(): array {
        return [
            'training' => [TrainingService::class],
            'leitner' => [LeitnerService::class],
        ];
    }

    /**
     * @param class-string $class
     * @return array{0: bool, 1: int, 2: int}
     */
    private function score(string $class, string $subtype, array $config, array $answers): array {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($class, 'scorePbqAnswer');
        /** @var array{0: bool, 1: int, 2: int} $result */
        $result = $method->invoke($service, $subtype, $config, $answers);
        return $result;
    }

    #[DataProvider('scorers')]
    public function testUntouchedDropdownRowAnswersWithItsInitialValue(string $class): void {
        $config = ['questions' => [
            ['id' => 'q1', 'options' => ['on', 'off'], 'initial_value' => 'on', 'correct' => 'on'],
            ['id' => 'q2', 'options' => ['on', 'off'], 'initial_value' => 'on', 'correct' => 'off'],
        ]];

        [, $points, $max] = $this->score($class, 'dropdown', $config, []);
        $this->assertSame([1, 2], [$points, $max]);

        // a submitted value still wins over the preset
        [, $points] = $this->score($class, 'dropdown', $config, ['q1' => 'off', 'q2' => 'off']);
        $this->assertSame(1, $points);
    }

    #[DataProvider('scorers')]
    public function testUntouchedMultiPanelDropdownRowAnswersWithItsInitialValue(string $class): void {
        $config = ['dropdown' => ['questions' => [
            ['id' => 'q1', 'options' => ['on', 'off'], 'initial_value' => 'on', 'correct' => 'on'],
        ]]];

        [, $points, $max] = $this->score($class, 'multi_panel', $config, []);
        $this->assertSame([1, 1], [$points, $max]);
    }

    #[DataProvider('scorers')]
    public function testRowWithoutPresetOrAnswerScoresNothing(string $class): void {
        $config = ['questions' => [['id' => 'q1', 'options' => ['a', 'b'], 'correct' => 'a']]];

        [, $points, $max] = $this->score($class, 'dropdown', $config, []);
        $this->assertSame([0, 1], [$points, $max]);
    }
}
