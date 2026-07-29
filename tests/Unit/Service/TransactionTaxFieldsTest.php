<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\TransactionService;
use PHPUnit\Framework\TestCase;

final class TransactionTaxFieldsTest extends TestCase
{
	public function testTaxDisabledAcceptsSimpleOnly(): void
	{
		$svc = self::newService();
		$out = self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'simple'],
			['taxModeEnabled' => false, 'defaultVatRateBp' => 1900],
			10_000
		);

		$this->assertSame('simple', $out['basis']);
		$this->assertNull($out['net']);
		$this->assertNull($out['vat']);
		$this->assertNull($out['gross']);
		$this->assertNull($out['vatRateBp']);
	}

	public function testTaxDisabledRejectsGrossOrNetPayload(): void
	{
		$svc = self::newService();
		$this->expectException(\InvalidArgumentException::class);
		self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'gross', 'vatRateBp' => 1900],
			['taxModeEnabled' => false, 'defaultVatRateBp' => 1900],
			11_900
		);
	}

	public function testTaxEnabledGrossUsesWorkspaceDefaultRateWhenPayloadOmitsRate(): void
	{
		$svc = self::newService();
		$out = self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'gross'],
			['taxModeEnabled' => true, 'defaultVatRateBp' => 1900],
			11_900
		);

		$this->assertSame('gross', $out['basis']);
		$this->assertSame(10_000, $out['net']);
		$this->assertSame(1_900, $out['vat']);
		$this->assertSame(11_900, $out['gross']);
		$this->assertSame(1900, $out['vatRateBp']);
	}

	public function testTaxEnabledNetEntryWithExplicitZeroRate(): void
	{
		$svc = self::newService();
		$out = self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'net', 'vatRateBp' => 0],
			['taxModeEnabled' => true, 'defaultVatRateBp' => 1900],
			10_000
		);
		$this->assertSame('net', $out['basis']);
		$this->assertSame(10_000, $out['net']);
		$this->assertSame(0, $out['vat']);
		$this->assertSame(10_000, $out['gross']);
		$this->assertSame(0, $out['vatRateBp']);
	}

	public function testTaxEnabledNetEntryWithNineteenPercent(): void
	{
		$svc = self::newService();
		$out = self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'net', 'vatRateBp' => 1900],
			['taxModeEnabled' => true, 'defaultVatRateBp' => 1900],
			10_000
		);
		$this->assertSame(10_000, $out['net']);
		$this->assertSame(1_900, $out['vat']);
		$this->assertSame(11_900, $out['gross']);
	}

	public function testTaxEnabledSimpleClearsSplitFields(): void
	{
		$svc = self::newService();
		$out = self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'simple'],
			['taxModeEnabled' => true, 'defaultVatRateBp' => 1900],
			10_000
		);
		$this->assertSame('simple', $out['basis']);
		$this->assertNull($out['net']);
		$this->assertNull($out['vat']);
		$this->assertNull($out['gross']);
		$this->assertNull($out['vatRateBp']);
	}

	public function testTaxEnabledRejectsMissingRateWhenNoWorkspaceDefault(): void
	{
		$svc = self::newService();
		$this->expectException(\InvalidArgumentException::class);
		self::invokeResolveTaxFields(
			$svc,
			['entryAmountBasis' => 'gross'],
			['taxModeEnabled' => true, 'defaultVatRateBp' => null],
			11_900
		);
	}

	private static function invokeResolveTaxFields(object $instance, array $payload, array $workspace, int $amount): array
	{
		$ref = new \ReflectionMethod(TransactionService::class, 'resolveTaxFields');
		$ref->setAccessible(true);
		/** @var array{basis:string,net:?int,vat:?int,gross:?int,vatRateBp:?int} $out */
		$out = $ref->invoke($instance, $payload, $workspace, $amount, TransactionService::DIRECTION_EXPENSE);
		return $out;
	}

	private static function newService(): TransactionService
	{
		$ref = new \ReflectionClass(TransactionService::class);
		/** @var TransactionService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$moneyProp = new \ReflectionProperty(TransactionService::class, 'money');
		$moneyProp->setAccessible(true);
		$moneyProp->setValue($svc, new MoneyService());
		return $svc;
	}
}

