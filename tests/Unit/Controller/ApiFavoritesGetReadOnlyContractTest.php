<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * GET workspace list/favorites must not persist favorites (§11.2 read-only GET).
 */
final class ApiFavoritesGetReadOnlyContractTest extends TestCase
{
	public function testListAndGetFavoritesDoNotCallSave(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ApiController.php'
		);
		self::assertMatchesRegularExpression(
			'/function listWorkspaces\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s',
			$src,
			'listWorkspaces body extractable'
		);
		preg_match(
			'/function listWorkspaces\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s',
			$src,
			$mList
		);
		self::assertStringNotContainsString(
			'saveFavoriteWorkspaceIds',
			$mList[1],
			'listWorkspaces must not write favorites'
		);

		preg_match(
			'/function getWorkspaceFavorites\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s',
			$src,
			$mGet
		);
		self::assertNotEmpty($mGet[1] ?? null);
		self::assertStringNotContainsString(
			'saveFavoriteWorkspaceIds',
			$mGet[1],
			'getWorkspaceFavorites must not write favorites'
		);

		preg_match(
			'/function saveWorkspaceFavorites\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s',
			$src,
			$mSave
		);
		self::assertNotEmpty($mSave[1] ?? null);
		self::assertStringContainsString(
			'saveFavoriteWorkspaceIds',
			$mSave[1],
			'saveWorkspaceFavorites is the sole write path'
		);
	}

	public function testPageResolveDoesNotPersistFavorites(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/PageController.php'
		);
		preg_match(
			'/function resolveWorkspace\(\): array\s*\{(.*?)\n\t\}/s',
			$src,
			$m
		);
		self::assertNotEmpty($m[1] ?? null, 'resolveWorkspace body extractable');
		self::assertStringNotContainsString(
			'saveFavoriteWorkspaceIds',
			$m[1],
			'PageController resolveWorkspace must not write favorites on GET'
		);
		self::assertStringContainsString('favoriteWorkspaceIds', $m[1]);
	}
}
