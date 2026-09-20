<?php

/**
 * TConvolveRecorder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

/**
 * TConvolveRecorder class.
 *
 * Stands in for an Imagick image, recording the kernel convolveImage() is given.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConvolveRecorder
{
	/** @var mixed the kernel of the last call. */
	public $kernel;

	/**
	 * @param mixed $kernel the convolution kernel.
	 * @return bool true, as a convolution that ran.
	 */
	public function convolveImage($kernel): bool
	{
		$this->kernel = $kernel;
		return true;
	}
}
