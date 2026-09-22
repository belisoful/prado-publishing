<?php

/**
 * TCopyingPublishingManager class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\TPublishingManager;

/**
 * TCopyingPublishingManager class.
 *
 * A test publishing manager that records the directories it is asked to copy, and
 * copies them.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCopyingPublishingManager extends TPublishingManager
{
	/** @var array<array{0:string, 1:string}> the source and destination of each copy, in order. */
	public array $copied = [];

	public function copyDirectory($src, $dst, $options = [], $basePath = null, &$visited = [])
	{
		$this->copied[] = [$src, $dst];
		return parent::copyDirectory($src, $dst, $options, $basePath, $visited);
	}
}
