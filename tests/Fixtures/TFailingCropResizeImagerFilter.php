<?php

/**
 * TFailingCropResizeImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TResizeImagerFilter;

/**
 * TFailingCropResizeImagerFilter class.
 *
 * A test resize filter whose crop fails, as GD imagecrop() does when it cannot
 * allocate the cropped image, and records the crop rectangles.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFailingCropResizeImagerFilter extends TResizeImagerFilter
{
	/** @var array<array{x: int, y: int, width: int, height: int}> the crop rectangles. */
	public static array $rects = [];

	protected static function cropImage($image, array $rect)
	{
		static::$rects[] = $rect;
		return false;
	}
}
