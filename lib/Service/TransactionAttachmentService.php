<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;

/**
 * Secure storage and delivery of receipt images linked to transactions.
 *
 * Files live in app data ({@see APPDATA_ATTACHMENTS}) — never in user-visible
 * folders — and every read/write is gated by workspace membership.
 */
class TransactionAttachmentService
{
	public const APPDATA_ATTACHMENTS = 'tx-attachments';

	private const MAX_FILE_SIZE = 5 * 1024 * 1024;
	private const MAX_FILES_PER_TRANSACTION = 10;
	private const MAX_TOTAL_SIZE_PER_TRANSACTION = 25 * 1024 * 1024;

	private const ALLOWED_EXTENSIONS = [
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'xml',
	];

	private const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/xml',
		'application/xml',
	];

	private const INLINE_IMAGE_MIMES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	];

	private const INLINE_PREVIEW_MIMES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/xml',
		'application/xml',
	];

	private const IMAGE_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	];

	private const DANGEROUS_EXTENSIONS = [
		'php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar',
		'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'app',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IRootFolder $rootFolder,
		private readonly IConfig $config,
		private readonly AccessControlService $access,
		private readonly WorkspaceService $workspaces,
		private readonly AuditLogService $audit,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @param list<int> $transactionIds
	 * @return array<int, int> transaction id => attachment count
	 */
	public function countsByTransactionIds(array $transactionIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn (int $id): bool => $id > 0)));
		if ($ids === [] || !$this->db->tableExists('bc_tx_attachments')) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('transaction_id')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from('bc_tx_attachments')
			->where($qb->expr()->in('transaction_id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('transaction_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['transaction_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listForTransaction(int $transactionId, string $userId): array
	{
		$transaction = $this->resolveReadableTransaction($transactionId, $userId);
		$rows = $this->loadRowsForTransaction($transactionId);
		return array_map(fn (array $row): array => $this->hydrate($row), $rows);
	}

	/**
	 * @param array{name?:mixed,tmp_name?:mixed,size?:mixed,error?:mixed} $file
	 * @return array<string, mixed>
	 */
	public function upload(int $transactionId, string $userId, array $file): array
	{
		$readable = $this->resolveReadableTransaction($transactionId, $userId);
		$this->access->ensureMinimumRole((int)$readable['workspace_id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);

		if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException('File upload failed. Please try again.');
		}

		$validation = $this->validateUploadedFile($file);
		if (($validation['success'] ?? false) !== true) {
			throw new \InvalidArgumentException((string)($validation['message'] ?? 'Invalid file.'));
		}

		$mimeType = (string)$validation['mimeType'];
		$originalName = (string)$validation['originalName'];
		$storedName = $this->buildStoredFilename($originalName);
		$newSize = (int)($file['size'] ?? 0);
		$tmpPath = (string)($file['tmp_name'] ?? '');
		if (!is_uploaded_file($tmpPath)) {
			throw new \InvalidArgumentException('Invalid upload source.');
		}

		$transaction = null;
		$attachmentId = 0;
		$this->db->beginTransaction();
		try {
			$transaction = $this->resolveWritableTransaction($transactionId, $userId);
			$this->lockTransactionRow($transactionId);

			$existing = $this->loadRowsForTransaction($transactionId);
			if (count($existing) >= self::MAX_FILES_PER_TRANSACTION) {
				throw new \InvalidArgumentException('This transaction already has the maximum number of attachments.');
			}

			$totalSize = $newSize;
			foreach ($existing as $row) {
				$totalSize += (int)$row['file_size'];
			}
			if ($totalSize > self::MAX_TOTAL_SIZE_PER_TRANSACTION) {
				throw new \InvalidArgumentException('Total attachment size for this transaction would exceed the limit.');
			}

			$this->persistFile((int)$transaction['id'], $storedName, $tmpPath);

			try {
				$now = $this->utcNow();
				$qb = $this->db->getQueryBuilder();
				$qb->insert('bc_tx_attachments')
					->values([
						'transaction_id' => $qb->createNamedParameter($transactionId, \PDO::PARAM_INT),
						'stored_name' => $qb->createNamedParameter($storedName),
						'original_name' => $qb->createNamedParameter($originalName),
						'mime_type' => $qb->createNamedParameter($mimeType),
						'file_size' => $qb->createNamedParameter($newSize, \PDO::PARAM_INT),
						'created_by' => $qb->createNamedParameter($userId),
						'created_at' => $qb->createNamedParameter($now),
					]);
				$qb->executeStatement();
				$attachmentId = (int)$qb->getLastInsertId();
			} catch (\Throwable $dbError) {
				$this->deleteStoredFile((int)$transaction['id'], $storedName);
				throw $dbError;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->audit->record(
			$userId,
			'transaction_attachment_uploaded',
			'transaction_attachment',
			(string)$attachmentId,
			['transactionId' => $transactionId, 'fileName' => $originalName],
			(int)$transaction['workspace_id'],
		);

		$row = $this->loadRowById($attachmentId);
		if ($row === null) {
			throw new \RuntimeException('Attachment record could not be loaded after upload.');
		}

		return $this->hydrate($row);
	}

	/**
	 * Attach a receipt from trusted server-side bytes (e.g. receipt AI staging).
	 * Caller must already enforce ACL; this re-checks contributor + closed month via writable resolve.
	 *
	 * @return array<string, mixed>
	 */
	public function attachFromBinary(
		int $transactionId,
		string $userId,
		string $content,
		string $originalName,
		string $mimeType,
	): array {
		$readable = $this->resolveReadableTransaction($transactionId, $userId);
		$this->access->ensureMinimumRole((int)$readable['workspace_id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);

		$size = strlen($content);
		if ($size < 1) {
			throw new \InvalidArgumentException('Attachment content is empty.');
		}
		if ($size > self::MAX_FILE_SIZE) {
			throw new \InvalidArgumentException('File is too large (max 5 MB).');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'bc_rs_');
		if ($tmp === false) {
			throw new \RuntimeException('Could not stage attachment for validation.');
		}
		try {
			if (file_put_contents($tmp, $content) === false) {
				throw new \RuntimeException('Could not stage attachment for validation.');
			}
			$validation = $this->validateUploadedFile([
				'name' => $originalName,
				'size' => $size,
				'type' => $mimeType,
				'tmp_name' => $tmp,
				'error' => \UPLOAD_ERR_OK,
			]);
		} finally {
			@unlink($tmp);
		}
		if (($validation['success'] ?? false) !== true) {
			throw new \InvalidArgumentException((string)($validation['message'] ?? 'Invalid file.'));
		}

		$mimeType = (string)$validation['mimeType'];
		$originalName = (string)$validation['originalName'];
		$storedName = $this->buildStoredFilename($originalName);

		$transaction = null;
		$attachmentId = 0;
		$this->db->beginTransaction();
		try {
			$transaction = $this->resolveWritableTransaction($transactionId, $userId);
			$this->lockTransactionRow($transactionId);

			$existing = $this->loadRowsForTransaction($transactionId);
			if (count($existing) >= self::MAX_FILES_PER_TRANSACTION) {
				throw new \InvalidArgumentException('This transaction already has the maximum number of attachments.');
			}

			$totalSize = $size;
			foreach ($existing as $row) {
				$totalSize += (int)$row['file_size'];
			}
			if ($totalSize > self::MAX_TOTAL_SIZE_PER_TRANSACTION) {
				throw new \InvalidArgumentException('Total attachment size for this transaction would exceed the limit.');
			}

			$folder = $this->getTransactionFolder((int)$transaction['id'], true);
			$folder->newFile($storedName, $content);

			try {
				$now = $this->utcNow();
				$qb = $this->db->getQueryBuilder();
				$qb->insert('bc_tx_attachments')
					->values([
						'transaction_id' => $qb->createNamedParameter($transactionId, \PDO::PARAM_INT),
						'stored_name' => $qb->createNamedParameter($storedName),
						'original_name' => $qb->createNamedParameter($originalName),
						'mime_type' => $qb->createNamedParameter($mimeType),
						'file_size' => $qb->createNamedParameter($size, \PDO::PARAM_INT),
						'created_by' => $qb->createNamedParameter($userId),
						'created_at' => $qb->createNamedParameter($now),
					]);
				$qb->executeStatement();
				$attachmentId = (int)$qb->getLastInsertId();
			} catch (\Throwable $dbError) {
				$this->deleteStoredFile((int)$transaction['id'], $storedName);
				throw $dbError;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->audit->record(
			$userId,
			'transaction_attachment_uploaded',
			'transaction_attachment',
			(string)$attachmentId,
			['transactionId' => $transactionId, 'fileName' => $originalName, 'source' => 'receipt_suggest'],
			(int)$transaction['workspace_id'],
		);

		$row = $this->loadRowById($attachmentId);
		if ($row === null) {
			throw new \RuntimeException('Attachment record could not be loaded after upload.');
		}

		return $this->hydrate($row);
	}

	/**
	 * Replace an existing image attachment with edited bytes (rotate/crop save).
	 *
	 * @param array{name?:mixed,tmp_name?:mixed,size?:mixed,error?:mixed} $file
	 * @return array<string, mixed>
	 */
	public function replace(int $attachmentId, string $userId, array $file): array
	{
		$row = $this->loadRowById($attachmentId);
		if ($row === null) {
			throw new AccessDeniedException();
		}

		$existingMime = (string)$row['mime_type'];
		if (!str_starts_with($existingMime, 'image/')) {
			throw new \InvalidArgumentException('Only image attachments can be edited.');
		}

		if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException('File upload failed. Please try again.');
		}

		$validation = $this->validateUploadedFile($file, true);
		if (($validation['success'] ?? false) !== true) {
			throw new \InvalidArgumentException((string)($validation['message'] ?? 'Invalid file.'));
		}

		$mimeType = (string)$validation['mimeType'];
		$newSize = (int)($file['size'] ?? 0);
		$tmpPath = (string)($file['tmp_name'] ?? '');
		if (!is_uploaded_file($tmpPath)) {
			throw new \InvalidArgumentException('Invalid upload source.');
		}

		$transactionId = (int)$row['transaction_id'];
		$storedName = (string)$row['stored_name'];
		$oldSize = (int)$row['file_size'];
		$workspaceId = 0;

		$this->db->beginTransaction();
		try {
			$transaction = $this->resolveWritableTransaction($transactionId, $userId);
			$workspaceId = (int)$transaction['workspace_id'];
			$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
			$this->lockTransactionRow($transactionId);

			$existing = $this->loadRowsForTransaction($transactionId);
			$totalSize = $newSize;
			foreach ($existing as $existingRow) {
				if ((int)$existingRow['id'] === $attachmentId) {
					continue;
				}
				$totalSize += (int)$existingRow['file_size'];
			}
			if ($totalSize > self::MAX_TOTAL_SIZE_PER_TRANSACTION) {
				throw new \InvalidArgumentException('Total attachment size for this transaction would exceed the limit.');
			}

			$this->overwriteStoredFile($transactionId, $storedName, $tmpPath);

			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_tx_attachments')
				->set('mime_type', $qb->createNamedParameter($mimeType))
				->set('file_size', $qb->createNamedParameter($newSize, \PDO::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($attachmentId, \PDO::PARAM_INT)));
			$qb->executeStatement();

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->audit->record(
			$userId,
			'transaction_attachment_replaced',
			'transaction_attachment',
			(string)$attachmentId,
			[
				'transactionId' => $transactionId,
				'previousSize' => $oldSize,
				'newSize' => $newSize,
			],
			$workspaceId,
		);

		$updated = $this->loadRowById($attachmentId);
		if ($updated === null) {
			throw new \RuntimeException('Attachment record could not be loaded after replace.');
		}

		return $this->hydrate($updated);
	}

	public function delete(int $attachmentId, string $userId): void
	{
		$row = $this->loadRowById($attachmentId);
		if ($row === null) {
			throw new AccessDeniedException();
		}

		$transaction = $this->resolveWritableTransaction((int)$row['transaction_id'], $userId);
		$this->access->ensureMinimumRole((int)$transaction['workspace_id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);

		$this->deleteStoredFile((int)$row['transaction_id'], (string)$row['stored_name']);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_tx_attachments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($attachmentId, \PDO::PARAM_INT)));
		$qb->executeStatement();

		$this->audit->record(
			$userId,
			'transaction_attachment_deleted',
			'transaction_attachment',
			(string)$attachmentId,
			['transactionId' => (int)$row['transaction_id']],
			(int)$transaction['workspace_id'],
		);
	}

	/**
	 * @return array{row: array<string, mixed>, filePath: string, disposition: string}
	 */
	public function resolveForDelivery(int $attachmentId, string $userId, bool $requestInline): array
	{
		$row = $this->loadRowById($attachmentId);
		if ($row === null) {
			throw new AccessDeniedException();
		}

		$this->resolveReadableTransaction((int)$row['transaction_id'], $userId);

		$filePath = $this->resolveFilesystemPath((int)$row['transaction_id'], (string)$row['stored_name']);
		if ($filePath === null) {
			throw new \RuntimeException('Attachment file is missing on disk.');
		}

		$mimeType = (string)$row['mime_type'];
		$disposition = ($requestInline && self::isInlinePreviewable($mimeType))
			? 'inline'
			: 'attachment';

		return [
			'row' => $row,
			'filePath' => $filePath,
			'disposition' => $disposition,
		];
	}

	public static function isPreviewableImage(string $mimeType): bool
	{
		return in_array($mimeType, self::INLINE_IMAGE_MIMES, true);
	}

	public static function isInlinePreviewable(string $mimeType): bool
	{
		return in_array($mimeType, self::INLINE_PREVIEW_MIMES, true);
	}

	public static function isPdfMime(string $mimeType): bool
	{
		return $mimeType === 'application/pdf';
	}

	public static function isEInvoiceXmlMime(string $mimeType): bool
	{
		return in_array($mimeType, ['text/xml', 'application/xml'], true);
	}

	/**
	 * @param array{name?:mixed,tmp_name?:mixed,size?:mixed} $file
	 * @return array{success: bool, message?: string, mimeType?: string, originalName?: string}
	 */
	public function validateUploadedFile(array $file, bool $imagesOnly = false): array
	{
		if (!isset($file['size'], $file['name'], $file['tmp_name'])) {
			return ['success' => false, 'message' => 'Invalid upload parameters.'];
		}

		if ((int)$file['size'] > self::MAX_FILE_SIZE) {
			return ['success' => false, 'message' => 'File is too large. Maximum size is 5 MB per file.'];
		}

		if ((int)$file['size'] < 1) {
			return ['success' => false, 'message' => 'File is empty.'];
		}

		$originalName = basename((string)$file['name']);
		if ($originalName === '' || $originalName === '.' || $originalName === '..') {
			return ['success' => false, 'message' => 'Invalid file name.'];
		}

		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			return ['success' => false, 'message' => 'File type is not allowed. Use JPEG, PNG, GIF, WebP, PDF, or XML e-invoice.'];
		}

		$tmpPath = (string)$file['tmp_name'];
		if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
			return ['success' => false, 'message' => 'Upload could not be read.'];
		}

		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mimeType = $finfo ? finfo_file($finfo, $tmpPath) : false;
		if ($finfo) {
			finfo_close($finfo);
		}
		if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			return ['success' => false, 'message' => 'File content does not match an allowed type.'];
		}

		if ($imagesOnly && !in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
			return ['success' => false, 'message' => 'Only image files can replace an existing receipt photo.'];
		}

		if ($mimeType === 'application/pdf' && $extension !== 'pdf') {
			return ['success' => false, 'message' => 'File extension does not match content.'];
		}
		if (in_array($mimeType, ['text/xml', 'application/xml'], true) && $extension !== 'xml') {
			return ['success' => false, 'message' => 'File extension does not match content.'];
		}
		if (str_starts_with($mimeType, 'image/') && !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
			return ['success' => false, 'message' => 'File extension does not match content.'];
		}

		if (in_array($mimeType, ['text/xml', 'application/xml'], true) && !$this->validateEInvoiceXmlContent($tmpPath)) {
			return ['success' => false, 'message' => 'XML file does not look like a valid e-invoice document.'];
		}

		if (substr_count($originalName, '.') > 1) {
			$parts = explode('.', strtolower($originalName));
			$lastPart = (string)end($parts);
			$secondLastPart = count($parts) > 1 ? (string)$parts[count($parts) - 2] : '';
			if (in_array($lastPart, self::DANGEROUS_EXTENSIONS, true) || in_array($secondLastPart, self::DANGEROUS_EXTENSIONS, true)) {
				return ['success' => false, 'message' => 'File name contains a blocked extension.'];
			}
		}

		foreach (self::DANGEROUS_EXTENSIONS as $dangerousExtension) {
			if (stripos($originalName, '.' . $dangerousExtension) !== false) {
				return ['success' => false, 'message' => 'File name contains a blocked extension.'];
			}
		}

		return [
			'success' => true,
			'mimeType' => $mimeType,
			'originalName' => $originalName,
		];
	}

	public function sanitizeContentDispositionFilename(string $filename): string
	{
		$clean = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/', '_', $filename) ?? 'attachment';
		$clean = trim($clean, " \t._");
		if ($clean === '') {
			return 'attachment';
		}
		return mb_substr($clean, 0, 180);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate(array $row): array
	{
		$id = (int)$row['id'];
		$mimeType = (string)$row['mime_type'];
		$isImage = str_starts_with($mimeType, 'image/');
		$isPdf = self::isPdfMime($mimeType);
		$isXml = self::isEInvoiceXmlMime($mimeType);
		$inlinePreview = self::isInlinePreviewable($mimeType);
		return [
			'id' => $id,
			'transactionId' => (int)$row['transaction_id'],
			'fileName' => (string)$row['original_name'],
			'mimeType' => $mimeType,
			'fileSize' => (int)$row['file_size'],
			'isImage' => $isImage,
			'isPdf' => $isPdf,
			'isXml' => $isXml,
			'isPreviewable' => self::isPreviewableImage($mimeType),
			'isEditableImage' => $isImage,
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'previewUrl' => $inlinePreview
				? $this->urlGenerator->linkToRoute('budgetcheck.attachment.download', ['id' => $id, 'inline' => '1'])
				: null,
			'downloadUrl' => $this->urlGenerator->linkToRoute('budgetcheck.attachment.download', ['id' => $id]),
		];
	}

	private function validateEInvoiceXmlContent(string $tmpPath): bool
	{
		$head = @file_get_contents($tmpPath, false, null, 0, 8192);
		if (!is_string($head) || $head === '') {
			return false;
		}
		$trimmed = ltrim($head);
		if ($trimmed === '' || $trimmed[0] !== '<') {
			return false;
		}
		if (stripos($head, '<!ENTITY') !== false) {
			return false;
		}
		if (preg_match('/<\?php/i', $head) === 1) {
			return false;
		}
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resolveReadableTransaction(int $transactionId, string $userId): array
	{
		$row = $this->loadTransactionRow($transactionId);
		if ($row === null || $row['deleted_at'] !== null) {
			throw new AccessDeniedException();
		}
		$this->workspaces->getForUser((int)$row['workspace_id'], $userId);
		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resolveWritableTransaction(int $transactionId, string $userId): array
	{
		$row = $this->resolveReadableTransaction($transactionId, $userId);
		$ym = substr((string)$row['booking_date'], 0, 7);
		if ($this->monthIsClosed((int)$row['workspace_id'], $ym)) {
			throw new \InvalidArgumentException('This transaction belongs to a closed month. Reopen the month before adding or removing attachments.');
		}
		return $row;
	}

	private function monthIsClosed(int $workspaceId, string $yearMonth): bool
	{
		if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_monthly_snapshots')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		$result = $qb->executeQuery();
		$countRow = $result->fetch();
		$result->closeCursor();
		return (int)($countRow['count'] ?? 0) > 0;
	}

	private function lockTransactionRow(int $transactionId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_transactions')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new AccessDeniedException();
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadTransactionRow(int $transactionId): ?array
	{
		if ($transactionId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'booking_date', 'deleted_at')
			->from('bc_transactions')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function loadRowsForTransaction(int $transactionId): array
	{
		if (!$this->db->tableExists('bc_tx_attachments')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_tx_attachments')
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadRowById(int $attachmentId): ?array
	{
		if ($attachmentId < 1 || !$this->db->tableExists('bc_tx_attachments')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_tx_attachments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($attachmentId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false ? $row : null;
	}

	private function buildStoredFilename(string $originalName): string
	{
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$prefix = bin2hex(random_bytes(16));
		return $extension !== '' ? ($prefix . '.' . $extension) : $prefix;
	}

	private function persistFile(int $transactionId, string $storedName, string $tmpPath): void
	{
		if ($storedName === '' || str_contains($storedName, '/') || str_contains($storedName, '\\')) {
			throw new \InvalidArgumentException('Invalid stored file name.');
		}

		$content = file_get_contents($tmpPath);
		if ($content === false) {
			throw new \RuntimeException('Could not read uploaded file.');
		}

		$folder = $this->getTransactionFolder($transactionId, true);
		$folder->newFile($storedName, $content);
	}

	private function overwriteStoredFile(int $transactionId, string $storedName, string $tmpPath): void
	{
		if ($storedName === '' || str_contains($storedName, '/') || str_contains($storedName, '\\')) {
			throw new \InvalidArgumentException('Invalid stored file name.');
		}

		$content = file_get_contents($tmpPath);
		if ($content === false) {
			throw new \RuntimeException('Could not read uploaded file.');
		}

		$folder = $this->getTransactionFolder($transactionId, true);
		if (!$folder->nodeExists($storedName)) {
			throw new \RuntimeException('Attachment file is missing on disk.');
		}
		$node = $folder->get($storedName);
		if (!$node instanceof File) {
			throw new \RuntimeException('Attachment path is not a file.');
		}
		$node->putContent($content);
	}

	private function deleteStoredFile(int $transactionId, string $storedName): void
	{
		$safeName = basename($storedName);
		if ($safeName === '' || $safeName === '.') {
			return;
		}
		try {
			$folder = $this->getTransactionFolder($transactionId, false);
			if ($folder->nodeExists($safeName)) {
				$node = $folder->get($safeName);
				if ($node instanceof File) {
					$node->delete();
				}
			}
		} catch (NotFoundException) {
			// Folder already gone — nothing to delete.
		}
	}

	private function getTransactionFolder(int $transactionId, bool $create): Folder
	{
		$appFolder = $this->getAppDataFolder($create);
		$txPath = (string)$transactionId;
		if (!$appFolder->nodeExists($txPath)) {
			if (!$create) {
				throw new NotFoundException('Transaction attachment folder missing.');
			}
			return $appFolder->newFolder($txPath);
		}
		$folder = $appFolder->get($txPath);
		if (!$folder instanceof Folder) {
			throw new \RuntimeException('Transaction attachment path is not a folder.');
		}
		return $folder;
	}

	private function getAppDataFolder(bool $create): Folder
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			throw new \RuntimeException('Instance id is not configured.');
		}

		$instanceFolder = $this->rootFolder->get('appdata_' . $instanceId);
		$appRootPath = BudgetCheckTableCatalog::APP_ID;
		if (!$instanceFolder->nodeExists($appRootPath)) {
			if (!$create) {
				throw new NotFoundException('App data folder missing.');
			}
			$appRoot = $instanceFolder->newFolder($appRootPath);
		} else {
			$appRoot = $instanceFolder->get($appRootPath);
		}
		if (!$appRoot instanceof Folder) {
			throw new \RuntimeException('App data path is not a folder.');
		}

		if (!$appRoot->nodeExists(self::APPDATA_ATTACHMENTS)) {
			if (!$create) {
				throw new NotFoundException('Attachments folder missing.');
			}
			return $appRoot->newFolder(self::APPDATA_ATTACHMENTS);
		}

		$attachmentsRoot = $appRoot->get(self::APPDATA_ATTACHMENTS);
		if (!$attachmentsRoot instanceof Folder) {
			throw new \RuntimeException('Attachments path is not a folder.');
		}
		return $attachmentsRoot;
	}

	private function resolveFilesystemPath(int $transactionId, string $storedName): ?string
	{
		$safeName = basename($storedName);
		if ($safeName === '' || $safeName === '.') {
			return null;
		}

		try {
			$folder = $this->getTransactionFolder($transactionId, false);
			if (!$folder->nodeExists($safeName)) {
				return null;
			}
			$node = $folder->get($safeName);
			if (!$node instanceof File) {
				return null;
			}
			$path = $node->getStorage()->getLocalFile($node->getInternalPath());
			if (!is_string($path) || !is_file($path) || !is_readable($path)) {
				return null;
			}
			return $path;
		} catch (NotFoundException) {
			return null;
		}
	}

	private function utcNow(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->format('Y-m-d H:i:s');
	}
}
