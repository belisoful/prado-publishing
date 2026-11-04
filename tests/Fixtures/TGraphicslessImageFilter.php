<?php

/**
 * TGraphicslessImageFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\TAssetImageFilter;

/**
 * TGraphicslessImageFilter class.
 *
 * A test imager on a host without a graphics library, so its matching assets publish
 * unprocessed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGraphicslessImageFilter extends TAssetImageFilter
{
	protected function hasGraphics(): bool
	{
		return false;
	}
}
