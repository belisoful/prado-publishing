<?php

/**
 * TConvolutionImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TConvolutionImagerFilter class
 *
 * This applies GD imageconvolution(), or Imagick convolveImage(), on the image.
 *
 * Set the convolution array[3][3] {@see setMatrix Matrix},
 * {@see setDivisor Divisor}, and {@see setOffset Offset}
 * to properly function imageconvolution().  The Matrix may also be
 * specified as 9 values, eg. "-1, -1, -1, -1, 16, -1, -1, -1, -1".
 *
 * Imagick normalizes and rotates a kernel where GD does not, so the Imagick
 * kernel is the Matrix rotated 180 degrees and divided by the Divisor, with the
 * Offset added afterwards, which matches GD to within a unit of rounding.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imageconvolution.php
 */
class TConvolutionImagerFilter extends TBaseImagerFilter
{
	/** @var array[] The convolution matrix */
	private array $_matrix = [[0, 0, 0], [0, 1, 0], [0, 0, 0]];

	/** @var float The convolution divisor */
	private float $_divisor = 1.0;

	/** @var float The convolution offset */
	private float $_offset = 0.0;


	/**
	 * @return array[] the convolution matrix, default the identity matrix.
	 */
	public function getMatrix(): array
	{
		return $this->_matrix;
	}

	/**
	 * @param array|string $value the 3x3 convolution matrix or 9 values.
	 * @throws TInvalidDataValueException when the matrix is not 3x3 or 9 values.
	 */
	public function setMatrix($value)
	{
		$matrix = array_values(TPropertyValue::ensureArray($value));
		if (count($matrix) === 9) {
			$matrix = array_chunk($matrix, 3);
		}
		if (count($matrix) !== 3) {
			throw new TInvalidDataValueException('convolutionfilter_bad_matrix');
		}
		foreach ($matrix as $i => $row) {
			if (!is_array($row) || count($row) !== 3) {
				throw new TInvalidDataValueException('convolutionfilter_bad_matrix');
			}
			$matrix[$i] = array_values($row);
		}
		$this->_matrix = $matrix;
	}

	/**
	 * @return float the divisor of the convolution, default 1.0.
	 */
	public function getDivisor(): float
	{
		return $this->_divisor;
	}

	/**
	 * @param float $value the divisor of the convolution
	 */
	public function setDivisor($value)
	{
		$this->_divisor = TPropertyValue::ensureFloat($value);
	}

	/**
	 * @return float the offset of the convolution, default 0.0.
	 */
	public function getOffset(): float
	{
		return $this->_offset;
	}

	/**
	 * @param float $value the offset of the convolution
	 */
	public function setOffset($value)
	{
		$this->_offset = TPropertyValue::ensureFloat($value);
	}


	/**
	 * Calls GD imageconvolution() with the specified matrix, divisor, and offset.
	 *
	 * @param object &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image) {
			return null;
		}

		return imageconvolution($image, $this->getMatrix(), $this->getDivisor(), $this->getOffset());
	}

	/**
	 * Calls Imagick convolveImage() with a kernel of the specified matrix and divisor,
	 * and then adds the offset.  GD correlates the matrix and divides the sum by the
	 * Divisor, where Imagick convolves (the kernel is applied rotated) and scales the
	 * kernel itself, so the kernel is the matrix rotated 180 degrees with the divisor
	 * applied to its values.  The alpha channel is set aside so the colors convolve
	 * unweighted by their transparency, as in GD, and the result is clamped because an
	 * HDRI Imagick keeps the values outside the color range.
	 *
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 * @see https://www.php.net/manual/en/imagick.convolveimage.php
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image) {
			return null;
		}
		$divisor = $this->getDivisor();
		$kernel = [];
		foreach (array_reverse($this->getMatrix()) as $row) {
			$kernel[] = array_map(static fn ($value) => fdiv((float) $value, $divisor), array_reverse($row));
		}

		$alpha = $image->getImageAlphaChannel();
		$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_DEACTIVATE);
		static::convolveImagickImage($image, $kernel);
		if (($offset = $this->getOffset()) != 0) {
			$quantum = $image->getQuantumRange()['quantumRangeLong'];
			$image->evaluateImage(\Imagick::EVALUATE_ADD, $offset * $quantum / 255.0, \Imagick::CHANNEL_RED | \Imagick::CHANNEL_GREEN | \Imagick::CHANNEL_BLUE);
		}
		$image->clampImage();
		$image->setImageAlphaChannel($alpha ? \Imagick::ALPHACHANNEL_ACTIVATE : \Imagick::ALPHACHANNEL_DEACTIVATE);

		return true;
	}
}
