<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\BudgetCheck\Capabilities;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCA\BudgetCheck\Service\MobilePushService;
use OCA\BudgetCheck\Service\UpgradeBackupService;
use OCA\BudgetCheck\Repair\BackupBeforeUpdate;
use OCA\BudgetCheck\Listener\GroupDeletedListener;
use OCA\BudgetCheck\Listener\UserDeletedListener;
use OCA\BudgetCheck\Repair\EnsureBudgetCheckSchema;
use OCA\BudgetCheck\Repair\UninstallDropTables;
use OCA\BudgetCheck\Middleware\AppAccessMiddleware;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\HouseholdYearlyExportService;
use OCA\BudgetCheck\Service\ImportPreferencesService;
use OCA\BudgetCheck\Service\SummaryViewPreferencesService;
use OCA\BudgetCheck\Service\LocaleFormatService;
use OCA\BudgetCheck\Service\CurrencyCatalog;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SavingsTargetService;
use OCA\BudgetCheck\Service\SnapshotService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TimezoneCatalog;
use OCA\BudgetCheck\Service\TransactionImportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\WarningEngine;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCA\BudgetCheck\Service\ReceiptSuggest\OcpReceiptTaskProcessingGateway;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAcceptGuard;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAvailability;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestJobStore;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestPromptBuilder;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestService;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestServiceInterface;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestStagingStore;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestionPipeline;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestionParser;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestionQualityGate;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestionValidator;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptTaskProcessingGateway;
use OCA\BudgetCheck\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\L10N\IFactory;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * BudgetCheck application bootstrap.
 *
 * - Registers all services explicitly so wiring is deterministic across PHP/NC versions.
 * - Shared frontend assets (CSS + common JS) are registered from
 *   {@see \OCA\BudgetCheck\Controller\PageController::page()} so every in-app
 *   view loads globals before the page module (path-based boot detection is
 *   unreliable across servers and custom_apps URL layouts).
 * - Adds the Files-style sidebar navigation entry only for users that pass
 *   {@see AccessControlService::canUseApp} (authenticated user, plus optional
 *   directory restriction and app/system administrator bypass). UI hiding is convenience only;
 *   server enforcement lives in the access middleware and per-route service checks.
 */
class Application extends App implements IBootstrap
{
	public const APP_ID = 'budgetcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// --- Cross-cutting access middleware --------------------------------
		$context->registerService(AccessControlService::class, function ($c): AccessControlService {
			return new AccessControlService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IGroupManager::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(MoneyService::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(AppAccessMiddleware::class, function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->query(\OCP\IUserSession::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);

		// Free companion capabilities (no license fields)
		$context->registerCapability(Capabilities::class);

		// --- Utility services -----------------------------------------------
		$context->registerService(CurrencyCatalog::class, fn () => new CurrencyCatalog());
		$context->registerService(TimezoneCatalog::class, fn () => new TimezoneCatalog());
		$context->registerService(MoneyService::class, function ($c): MoneyService {
			return new MoneyService($c->query(CurrencyCatalog::class));
		});

		$context->registerService(LocaleFormatService::class, function ($c): LocaleFormatService {
			return new LocaleFormatService(
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\OCP\IDateTimeFormatter::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCP\IDateTimeZone::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(AuditLogService::class, function ($c): AuditLogService {
			return new AuditLogService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(RateLimitService::class, function ($c): RateLimitService {
			return new RateLimitService(
				$c->query(\OCP\IConfig::class),
				$c->query(AuditLogService::class),
			);
		});

		// --- Domain services ------------------------------------------------
		$context->registerService(WorkspaceService::class, function ($c): WorkspaceService {
			return new WorkspaceService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
				$c->query(TimezoneCatalog::class),
				$c->query(CurrencyCatalog::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(CategoryService::class),
				$c->query(MoneyService::class),
				$c->query(\OCP\IGroupManager::class),
				$c->query(SummaryViewPreferencesService::class),
			);
		});

		$context->registerService(CategoryService::class, function ($c): CategoryService {
			return new CategoryService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		$context->registerService(TransactionService::class, function ($c): TransactionService {
			return new TransactionService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(MoneyService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
				$c->query(CategoryService::class),
				$c->query(BookingStatusService::class),
				$c->query(TransactionAttachmentService::class),
			);
		});

		$context->registerService(MobileIdempotencyService::class, function ($c): MobileIdempotencyService {
			return new MobileIdempotencyService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
			);
		});

		$context->registerService(MobilePushService::class, function ($c): MobilePushService {
			return new MobilePushService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
			);
		});

		$context->registerService(\OCA\BudgetCheck\Service\TransactionBillingService::class, function ($c): \OCA\BudgetCheck\Service\TransactionBillingService {
			return new \OCA\BudgetCheck\Service\TransactionBillingService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		$context->registerService(\OCA\BudgetCheck\Public\BillingReadFacade::class, function ($c): \OCA\BudgetCheck\Public\BillingReadFacade {
			return new \OCA\BudgetCheck\Public\BillingReadFacade(
				$c->query(\OCA\BudgetCheck\Service\TransactionBillingService::class),
				$c->query(IAppManager::class),
			);
		});

		$context->registerService(\OCA\BudgetCheck\Public\BillingWriteFacade::class, function ($c): \OCA\BudgetCheck\Public\BillingWriteFacade {
			return new \OCA\BudgetCheck\Public\BillingWriteFacade(
				$c->query(\OCA\BudgetCheck\Service\TransactionBillingService::class),
			);
		});

		$context->registerService(TransactionAttachmentService::class, function ($c): TransactionAttachmentService {
			return new TransactionAttachmentService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Files\IRootFolder::class),
				$c->query(\OCP\IConfig::class),
				$c->query(AccessControlService::class),
				$c->query(WorkspaceService::class),
				$c->query(AuditLogService::class),
				$c->query(\OCP\IURLGenerator::class),
			);
		});

		$context->registerService(ReceiptSuggestAvailability::class, fn () => new ReceiptSuggestAvailability());
		$context->registerService(ReceiptSuggestionParser::class, fn () => new ReceiptSuggestionParser());
		$context->registerService(ReceiptSuggestionValidator::class, function ($c): ReceiptSuggestionValidator {
			return new ReceiptSuggestionValidator($c->query(MoneyService::class));
		});
		$context->registerService(ReceiptSuggestionQualityGate::class, fn () => new ReceiptSuggestionQualityGate());
		$context->registerService(ReceiptSuggestionPipeline::class, function ($c): ReceiptSuggestionPipeline {
			return new ReceiptSuggestionPipeline(
				$c->query(ReceiptSuggestionParser::class),
				$c->query(ReceiptSuggestionValidator::class),
				$c->query(ReceiptSuggestionQualityGate::class),
			);
		});
		$context->registerService(ReceiptSuggestPromptBuilder::class, fn () => new ReceiptSuggestPromptBuilder());
		$context->registerService(ReceiptSuggestAcceptGuard::class, fn () => new ReceiptSuggestAcceptGuard());
		$context->registerService(ReceiptSuggestJobStore::class, function ($c): ReceiptSuggestJobStore {
			return new ReceiptSuggestJobStore($c->query(\OCP\IConfig::class));
		});
		$context->registerService(ReceiptSuggestStagingStore::class, function ($c): ReceiptSuggestStagingStore {
			return new ReceiptSuggestStagingStore($c->query(\OCP\Files\IRootFolder::class));
		});
		$context->registerService(ReceiptTaskProcessingGateway::class, function ($c): ReceiptTaskProcessingGateway {
			return new OcpReceiptTaskProcessingGateway(
				$c->query(\OCP\TaskProcessing\IManager::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(ReceiptSuggestService::class, function ($c): ReceiptSuggestService {
			return new ReceiptSuggestService(
				$c->query(ReceiptTaskProcessingGateway::class),
				$c->query(ReceiptSuggestAvailability::class),
				$c->query(ReceiptSuggestStagingStore::class),
				$c->query(ReceiptSuggestJobStore::class),
				$c->query(ReceiptSuggestPromptBuilder::class),
				$c->query(ReceiptSuggestionPipeline::class),
				$c->query(ReceiptSuggestAcceptGuard::class),
				$c->query(AccessControlService::class),
				$c->query(WorkspaceService::class),
				$c->query(CategoryService::class),
				$c->query(TransactionService::class),
				$c->query(TransactionAttachmentService::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerAlias(ReceiptSuggestServiceInterface::class, ReceiptSuggestService::class);

		$context->registerService(TransactionImportService::class, function ($c): TransactionImportService {
			return new TransactionImportService(
				$c->query(CategoryService::class),
				$c->query(BookingStatusService::class),
				$c->query(TransactionService::class),
				$c->query(MoneyService::class),
				$c->query(AuditLogService::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\IDBConnection::class),
			);
		});

		$context->registerService(ImportPreferencesService::class, function ($c): ImportPreferencesService {
			return new ImportPreferencesService(
				$c->query(\OCP\IConfig::class),
				$c->query(AccessControlService::class),
			);
		});

		$context->registerService(SummaryViewPreferencesService::class, function ($c): SummaryViewPreferencesService {
			return new SummaryViewPreferencesService(
				$c->query(\OCP\IConfig::class),
				$c->query(AccessControlService::class),
			);
		});

		$context->registerService(BookingStatusService::class, function ($c): BookingStatusService {
			return new BookingStatusService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(WorkspaceService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		$context->registerService(BudgetPlannedService::class, function ($c): BudgetPlannedService {
			return new BudgetPlannedService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(BudgetService::class),
				$c->query(CategoryService::class),
				$c->query(TransactionService::class),
				$c->query(SnapshotService::class),
				$c->query(AuditLogService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
			);
		});

		$context->registerService(BudgetService::class, function ($c): BudgetService {
			return new BudgetService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(MoneyService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
				$c->query(CategoryService::class),
				$c->query(IFactory::class)->get(self::APP_ID),
			);
		});

		$context->registerService(SavingsTargetService::class, function ($c): SavingsTargetService {
			return new SavingsTargetService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(MoneyService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		$context->registerService(RecurringRuleService::class, function ($c): RecurringRuleService {
			return new RecurringRuleService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(MoneyService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		$context->registerService(SummaryService::class, function ($c): SummaryService {
			return new SummaryService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(MoneyService::class),
				$c->query(BudgetService::class),
				$c->query(SavingsTargetService::class),
				$c->query(WorkspaceService::class),
				$c->query(CategoryService::class),
				$c->query(WarningEngine::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(TransactionService::class),
			);
		});
		$context->registerService(HouseholdYearlyExportService::class, function ($c): HouseholdYearlyExportService {
			return new HouseholdYearlyExportService(
				$c->query(WorkspaceService::class),
				$c->query(SummaryService::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\IDBConnection::class),
			);
		});

		$context->registerService(WarningEngine::class, function ($c): WarningEngine {
			return new WarningEngine(
				$c->query(MoneyService::class),
			);
		});

		$context->registerService(SnapshotService::class, function ($c): SnapshotService {
			return new SnapshotService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
				$c->query(WorkspaceService::class),
				$c->query(SummaryService::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(AuditLogService::class),
			);
		});

		// Admin section entry — configures global app defaults.
		$context->registerService(AdminSettings::class, function ($c): AdminSettings {
			return new AdminSettings(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(AccessControlService::class),
			);
		});

		$context->registerService(EnsureBudgetCheckSchema::class, function ($c): EnsureBudgetCheckSchema {
			return new EnsureBudgetCheckSchema(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
				$c->query(IAppManager::class),
				$c->query(ILockingProvider::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->query(UpgradeBackupService::class),
			);
		});


		// React to permanent user deletions: remove memberships, scrub configured
		// app-admin entries. Snapshots and historical ledger rows stay intact for audit.
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

		// React to permanent group deletions: drop workspace group assignments and
		// the directory allow-list entry so authorization never references a ghost group.
		$context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
	}

	public function boot(IBootContext $context): void
	{
		$this->registerNavigationWhenAllowed();
	}

	private function registerNavigationWhenAllowed(): void
	{
		try {
			$container = $this->getContainer();
			$user = $container->get(\OCP\IUserSession::class)->getUser();
			if ($user === null) {
				return;
			}
			$access = $container->get(AccessControlService::class);
			if (!$access->canUseApp($user->getUID())) {
				return;
			}
			$navigationManager = $container->get(INavigationManager::class);
			$urlGenerator = $container->get(\OCP\IURLGenerator::class);
			$l10nFactory = $container->get(IFactory::class);
			$navigationManager->add(function () use ($urlGenerator, $l10nFactory): array {
				return [
					'id' => self::APP_ID,
					'app' => self::APP_ID,
					'order' => 11,
					'href' => $urlGenerator->linkToRoute('budgetcheck.page.index'),
					'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
					'name' => $l10nFactory->get(self::APP_ID)->t('BudgetCheck'),
				];
			});
		} catch (\Throwable) {
			// Navigation registration is best-effort.
		}
	}
}
