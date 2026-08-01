<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mobile companion S0/P2: idempotency store for offline creates + push token registry.
 *
 * No license tables — BudgetCheck Mobile is a Play one-time purchase (COMPANION-APP.md v2.1); no BDC2 org seats.
 */
class Version1020Date20260727120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_idempotency')) {
			$t = $schema->createTable('bc_idempotency');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$t->addColumn('idem_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('request_hash', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('response_json', Types::TEXT, [
				'notnull' => true,
			]);
			$t->addColumn('http_status', Types::INTEGER, [
				'notnull' => true,
				'default' => 200,
			]);
			$t->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'bc_idemp_pk');
			$t->addUniqueIndex(['user_id', 'workspace_id', 'idem_key'], 'bc_idemp_uidx');
			$t->addIndex(['created_at'], 'bc_idemp_created_idx');
		}

		if (!$schema->hasTable('bc_mobile_push')) {
			$t = $schema->createTable('bc_mobile_push');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('push_token', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$t->addColumn('platform', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'android',
			]);
			$t->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$t->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'bc_mpush_pk');
			$t->addUniqueIndex(['user_id', 'push_token'], 'bc_mpush_uidx');
			$t->addIndex(['user_id'], 'bc_mpush_user_idx');
		}

		return $schema;
	}
}
