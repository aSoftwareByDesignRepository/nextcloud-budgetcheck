<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Notification;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders BudgetCheck alert notifications inside the NC notification surface.
 * Subjects arrive pre-composed in subject parameters (the dispatch service
 * already localized them at fire time); prepare() only re-localizes the
 * fallback copy for the viewer's own language.
 */
class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('BudgetCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$subject = trim((string)($params['subject'] ?? ''));
		$message = trim((string)($params['message'] ?? ''));
		$link = trim((string)($params['link'] ?? ''));

		$type = $notification->getSubject();
		$fallbackSubject = match ($type) {
			'budget' => $l->t('Budget alert'),
			'recurring' => $l->t('Recurring bookings'),
			'digest' => $l->t('Monthly digest'),
			'savings' => $l->t('Savings update'),
			default => $l->t('BudgetCheck alert'),
		};
		$notification->setParsedSubject($subject !== '' ? $subject : $fallbackSubject);
		$notification->setParsedMessage($message !== '' ? $message : $l->t('Open BudgetCheck for details.'));
		$notification->setLink($link !== ''
			? $link
			: $this->urlGenerator->linkToRouteAbsolute('budgetcheck.page.dashboard'));
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
		));
		return $notification;
	}
}
