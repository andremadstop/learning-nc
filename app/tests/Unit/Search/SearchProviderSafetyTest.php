<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Search;

use OCA\Learning\Search\PoolSearchProvider;
use OCA\Learning\Search\QuestionSearchProvider;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\TestCase;

class SearchProviderSafetyTest extends TestCase {
    public function testPoolSearchNormalizesControlCharactersAndCapsLength(): void {
        $builder = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $provider = $this->createPoolProvider(new FakeDbConnection([$builder]));

        $result = $provider->search(
            $this->createUser('alice'),
            $this->createQuery("\x00phpinfo\n" . str_repeat('A', 200))
        );

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertFalse($result->complete);

        $likeValue = $this->findLikeParameter($builder->namedParameters);
        $this->assertNotNull($likeValue);
        $this->assertLessThanOrEqual(130, strlen($likeValue));
        $this->assertStringContainsString('phpinfo', $likeValue);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $likeValue);
    }

    public function testPoolSearchFallsBackToEmptyResultWhenDatabaseFails(): void {
        $builder = new class extends FakeQueryBuilder {
            public function executeQuery(): FakeResult {
                throw new \RuntimeException('simulated search failure');
            }
        };
        $provider = $this->createPoolProvider(new FakeDbConnection([$builder]));

        $result = $provider->search(
            $this->createUser('alice'),
            $this->createQuery('phpinfo')
        );

        $this->assertTrue($result->complete);
        $this->assertSame([], $result->entries);
    }

    public function testQuestionSearchNormalizesControlCharactersAndCapsLength(): void {
        $builder = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $provider = $this->createQuestionProvider(new FakeDbConnection([$builder]));

        $result = $provider->search(
            $this->createUser('alice'),
            $this->createQuery("\x00phpinfo\n" . str_repeat('B', 200))
        );

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertFalse($result->complete);

        $likeValue = $this->findLikeParameter($builder->namedParameters);
        $this->assertNotNull($likeValue);
        $this->assertLessThanOrEqual(130, strlen($likeValue));
        $this->assertStringContainsString('phpinfo', $likeValue);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $likeValue);
    }

    public function testQuestionSearchFallsBackToEmptyResultWhenDatabaseFails(): void {
        $builder = new class extends FakeQueryBuilder {
            public function executeQuery(): FakeResult {
                throw new \RuntimeException('simulated search failure');
            }
        };
        $provider = $this->createQuestionProvider(new FakeDbConnection([$builder]));

        $result = $provider->search(
            $this->createUser('alice'),
            $this->createQuery('phpinfo')
        );

        $this->assertTrue($result->complete);
        $this->assertSame([], $result->entries);
    }

    private function createPoolProvider(FakeDbConnection $db): PoolSearchProvider {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text, array $parameters = []): string => $text);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/img/app.svg');
        $urlGenerator->method('linkToRoute')->willReturn('/apps/learning');
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.invalid/apps/learning');

        return new PoolSearchProvider(
            $l10n,
            $urlGenerator,
            $db
        );
    }

    private function createQuestionProvider(FakeDbConnection $db): QuestionSearchProvider {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text, array $parameters = []): string => $text);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/img/app.svg');
        $urlGenerator->method('linkToRoute')->willReturn('/apps/learning');
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.invalid/apps/learning');

        return new QuestionSearchProvider(
            $l10n,
            $urlGenerator,
            $db
        );
    }

    private function createUser(string $uid): IUser {
        return new class($uid) implements IUser {
            public function __construct(private string $uid) {
            }

            public function getUID(): string {
                return $this->uid;
            }

            public function getDisplayName(): string {
                return $this->uid;
            }

            // Phase 160 stub additions — search tests do not use these
            public function getEMailAddress(): ?string { return null; }
            public function setDisplayName(string $displayName): bool { return true; }
        };
    }

    private function createQuery(string $term): ISearchQuery {
        return new class($term) implements ISearchQuery {
            public function __construct(private string $term) {
            }

            public function getTerm() {
                return $this->term;
            }

            public function getCursor() {
                return 0;
            }

            public function getLimit() {
                return 20;
            }

            public function getFilter(string $name) {
                return null;
            }
        };
    }

    /**
     * @param array<int, array{value:mixed}> $parameters
     */
    private function findLikeParameter(array $parameters): ?string {
        foreach ($parameters as $parameter) {
            if (!isset($parameter['value']) || !is_string($parameter['value'])) {
                continue;
            }

            if (str_contains($parameter['value'], 'phpinfo')) {
                return $parameter['value'];
            }
        }

        return null;
    }
}
