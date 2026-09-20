<?php

/**
 * TCircleMaskImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TCircleMaskImagerFilter class
 *
 * This applies a circle mask so only a central circle
 * is passed through.
 *
 * The pixels outside a circle, from the center of the image
 * to the nearest edge, are made fully transparent with the RGB of
 * the {@see getBackgroundColor BackgroundColor}.  The pixels on the
 * edge of the circle are anti-aliased by increasing their transparency.
 *
 * The filter works in both graphics libraries and the circle is the same, but the
 * edge is anti-aliased by each library itself: Imagick rasterizes the ellipse with
 * its own coverage, so an edge pixel can be up to half a step more or less
 * transparent than the same pixel in GD.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TCircleMaskImagerFilter extends TBaseImagerFilter
{
	/** @var string The RGB Web Color of the transparent pixels, default "#000000" */
	private $_backgroundColor = '#000000';

	/**
	 * @return string Web Color (RGB) for transparent pixels,
	 *   default "#000000".
	 */
	public function getBackgroundColor(): string
	{
		return $this->_backgroundColor;
	}

	/**
	 * @param string $color Web Color (RGB) for transparent pixels.
	 */
	public function setBackgroundColor($color): void
	{
		$this->_backgroundColor = TPropertyValue::ensureHexColor($color);
	}

	/**
	 * Passes through the central circle of the image
	 * but area outside the central circle is made transparent.
	 *
	 * @param object &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !imageIsTrueColor($image)) {
			return null;
		}

		$color = $this->getBackgroundColor();
		$red = hexdec(substr($color, 1, 2));
		$green = hexdec(substr($color, 3, 2));
		$blue = hexdec(substr($color, 5, 2));

		// A true color image allocates any valid color, so this is never false.
		$clear = imagecolorallocatealpha($image, $red, $green, $blue, 127);
		$return = self::imageCircleMask($image, $clear);
		imagecolordeallocate($image, $clear);
		return $return;
	}


	/**
	 * Passes through the central circle of the image but the area outside the
	 * central circle is made transparent with the RGB of the {@see
	 * getBackgroundColor BackgroundColor}.  The circle is the ellipse of {@see
	 * imageCircleMask}, drawn by Imagick with its own anti-aliased edge.
	 *
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image->getNumberImages()) {
			return null;
		}
		$sx = $image->getImageWidth();
		$sy = $image->getImageHeight();

		$cx = $sx / 2.0;
		$cy = $sy / 2.0;
		$r = min($cx, $cy);

		// The mask of the circle.  A drawing coordinate is the center of its pixel, so
		// the center of the image is half a pixel back from the GD center.
		$mask = new \Imagick();
		$mask->newImage($sx, $sy, new \ImagickPixel('none'));
		$draw = new \ImagickDraw();
		$draw->setFillColor(new \ImagickPixel('#FFFFFF'));
		$draw->ellipse($cx - 0.5, $cy - 0.5, $r, $r, 0, 360);
		$mask->drawImage($draw);

		// The colors of the result: the image where the circle covers any of the pixel,
		// and the background color where it does not.  The image is made opaque so a
		// semi-transparent pixel keeps its own color rather than blending into the
		// background color.
		$colors = new \Imagick();
		$colors->newImage($sx, $sy, new \ImagickPixel($this->getBackgroundColor()));
		$touched = clone $mask;
		$touched->thresholdImage(0, \Imagick::CHANNEL_ALPHA);
		$opaque = clone $image;
		$opaque->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
		$opaque->compositeImage($touched, \Imagick::COMPOSITE_DSTIN, 0, 0);
		$colors->compositeImage($opaque, \Imagick::COMPOSITE_OVER, 0, 0);

		// The transparency of the circle, then the colors.  A composite divides the
		// color by the alpha it computes, which leaves a fully transparent pixel black,
		// so the color channels are copied over the masked image afterwards.
		$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
		$image->compositeImage($mask, \Imagick::COMPOSITE_DSTIN, 0, 0);
		foreach ([\Imagick::COMPOSITE_COPYRED, \Imagick::COMPOSITE_COPYGREEN, \Imagick::COMPOSITE_COPYBLUE] as $channel) {
			$image->compositeImage($colors, $channel, 0, 0);
		}
		return true;
	}


	/**
	 * This masks the image with a circle centered in the image with a radius
	 * of half the smallest dimension.  Pixels outside the circle are set to
	 * $backgroundColor.  Pixels on the edge are anti-aliased by increasing their
	 * alpha (transparency) by the part of the pixel outside of the circle.
	 * Distances are measured from the center of each pixel.
	 *
	 * @param object $image The true color image to mask.
	 * @param int $backgroundColor The GD true color for pixels outside the circle.
	 * @return ?bool Successful or not, null is no action, false is fail, true is success.
	 */
	public static function imageCircleMask($image, int $backgroundColor): ?bool
	{
		if (!$image || $backgroundColor === -1 || !imageIsTrueColor($image)) {
			return null;
		}
		$sx = imagesx($image);
		$sy = imagesy($image);

		$cx = $sx / 2.0;
		$cy = $sy / 2.0;
		$r = min($cx, $cy);
		$outer2 = ($r + 0.5) * ($r + 0.5);
		$inner = $r - 0.5;
		$inner2 = $inner * $inner;
		imagealphablending($image, false);
		$return = true;
		for ($y = 0; $y < $sy; $y++) {
			$dy = $cy - ($y + 0.5);
			$dy2 = $dy * $dy;
			if ($dy2 >= $outer2) {
				$return = imagefilledrectangle($image, 0, $y, $sx - 1, $y, $backgroundColor) && $return;
				continue;
			}
			$outerDx = sqrt($outer2 - $dy2);

			// Pixels whose centers are at or beyond the outer radius are background.
			$leftEnd = min($sx - 1, (int) floor($cx - $outerDx - 0.5));
			$rightStart = max(0, (int) ceil($cx + $outerDx - 0.5));
			if ($leftEnd >= 0) {
				$return = imagefilledrectangle($image, 0, $y, $leftEnd, $y, $backgroundColor) && $return;
			}
			if ($rightStart <= $sx - 1) {
				$return = imagefilledrectangle($image, $rightStart, $y, $sx - 1, $y, $backgroundColor) && $return;
			}

			// Pixels whose centers are within the inner radius are unchanged.
			$innerStart = $sx;
			$innerEnd = -1;
			if ($inner > 0 && $dy2 < $inner2) {
				$innerDx = sqrt($inner2 - $dy2);
				$innerStart = (int) ceil($cx - $innerDx - 0.5);
				$innerEnd = (int) floor($cx + $innerDx - 0.5);
			}

			for ($x = max(0, $leftEnd + 1), $xEnd = min($sx - 1, $rightStart - 1); $x <= $xEnd; $x++) {
				if ($x >= $innerStart && $x <= $innerEnd) {
					$x = $innerEnd;
					continue;
				}
				$dx = $cx - ($x + 0.5);
				// Between $leftEnd and $rightStart, pixel centers are inside the outer radius, so the coverage is above 0.
				$coverage = $r + 0.5 - sqrt($dx * $dx + $dy2);
				if ($coverage >= 1.0) {
					continue;
				}
				$c = imagecolorat($image, $x, $y);
				$alpha = ($c >> 24) & 0x7F;
				$alpha = 127 - (int) round((127 - $alpha) * $coverage);
				$c = ($alpha << 24) | ($c & 0xFFFFFF);
				$return = imagesetpixel($image, $x, $y, $c) && $return;
			}
		}
		imagealphablending($image, true);
		return $return;
	}
}
