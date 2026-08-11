<?php

declare(strict_types=1);

namespace OCA\BudgetCheck;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * OCS capabilities for BudgetCheck Mobile (Play companion — no organisation license fields).
 *
 * Advertises {@see COMPANION_MIN} / {@see COMPANION_API} so the official Android
 * client can fail closed with “Update BudgetCheck on the server” when too old.
 */
class Capabilities implements ICapability
{
	public const COMPANION_MIN = 1;
	/** Companion API major: 5 = attachment download; 6 = workspace privacy update (PUT). */
	public const COMPANION_API = 6;

	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getCapabilities(): array
	{
		$version = $this->appManager->getAppVersion(Application::APP_ID, false);
		return [
			'budgetcheck' => [
				'version' => $version,
				'companion.min' => self::COMPANION_MIN,
				'companion.api' => self::COMPANION_API,
				'companion' => [
					'min' => self::COMPANION_MIN,
					'api' => self::COMPANION_API,
					'licensed' => false,
					'free' => true,
				],
			],
		];
	}
}
