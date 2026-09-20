<?php

/**
 * TArrayConvolveImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TConvolutionImagerFilter;

/**
 * TArrayConvolveImagerFilter class.
 *
 * A convolution filter on an Imagick that takes the flat array of the matrix, as the
 * versions before Imagick 3.8 do.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TArrayConvolveImagerFilter extends TConvolutionImagerFilter
{
	protected static function usesImagickKernel(): bool
	{
		return false;
	}
}
