<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Every column of the freshly installed schema can be hydrated into its mapper's entity.
 *
 * Entity::fromRow() calls the setter for each column of a row and throws
 * "<property> is not a valid attribute" for a column without a property. A mapper method that
 * selects '*' and returns entities (findEntity/findEntities) therefore breaks as soon as its table
 * has one column more than its entity knows: that is how 5.11.0 broke every full pool read on
 * installs with the legacy column learning_pools.visibility (fixed in 5.14.1).
 *
 * This guard needs no live instance: the columns come from the database this suite runs on
 * (scripts/nc-integration.sh installs the app fresh, PostgreSQL or MariaDB), the mappers and
 * entities from the real container, and each column goes through the real Entity::fromRow().
 * It is independent of scripts/schema-drift.py, which parses the PHP sources instead.
 * A live column that only old installs have is outside its reach; that is schema-drift.sh's job.
 */
final class EntityCoversSchemaTest extends TestCase {
	/** Mapper methods that select '*' (or 'alias.*'), as "Mapper::method", from the source. */
	private static function starMethods(string $file): array {
		$src = (string)file_get_contents($file);
		$methods = [];
		preg_match_all('/function\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
		foreach ($m[1] as $i => [$name, $offset]) {
			$end = $m[0][$i + 1][1] ?? strlen($src);
			if (preg_match('/->select\(\s*[\'"](?:\w+\.)?\*[\'"]\s*\)/', substr($src, $offset, $end - $offset)) === 1) {
				$methods[] = $name;
			}
		}
		return $methods;
	}

	/** @return array<string, string[]> column names per table (prefix stripped), from the live database */
	private static function columns(): array {
		$prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$schema = Server::get(IDBConnection::class)->createSchema();
		$out = [];
		foreach ($schema->getTables() as $table) {
			$name = strtolower(trim($table->getName(), '`"'));
			if (!str_starts_with($name, strtolower($prefix) . 'learning_')) {
				continue;
			}
			$out[substr($name, strlen($prefix))] = array_map(
				static fn ($c) => strtolower(trim($c->getName(), '`"')),
				array_values($table->getColumns())
			);
		}
		return $out;
	}

	public function testEveryFreshColumnHydratesIntoTheEntityOfEachSelectStarMapper(): void {
		$columns = self::columns();
		$this->assertGreaterThan(40, count($columns), 'the fresh schema has the learning tables');

		$dir = dirname(__DIR__, 2) . '/lib/Db';
		$files = glob($dir . '/*Mapper.php') ?: [];
		$this->assertGreaterThan(30, count($files), 'mapper sources found in ' . $dir);

		$checked = [];
		$missing = [];
		$starTotal = 0;
		foreach ($files as $file) {
			$class = 'OCA\\Learning\\Db\\' . basename($file, '.php');
			if (!class_exists($class) || !is_subclass_of($class, QBMapper::class)) {
				continue;
			}
			$star = self::starMethods($file);
			if ($star === []) {
				continue; // explicit column lists hydrate only the columns they name
			}
			$starTotal += count($star);
			/** @var QBMapper $mapper */
			$mapper = Server::get($class);
			$table = $mapper->getTableName();
			$entityClass = (new \ReflectionProperty(QBMapper::class, 'entityClass'))->getValue($mapper);
			$this->assertTrue(is_subclass_of($entityClass, Entity::class), "$class maps to an entity");
			$this->assertArrayHasKey($table, $columns, "$class: table $table exists in the fresh schema");

			foreach ($columns[$table] as $column) {
				try {
					// The exact call a SELECT * row goes through; one column at a time to name each.
					$entityClass::fromRow([$column => null]);
				} catch (\BadFunctionCallException $e) {
					$missing[] = sprintf('%s.%s -> %s (%s; SELECT * in %s: %s)', $table, $column,
						$e->getMessage(), $entityClass, basename($file, '.php'), implode(', ', $star));
				} catch (\TypeError) {
					// the setter was found; null into a typed property is not this guard's question
				}
			}
			$checked[] = $table;
		}

		$this->assertGreaterThan(30, count($checked), 'select-star mappers found: ' . implode(', ', $checked));
		$this->assertGreaterThan(40, $starTotal, 'select-star mapper methods found');
		$this->assertSame([], $missing, "columns of the fresh schema without entity property:\n" . implode("\n", $missing));
	}
}
