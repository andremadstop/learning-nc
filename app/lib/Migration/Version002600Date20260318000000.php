<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002600Date20260318000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('oc_learning_duel_answers')) {
            $table = $schema->getTable('oc_learning_duel_answers');
            if ($table->hasColumn('answered_at')) {
                $col = $table->getColumn('answered_at');
                $col->setType(\Doctrine\DBAL\Types\Type::getType('bigint'));
                $col->setOptions(['notnull' => false, 'unsigned' => false]);
            }
        }
        return $schema;
    }
}
