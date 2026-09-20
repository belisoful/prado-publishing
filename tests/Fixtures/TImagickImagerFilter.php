<?php

/**
 * TImagickImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TImagickImagerFilter class.
 *
 * A test imager filter written in Imagick, which negates the image, to exercise a chain
 * of filters of both graphics libraries.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImagickImagerFilter extends TBaseImagerFilter
{
	/** @var string[] the class of the image of each call. */
	public array $calls = [];

	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$this->calls[] = get_debug_type($image);
		return $image instanceof \Imagick && $image->negateImage(false);
	}
}
