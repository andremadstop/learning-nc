<?php
declare(strict_types=1);

namespace OCP {
    if (!interface_exists(IURLGenerator::class)) {
        interface IURLGenerator {
            public function linkToRouteAbsolute(string $route, array $params = []): string;
        }
    }
}

namespace Sabre\VObject\Component {
    if (!class_exists(VCalendar::class)) {
        final class VProperty implements \ArrayAccess {
            private mixed $value;
            private array $parameters = [];

            public function __construct(mixed $value) {
                $this->value = $value;
            }

            public function offsetExists(mixed $offset): bool {
                return isset($this->parameters[(string)$offset]);
            }

            public function offsetGet(mixed $offset): mixed {
                return $this->parameters[(string)$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void {
                $this->parameters[(string)$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void {
                unset($this->parameters[(string)$offset]);
            }

            public function serialize(string $name): string {
                $parameterString = '';
                foreach ($this->parameters as $key => $value) {
                    $parameterString .= sprintf(';%s=%s', $key, $value);
                }

                $value = $this->value;
                if ($value instanceof \DateTimeInterface) {
                    $isDate = ($this->parameters['VALUE'] ?? null) === 'DATE';
                    $value = $isDate
                        ? $value->setTimezone(new \DateTimeZone('UTC'))->format('Ymd')
                        : $value->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
                }

                return sprintf(
                    '%s%s:%s',
                    $name,
                    $parameterString,
                    str_replace("\n", '\n', (string)$value)
                );
            }
        }

        final class VEvent {
            /** @var array<string, VProperty> */
            private array $properties = [];

            public function __construct(array $properties = []) {
                foreach ($properties as $name => $value) {
                    $this->__set((string)$name, $value);
                }
            }

            public function __set(string $name, mixed $value): void {
                $this->properties[$name] = new VProperty($value);
            }

            public function __get(string $name): VProperty {
                if (!isset($this->properties[$name])) {
                    $this->properties[$name] = new VProperty('');
                }

                return $this->properties[$name];
            }

            public function add(string $name, mixed $value): void {
                $this->__set($name, $value);
            }

            /**
             * @return string[]
             */
            public function serialize(): array {
                $lines = ['BEGIN:VEVENT'];
                foreach ($this->properties as $name => $property) {
                    $lines[] = $property->serialize($name);
                }
                $lines[] = 'END:VEVENT';

                return $lines;
            }
        }

        final class VCalendar {
            /** @var array<string, VProperty> */
            private array $properties = [];

            /** @var list<VEvent> */
            private array $events = [];

            public function __set(string $name, mixed $value): void {
                $this->properties[$name] = new VProperty($value);
            }

            public function add(string $name, array $properties = []): VEvent {
                if ($name !== 'VEVENT') {
                    throw new \InvalidArgumentException('Only VEVENT is supported in test stub');
                }

                $event = new VEvent($properties);
                $this->events[] = $event;

                return $event;
            }

            public function serialize(): string {
                $lines = ['BEGIN:VCALENDAR'];
                foreach ($this->properties as $name => $property) {
                    $lines[] = $property->serialize($name);
                }
                foreach ($this->events as $event) {
                    $lines = array_merge($lines, $event->serialize());
                }
                $lines[] = 'END:VCALENDAR';

                return implode("\r\n", $lines) . "\r\n";
            }
        }
    }
}

namespace OCA\Learning\Tests\Unit\Service {

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class IcsServiceTest extends TestCase {
    public function testEnsureCalendarTokenGeneratesTokenAndUrlWhenMissing(): void {
        $entity = new UserTelos();
        $entity->setId(42);
        $entity->setUserId('alice');

        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->expects($this->once())
            ->method('findByUserIdOrNull')
            ->with('alice')
            ->willReturn($entity);
        $mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (UserTelos $updated): UserTelos {
                return $updated;
            });

        $urlGenerator = $this->createUrlGenerator();
        $service = new IcsService(new FakeDbConnection(), $mapper, $urlGenerator);

        $result = $service->ensureCalendarToken('alice');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['token']);
        $this->assertSame(
            'https://cloud.example/apps/learning/api/ics/' . $result['token'],
            $result['url']
        );
    }

    public function testEnsureCalendarTokenReturnsExistingTokenWithoutWritingAgain(): void {
        $token = str_repeat('a', 64);
        $entity = new UserTelos();
        $entity->setId(7);
        $entity->setUserId('alice');
        $entity->setIcsToken($token);

        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->expects($this->once())
            ->method('findByUserIdOrNull')
            ->with('alice')
            ->willReturn($entity);
        $mapper->expects($this->never())->method('insert');
        $mapper->expects($this->never())->method('update');

        $service = new IcsService(new FakeDbConnection(), $mapper, $this->createUrlGenerator());

        $result = $service->ensureCalendarToken('alice');

        $this->assertSame($token, $result['token']);
        $this->assertSame('https://cloud.example/apps/learning/api/ics/' . $token, $result['url']);
    }

    public function testEnsureCalendarTokenUsesSixtyFourCharacterHexFormat(): void {
        $entity = new UserTelos();
        $entity->setId(9);
        $entity->setUserId('alice');

        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->method('findByUserIdOrNull')->willReturn($entity);
        $mapper->method('update')->willReturnCallback(static function (UserTelos $updated): UserTelos {
            return $updated;
        });

        $service = new IcsService(new FakeDbConnection(), $mapper, $this->createUrlGenerator());

        $token = $service->ensureCalendarToken('alice')['token'];

        $this->assertSame(64, strlen($token));
        $this->assertSame(1, preg_match('/^[a-f0-9]+$/', $token));
    }

    public function testRenderCalendarForUserReturnsVcalendarSkeletonWhenQueueIsEmpty(): void {
        $builder = new FakeQueryBuilder(new FakeResult());
        $service = $this->createServiceWithRows($builder);

        $calendar = $service->renderCalendarForUser('alice');

        $this->assertStringContainsString('BEGIN:VCALENDAR', $calendar);
        $this->assertStringContainsString('END:VCALENDAR', $calendar);
        $this->assertSame(0, substr_count($calendar, 'BEGIN:VEVENT'));
    }

    public function testRenderCalendarForUserIncludesCalendarMetadata(): void {
        $builder = new FakeQueryBuilder(new FakeResult());
        $service = $this->createServiceWithRows($builder);

        $calendar = $service->renderCalendarForUser('alice');

        $this->assertStringContainsString('PRODID:-//Learning-NC//Leitner Repetition//DE', $calendar);
        $this->assertStringContainsString('VERSION:2.0', $calendar);
        $this->assertStringContainsString('X-WR-CALNAME:Leitner-Wiederholungen', $calendar);
        $this->assertStringContainsString('X-WR-CALDESC:Faellige Leitner-Wiederholungen nach Kurskontext', $calendar);
    }

    public function testRenderCalendarForUserAggregatesDueCardsIntoGroupedEvents(): void {
        $todayStart = $this->todayStart();
        $nextReview = $todayStart + (10 * 86400) + 3600;
        $dueDate = gmdate('Ymd', $nextReview);
        $endDate = gmdate('Ymd', $nextReview + 86400);
        $rows = [
            [
                'course_id' => 2,
                'course_title' => 'Network+',
                'box' => 3,
                'next_review' => $nextReview,
            ],
            [
                'course_id' => 2,
                'course_title' => 'Network+',
                'box' => 3,
                'next_review' => $nextReview,
            ],
        ];

        $service = $this->createServiceWithRows(new FakeQueryBuilder(new FakeResult($rows)));

        $calendar = $service->renderCalendarForUser('alice');

        $this->assertSame(1, substr_count($calendar, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:Leitner-Wiederholung: Network+', $calendar);
        $this->assertStringContainsString(
            'DESCRIPTION:2 Karten faellig aus Box 3.\nDirekt zum Lernraum: https://cloud.example/apps/learning/',
            $calendar
        );
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . $dueDate, $calendar);
        $this->assertStringContainsString('DTEND;VALUE=DATE:' . $endDate, $calendar);
        $this->assertStringContainsString('URL:https://cloud.example/apps/learning/', $calendar);
    }

    /**
     * Audit #54: the feed dated cards by fixed box intervals (box 3 = last_reviewed + 7 days) and
     * left boxes 1-2 out, while the queue makes a card due at next_review (the FSRS interval).
     */
    public function testRenderCalendarForUserDatesEveryBoxByNextReview(): void {
        $todayStart = $this->todayStart();
        $rows = [
            // box 3 with an FSRS interval of 2 days: due in 2 days, not 7
            ['course_id' => 2, 'course_title' => 'Network+', 'box' => 3, 'next_review' => $todayStart + (2 * 86400) + 60],
            // box 1, overdue: today
            ['course_id' => 2, 'course_title' => 'Network+', 'box' => 1, 'next_review' => $todayStart - (3 * 86400)],
        ];
        $builder = new FakeQueryBuilder(new FakeResult($rows));
        $service = $this->createServiceWithRows($builder);

        $calendar = $service->renderCalendarForUser('alice');

        $this->assertSame(2, substr_count($calendar, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . gmdate('Ymd', $todayStart + (2 * 86400)), $calendar);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . gmdate('Ymd', $todayStart), $calendar);
        $this->assertStringContainsString('aus Box 1.', $calendar);
        foreach ($builder->namedParameters as $parameter) {
            $this->assertNotSame(IQueryBuilder::PARAM_INT_ARRAY, $parameter['type'], 'no box filter');
        }
    }

    public function testRenderCalendarForTokenReturnsNullForMalformedToken(): void {
        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->expects($this->never())->method('findByIcsTokenOrNull');

        $service = new IcsService(new FakeDbConnection(), $mapper, $this->createUrlGenerator());

        $this->assertNull($service->renderCalendarForToken('not-a-valid-token'));
    }

    public function testRenderCalendarForTokenReturnsNullWhenTokenIsUnknown(): void {
        $token = str_repeat('b', 64);
        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->expects($this->once())
            ->method('findByIcsTokenOrNull')
            ->with($token)
            ->willReturn(null);

        $service = new IcsService(new FakeDbConnection(), $mapper, $this->createUrlGenerator());

        $this->assertNull($service->renderCalendarForToken($token));
    }

    public function testRenderCalendarForTokenReturnsEmptyCalendarWhenUserHasNoDueRows(): void {
        $token = str_repeat('c', 64);
        $entity = new UserTelos();
        $entity->setId(5);
        $entity->setUserId('alice');
        $entity->setIcsToken($token);

        $mapper = $this->createMock(UserTelosMapper::class);
        $mapper->expects($this->once())
            ->method('findByIcsTokenOrNull')
            ->with($token)
            ->willReturn($entity);

        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult())]);
        $service = new IcsService($db, $mapper, $this->createUrlGenerator());

        $calendar = $service->renderCalendarForToken($token);

        $this->assertIsString($calendar);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $calendar);
        $this->assertSame(0, substr_count($calendar, 'BEGIN:VEVENT'));
    }

    private function createServiceWithRows(FakeQueryBuilder $builder): IcsService {
        return new IcsService(
            new FakeDbConnection([$builder]),
            $this->createMock(UserTelosMapper::class),
            $this->createUrlGenerator()
        );
    }

    private function createUrlGenerator(): IURLGenerator {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')
            ->willReturnCallback(static function (string $route, array $params = []): string {
                if ($route === 'learning.ics.feed') {
                    return 'https://cloud.example/apps/learning/api/ics/' . ($params['token'] ?? '');
                }

                if ($route === 'learning.page.index') {
                    return 'https://cloud.example/apps/learning/';
                }

                return 'https://cloud.example/' . $route;
            });

        return $urlGenerator;
    }

    private function todayStart(): int {
        return (new DateTimeImmutable('today', new DateTimeZone('UTC')))->getTimestamp();
    }
}
}
