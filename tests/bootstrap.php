<?php

/**
 * Test bootstrap for belisoful/prado-publishing.
 *
 * TPublishingManager must remain a drop-in replacement for TAssetManager, so the suite runs
 * the framework's own TAssetManagerTest against it.  That test and its harness helpers ship
 * in the pradosoft/prado package's tests/ directory; they are loaded from there rather than
 * copied, so the contract cannot drift from core.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

require_once __DIR__ . '/../vendor/autoload.php';

$pradoDir = dirname((new ReflectionClass(\Prado\Prado::class))->getFileName(), 2);
$coreTests = $pradoDir . '/tests/unit';

foreach (['/PradoUnit.php', '/Harness/IO/TarTestHelper.php', '/Web/TAssetManagerTest.php'] as $coreFile) {
	if (!is_file($coreTests . $coreFile)) {
		fwrite(STDERR, "The pradosoft/prado package at $pradoDir does not have tests/unit$coreFile.\n"
			. "The suite runs the framework's own TAssetManagerTest against TPublishingManager, so\n"
			. "install the package with its tests: composer update --prefer-source pradosoft/prado\n");
		exit(1);
	}
	require_once $coreTests . $coreFile;
}

unset($pradoDir, $coreTests, $coreFile);
