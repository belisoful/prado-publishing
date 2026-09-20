<?php

/**
 * TKernelConvolveImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TConvolutionImagerFilter;

/**
 * TKernelConvolveImagerFilter class.
 *
 * A convolution filter on an Imagick that takes an ImagickKernel, as Imagick 3.8 does.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TKernelConvolveImagerFilter extends TConvolutionImagerFilter
{
	protected static function usesImagickKernel(): bool
	{
		return true;
	}
}
