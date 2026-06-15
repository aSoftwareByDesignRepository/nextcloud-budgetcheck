<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Listener;

use OCA\BudgetCheck\Service\AccessControlService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Cleans up authorization state when a Nextcloud group is permanently deleted:
 * removes its workspace assignments and any directory allow-list entry, so the
 * access gate can never reference a group that no longer exists.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener
{
	public function __construct(private AccessControlService $access)
	{
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}
		$this->access->purgeGroup($event->getGroup()->getGID());
	}
}
