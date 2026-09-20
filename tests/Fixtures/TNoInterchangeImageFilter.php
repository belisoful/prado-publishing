<?php

/**
 * TNoInterchangeImageFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\TAssetImageFilter;

/**
 * TNoInterchangeImageFilter class.
 *
 * A test imager on a host whose graphics library cannot encode the interchange format,
 * so an image cannot be converted between the libraries.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TNoInterchangeImageFilter extends TAssetImageFilter
{
	protected static function encodePortableImage($image): false|string
	{
		return false;
	}
}
