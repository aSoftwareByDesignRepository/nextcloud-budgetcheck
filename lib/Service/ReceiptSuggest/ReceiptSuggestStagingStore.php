<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Stages receipt bytes in the user's file tree so Task Processing mount checks pass.
 * Appdata is intentionally avoided (providers cannot read it for the user).
 */
class ReceiptSuggestStagingStore
{
	public const FOLDER = '.budgetcheck-receipt-suggest';

	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return array{fileId:int,path:string,fileName:string,mimeType:string,size:int}
	 */
	public function storeUpload(string $userId, array $file): array
	{
		if (!isset($file['error']) || (int)$file['error'] !== \UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException('File upload failed. Please try again.');
		}
		$tmp = (string)($file['tmp_name'] ?? '');
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			throw new \InvalidArgumentException('Invalid upload source.');
		}
		$content = file_get_contents($tmp);
		if ($content === false || $content === '') {
			throw new \InvalidArgumentException('Uploaded file is empty.');
		}
		$original = basename((string)($file['name'] ?? 'receipt.jpg'));
		$mime = (string)($file['type'] ?? 'application/octet-stream');
		return $this->storeBytes($userId, $content, $original, $mime);
	}

	/**
	 * @return array{fileId:int,path:string,fileName:string,mimeType:string,size:int}
	 */
	public function storeBytes(string $userId, string $content, string $originalName, string $mimeType): array
	{
		$size = strlen($content);
		if ($size < 1) {
			throw new \InvalidArgumentException('Uploaded file is empty.');
		}
		if ($size > 5 * 1024 * 1024) {
			throw new \InvalidArgumentException('File is too large (max 5 MB).');
		}

		$safeName = $this->sanitizeFileName($originalName);
		$ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
		$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
		if (!in_array($ext, $allowed, true)) {
			throw new \InvalidArgumentException('Only receipt images or PDF files are supported.');
		}
		foreach (['php', 'phtml', 'phar', 'exe', 'sh', 'js'] as $danger) {
			if (str_contains(strtolower($safeName), '.' . $danger)) {
				throw new \InvalidArgumentException('This file type is not allowed.');
			}
		}

		$mime = $this->normalizeMime($mimeType, $ext);
		$stored = bin2hex(random_bytes(16)) . '.' . $ext;

		$folder = $this->ensureFolder($userId);
		$node = $folder->newFile($stored, $content);
		if (!$node instanceof File) {
			throw new \RuntimeException('Could not stage receipt file.');
		}

		return [
			'fileId' => $node->getId(),
			'path' => $node->getPath(),
			'fileName' => $safeName,
			'mimeType' => $mime,
			'size' => $size,
		];
	}

	public function readBytes(string $userId, int $fileId): string
	{
		$file = $this->getOwnedFile($userId, $fileId);
		$content = $file->getContent();
		if ($content === '') {
			throw new \InvalidArgumentException('Staged receipt file is empty.');
		}
		return $content;
	}

	public function delete(string $userId, int $fileId): void
	{
		try {
			$file = $this->getOwnedFile($userId, $fileId);
			$file->delete();
		} catch (NotFoundException) {
			// already gone
		} catch (NotPermittedException) {
			// best-effort cleanup
		}
	}

	private function ensureFolder(string $userId): Folder
	{
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if ($userFolder->nodeExists(self::FOLDER)) {
			$node = $userFolder->get(self::FOLDER);
			if ($node instanceof Folder) {
				return $node;
			}
			$node->delete();
		}
		return $userFolder->newFolder(self::FOLDER);
	}

	private function getOwnedFile(string $userId, int $fileId): File
	{
		$nodes = $this->rootFolder->getById($fileId);
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$path = $node->getPath();
			$userPath = $this->rootFolder->getUserFolder($userId)->getPath();
			$expectedPrefix = $userPath . '/' . self::FOLDER . '/';
			if (str_starts_with($path, $expectedPrefix)) {
				return $node;
			}
		}
		throw new NotFoundException('Staged receipt file not found.');
	}

	private function sanitizeFileName(string $name): string
	{
		$base = basename(str_replace(["\0", '\\'], '', $name));
		$base = trim($base);
		return $base !== '' ? $base : 'receipt.jpg';
	}

	private function normalizeMime(string $mime, string $ext): string
	{
		$mime = strtolower(trim($mime));
		$map = [
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'pdf' => 'application/pdf',
		];
		if (isset($map[$ext]) && ($mime === '' || $mime === 'application/octet-stream')) {
			return $map[$ext];
		}
		$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
		if (!in_array($mime, $allowed, true)) {
			return $map[$ext] ?? 'application/octet-stream';
		}
		return $mime;
	}
}
