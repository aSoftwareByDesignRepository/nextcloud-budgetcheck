<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\TransactionAttachmentService;
use PHPUnit\Framework\TestCase;

/**
 * Guards against duplicate SQL aliases in grouped count queries (MariaDB 1064).
 */
final class TransactionAttachmentCountsQueryTest extends TestCase
{
	public function testCountsQueryUsesSingleCntAlias(): void
	{
		$ref = new \ReflectionClass(TransactionAttachmentService::class);
		$source = file_get_contents($ref->getFileName());
		$this->assertIsString($source);
		$this->assertStringNotContainsString("selectAlias(\$qb->func()->count('*', 'cnt'), 'cnt')", $source);
		$this->assertStringContainsString("->addSelect(\$qb->func()->count('*', 'cnt'))", $source);
	}
}
