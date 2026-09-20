<?php

/**
 * TBoxBlurImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TBoxBlurImagerFilter class
 *
 * This applies a "Box Blur" Average on the image.
 *
 * For large values of blurring, this is more efficient
 * than the built in GD Gaussian Blur and Smooth.
 *
 * This filter is written in GD, as its moving average is its own implementation rather than a
 * library call. The imager converts an image of another graphics library into GD for it, and
 * skips the filter where GD is not installed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://en.wikipedia.org/wiki/Box_blur
 *   "In digital signal processing terminology, each pass
 *    is a moving-average filter."
 */
class TBoxBlurImagerFilter extends TBaseImagerFilter
{
	/** @var float Amount of blur in the x axis. */
	private float $_blurx = 5.0;

	/** @var float Amount of blur in the y axis. */
	private float $_blury = 5.0;

	/**
	 * @return float the Blur in the X axis, default 5.0.
	 */
	public function getBlurX(): float
	{
		return $this->_blurx;
	}

	/**
	 * @param float $value the Blur in the X axis.
	 */
	public function setBlurX($value)
	{
		$this->_blurx = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return float the Blur in the Y axis, default 5.0.
	 */
	public function getBlurY(): float
	{
		return $this->_blury;
	}

	/**
	 * @param float $value the Blur in the Y axis.
	 */
	public function setBlurY($value)
	{
		$this->_blury = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * Renders a custom "fast" box blur on the image.
	 *
	 * @param object &$image The image to filter.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !imageistruecolor($image)) {
			return null;
		}
		$bx = (int) round($this->getBlurX());
		$by = (int) round($this->getBlurY());
		return self::imageBoxBlur($image, $bx, $by);
	}

	/**
	 * This is a "fast" box blur.  It wraps the pixels around the edges.  It is
	 * primarily [fast] for large values of blur.  For smaller values, the
	 * (TBlurImagerFilter) built-in imagefilter Gaussian blur is faster.
	 *
	 * The box is $boxX + 1 pixels wide and $boxY + 1 pixels high, centered on
	 * each pixel, and is limited to the size of the image.  A horizontal pass is
	 * applied to each row then a vertical pass to each column, using running sums.
	 * Only one row or column of channel values is held in memory at a time.
	 *
	 * @param object $image The Image to Box Blur
	 * @param int $boxX The box size in the x dimension.
	 * @param int $boxY The box size in the y dimension.
	 * @return ?bool Successful or not, null is no action, false is fail, true is success.
	 */
	public static function imageBoxBlur($image, int $boxX, int $boxY)
	{
		if (!$image || !imageistruecolor($image)) {
			return null;
		}
		$boxX = max(0, $boxX);
		$boxY = max(0, $boxY);
		if ($boxX === 0 && $boxY === 0) {
			return false;
		}
		$sx = imagesx($image);
		$sy = imagesy($image);
		$bx = min($boxX + 1, $sx);
		$by = min($boxY + 1, $sy);

		imagealphablending($image, false);
		if ($bx > 1) {
			for ($y = 0; $y < $sy; $y++) {
				$line = [];
				for ($x = 0; $x < $sx; $x++) {
					$line[$x] = imagecolorat($image, $x, $y);
				}
				$line = self::boxBlurLine($line, $bx);
				for ($x = 0; $x < $sx; $x++) {
					imagesetpixel($image, $x, $y, $line[$x]);
				}
			}
		}
		if ($by > 1) {
			for ($x = 0; $x < $sx; $x++) {
				$line = [];
				for ($y = 0; $y < $sy; $y++) {
					$line[$y] = imagecolorat($image, $x, $y);
				}
				$line = self::boxBlurLine($line, $by);
				for ($y = 0; $y < $sy; $y++) {
					imagesetpixel($image, $x, $y, $line[$y]);
				}
			}
		}
		imagealphablending($image, true);
		return true;
	}

	/**
	 * Applies a centered, wrapping, moving-average of $box pixels to a line
	 * of GD true color pixels.
	 *
	 * @param int[] $line The GD true color pixels of a row or column.
	 * @param int $box The number of pixels in the moving average, [1..count($line)].
	 * @return int[] The averaged GD true color pixels.
	 */
	protected static function boxBlurLine(array $line, int $box): array
	{
		$n = count($line);
		$box = max(1, min($box, $n));
		if ($box === 1) {
			return $line;
		}
		$left = intdiv($box - 1, 2);
		$right = $box - 1 - $left;
		$box2 = 2 * $box;

		$pa = $pr = $pg = $pb = [];
		foreach ($line as $i => $c) {
			$pa[$i] = ($c >> 24) & 0x7F;
			$pr[$i] = ($c >> 16) & 0xFF;
			$pg[$i] = ($c >> 8) & 0xFF;
			$pb[$i] = $c & 0xFF;
		}

		$sa = $sr = $sg = $sb = 0;
		for ($k = -$left; $k <= $right; $k++) {
			$i = $k < 0 ? $k + $n : $k;
			$sa += $pa[$i];
			$sr += $pr[$i];
			$sg += $pg[$i];
			$sb += $pb[$i];
		}

		$result = [];
		for ($i = 0; $i < $n; $i++) {
			// rounded integer average: floor((2 * sum + box) / (2 * box))
			$a = min(0x7F, intdiv(2 * $sa + $box, $box2));
			$r = min(0xFF, intdiv(2 * $sr + $box, $box2));
			$g = min(0xFF, intdiv(2 * $sg + $box, $box2));
			$b = min(0xFF, intdiv(2 * $sb + $box, $box2));
			$result[$i] = ($a << 24) | ($r << 16) | ($g << 8) | $b;

			$add = $i + $right + 1;
			if ($add >= $n) {
				$add -= $n;
			}
			$sub = $i - $left;
			if ($sub < 0) {
				$sub += $n;
			}
			$sa += $pa[$add] - $pa[$sub];
			$sr += $pr[$add] - $pr[$sub];
			$sg += $pg[$add] - $pg[$sub];
			$sb += $pb[$add] - $pb[$sub];
		}
		return $result;
	}
}
