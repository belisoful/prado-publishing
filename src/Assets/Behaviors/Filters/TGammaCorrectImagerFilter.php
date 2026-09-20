<?php

/**
 * TGammaCorrectImagerFilter class file.
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
 * TGammaCorrectImagerFilter class
 *
 * This applies a GD imagegammacorrect(), or an Imagick gammaImage(), on the image.
 *
 * Use the {@see getInputGamma InputGamma} and {@see getOutputGamma
 * OutputGamma} to correct the image.  Both must be greater than 0.  Imagick has a
 * single gamma, and so is given the ratio of the OutputGamma to the InputGamma.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imagegammacorrect.php
 */
class TGammaCorrectImagerFilter extends TBaseImagerFilter
{
	/** @var float input gamma, default 1.0 */
	private float $_inputGamma = 1.0;

	/** @var float output gamma, default 1.0 */
	private float $_outputGamma = 1.0;

	/**
	 * @return float the input gamma, default 1.0.
	 */
	public function getInputGamma(): float
	{
		return $this->_inputGamma;
	}

	/**
	 * @param float $value the input gamma, greater than 0.
	 * @throws TInvalidDataValueException when the gamma is not greater than 0.
	 */
	public function setInputGamma($value)
	{
		$this->_inputGamma = self::ensureGamma($value);
	}

	/**
	 * @return float the output gamma, default 1.0.
	 */
	public function getOutputGamma(): float
	{
		return $this->_outputGamma;
	}

	/**
	 * @param float $value the output gamma, greater than 0.
	 * @throws TInvalidDataValueException when the gamma is not greater than 0.
	 */
	public function setOutputGamma($value)
	{
		$this->_outputGamma = self::ensureGamma($value);
	}

	/**
	 * @param mixed $value The gamma value to validate.
	 * @throws TInvalidDataValueException when the gamma is not greater than 0.
	 * @return float The gamma.
	 */
	protected static function ensureGamma($value): float
	{
		$gamma = TPropertyValue::ensureFloat($value);
		if (!($gamma > 0.0) || is_infinite($gamma)) {
			throw new TInvalidDataValueException('gammacorrectimagerfilter_bad_gamma', $value);
		}
		return $gamma;
	}

	/**
	 * Calls GD imagegammacorrect() with the specified InputGamma and OutputGamma.
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
		// The gammas are validated to be greater than 0, so imagegammacorrect() cannot fail.
		imagegammacorrect($image, $this->getInputGamma(), $this->getOutputGamma());
		return true;
	}

	/**
	 * Calls Imagick gammaImage() with the ratio of the OutputGamma to the InputGamma: GD
	 * raises each channel to the power of InputGamma / OutputGamma and Imagick to the
	 * inverse of its gamma, so the ratio gives the same correction in both libraries.
	 *
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		// The gammas are validated to be greater than 0, so the ratio is a valid gamma.
		$image->gammaImage($this->getOutputGamma() / $this->getInputGamma());
		return true;
	}
}
