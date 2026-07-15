<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Receipt and image attachments linked to ledger transactions.
 */
class Version1016Date20260715120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_tx_attachments')) {
			$table = $schema->createTable('bc_tx_attachments');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('transaction_id', 'bigint', ['notnull' => true]);
			$table->addColumn('stored_name', 'string', ['notnull' => true, 'length' => 128]);
			$table->addColumn('original_name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime_type', 'string', ['notnull' => true, 'length' => 127]);
			$table->addColumn('file_size', 'bigint', ['notnull' => true]);
			$table->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'bc_tx_att_pk');
			$table->addIndex(['transaction_id'], 'bc_tx_att_tx_idx');
		}

		return $schema;
	}
}
