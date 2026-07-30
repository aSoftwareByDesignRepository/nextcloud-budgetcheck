<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service\ReceiptSuggest;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestMetrics;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ReceiptSuggestMetricsTest extends TestCase
{
	public function testIncrementKnownKey(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, ReceiptSuggestMetrics::STARTED, '0')
			->willReturn('2');
		$config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, ReceiptSuggestMetrics::STARTED, '3');

		$metrics = new ReceiptSuggestMetrics($config);
		$metrics->increment(ReceiptSuggestMetrics::STARTED);
	}

	public function testIgnoresUnknownKey(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setAppValue');
		$metrics = new ReceiptSuggestMetrics($config);
		$metrics->increment('rs_metric_evil');
	}

	public function testSnapshotReturnsAllKeys(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key): string {
			return $key === ReceiptSuggestMetrics::ACCEPTED ? '9' : '0';
		});
		$metrics = new ReceiptSuggestMetrics($config);
		$snap = $metrics->snapshot();
		$this->assertSame(9, $snap[ReceiptSuggestMetrics::ACCEPTED]);
		$this->assertSame(0, $snap[ReceiptSuggestMetrics::STARTED]);
		$this->assertCount(8, $snap);
	}
}
