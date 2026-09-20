<?php

/**
 * TBlurImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TBlurImagerFilter class
 *
 * This applies several gaussian blurs.
 *
 * For smaller values of blurring, this is more efficient
 * than the Box Blur.
 *
 * Imagick blurs once rather than {@see getBlurCount BlurCount} times, with the sigma
 * of that many GD passes, so the two libraries blur alike but not pixel for pixel.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://en.wikipedia.org/wiki/Gaussian_blur
 */
class TBlurImagerFilter extends TBaseImagerFilter
{
	/** @var int Number of time to run the imagefilter Gaussian Blur. */
	private int $_blurCount = 3;

	/**
	 * each iteration adds two pixel at the boundaries. In effect,
	 * to achieve a, eg,  10 pixel blur, the filter is applied 5 times.
	 *
	 * @return int Number of times to apply GD Gaussian Blur Filter,
	 *    default 3.
	 */
	public function getBlurCount(): int
	{
		return $this->_blurCount;
	}

	/**
	 * @param int $value Number of times to apply GD Gaussian Blur Filter.
	 */
	public function setBlurCount($value)
	{
		$this->_blurCount = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * Renders a gaussian blur on the image.
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
		$count = $this->getBlurCount();

		if (!$count) {
			return false;
		}

		$return = true;
		for ($x = 0; $x < $count && $return; $x++) {
			$return = imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
		}

		return $return;
	}

	/**
	 * Renders a gaussian blur on the image with a single Imagick gaussianBlurImage().
	 * BlurCount passes of the GD 1-2-1 kernel each add a variance of 1/2 pixel, so the
	 * equal single blur has the sigma of {@see blurSigma}, and Imagick computes its own
	 * radius from that sigma.
	 *
	 * @param \Imagick &$image The image to filter.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$count = $this->getBlurCount();

		if (!$count) {
			return false;
		}

		return $image->gaussianBlurImage(0, static::blurSigma($count));
	}

	/**
	 * The standard deviation of the gaussian equal to $count passes of the GD
	 * IMG_FILTER_GAUSSIAN_BLUR 1-2-1 kernel.  Each pass convolves a gaussian of a
	 * variance of 1/2 pixel, and convolved gaussians add their variances.
	 *
	 * @param int $count Number of GD Gaussian Blur passes, at least 1.
	 * @return float The sigma, in pixels, of the equal single gaussian blur.
	 */
	protected static function blurSigma(int $count): float
	{
		return sqrt($count / 2.0);
	}
}
