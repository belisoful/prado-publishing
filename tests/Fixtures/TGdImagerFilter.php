<?php

/**
 * TGdImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TGdImagerFilter class.
 *
 * A test imager filter of GD calls, which records the class of the image of each call,
 * the counterpart of {@see TImagickImagerFilter} for the ordering of filters of both
 * graphics libraries.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGdImagerFilter extends TBaseImagerFilter
{
	/** @var string[] the class of the image of each call. */
	public array $calls = [];

	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$this->calls[] = get_debug_type($image);
		return $image instanceof \GdImage && imagefilter($image, IMG_FILTER_NEGATE);
	}
}
