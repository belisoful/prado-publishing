<?php

/**
 * TOrientImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TOrientImagerFilter class
 *
 * Orients an image upright from its metadata. The orientation is read by the image's
 * {@see \Prado\Web\Assets\Behaviors\TAssetImageMetaData}: the EXIF Orientation tag, or
 * else the IPTC image rotation. The image is flipped and rotated to match, and the
 * metadata is updated to record the upright orientation, so a viewer does not rotate
 * it a second time.
 *
 * ```xml
 * <filter type="Orient" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://exiftool.org/TagNames/EXIF.html
 * @see https://exiftool.org/TagNames/IPTC.html
 */
class TOrientImagerFilter extends TBaseImagerFilter
{
	/**
	 * Flips and rotates the image upright.
	 * @param \GdImage &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Whether the image changed; null when there is no metadata.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !($metaData = $param?->getImageMetaData())) {
			return null;
		}
		$orientation = $metaData->getOrientation();
		if ($orientation === 1) {
			return false;
		}
		imagealphablending($image, false);
		$flip = match ($orientation) {
			2, 7, 9 => IMG_FLIP_HORIZONTAL,
			4, 5, 10 => IMG_FLIP_VERTICAL,
			default => null,
		};
		// GD rotates counter-clockwise for a positive angle.
		$angle = match ($orientation) {
			3 => 180,
			5, 6, 7 => -90,
			8, 9, 10 => 90,
			default => 0,
		};
		if ($flip !== null) {
			imageflip($image, $flip);
		}
		if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0))) {
			static::transferImageProperties($rotated, $image);
			$image = $rotated;
		}
		$metaData->resetOrientation(1);
		return true;
	}

	/**
	 * Flips and rotates the image upright, the Imagick equal of {@see filterGdImage}: the
	 * same orientation drives a flopImage/flipImage and a rotateImage on a transparent
	 * background, and the metadata records the upright orientation.
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Whether the image changed; null when there is no metadata.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!($metaData = $param?->getImageMetaData())) {
			return null;
		}
		$orientation = $metaData->getOrientation();
		if ($orientation === 1) {
			return false;
		}
		$flip = match ($orientation) {
			2, 7, 9 => 'flopImage',
			4, 5, 10 => 'flipImage',
			default => null,
		};
		// Imagick rotates clockwise for a positive angle, the opposite of GD.
		$angle = match ($orientation) {
			3 => 180,
			5, 6, 7 => 90,
			8, 9, 10 => -90,
			default => 0,
		};
		if ($flip !== null) {
			$image->$flip();
		}
		if ($angle !== 0) {
			$image->rotateImage(new \ImagickPixel('transparent'), $angle);
		}
		$metaData->resetOrientation(1);
		return true;
	}
}
