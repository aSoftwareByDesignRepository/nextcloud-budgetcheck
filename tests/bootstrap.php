<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Nextcloud OCP stubs reference Doctrine DBAL constants. In this isolated app
// test setup Doctrine is not installed, so provide minimal constant stubs
// required by the OCP interfaces our code uses at parse time.
if (!class_exists(\Doctrine\DBAL\ParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ParameterType { public const NULL = 0; public const INTEGER = 1; public const STRING = 2; public const LARGE_OBJECT = 3; }');
}
if (!class_exists(\Doctrine\DBAL\ArrayParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ArrayParameterType { public const INTEGER = 1; public const STRING = 2; public const ASCII = 3; public const BINARY = 4; }');
}
if (!class_exists(\Doctrine\DBAL\Connection::class)) {
	eval('namespace Doctrine\\DBAL; class Connection {}');
}
if (!class_exists(\Doctrine\DBAL\Types\Types::class)) {
	eval("namespace Doctrine\\DBAL\\Types; final class Types { public const BOOLEAN = 'boolean'; public const DATETIME_MUTABLE = 'datetime'; public const TIME_MUTABLE = 'time'; public const DATE_MUTABLE = 'date'; public const DATE_IMMUTABLE = 'date_immutable'; public const DATETIME_IMMUTABLE = 'datetime_immutable'; public const DATETIMETZ_MUTABLE = 'datetimetz'; public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable'; public const BIGINT = 'bigint'; public const BINARY = 'binary'; public const BLOB = 'blob'; public const DATEINTERVAL = 'dateinterval'; public const DECIMAL = 'decimal'; public const FLOAT = 'float'; public const GUID = 'guid'; public const JSON = 'json'; public const SIMPLE_ARRAY = 'simple_array'; public const SMALLFLOAT = 'smallfloat'; public const SMALLINT = 'smallint'; public const STRING = 'string'; public const TEXT = 'text'; }");
}

// OCP\DB\QueryBuilder\IExpressionBuilder references Doctrine ExpressionBuilder
// constants at parse time.
if (!class_exists(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class)) {
	eval('namespace Doctrine\\DBAL\\Query\\Expression; final class ExpressionBuilder { public const EQ = \'=\'; public const NEQ = \'<>\'; public const LT = \'<\'; public const LTE = \'<=\'; public const GT = \'>\'; public const GTE = \'>=\'; }');
}
