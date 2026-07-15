<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\TransactionAttachmentService;
use PHPUnit\Framework\TestCase;

final class TransactionAttachmentUploadValidationTest extends TestCase
{
	public function testRejectsMissingFields(): void
	{
		$result = self::service()->validateUploadedFile([]);
		$this->assertFalse($result['success']);
	}

	public function testRejectsDangerousExtensionInName(): void
	{
		$path = $this->tempFile('evil.php.jpg', 'plain text');
		$result = self::service()->validateUploadedFile([
			'name' => 'evil.php.jpg',
			'size' => filesize($path),
			'tmp_name' => $path,
		]);
		$this->assertFalse($result['success']);
	}

	public function testAcceptsValidPng(): void
	{
		$path = $this->tempPng();
		$result = self::service()->validateUploadedFile([
			'name' => 'receipt.png',
			'size' => filesize($path),
			'tmp_name' => $path,
		]);
		$this->assertTrue($result['success']);
		$this->assertSame('image/png', $result['mimeType']);
	}

	public function testSanitizeContentDispositionFilename(): void
	{
		$this->assertSame('receipt_2026.pdf', self::service()->sanitizeContentDispositionFilename('receipt/2026.pdf'));
	}

	public function testPreviewableImageMimeTypes(): void
	{
		$this->assertTrue(TransactionAttachmentService::isPreviewableImage('image/jpeg'));
		$this->assertFalse(TransactionAttachmentService::isPreviewableImage('application/pdf'));
		$this->assertTrue(TransactionAttachmentService::isInlinePreviewable('application/pdf'));
		$this->assertTrue(TransactionAttachmentService::isInlinePreviewable('text/xml'));
		$this->assertTrue(TransactionAttachmentService::isEInvoiceXmlMime('application/xml'));
	}

	public function testAcceptsValidXml(): void
	{
		$path = $this->tempFile('invoice.xml', '<?xml version="1.0"?><Invoice></Invoice>');
		$result = self::service()->validateUploadedFile([
			'name' => 'invoice.xml',
			'size' => filesize($path),
			'tmp_name' => $path,
		]);
		$this->assertTrue($result['success']);
		$this->assertSame('text/xml', $result['mimeType']);
	}

	public function testRejectsXmlWithEntity(): void
	{
		$path = $this->tempFile('bad.xml', '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe "test">]><Invoice></Invoice>');
		$result = self::service()->validateUploadedFile([
			'name' => 'bad.xml',
			'size' => filesize($path),
			'tmp_name' => $path,
		]);
		$this->assertFalse($result['success']);
	}

	private function tempFile(string $name, string $contents): string
	{
		$path = sys_get_temp_dir() . '/' . $name;
		file_put_contents($path, $contents);
		$this->addCleanup($path);
		return $path;
	}

	private function tempPng(): string
	{
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD extension required.');
		}
		$img = imagecreatetruecolor(2, 2);
		$path = sys_get_temp_dir() . '/bc-test-' . bin2hex(random_bytes(4)) . '.png';
		imagepng($img, $path);
		imagedestroy($img);
		$this->addCleanup($path);
		return $path;
	}

	private function addCleanup(string $path): void
	{
		register_shutdown_function(static function () use ($path): void {
			if (is_file($path)) {
				@unlink($path);
			}
		});
	}

	private static function service(): TransactionAttachmentService
	{
		$ref = new \ReflectionClass(TransactionAttachmentService::class);
		/** @var TransactionAttachmentService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		return $svc;
	}
}
