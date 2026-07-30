<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\Exception\NotFoundException as TaskNotFoundException;
use OCP\TaskProcessing\Exception\PreConditionNotMetException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Production adapter around {@see IManager}.
 */
final class OcpReceiptTaskProcessingGateway implements ReceiptTaskProcessingGateway
{
	public function __construct(
		private readonly IManager $manager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function availableTaskTypeIds(?string $userId = null): array
	{
		try {
			if (!$this->manager->hasProviders()) {
				return [];
			}
			return array_values($this->manager->getAvailableTaskTypeIds(false, $userId));
		} catch (\Throwable $e) {
			$this->logger->warning('BudgetCheck receipt suggest: task type probe failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return [];
		}
	}

	public function schedule(string $taskTypeId, array $input, string $userId, string $customId): int
	{
		$task = new Task($taskTypeId, $input, Application::APP_ID, $userId, $customId);
		try {
			$this->manager->scheduleTask($task);
		} catch (PreConditionNotMetException $e) {
			throw new \InvalidArgumentException('Receipt AI is not available on this server.', 0, $e);
		} catch (TaskProcessingException $e) {
			throw new \InvalidArgumentException('Could not start receipt analysis.', 0, $e);
		}
		$id = $task->getId();
		if ($id === null || $id < 1) {
			throw new \RuntimeException('Task Processing did not assign a task id.');
		}
		return $id;
	}

	public function getUserTask(int $taskId, string $userId): array
	{
		try {
			$task = $this->manager->getUserTask($taskId, $userId);
		} catch (TaskNotFoundException) {
			throw new \OCA\BudgetCheck\Exception\NotFoundException('Suggestion job not found.');
		} catch (TaskProcessingException $e) {
			throw new \RuntimeException('Could not load suggestion job.', 0, $e);
		}

		return [
			'id' => (int)$task->getId(),
			'status' => $task->getStatus(),
			'output' => $task->getOutput(),
			'error' => $task->getErrorMessage(),
		];
	}

	public function cancel(int $taskId): void
	{
		try {
			$this->manager->cancelTask($taskId);
		} catch (\Throwable $e) {
			$this->logger->info('BudgetCheck receipt suggest: cancel ignored', [
				'app' => Application::APP_ID,
				'taskId' => $taskId,
				'exception' => $e,
			]);
		}
	}
}
