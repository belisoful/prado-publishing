<?php

/**
 * TUnwritableImageFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\TAssetImageFilter;

/**
 * TUnwritableImageFilter class.
 *
 * A test imager whose encoding or saving of an image type it reports it can encode
 * fails, as it does when the image data cannot be produced or the file cannot be
 * written.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TUnwritableImageFilter extends TAssetImageFilter
{
	/** @var bool whether encoding the image fails. */
	public bool $failEncode = false;

	/** @var bool whether saving the image fails. */
	public bool $failSave = false;

	public function encodeImage($image, int $type, ?int $paletteColors): false|string
	{
		return $this->failEncode ? false : parent::encodeImage($image, $type, $paletteColors);
	}

	public function saveImage($image, int $type, ?int $paletteColors, string $filePath): ?bool
	{
		return $this->failSave ? false : parent::saveImage($image, $type, $paletteColors, $filePath);
	}
}
