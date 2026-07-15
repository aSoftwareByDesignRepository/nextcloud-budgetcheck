<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\LocaleFormatService;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Format locale resolution: the explicit account locale (Personal settings →
 * "Locale") must win over the account language, mirroring core behaviour, so
 * BudgetCheck formats dates and money the same way as Files or Calendar.
 */
final class LocaleFormatServiceLocaleTest extends TestCase
{
	private function makeService(string $userLocale, string $userLanguage, string $appDefault = ''): LocaleFormatService
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->with('user1', 'core', 'locale', '')
			->willReturn($userLocale);
		$config->method('getAppValue')
			->with('budgetcheck', 'default_locale', '')
			->willReturn($appDefault);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn($userLanguage);
		$l10nFactory->method('findLanguage')->willReturn('en');

		return new LocaleFormatService(
			$l10nFactory,
			$this->createMock(IDateTimeFormatter::class),
			$session,
			$this->createMock(IDateTimeZone::class),
			$config,
		);
	}

	public function testExplicitAccountLocaleWinsOverLanguage(): void
	{
		$service = $this->makeService('da_DK', 'en_GB');
		self::assertSame('da_DK', $service->locale());
	}

	public function testAccountLanguageUsedWhenNoLocaleSet(): void
	{
		$service = $this->makeService('', 'en_GB');
		self::assertSame('en_GB', $service->locale());
	}

	public function testAppDefaultUsedWhenUserHasNeither(): void
	{
		$service = $this->makeService('', '', 'fr');
		self::assertSame('fr', $service->locale());
	}

	public function testResultIsCached(): void
	{
		$service = $this->makeService('sv_SE', 'de');
		self::assertSame('sv_SE', $service->locale());
		self::assertSame('sv_SE', $service->locale());
	}
}
