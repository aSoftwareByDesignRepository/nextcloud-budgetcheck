<?php

declare(strict_types=1);

/**
 * BudgetCheck — admin settings entry.
 *
 * Renders a small, self-contained form that lives in Nextcloud's admin area
 * and lets a system administrator nominate one or more BudgetCheck app
 * administrators plus the default timezone and currency for the workspace
 * creation form. Workspace-level configuration (members, categories, savings,
 * tax mode, recurring rules) lives inside the app itself so day-to-day
 * managers do not need access to the Nextcloud admin UI.
 */

namespace OCA\BudgetCheck\Settings;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings
{
	public function __construct(
		private IConfig $config,
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private AccessControlService $access,
	) {
	}

	public function getForm(): TemplateResponse
	{
		$l = $this->l10nFactory->get(Application::APP_ID);
		$policy = $this->access->getAppPolicy();

		$parameters = [
			'l' => $l,
			'policy' => $policy,
			'appAdminUserIds' => $policy['appAdminUserIds'],
			'appAdminUserIdsCsv' => implode(', ', $policy['appAdminUserIds']),
			'defaultTimezone' => $policy['defaultTimezone'],
			'defaultCurrency' => $policy['defaultCurrency'],
			'saveUrl' => $this->urlGenerator->linkToRoute('budgetcheck.api.saveAppPolicy'),
			'appUrl' => $this->urlGenerator->linkToRoute('budgetcheck.page.appSettings'),
		];

		return new TemplateResponse(Application::APP_ID, 'admin-settings', $parameters, 'blank');
	}

	public function getSection(): string
	{
		// `additional` is Nextcloud's catch-all admin section; it always
		// exists, so we can register cleanly without creating a custom one.
		return 'additional';
	}

	public function getPriority(): int
	{
		return 60;
	}
}
