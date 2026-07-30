<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\NotFoundException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates receipt AI suggest: stage → Task Processing → quality gates → accept.
 * Never auto-posts to the ledger.
 */
final class ReceiptSuggestService implements ReceiptSuggestServiceInterface
{
	public const PHASE_VISION = 'vision';
	public const PHASE_OCR = 'ocr';
	public const PHASE_STRUCTURE = 'structure';

	public function __construct(
		private readonly ReceiptTaskProcessingGateway $tasks,
		private readonly ReceiptSuggestAvailability $availability,
		private readonly ReceiptSuggestStagingStore $staging,
		private readonly ReceiptSuggestJobStore $jobs,
		private readonly ReceiptSuggestPromptBuilder $prompts,
		private readonly ReceiptSuggestionPipeline $pipeline,
		private readonly ReceiptSuggestAcceptGuard $acceptGuard,
		private readonly AccessControlService $access,
		private readonly WorkspaceService $workspaces,
		private readonly CategoryService $categories,
		private readonly TransactionService $transactions,
		private readonly TransactionAttachmentService $attachments,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function modesForUser(?string $userId): array
	{
		return $this->availability->detectModes($this->tasks->availableTaskTypeIds($userId));
	}

	public function isAvailable(?string $userId): bool
	{
		return $this->modesForUser($userId) !== [];
	}

	/**
	 * @param array{name?:mixed,tmp_name?:mixed,size?:mixed,error?:mixed,type?:mixed} $upload
	 * @return array{jobId:int,status:string}
	 */
	public function startFromUpload(int $workspaceId, string $userId, array $upload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);

		$modes = $this->modesForUser($userId);
		if ($modes === []) {
			throw new \InvalidArgumentException('Receipt AI is not available on this server.');
		}

		$active = $this->jobs->activeJobId($userId, $workspaceId);
		if ($active !== null) {
			$existing = $this->jobs->get($userId, $active);
			if ($existing !== null && (time() - (int)$existing['createdAt']) < ReceiptSuggestConstants::CLIENT_POLL_TIMEOUT_SEC) {
				throw new \InvalidArgumentException('A receipt suggestion is already in progress. Wait or cancel it first.');
			}
			// Stale lock — clear.
			$this->cancelJob($workspaceId, $userId, $active);
		}

		$staged = $this->staging->storeUpload($userId, $upload);
		$catalog = $this->expenseCategoryCatalog($workspaceId, $userId);
		if ($catalog['ids'] === []) {
			$this->staging->delete($userId, $staged['fileId']);
			throw new \InvalidArgumentException('Add at least one active expense category before using receipt AI.');
		}

		$source = $this->availability->preferredSource($modes);
		if ($source === null) {
			$this->staging->delete($userId, $staged['fileId']);
			throw new \InvalidArgumentException('Receipt AI is not available on this server.');
		}

		$isPdf = str_contains(strtolower($staged['mimeType']), 'pdf') || str_ends_with(strtolower($staged['fileName']), '.pdf');
		if ($isPdf && $source === ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES) {
			if (!in_array(ReceiptSuggestConstants::SOURCE_OCR_TEXT, $modes, true)) {
				$this->staging->delete($userId, $staged['fileId']);
				throw new \InvalidArgumentException('PDF receipts need OCR on the server. Use a photo, or ask your admin to enable OCR.');
			}
			$source = ReceiptSuggestConstants::SOURCE_OCR_TEXT;
		}

		$customId = 'bc-rs-' . $workspaceId . '-' . bin2hex(random_bytes(8));
		$currency = (string)$workspace['currencyCode'];

		try {
			if ($source === ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES) {
				$question = $this->prompts->buildAnalyzeImagesQuestion($catalog['rows'], $currency);
				$taskId = $this->tasks->schedule(
					ReceiptSuggestConstants::TASK_ANALYZE_IMAGES,
					[
						'images' => [$staged['fileId']],
						'input' => $question,
					],
					$userId,
					$customId,
				);
				$phase = self::PHASE_VISION;
			} else {
				$taskId = $this->tasks->schedule(
					ReceiptSuggestConstants::TASK_OCR,
					['input' => [$staged['fileId']]],
					$userId,
					$customId,
				);
				$phase = self::PHASE_OCR;
			}
		} catch (\Throwable $e) {
			$this->staging->delete($userId, $staged['fileId']);
			throw $e;
		}

		$meta = [
			'jobId' => $taskId,
			'workspaceId' => $workspaceId,
			'userId' => $userId,
			'source' => $source,
			'phase' => $phase,
			'taskId' => $taskId,
			'fileId' => $staged['fileId'],
			'fileName' => $staged['fileName'],
			'mimeType' => $staged['mimeType'],
			'categoryIds' => $catalog['ids'],
			'currencyCode' => $currency,
			'createdAt' => time(),
			'customId' => $customId,
		];
		$this->jobs->save($meta);

		return [
			'jobId' => $taskId,
			'status' => 'pending',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function poll(int $workspaceId, string $userId, int $jobId): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		$meta = $this->jobs->get($userId, $jobId);
		if ($meta === null || (int)$meta['workspaceId'] !== $workspaceId) {
			throw new NotFoundException('Suggestion job not found.');
		}

		if ((time() - (int)$meta['createdAt']) > ReceiptSuggestConstants::CLIENT_POLL_TIMEOUT_SEC) {
			$this->cleanupJob($meta, true);
			return ReceiptSuggestionResult::failed($meta['source'], 'timeout')->toArray() + ['jobId' => $jobId];
		}

		$task = $this->tasks->getUserTask((int)$meta['taskId'], $userId);
		$status = (int)$task['status'];

		if ($status === Task::STATUS_SCHEDULED || $status === Task::STATUS_RUNNING || $status === Task::STATUS_UNKNOWN) {
			return [
				'jobId' => $jobId,
				'status' => 'pending',
				'phase' => $meta['phase'],
				'source' => $meta['source'],
			];
		}

		if ($status === Task::STATUS_CANCELLED) {
			$this->cleanupJob($meta, false);
			return ReceiptSuggestionResult::failed($meta['source'], 'cancelled')->toArray() + ['jobId' => $jobId];
		}

		if ($status === Task::STATUS_FAILED) {
			$this->logger->info('BudgetCheck receipt suggest task failed', [
				'app' => Application::APP_ID,
				'jobId' => $jobId,
				// Do not log model output / receipt text.
			]);
			$this->cleanupJob($meta, true);
			return ReceiptSuggestionResult::failed($meta['source'], 'task_failed')->toArray() + ['jobId' => $jobId];
		}

		if ($status !== Task::STATUS_SUCCESSFUL) {
			return [
				'jobId' => $jobId,
				'status' => 'pending',
				'phase' => $meta['phase'],
				'source' => $meta['source'],
			];
		}

		$output = $task['output'] ?? null;
		if ($meta['phase'] === self::PHASE_OCR) {
			return $this->advanceFromOcr($meta, $output);
		}

		$rawText = $this->extractTextOutput($output, $meta['phase']);
		$context = new ReceiptSuggestContext(
			$meta['categoryIds'],
			$meta['currencyCode'],
			new \DateTimeImmutable('today'),
			$meta['source'],
		);
		$result = $this->pipeline->process($rawText, $context);
		// Keep staging file until accept/cancel — only clear active lock on terminal non-ready? Keep job meta for accept.
		if ($result->isReady()) {
			$meta['phase'] = 'ready';
			$this->jobs->save($meta);
			$payload = $result->toArray();
			$payload['jobId'] = $jobId;
			$payload['fileName'] = $meta['fileName'];
			$payload['mimeType'] = $meta['mimeType'];
			return $payload;
		}

		$this->cleanupJob($meta, true);
		$payload = $result->toArray();
		$payload['jobId'] = $jobId;
		return $payload;
	}

	/**
	 * @param array<string, mixed> $accepted Suggestion payload from client (must pass gates again).
	 * @return array{transactions:list<array<string,mixed>>,attachments:list<array<string,mixed>>}
	 */
	public function accept(int $workspaceId, string $userId, int $jobId, array $accepted): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$meta = $this->jobs->get($userId, $jobId);
		if ($meta === null || (int)$meta['workspaceId'] !== $workspaceId) {
			throw new NotFoundException('Suggestion job not found.');
		}

		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$catalog = $this->expenseCategoryCatalog($workspaceId, $userId);
		$context = new ReceiptSuggestContext(
			$catalog['ids'],
			(string)$workspace['currencyCode'],
			new \DateTimeImmutable('today'),
			$meta['source'],
		);

		$guard = $this->acceptGuard->assertAcceptable($accepted, $context);
		if ($guard['ok'] !== true) {
			throw new \InvalidArgumentException('Suggestion is no longer valid. Enter the booking manually.');
		}

		// Re-process to get canonical lines (after collapse etc.).
		$canonical = $this->pipeline->process(json_encode($accepted, JSON_THROW_ON_ERROR), $context);
		if (!$canonical->isReady()) {
			throw new \InvalidArgumentException('Suggestion is no longer valid. Enter the booking manually.');
		}

		$bookingDate = $canonical->bookingDate ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
		$titleBase = $canonical->title ?? $canonical->merchant ?? 'Receipt';
		$direction = $canonical->direction ?? ReceiptSuggestConstants::DIRECTION_EXPENSE;

		$created = [];
		$attachmentRows = [];
		$content = $this->staging->readBytes($userId, (int)$meta['fileId']);

		foreach ($canonical->lines as $index => $line) {
			$category = $this->categories->loadForWorkspace($line->categoryId, $workspaceId);
			if ($category === null) {
				throw new \InvalidArgumentException('Suggestion is no longer valid. Enter the booking manually.');
			}
			$title = count($canonical->lines) === 1
				? $titleBase
				: ($titleBase . ' — ' . $line->label);
			$payload = [
				'direction' => $direction,
				'title' => mb_substr($title, 0, ReceiptSuggestConstants::MAX_TITLE_LEN),
				'bookingDate' => $bookingDate,
				'amountMinor' => $line->amountMinor,
				'categoryId' => $line->categoryId,
				'notes' => null,
			];
			$tx = $this->transactions->create($workspaceId, $userId, $payload, $workspace, $category, null);
			$created[] = $tx;
			$txId = (int)$tx['id'];
			// Attach receipt to every booking in a split so each row has the proof.
			$attachmentRows[] = $this->attachments->attachFromBinary(
				$txId,
				$userId,
				$content,
				(string)$meta['fileName'],
				(string)$meta['mimeType'],
			);
		}

		$this->cleanupJob($meta, true);
		return [
			'transactions' => $created,
			'attachments' => $attachmentRows,
		];
	}

	public function cancelJob(int $workspaceId, string $userId, int $jobId): void
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$meta = $this->jobs->get($userId, $jobId);
		if ($meta === null || (int)$meta['workspaceId'] !== $workspaceId) {
			throw new NotFoundException('Suggestion job not found.');
		}
		$this->cleanupJob($meta, true);
	}

	/**
	 * @param JobMeta $meta
	 * @param array<string, mixed>|null $output
	 * @return array<string, mixed>
	 */
	private function advanceFromOcr(array $meta, ?array $output): array
	{
		$texts = $output['output'] ?? null;
		$ocrText = '';
		if (is_array($texts)) {
			$parts = [];
			foreach ($texts as $t) {
				if (is_string($t) && trim($t) !== '') {
					$parts[] = $t;
				}
			}
			$ocrText = implode("\n", $parts);
		} elseif (is_string($texts)) {
			$ocrText = $texts;
		}
		if (trim($ocrText) === '') {
			$this->cleanupJob($meta, true);
			return ReceiptSuggestionResult::lowQuality($meta['source'], 'ocr_empty')->toArray()
				+ ['jobId' => $meta['jobId']];
		}

		$catalog = [];
		foreach ($meta['categoryIds'] as $id) {
			$catalog[] = ['id' => $id, 'name' => 'Category ' . $id];
		}
		// Prefer live names when possible.
		try {
			$live = $this->expenseCategoryCatalog((int)$meta['workspaceId'], $meta['userId']);
			$catalog = $live['rows'];
		} catch (\Throwable) {
			// keep stub names — validator only needs ids
		}

		$input = $this->prompts->buildTextToTextInput($ocrText, $catalog, $meta['currencyCode']);
		$taskId = $this->tasks->schedule(
			ReceiptSuggestConstants::TASK_TEXT2TEXT,
			['input' => $input],
			$meta['userId'],
			$meta['customId'] . '-struct',
		);
		$meta['phase'] = self::PHASE_STRUCTURE;
		$meta['taskId'] = $taskId;
		$this->jobs->save($meta);

		return [
			'jobId' => $meta['jobId'],
			'status' => 'pending',
			'phase' => self::PHASE_STRUCTURE,
			'source' => $meta['source'],
		];
	}

	/**
	 * @param array<string, mixed>|null $output
	 */
	private function extractTextOutput(?array $output, string $phase): string
	{
		if ($output === null) {
			return '';
		}
		$raw = $output['output'] ?? null;
		if (is_string($raw)) {
			return $raw;
		}
		if (is_array($raw)) {
			$parts = [];
			foreach ($raw as $item) {
				if (is_string($item)) {
					$parts[] = $item;
				}
			}
			return implode("\n", $parts);
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private function cleanupJob(array $meta, bool $cancelTask): void
	{
		if ($cancelTask) {
			try {
				$this->tasks->cancel((int)$meta['taskId']);
			} catch (\Throwable) {
			}
			if ((int)$meta['taskId'] !== (int)$meta['jobId']) {
				try {
					$this->tasks->cancel((int)$meta['jobId']);
				} catch (\Throwable) {
				}
			}
		}
		try {
			$this->staging->delete($meta['userId'], (int)$meta['fileId']);
		} catch (\Throwable) {
		}
		$this->jobs->delete($meta['userId'], (int)$meta['jobId'], (int)$meta['workspaceId']);
	}

	/**
	 * @return array{ids:list<int>,rows:list<array{id:int,name:string}>}
	 */
	private function expenseCategoryCatalog(int $workspaceId, string $userId): array
	{
		$list = $this->categories->listForWorkspace($workspaceId, $userId, false);
		$ids = [];
		$rows = [];
		foreach ($list as $cat) {
			if (!(bool)($cat['isActive'] ?? true)) {
				continue;
			}
			$type = (string)($cat['type'] ?? '');
			if ($type !== 'expense' && $type !== 'both') {
				continue;
			}
			$id = (int)$cat['id'];
			$name = trim((string)($cat['name'] ?? ''));
			if ($id < 1 || $name === '') {
				continue;
			}
			$ids[] = $id;
			$rows[] = ['id' => $id, 'name' => $name];
		}
		return ['ids' => $ids, 'rows' => $rows];
	}
}
