<?php

/**
 * TPublishingManagerTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests;

use Prado\Test\Unit\Web\TAssetManagerTest;
use Prado\Web\TPublishingManager;

/**
 * TPublishingManagerTest class.
 *
 * Runs the full TAssetManager test suite against TPublishingManager to verify that
 * TPublishingManager remains a compatible drop-in replacement for TAssetManager.
 *
 * Every test inherited from {@see TAssetManagerTest} instantiates the class returned
 * by {@see getTestClass()}, so overriding it to TPublishingManager exercises the
 * subclass against the parent's contract.  The parent is loaded from the pradosoft/prado
 * package by tests/bootstrap.php.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPublishingManagerTest extends TAssetManagerTest
{
	protected function getTestClass(): string
	{
		return TPublishingManager::class;
	}
}
