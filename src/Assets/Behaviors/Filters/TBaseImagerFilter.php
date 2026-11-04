<?php

/**
 * TBaseImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Helpers\TBitHelper;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TBaseImagerFilter class
 *
 * The base of the imager filters. A filter is a plain component that an imager
 * ({@see \Prado\Web\Assets\Behaviors\TAssetImagerBase}) creates from its configuration and
 * runs in order; see {@see IBaseImagerFilter}. A subclass implements {@see filterGdImage},
 * {@see filterImagickImage}, or both, and may implement `init($config)` to read child elements
 * of its configuration.
 *
 * A filter that makes an image, or draws glyphs, of a size from its configuration checks
 * the size with {@see ensureOutputSize} before it allocates the image, so a configured size
 * cannot exhaust the memory of the process.  {@see getMaximumOutputPixels
 * MaximumOutputPixels} is the limit, 50 megapixels by default and 0 for none.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
abstract class TBaseImagerFilter extends TComponent implements IBaseImagerFilter
{
	/** The default {@see getMaximumOutputPixels MaximumOutputPixels}, 50 megapixels. */
	public const DEFAULT_MAXIMUM_OUTPUT_PIXELS = 50000000;

	/** @var bool whether the filter runs. */
	private bool $_enabled = true;

	/** @var int the most pixels of an image the filter makes, 0 for no limit. */
	private int $_maximumOutputPixels = self::DEFAULT_MAXIMUM_OUTPUT_PIXELS;

	/** @var array<string, array{0:bool, 1:bool}> the filter methods implemented, by class. */
	private static array $_implementations = [];

	/**
	 * @return bool whether the filter runs, default true.
	 */
	public function getEnabled(): bool
	{
		return $this->_enabled;
	}

	/**
	 * @param bool|string $value whether the filter runs.
	 */
	public function setEnabled($value)
	{
		$this->_enabled = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int the most pixels of an image the filter makes from a size in its
	 *   configuration, default {@see DEFAULT_MAXIMUM_OUTPUT_PIXELS}; 0 is no limit.
	 */
	public function getMaximumOutputPixels(): int
	{
		return $this->_maximumOutputPixels;
	}

	/**
	 * @param int|string $value the most pixels of an image the filter makes from a size in
	 *   its configuration; 0, or a negative value, is no limit.
	 */
	public function setMaximumOutputPixels($value)
	{
		$this->_maximumOutputPixels = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * Checks the size of an image the filter is about to make against {@see
	 * getMaximumOutputPixels MaximumOutputPixels}.
	 * @param float|int $width the width of the image.
	 * @param float|int $height the height of the image.
	 * @throws TInvalidDataValueException when the image has more pixels than the limit.
	 */
	protected function ensureOutputSize($width, $height): void
	{
		$limit = $this->getMaximumOutputPixels();
		if ($limit && (!is_finite($pixels = (float) $width * (float) $height) || $pixels > $limit)) {
			throw new TInvalidDataValueException('imagerfilter_output_too_large', static::class, (string) $width, (string) $height, $limit);
		}
	}

	/**
	 * The graphics library the filter works in, from the filter methods it implements:
	 * {@see filterGdImage} alone gives GD, {@see filterImagickImage} alone gives Imagick,
	 * and a filter that implements both works in either, so the imager runs it in the
	 * library the image is already in.  A filter that implements neither, because it
	 * overrides {@see filterImage} itself, also works in either: nothing here can tell
	 * which library it needs, and requiring one would convert the image for it, and skip
	 * it when that library is missing.  Such a filter overrides this method when it does
	 * need a specific library.
	 * @return ?string the graphics mode the filter needs, or null for either.
	 */
	public function getGraphicsMode(): ?string
	{
		[$gd, $imagick] = static::filterImplementations();
		if ($gd === $imagick) {
			return null;
		}
		return $imagick ? TImageGraphicsMode::Imagick : TImageGraphicsMode::GD;
	}

	/**
	 * @return array{0:bool, 1:bool} whether the filter implements {@see filterGdImage} and
	 *   whether it implements {@see filterImagickImage}.
	 */
	protected static function filterImplementations(): array
	{
		return self::$_implementations[static::class] ??= [
			(new \ReflectionMethod(static::class, 'filterGdImage'))->getDeclaringClass()->getName() !== self::class,
			(new \ReflectionMethod(static::class, 'filterImagickImage'))->getDeclaringClass()->getName() !== self::class,
		];
	}

	/**
	 * Filters the image in the library it is in: an Imagick image goes to
	 * {@see filterImagickImage} and a GD image to {@see filterGdImage}. The imager gives
	 * the filter an image of the library of {@see getGraphicsMode}.
	 * @param \GdImage|\Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	public function filterImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if ($image instanceof \Imagick) {
			return $this->filterImagickImage($image, $param);
		}
		return $this->filterGdImage($image, $param);
	}

	/**
	 * Filters a GD image. A filter of GD calls implements this.
	 * @param \GdImage &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed; null when the filter is not implemented in GD.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		return null;
	}

	/**
	 * Filters an Imagick image. A filter of Imagick calls implements this; an Imagick
	 * image mutates in place, so the image only needs replacing when a new one is made.
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed; null when the filter is not implemented in Imagick.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		return null;
	}

	/**
	 * Convolves an Imagick image with a convolution matrix.  Imagick takes the kernel as
	 * an `\ImagickKernel` from 3.8 and as the flat array of the matrix before that, so the
	 * matrix is given in the form the installed extension takes.  The matrix is used as it
	 * is: Imagick only divides by the sum of the weights when the image has an alpha
	 * channel, so a caller that wants GD's normalization divides the matrix itself.
	 *
	 * @param object $image The Imagick image to convolve.
	 * @param array<array<float>> $matrix The convolution matrix, row by row.
	 * @return bool Was the image convolved.
	 */
	protected static function convolveImagickImage($image, array $matrix): bool
	{
		if (static::usesImagickKernel()) {
			return $image->convolveImage(\ImagickKernel::fromMatrix($matrix));
		}
		return $image->convolveImage(array_merge(...$matrix));
	}

	/**
	 * @return bool Does `Imagick::convolveImage()` take an `\ImagickKernel`, as it does
	 *   from Imagick 3.8, rather than the flat array of the matrix it took before.
	 */
	protected static function usesImagickKernel(): bool
	{
		static $usesKernel = null;
		if ($usesKernel === null) {
			$type = ((new \ReflectionMethod(\Imagick::class, 'convolveImage'))->getParameters()[0] ?? null)?->getType();
			$usesKernel = $type instanceof \ReflectionNamedType && $type->getName() === 'ImagickKernel';
		}
		return $usesKernel;
	}

	/**
	 * The Imagick resampling filter of a GD interpolation mode, from {@see
	 * imagickScaleModes}.  A GD mode that Imagick has no filter for resamples with the
	 * triangle filter, the Imagick equal of the default IMG_BILINEAR_FIXED.
	 *
	 * @param int $mode The GD interpolation mode, eg. IMG_BILINEAR_FIXED.
	 * @return int The Imagick FILTER_* resampling filter.
	 */
	protected static function imagickScaleMode(int $mode): int
	{
		return static::imagickScaleModes()[$mode] ?? \Imagick::FILTER_TRIANGLE;
	}

	/**
	 * The Imagick resampling filter of each GD interpolation mode of {@see
	 * TInterpolationImagerMode}, for {@see imagickScaleMode}.  The nearest Imagick filter
	 * is used where the two libraries name the same filter differently: the GD bilinear
	 * interpolations are the Imagick triangle filter, the GD bicubic interpolations are the
	 * Imagick Catmull-Rom filter, the GD bell is the Imagick quadratic filter, and the GD
	 * (B-)spline cubics are the Imagick cubic filter, the cubic B-spline.  A mode without an
	 * Imagick equal, such as IMG_POWER, is not in the map and resamples with the default of
	 * {@see imagickScaleMode}.
	 *
	 * The map is built when it is used, rather than held in a class constant, so that
	 * nothing evaluates the `\Imagick::FILTER_*` constants on a host without the extension.
	 *
	 * @return array<int, int> The Imagick FILTER_* filter of each GD IMG_* mode.
	 */
	protected static function imagickScaleModes(): array
	{
		return [
			IMG_NEAREST_NEIGHBOUR => \Imagick::FILTER_POINT,
			IMG_BILINEAR_FIXED => \Imagick::FILTER_TRIANGLE,
			IMG_TRIANGLE => \Imagick::FILTER_TRIANGLE,
			IMG_BICUBIC => \Imagick::FILTER_CATROM,
			IMG_BICUBIC_FIXED => \Imagick::FILTER_CATROM,
			IMG_CATMULLROM => \Imagick::FILTER_CATROM,
			IMG_BELL => \Imagick::FILTER_QUADRATIC,
			IMG_BESSEL => \Imagick::FILTER_BESSEL,
			IMG_BLACKMAN => \Imagick::FILTER_BLACKMAN,
			IMG_BOX => \Imagick::FILTER_BOX,
			IMG_BSPLINE => \Imagick::FILTER_CUBIC,
			IMG_GAUSSIAN => \Imagick::FILTER_GAUSSIAN,
			IMG_GENERALIZED_CUBIC => \Imagick::FILTER_CUBIC,
			IMG_HAMMING => \Imagick::FILTER_HAMMING,
			IMG_HANNING => \Imagick::FILTER_HANNING,
			IMG_HERMITE => \Imagick::FILTER_HERMITE,
			IMG_MITCHELL => \Imagick::FILTER_MITCHELL,
			IMG_QUADRATIC => \Imagick::FILTER_QUADRATIC,
			IMG_SINC => \Imagick::FILTER_SINC,
		];
	}

	/**
	 * This transfers properties of one image to another. Specifically,
	 * if there is a transparent color, it is copied from the $srcImage
	 * to the $dstImage.
	 *
	 * @param object $dstImage The GD image the properties are copied to.
	 * @param object $srcImage The GD image the properties are copied from.
	 */
	public static function transferImageProperties($dstImage, $srcImage)
	{
		if (!$dstImage || !$srcImage) {
			return;
		}
		if (($clearIndex = imageColorTransparent($srcImage)) >= 0) {
			$c = imageColorsForIndex($srcImage, $clearIndex);
			$dstClearIndex = imageColorAllocateAlpha($dstImage, $c['red'], $c['green'], $c['blue'], $c['alpha']);
			imageColorTransparent($dstImage, $dstClearIndex);
		}
	}

	/**
	 * The $place can be a numeric in pixels or a percentage of the size.
	 * If $place is negative, the pixels are subtracted from the dimensional
	 * size.  If $place is a percentage, the percentage is multiplied by
	 * the dimensional size to compute the Placement.
	 * $place can be "-0" to specify the max dimensional edge; as the computed
	 * value would be ($size - 1) + "-0".  The result is rounded to a whole pixel.
	 *
	 * @param null|numeric|string $place the pixels or percentage of the size, null is 0.
	 * @param float $size the size of the dimension.
	 * @return float the pixel location, a whole number.
	 */
	public static function computePlacement($place, float $size)
	{
		if ($place === null) {
			return 0.0;
		}
		if (is_numeric($place)) {
			$place = (float) $place;
			if ($place < 0 || TBitHelper::isNegativeFloat($place)) {
				return round($size + $place - 1);
			}
			return round($place);
		} else {
			$place = (float) trim((string) $place, " \t\n\r\0\x0B%");
			if ($place < 0 || TBitHelper::isNegativeFloat($place)) {
				return round(($size - 1) * (1 + ($place / 100.0)));
			}
			return round($place * ($size - 1) / 100.0);
		}
	}

	/**
	 * Whether a placement value (see {@see computePlacement}) is negative,
	 * including "-0" and "-0%".  Negative placements are measured from the
	 * right or bottom edge.
	 *
	 * @param null|numeric|string $place the pixels or percentage of the size.
	 * @return bool Is the placement negative.
	 */
	public static function isNegativePlacement($place): bool
	{
		if ($place === null) {
			return false;
		}
		$place = (float) (is_numeric($place) ? $place : trim((string) $place, " \t\n\r\0\x0B%"));
		return $place < 0 || TBitHelper::isNegativeFloat($place);
	}

	/**
	 * Scales an image with imagescale() using the interpolation $mode.  Some GD
	 * builds do not support some modes in imagescale() (eg. Bicubic, BicubicFixed,
	 * and Weighted4 with a system libgd); in that case the image is resampled with
	 * imagecopyresampled() into a new true color image preserving the alpha channel.
	 *
	 * @param object $image The image to scale.
	 * @param int $width The new width, at least 1.
	 * @param int $height The new height, at least 1.
	 * @param int $mode The GD interpolation mode, eg. IMG_BILINEAR_FIXED.
	 * @return false|object The new scaled image, or false on failure.
	 */
	public static function imageScaleWithMode($image, int $width, int $height, int $mode = IMG_BILINEAR_FIXED)
	{
		if (!$image) {
			return false;
		}
		$width = max(1, $width);
		$height = max(1, $height);
		if ($scaled = @imagescale($image, $width, $height, $mode)) {
			return $scaled;
		}
		if (!($scaled = @imagecreatetruecolor($width, $height))) {
			return false;
		}
		imagealphablending($scaled, false);
		imagesavealpha($scaled, true);
		imagefilledrectangle($scaled, 0, 0, $width - 1, $height - 1, 0x7F000000);
		imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
		imagealphablending($scaled, true);
		return $scaled;
	}
}
