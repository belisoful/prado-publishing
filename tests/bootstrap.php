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

require_once $coreTests . '/PradoUnit.php';
require_once $coreTests . '/Harness/IO/TarTestHelper.php';
require_once $coreTests . '/Web/TAssetManagerTest.php';

unset($pradoDir, $coreTests);
