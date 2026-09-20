<?php

/**
 * TGraphicslessPNGize class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\TAssetPNGize;

/**
 * TGraphicslessPNGize class.
 *
 * A test PNG conversion on a host without a graphics library, which cannot convert and
 * therefore does not rename.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGraphicslessPNGize extends TAssetPNGize
{
	protected function hasGraphics(): bool
	{
		return false;
	}
}
