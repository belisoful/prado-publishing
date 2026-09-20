<?php

/**
 * TFixedOrientationMetaData class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\TAssetImageMetaData;

/**
 * TFixedOrientationMetaData class.
 *
 * A test image metadata that reports a fixed orientation, including the
 * IPTC-only flip orientations 9 and 10, and records the reset orientation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFixedOrientationMetaData extends TAssetImageMetaData
{
	/** @var int the orientation reported. */
	public int $orientation = 1;

	/** @var array<?int> the orientations passed to resetOrientation. */
	public array $resets = [];

	public function getOrientation(): int
	{
		return $this->orientation;
	}

	public function resetOrientation(?int $exifOrientation = 1): void
	{
		$this->resets[] = $exifOrientation;
		$this->orientation = $exifOrientation ?? 1;
	}
}
