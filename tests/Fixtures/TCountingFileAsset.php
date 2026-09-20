<?php

/**
 * TCountingFileAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\TFileAsset;

/**
 * TCountingFileAsset class.
 *
 * A test file asset that counts how many times its file path cache is reset, so a
 * behavior property setter can be checked for resetting its owner's cache.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCountingFileAsset extends TFileAsset
{
	/** @var int the number of file path cache resets. */
	public int $resets = 0;

	public function resetFilePathCache(): void
	{
		$this->resets++;
		parent::resetFilePathCache();
	}
}
