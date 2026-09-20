<?php

/**
 * TFilterImagerFilter class file.
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
 * TFilterImagerFilter class
 *
 * This applies a GD imagefilter() on the image.
 *
 * All imagefilter() {@see setEffect Effects} are supported.  It operates
 * like imagefilter. The first parameter is {@see getArg1 Arg1},
 * the second is {@see getArg2 Arg2}, third is {@see getArg3 Arg3},
 * and fourth-last is {@see getArg4 Arg4}.
 *
 * Effects are: "Negate", "Grayscale", "Brightness", "Contrast", "Colorize",
 * "EdgeDetect", "Emboss", "GaussianBlur", "SelectiveBlur", "MeanRemoval",
 * "Smooth", "Pixelate", and "Scatter".
 *
 * {@see getArg1 Arg1}, {@see getArg2 Arg2}, {@see getArg3 Arg3},
 * and {@see getArg4 Arg4} are dependent upon the {@see setEffect Effect}.
 *
 * The filter runs in both graphics libraries.  Imagick has no imagefilter(), so each
 * effect uses the nearest Imagick operation and the arguments are converted to it.  The
 * libraries are not identical, so the Imagick result is an approximation of the GD one:
 *
 * <pre>
 * Effect        Imagick                     Argument conversion and fidelity
 * ------------- --------------------------- --------------------------------------------
 * Negate        negateImage(false)          None.  Identical to GD.
 * Grayscale     transformImageColorspace()  None.  To COLORSPACE_GRAY and back to
 *                                           COLORSPACE_SRGB, keeping the image in RGB.
 *                                           Imagick weighs the channels by Rec.709 and GD
 *                                           by Rec.601, so a saturated color differs by
 *                                           up to ~22/255; a muted color is within 1.
 * Brightness    brightnessContrastImage()   Arg1 [-255..255] * 100 / 255 as a percent.
 *                                           Identical to GD, both add a constant.
 * Contrast      brightnessContrastImage()   -Arg1 as a percent: GD counts 100 as the
 *                                           least contrast and -100 as the most, Imagick
 *                                           the reverse.  GD scales the distance from the
 *                                           midpoint by ((100 - Arg1) / 100)^2 while
 *                                           Imagick applies a sigmoid, so only the
 *                                           direction and the ends (a flat mid gray at
 *                                           Arg1 = 100) agree.
 * Colorize      colorizeImage()             Each of Arg1..Arg3 [-255..255] becomes a
 *                                           channel of the colorize color, 255 for a
 *                                           positive offset and 0 for a negative one, and
 *                                           |Arg| / 255 as that channel's blend in the
 *                                           opacity color.  GD adds the offset while
 *                                           Imagick blends toward the color, so a pixel
 *                                           differs by up to the offset scaled by its own
 *                                           distance from the target, ~16/255 typically.
 *                                           A non-zero alpha offset (Arg4) has no Imagick
 *                                           equivalent and runs in GD.
 * EdgeDetect    edgeImage(1)                None.  GD biases the convolution by +127, so
 *                                           GD renders a flat area mid gray and Imagick
 *                                           renders it black.
 * Emboss        embossImage(0, 1)           None.  GD uses a 3x3 diagonal kernel biased
 *                                           by +127 and Imagick an unbiased Gaussian
 *                                           derivative of a sum above one, so GD renders
 *                                           a flat area mid gray and Imagick brightens
 *                                           it toward white.
 * GaussianBlur  gaussianBlurImage(1, r)     None.  A radius of 1 and a sigma of
 *                                           sqrt(1/2), the deviation of GD's 1-2-1
 *                                           binomial kernel.  Within ~10/255 of GD.
 * SelectiveBlur adaptiveBlurImage(1, r)     None.  The nearest equivalent: both blur the
 *                                           flat areas and preserve the edges, by the
 *                                           edge intensity in Imagick and by a threshold
 *                                           on the neighbors in GD.  The amounts differ.
 * MeanRemoval   convolveImage()             None.  GD's mean removal kernel,
 *                                           [[-1,-1,-1],[-1,9,-1],[-1,-1,-1]], divided by
 *                                           its own sum ({@see convolutionMatrix}).
 *                                           Identical to GD within rounding.
 * Smooth        convolveImage()             GD's smoothing kernel with Arg1 as the center
 *                                           weight, [[1,1,1],[1,Arg1,1],[1,1,1]], divided
 *                                           by its own sum, as GD divides by Arg1 + 8.
 *                                           Identical to GD within rounding.
 * Pixelate      scaleImage()/resizeImage()  Arg1 is the block size: the image is scaled
 *                                           to ceil(size / Arg1) and back with
 *                                           FILTER_POINT.  Arg2, the advanced mode,
 *                                           averages each block with scaleImage(), as GD
 *                                           does; otherwise a block takes the color of
 *                                           the FILTER_POINT sample, the pixel nearest
 *                                           the center of the block rather than GD's
 *                                           top-left pixel of the block.
 * Scatter       spreadImage()               Arg2 - Arg1, the width of GD's displacement
 *                                           range, as the radius.  GD displaces a pixel
 *                                           by at least Arg1 and Imagick from zero, and
 *                                           Imagick interpolates the displaced pixel, so
 *                                           it blends the neighbors GD only swaps.  A
 *                                           list of colors to scatter (Arg3) has no
 *                                           Imagick equivalent and runs in GD.
 * </pre>
 *
 * An effect that cannot run in Imagick returns null and the imager converts the image to
 * GD and runs {@see filterGdImage} on it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imagefilter.php
 */
class TFilterImagerFilter extends TBaseImagerFilter
{
	/**
	 * The GD imagefilter() effects, lower case, and their IMG_FILTER_* constants.  Each
	 * name is also a filter type of {@see TImagerFilterFactory}, creating this filter with
	 * that {@see setEffect Effect}.
	 */
	public const FILTER_MAP = [
			'negate' => IMG_FILTER_NEGATE,
			'grayscale' => IMG_FILTER_GRAYSCALE,
			'brightness' => IMG_FILTER_BRIGHTNESS,
			'contrast' => IMG_FILTER_CONTRAST,
			'colorize' => IMG_FILTER_COLORIZE,
			'edgedetect' => IMG_FILTER_EDGEDETECT,
			'emboss' => IMG_FILTER_EMBOSS,
			'gaussianblur' => IMG_FILTER_GAUSSIAN_BLUR,
			'selectiveblur' => IMG_FILTER_SELECTIVE_BLUR,
			'meanremoval' => IMG_FILTER_MEAN_REMOVAL,
			'smooth' => IMG_FILTER_SMOOTH,
			'pixelate' => IMG_FILTER_PIXELATE,
			'scatter' => IMG_FILTER_SCATTER];

	/** @var null|int|string the GD imagefilter() filter effect, a FILTER_MAP name or IMG_FILTER_* integer */
	private $_effect;

	/** @var mixed the GD imagefilter() argument 1 */
	private $_arg1;

	/** @var mixed the GD imagefilter() argument 2 */
	private $_arg2;

	/** @var mixed the GD imagefilter() argument 3 */
	private $_arg3;

	/** @var mixed the GD imagefilter() argument 4 */
	private $_arg4;

	/**
	 * @return null|int|string the imagefilter() filter effect, default null.
	 */
	public function getEffect()
	{
		return $this->_effect;
	}

	/**
	 * The effect can be a (case-insensitive) name, eg. "Brightness", or the
	 * numeric IMG_FILTER_* constant.  Numeric values are stored as integers.
	 * @param int|string $value the imagefilter() filter effect.
	 * @throws TInvalidDataValueException when the effect is not valid.
	 */
	public function setEffect($value)
	{
		if (is_numeric($value) && in_array((int) $value, self::FILTER_MAP, true)) {
			$this->_effect = (int) $value;
			return;
		}
		$v = strtolower($value = trim(TPropertyValue::ensureString($value)));

		if (!array_key_exists($v, self::FILTER_MAP)) {
			throw new TInvalidDataValueException('filterimagerfilter_bad_effect', $value);
		}
		$this->_effect = $value;
	}

	/**
	 * For Effect="Brightness" (IMG_FILTER_BRIGHTNESS), this argument
	 * is the integer Brightness level [-255..255].
	 * For Effect="Contrast" (IMG_FILTER_CONTRAST), this argument
	 * is the integer Contrast level [-100..100]. -100 is maximum contrast
	 * and 100 is lack of contrast.
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer red channel offset [-255..255].
	 * For Effect="Smooth" (IMG_FILTER_SMOOTH), this argument
	 * is the float Smoothness level.
	 * For Effect="Pixelate" (IMG_FILTER_PIXELATE), this argument
	 * is the integer block size in pixels.
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is the integer effect subtraction level. Default 0, must be
	 * less than $arg2
	 *
	 * @return null|float|int The first argument to the filter.
	 */
	public function getArg1()
	{
		return $this->_arg1;
	}

	/**
	 * For Effect="Brightness" (IMG_FILTER_BRIGHTNESS), this argument
	 * is the integer Brightness level [-255..255].
	 * For Effect="Contrast" (IMG_FILTER_CONTRAST), this argument
	 * is the integer Contrast level [-100..100]. -100 is maximum contrast
	 * and 100 is lack of contrast.
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer red channel offset [-255..255].
	 * For Effect="Smooth" (IMG_FILTER_SMOOTH), this argument
	 * is the float Smoothness level.
	 * For Effect="Pixelate" (IMG_FILTER_PIXELATE), this argument
	 * is the integer block size in pixels.
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is the integer effect subtraction level. Default 0, must be
	 * less than $arg2
	 *
	 * @param null|float|int $value The first argument to the filter.
	 */
	public function setArg1($value)
	{
		$this->_arg1 = $value;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer green channel offset [-255..255]. Default 0.
	 * For Effect="Pixelate" (IMG_FILTER_PIXELATE), this argument
	 * is the boolean Whether to use advanced pixelation effect or not (defaults
	 * to false).
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is the integer effect addition level. Default 0, must be
	 * greater than $arg1
	 *
	 * @return null|bool|int The second argument to the filter.
	 */
	public function getArg2()
	{
		return $this->_arg2;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer green channel offset [-255..255].
	 * For Effect="Pixelate" (IMG_FILTER_PIXELATE), this argument
	 * is the boolean Whether to use advanced pixelation effect or not (defaults
	 * to false).
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is the integer effect addition level.
	 *
	 * @param null|bool|int $value The second argument to the filter.
	 */
	public function setArg2($value)
	{
		$this->_arg2 = $value;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer blue channel offset [-255..255].  If unset,
	 * this defaults to 0.
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is an array of pixel values to apply the effect. Default: all.
	 *
	 * @return null|array|int The third argument to the filter.
	 */
	public function getArg3()
	{
		return $this->_arg3;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer blue channel offset [-255..255].
	 * For Effect="Scatter" (IMG_FILTER_SCATTER), this argument
	 * is an array of pixel values to apply the effect.
	 *
	 * @param null|array|int $value The third argument to the filter.
	 */
	public function setArg3($value)
	{
		$this->_arg3 = $value;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer alpha channel offset [-127..127].  If unset,
	 * this defaults to 0.
	 *
	 * @return null|int The fourth argument to the filter, default null.
	 */
	public function getArg4()
	{
		return $this->_arg4;
	}

	/**
	 * For Effect="Colorize" (IMG_FILTER_COLORIZE), this argument
	 * is the integer alpha channel offset [-127..127];
	 *
	 * @param null|int $value The fourth argument to the filter.
	 */
	public function setArg4($value)
	{
		$this->_arg4 = $value;
	}

	/**
	 * Resolves the {@see getEffect Effect} into its IMG_FILTER_* constant.  A numeric
	 * effect is the constant and a name is looked up in {@see FILTER_MAP}.
	 *
	 * @return ?int The IMG_FILTER_* constant, or null when there is no valid effect.
	 */
	private function effectFilter(): ?int
	{
		if (($effect = $this->getEffect()) === null) {
			return null;
		}
		if (is_numeric($effect)) {
			return (int) $effect;
		}
		return self::FILTER_MAP[strtolower($effect)] ?? null;
	}

	/**
	 * Calls GD imagefilter() with the specified Effect, Arg1, Arg2, Arg3, and Arg4.
	 *
	 * @param object &$image The image to filter.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || ($effect = $this->effectFilter()) === null) {
			return null;
		}
		$args = [$image, $effect];
		$arg1 = $this->getArg1();
		$arg2 = $this->getArg2();
		$arg3 = $this->getArg3();
		$arg4 = $this->getArg4();
		switch ($effect) {
			case IMG_FILTER_BRIGHTNESS:
				$arg1 = $args[] = max(-255, min(255, TPropertyValue::ensureInteger($arg1)));
				if ($arg1 === 0) {
					return false;
				}
				break;
			case IMG_FILTER_CONTRAST: //-100 is max contrast, 100 is reduction in contrast
				$arg1 = $args[] = max(-100, min(100, TPropertyValue::ensureInteger($arg1)));
				if ($arg1 === 0) {
					return false;
				}
				break;
			case IMG_FILTER_COLORIZE:
				$arg1 = $args[] = max(-255, min(255, TPropertyValue::ensureInteger($arg1)));
				$arg2 = $args[] = max(-255, min(255, TPropertyValue::ensureInteger($arg2)));
				$arg3 = $args[] = max(-255, min(255, TPropertyValue::ensureInteger($arg3)));
				$arg4 = $args[] = max(-127, min(127, TPropertyValue::ensureInteger($arg4)));
				if ($arg1 === 0 && $arg2 === 0 && $arg3 === 0 && $arg4 === 0) {
					return false;
				}
				break;
			case IMG_FILTER_SMOOTH:
				$arg1 = $args[] = TPropertyValue::ensureFloat($arg1);
				if ($arg1 >= 2048.0) { // 8 * 256;  8 = # surrounding pixels.
					return false;
				}
				break;
			case IMG_FILTER_PIXELATE:
				$arg1 = $args[] = max(0, TPropertyValue::ensureInteger($arg1));
				$arg2 = $args[] = TPropertyValue::ensureBoolean($arg2);
				if (!$arg1) {
					return false;
				}
				break;
			case IMG_FILTER_SCATTER:
				$args[] = TPropertyValue::ensureInteger($arg1);
				$args[] = TPropertyValue::ensureInteger($arg2);
				if ($arg3 !== null && ($colors = TPropertyValue::ensureArray($arg3))) {
					$args[] = array_values($colors);
				}
				break;
		}
		return call_user_func_array('imagefilter', $args);
	}

	/**
	 * Applies the Imagick equivalent of the GD imagefilter() Effect, with Arg1, Arg2,
	 * Arg3, and Arg4 converted to it; the class documentation has the conversion of
	 * each effect.  The Imagick operations change the image in place.
	 *
	 * @param \Imagick &$image The image to filter.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed; null when the effect has no Imagick
	 *   equivalent and runs in GD.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (($effect = $this->effectFilter()) === null) {
			return null;
		}
		return match ($effect) {
			IMG_FILTER_NEGATE => $image->negateImage(false),
			IMG_FILTER_GRAYSCALE => $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY) &&
				$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB),
			IMG_FILTER_BRIGHTNESS => ($level = max(-255, min(255, TPropertyValue::ensureInteger($this->getArg1())))) !== 0 &&
				$image->brightnessContrastImage($level * 100.0 / 255.0, 0.0),
			IMG_FILTER_CONTRAST => ($level = max(-100, min(100, TPropertyValue::ensureInteger($this->getArg1())))) !== 0 &&
				$image->brightnessContrastImage(0.0, -$level),
			IMG_FILTER_COLORIZE => $this->colorizeImagickImage($image),
			IMG_FILTER_EDGEDETECT => $image->edgeImage(1.0),
			IMG_FILTER_EMBOSS => $image->embossImage(0.0, 1.0),
			IMG_FILTER_GAUSSIAN_BLUR => $image->gaussianBlurImage(1.0, M_SQRT1_2),
			IMG_FILTER_SELECTIVE_BLUR => $image->adaptiveBlurImage(1.0, M_SQRT1_2),
			IMG_FILTER_MEAN_REMOVAL => static::convolveImagickImage($image, static::convolutionMatrix([
				[-1.0, -1.0, -1.0], [-1.0, 9.0, -1.0], [-1.0, -1.0, -1.0]])),
			IMG_FILTER_SMOOTH => ($weight = TPropertyValue::ensureFloat($this->getArg1())) < 2048.0 &&
				static::convolveImagickImage($image, static::convolutionMatrix([
					[1.0, 1.0, 1.0], [1.0, $weight, 1.0], [1.0, 1.0, 1.0]])),
			IMG_FILTER_PIXELATE => $this->pixelateImagickImage($image),
			IMG_FILTER_SCATTER => $this->scatterImagickImage($image),
			default => null, // An imagefilter() without an Imagick equivalent here.
		};
	}

	/**
	 * Normalizes a GD convolution matrix for Imagick.  GD divides the
	 * convolved pixel by the sum of the matrix, and Imagick only divides by the sum of
	 * the weights when the image has an alpha channel, so the matrix is divided here and
	 * the kernel has the same effect on either image.  A matrix of a zero sum, which
	 * cannot be normalized, is used as it is.
	 *
	 * @param array<array<float>> $matrix The GD convolution matrix.
	 * @return array<array<float>> The normalized convolution matrix.
	 */
	private static function convolutionMatrix(array $matrix): array
	{
		if ($sum = array_sum(array_map('array_sum', $matrix))) {
			$matrix = array_map(fn ($row) => array_map(fn ($weight) => $weight / $sum, $row), $matrix);
		}
		return $matrix;
	}

	/**
	 * Colorizes an Imagick image, blending it toward the color of the Arg1, Arg2, and
	 * Arg3 channel offsets by |offset| / 255 of each channel.  Imagick cannot offset the
	 * alpha channel, so a non-zero Arg4 runs the effect in GD.
	 *
	 * @param \Imagick $image The image to colorize.
	 * @return ?bool Was the image changed; null when there is an alpha offset.
	 */
	private function colorizeImagickImage(\Imagick $image): ?bool
	{
		$red = max(-255, min(255, TPropertyValue::ensureInteger($this->getArg1())));
		$green = max(-255, min(255, TPropertyValue::ensureInteger($this->getArg2())));
		$blue = max(-255, min(255, TPropertyValue::ensureInteger($this->getArg3())));
		if (max(-127, min(127, TPropertyValue::ensureInteger($this->getArg4()))) !== 0) {
			return null;
		}
		if ($red === 0 && $green === 0 && $blue === 0) {
			return false;
		}
		return $image->colorizeImage(
			sprintf('rgb(%d, %d, %d)', $red > 0 ? 255 : 0, $green > 0 ? 255 : 0, $blue > 0 ? 255 : 0),
			sprintf('rgba(%F%%, %F%%, %F%%, 1.0)', abs($red) / 2.55, abs($green) / 2.55, abs($blue) / 2.55),
			false
		);
	}

	/**
	 * Pixelates an Imagick image in blocks of Arg1 pixels, by scaling the image down to
	 * one pixel per block and back up with FILTER_POINT.  The advanced mode (Arg2)
	 * averages each block, with scaleImage(), rather than sampling it.
	 *
	 * @param \Imagick $image The image to pixelate.
	 * @return bool Was the image changed; false when the block size is zero.
	 */
	private function pixelateImagickImage(\Imagick $image): bool
	{
		$block = max(0, TPropertyValue::ensureInteger($this->getArg1()));
		if (!$block) {
			return false;
		}
		$width = $image->getImageWidth();
		$height = $image->getImageHeight();
		$columns = (int) ceil($width / $block);
		$rows = (int) ceil($height / $block);
		if (TPropertyValue::ensureBoolean($this->getArg2())) {
			$image->scaleImage($columns, $rows);
		} else {
			$image->resizeImage($columns, $rows, \Imagick::FILTER_POINT, 1.0);
		}
		return $image->resizeImage($width, $height, \Imagick::FILTER_POINT, 1.0);
	}

	/**
	 * Scatters the pixels of an Imagick image within the Arg2 - Arg1 displacement of
	 * GD's scatter.  spreadImage() cannot be restricted to a list of colors, so a
	 * Arg3 of colors runs the effect in GD.
	 *
	 * @param \Imagick $image The image to scatter.
	 * @return ?bool Was the image changed; false when there is no displacement and null
	 *   when there is a list of colors.
	 */
	private function scatterImagickImage(\Imagick $image): ?bool
	{
		if (($colors = $this->getArg3()) !== null && TPropertyValue::ensureArray($colors)) {
			return null;
		}
		$radius = TPropertyValue::ensureInteger($this->getArg2()) - TPropertyValue::ensureInteger($this->getArg1());
		if ($radius <= 0) {
			return false;
		}
		return $image->spreadImage((float) $radius);
	}
}
