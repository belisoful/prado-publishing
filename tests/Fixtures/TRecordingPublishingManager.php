<?php

/**
 * TRecordingPublishingManager class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\TPublishingManager;

/**
 * TRecordingPublishingManager class.
 *
 * A test publishing manager that records what it is asked to publish instead of
 * publishing it, and optionally throws {@see $throw} from publish.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingPublishingManager extends TPublishingManager
{
	/** @var array the paths and assets passed to publish, in order. */
	public array $recorded = [];

	/** @var ?\Throwable the exception thrown by publish, when set. */
	public ?\Throwable $throw = null;

	public function publish($path, $checkTimestamp = false)
	{
		$this->recorded[] = $path;
		if ($this->throw !== null) {
			throw $this->throw;
		}
		return '/recorded';
	}
}
