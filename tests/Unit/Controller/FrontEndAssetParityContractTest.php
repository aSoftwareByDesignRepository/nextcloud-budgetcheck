<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Asset-pairing contract: a registered `common/<n>` script whose stylesheet
 * also ships must have that stylesheet registered too. Guards the
 * unregistered-paired-asset regression where `common/check-dates` loaded its
 * JS on every page but `css/common/check-dates.css` was never added, so every
 * date input rendered a stray `.bc-date-display` overlay plus an unstyled
 * `.bc-date-picker` button.
 */
final class FrontEndAssetParityContractTest extends TestCase {
	/** @return array<string,string> shipped PHP sources (path → contents) */
	private static function shippedSources(): array {
		$root = dirname(__DIR__, 3);
		$sources = [];
		foreach (['lib', 'templates'] as $dir) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if ($file->isFile() && $file->getExtension() === 'php') {
					$sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
				}
			}
		}
		self::assertNotEmpty($sources);
		return $sources;
	}

	/** @return array{scripts: array<string,string>, styles: array<string,string>} name → source path */
	private static function registeredAssets(): array {
		$scripts = [];
		$styles = [];
		foreach (self::shippedSources() as $path => $src) {
			if (preg_match_all(
				"/Util::addScript\\(\\s*Application::APP_ID\\s*,\\s*'([^']+)'\\s*\\)/",
				$src,
				$m
			)) {
				foreach ($m[1] as $name) {
					$scripts[$name] = $path;
				}
			}
			if (preg_match_all(
				"/Util::addStyle\\(\\s*Application::APP_ID\\s*,\\s*'([^']+)'\\s*\\)/",
				$src,
				$m
			)) {
				foreach ($m[1] as $name) {
					$styles[$name] = $path;
				}
			}
		}
		return ['scripts' => $scripts, 'styles' => $styles];
	}

	public function testRegisteredCommonScriptsWithShippedStylesheetHaveStyleRegistered(): void {
		$root = dirname(__DIR__, 3);
		$registered = self::registeredAssets();
		$missing = [];
		foreach ($registered['scripts'] as $name => $path) {
			if (!str_starts_with($name, 'common/')) {
				continue;
			}
			$css = $root . '/css/' . $name . '.css';
			if (!is_file($css)) {
				continue;
			}
			if (!isset($registered['styles'][$name])) {
				$missing[] = $name . ' (script in ' . basename($path) . ', css/' . $name . '.css shipped)';
			}
		}
		self::assertSame([], $missing, 'Script/style parity broken for: ' . implode(', ', $missing));
	}

	public function testEveryShippedCommonStylesheetIsRegistered(): void {
		$root = dirname(__DIR__, 3);
		$registered = self::registeredAssets();
		$unregistered = [];
		foreach (glob($root . '/css/common/*.css') ?: [] as $file) {
			$name = 'common/' . basename($file, '.css');
			if (!isset($registered['styles'][$name])) {
				$unregistered[] = $name;
			}
		}
		self::assertSame([], $unregistered, 'Shipped stylesheets never registered: ' . implode(', ', $unregistered));
	}
}
