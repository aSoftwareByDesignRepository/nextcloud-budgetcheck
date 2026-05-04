<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Listener;

use OCA\BudgetCheck\Service\AccessControlService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(private AccessControlService $access)
	{
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$this->access->purgeUser($event->getUser()->getUID());
	}
}
