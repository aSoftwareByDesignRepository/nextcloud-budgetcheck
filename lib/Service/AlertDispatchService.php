<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Single dispatch point for all alerts (spec: alert-notifications §4).
 *
 * Dedupe primitive: UNIQUE (user_id, workspace_id, alert_type, dedupe_key) —
 * insert-then-send. A duplicate insert means another evaluation already
 * claimed this alert, so we skip silently. Check-then-send without the
 * unique constraint would be a TOCTOU double-fire (NN-02).
 *
 * The notification channel is wrapped in try/catch: a minimal instance
 * without the notifications app degrades quietly instead of 500ing the job.
 */
class AlertDispatchService
{
	private const RETENTION_MONTHS = 13;

	public function __construct(
		private IDBConnection $db,
		private INotificationManager $notifications,
		private AlertPreferencesService $prefs,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private ?IMailer $mailer = null,
		private ?IUserManager $userManager = null,
	) {
	}

	/**
	 * Dispatch one alert candidate. Returns true when a notification was sent.
	 *
	 * @param array{subject:string,message?:string,route?:string,params?:array<string,string|int>} $payload
	 */
	public function dispatch(string $userId, int $workspaceId, string $type, string $dedupeKey, array $payload): bool
	{
		if (!$this->prefs->canSend($userId, $type)) {
			return false;
		}
		if (!$this->claim($userId, $workspaceId, $type, $dedupeKey, (string)($payload['subject'] ?? ''))) {
			return false; // already fired — dedupe wins
		}
		$this->send($userId, $workspaceId, $type, $payload);
		return true;
	}

	/**
	 * Atomic dedupe claim. The UNIQUE index does the work: a concurrent
	 * evaluation that loses the insert race simply returns false.
	 */
	private function claim(string $userId, int $workspaceId, string $type, string $dedupeKey, string $subject): bool
	{
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		try {
			$this->db->insertIfNotExist('bc_alert_log', [
				'workspace_id' => $workspaceId,
				'user_id' => $userId,
				'alert_type' => $type,
				'dedupe_key' => $dedupeKey,
				'subject' => mb_substr($subject, 0, 255),
				'fired_at' => $now,
				'created_at' => $now,
			], ['user_id', 'workspace_id', 'alert_type', 'dedupe_key']);
		} catch (\Throwable) {
			return false;
		}
		// insertIfNotExist does not report "row existed" — verify ownership by
		// checking whether a row with this exact key exists and was created now.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_alert_log')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('alert_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)))
			->andWhere($qb->expr()->eq('created_at', $qb->createNamedParameter($now)));
		$row = $qb->executeQuery()->fetch();
		return $row !== false;
	}

	private function send(string $userId, int $workspaceId, string $type, array $payload): void
	{
		try {
			$route = (string)($payload['route'] ?? 'budgetcheck.page.dashboard');
			$params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
			$params['workspaceId'] = $workspaceId;
			$link = $this->urlGenerator->linkToRouteAbsolute($route, $params);

			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('alert_' . $type, $type . '_' . $workspaceId)
				->setSubject($type, [
					'subject' => (string)($payload['subject'] ?? ''),
					'message' => (string)($payload['message'] ?? ''),
					'link' => $link,
					'workspaceId' => $workspaceId,
				]);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			// Notification app absent/disabled or send failure: degrade, never 500.
			$this->logger->info('budgetcheck alert channel skipped', [
				'type' => $type,
				'workspaceId' => $workspaceId,
				'exception' => $e,
			]);
		}
		$this->sendEmail($userId, $workspaceId, $type, $payload, $link ?? null);
	}

	/**
	 * Email channel (NH-01): opt-in per user, plain-text, best-effort. Sent
	 * after the in-app notification so a mail failure never eats the primary
	 * channel; its own dedupe is the shared alert_log claim (one alert = one
	 * email, quiet hours already applied by canSend before dispatch).
	 */
	private function sendEmail(string $userId, int $workspaceId, string $type, array $payload, ?string $link): void
	{
		if ($this->mailer === null || $this->userManager === null) {
			return;
		}
		try {
			if (!$this->prefs->emailEnabled($userId)) {
				return;
			}
			$email = $this->userManager->get($userId)?->getEMailAddress();
			if (!is_string($email) || $email === '' || !$this->mailer->validateMailAddress($email)) {
				return;
			}
			$subject = (string)($payload['subject'] ?? '');
			$message = (string)($payload['message'] ?? '');
			$body = $subject . "\n\n" . $message
				. ($link !== null ? "\n\n" . $link : '');
			$mail = $this->mailer->createMessage();
			$mail->setTo([$email])
				->setSubject('BudgetCheck: ' . ($subject !== '' ? $subject : $type))
				->setPlainBody($body)
				->setAutoSubmitted('auto-generated');
			$this->mailer->send($mail);
		} catch (\Throwable $e) {
			$this->logger->info('budgetcheck alert email skipped', [
				'type' => $type,
				'workspaceId' => $workspaceId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Alert center (NH-04): a member's own fired alerts, newest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listForUser(int $workspaceId, string $userId, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_alert_log')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('fired_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min(200, $limit)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'type' => (string)$row['alert_type'],
				'subject' => (string)$row['subject'],
				'firedAt' => (string)$row['fired_at'],
			];
		}
		$result->closeCursor();
		return $out;
	}

	/** Prune log rows older than the retention window (job housekeeping). */
	public function prune(): int
	{
		$cutoff = $this->timeFactory->getDateTime()->modify('-' . self::RETENTION_MONTHS . ' months')->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_alert_log')
			->where($qb->expr()->lt('fired_at', $qb->createNamedParameter($cutoff)));
		return $qb->executeStatement();
	}
}
