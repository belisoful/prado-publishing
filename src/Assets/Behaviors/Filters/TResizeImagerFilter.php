<?php

/**
 * TResizeImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TResizeImagerFilter class
 *
 * This applies a GD imagecrop() and imagescale() to the image
 * based upon {@see getMaximumWidth MaximumWidth}, {@see getMaximumHeight
 * MaximumHeight}, {@see getFixedWidth FixedWidth}, {@see getFixedHeight
 * FixedHeight}, {@see getMaximumPixels MaximumPixels}, {@see getCropFill
 * CropFill}, and scaling {@see getResizeMode ResizeMode}.
 *
 * CropFill is only needed when both FixedWidth and FixedHeight is specified.
 * CropFill allows for resizing to fill the Fixed area, rather than merely
 * scaling the x and y dimensions to a maximum of the Fixed axis.  When true,
 * x and y will be FixedWidth and FixedHeight, respectively.
 *
 * When CropFill is false, the x and y size of the image will be scaled to
 * the maximum of the fixed dimensions; one dimension will be fixed and the
 * other will be smaller or equal than its Fixed dimension.
 *
 * Imagick crops and resizes to the same geometry, computed by {@see
 * computeResizeGeometry}, with an Imagick cropImage() and resizeImage().  The
 * {@see getResizeMode ResizeMode} is a GD interpolation mode, so it is mapped to the
 * nearest Imagick resampling filter by {@see imagickScaleModes}; the two libraries
 * resample differently, so an Imagick resize is the same size as, but not pixel for
 * pixel identical to, the GD resize.  Imagick resizeImage() is used rather than
 * thumbnailImage() because thumbnailImage() strips the profiles of the image that the
 * imager writes back into the published file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imagecrop.php
 * @see https://www.php.net/manual/en/function.imagescale.php
 * @see https://www.php.net/manual/en/imagick.resizeimage.php
 */
class TResizeImagerFilter extends TBaseImagerFilter
{
	/**
	 * The interpolation modes the PHP imagescale() documentation lists, lower case, and
	 * their IMG_* constants.  {@see setResizeMode ResizeMode} is not limited to these: it
	 * accepts every mode of {@see TInterpolationImagerMode} except "Weighted4", which
	 * imagescale() does not implement.
	 */
	public const SCALE_MODE = [
			'nearestneighbour' => IMG_NEAREST_NEIGHBOUR,
			'bilinearfixed' => IMG_BILINEAR_FIXED,
			'bicubic' => IMG_BICUBIC,
			'bicubicfixed' => IMG_BICUBIC_FIXED,
			//, 'weighted4' => IMG_WEIGHTED4 // not implemented in PHP yet, per imagescale doc.
		];

	/** @var float The maximum width allowed, default 0 for no maximum. */
	private float $_maximumWidth = 0.0;

	/** @var float The maximum height allowed, default 0 for no maximum. */
	private float $_maximumHeight = 0.0;

	/** @var float The width of the maximum pixels, default 0 for no maximum. */
	private float $_maximumPixelsWidth = 0.0;

	/** @var float The height of the maximum pixels, default 0 for no maximum. */
	private float $_maximumPixelsHeight = 0.0;

	/** @var float The fixed width, default 0 for no fixed width. */
	private float $_fixedWidth = 0.0;

	/** @var float The fixed height, default 0 for no fixed height. */
	private float $_fixedHeight = 0.0;

	/** @var bool On both FixedWidth and FixedHeight, crop to fill both Fixed dimensions, default true. */
	private bool $_cropFill = true;

	/** @var int The imagescale mode. */
	private int $_resizeMode = IMG_BILINEAR_FIXED;

	/**
	 * @return float Maximum width, default 0 for none.
	 */
	public function getMaximumWidth(): float
	{
		return $this->_maximumWidth;
	}

	/**
	 * @param float $value the new maximum width
	 */
	public function setMaximumWidth($value)
	{
		$this->_maximumWidth = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return float Maximum height, default 0 for none
	 */
	public function getMaximumHeight(): float
	{
		return $this->_maximumHeight;
	}

	/**
	 * @param float $value the new maximum height
	 */
	public function setMaximumHeight($value)
	{
		$this->_maximumHeight = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * The maximum pixels in the form {@see setMaximumPixels} reads: one number, the size
	 * of both dimensions, when the width and height are equal, and "width x height"
	 * otherwise.  Setting the value read gives the same maximum.
	 * @return string Maximum pixels, default "0" for none.
	 */
	public function getMaximumPixels(): string
	{
		$width = $this->_maximumPixelsWidth;
		$height = $this->_maximumPixelsHeight;
		if (!$width || !$height) {
			return '0';
		}
		if ($width === $height) {
			return static::formatPixels($width);
		}
		return static::formatPixels($width) . 'x' . static::formatPixels($height);
	}

	/**
	 * @return int The number of pixels of {@see getMaximumPixels MaximumPixels}, 0 for none,
	 *   and PHP_INT_MAX when the product is beyond the integer range.
	 */
	public function getMaximumPixelCount(): int
	{
		$pixels = floor($this->_maximumPixelsWidth * $this->_maximumPixelsHeight);
		return $pixels >= PHP_INT_MAX ? PHP_INT_MAX : (int) $pixels;
	}

	/**
	 * This can specify a number of pixels in both dimensions (eg. "1000" for 1000 x 1000
	 * pixels) or pixels dimensions in the format of eg. "1920x1080", "1920×1080", or
	 * "1920*1080".  Each number is finite; a negative number is 0, and a 0 dimension
	 * is no maximum.  An empty value is no maximum.
	 * @param numeric|string $value the maximum pixels
	 * @throws TInvalidDataValueException when the format is invalid or a number is not finite.
	 */
	public function setMaximumPixels($value)
	{
		$number = '[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?';
		if (is_int($value) || is_float($value)) {
			$width = $height = (float) $value;
		} elseif (($value = trim(TPropertyValue::ensureString($value))) === '') {
			$width = $height = 0.0;
		} elseif (preg_match('/^(' . $number . ')\s*(?:(?:x|\*|×)\s*(' . $number . '))?$/iu', $value, $matches)) {
			$width = (float) $matches[1];
			$height = isset($matches[2]) ? (float) $matches[2] : $width;
		} else {
			throw new TInvalidDataValueException('resizeimagerfilter_bad_max_pixel_format', $value);
		}
		if (!is_finite($width) || !is_finite($height)) {
			throw new TInvalidDataValueException('resizeimagerfilter_bad_max_pixel_format', (string) $value);
		}
		$this->_maximumPixelsWidth = max(0.0, $width);
		$this->_maximumPixelsHeight = max(0.0, $height);
	}

	/**
	 * @param float $value a dimension of the maximum pixels.
	 * @return string the dimension in a form {@see setMaximumPixels} reads back exactly.
	 */
	protected static function formatPixels(float $value): string
	{
		$text = var_export($value, true); // The shortest form that reads back as the same float.
		return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
	}

	/**
	 * @return float Fixed width, default 0 for none
	 */
	public function getFixedWidth(): float
	{
		return $this->_fixedWidth;
	}

	/**
	 * @param float $value the new fixed width
	 */
	public function setFixedWidth($value)
	{
		$this->_fixedWidth = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return float Fixed height, default 0 for none
	 */
	public function getFixedHeight(): float
	{
		return $this->_fixedHeight;
	}

	/**
	 * @param float $value the new fixed height
	 */
	public function setFixedHeight($value)
	{
		$this->_fixedHeight = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return bool crop the edges of the image when both {@see getFixedWidth
	 * FixedWidth} and {@see getFixedHeight FixedHeight} is not 0.
	 */
	public function getCropFill(): bool
	{
		return $this->_cropFill;
	}

	/**
	 * @param bool $value crop the edges of the image when both {@see getFixedWidth
	 * FixedWidth} and {@see getFixedHeight FixedHeight} is not 0.
	 */
	public function setCropFill($value)
	{
		$this->_cropFill = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int the imagescale() Mode, default IMG_BILINEAR_FIXED.
	 */
	public function getResizeMode(): int
	{
		return $this->_resizeMode;
	}

	/**
	 * This can be any (case-insensitive) name in {@see TInterpolationImagerMode},
	 * eg. "NearestNeighbour", "BilinearFixed", "Bicubic", or "BicubicFixed", or its
	 * integer value, except "Weighted4".  These values are mapped to their imagescale()
	 * value.  When the GD build does not support the mode in imagescale(), the image
	 * is resampled with imagecopyresampled().
	 * @param int|string $value the imagescale() Mode.
	 * @throws TInvalidDataValueException when the mode is not valid.
	 */
	public function setResizeMode($value)
	{
		static $modes;
		if (!$modes) {
			$reflect = new \ReflectionClass(TInterpolationImagerMode::class);
			$modes = $reflect->getConstants();
			$modes = array_change_key_case($modes);
		}

		if (is_numeric($value)) {
			if (!in_array((int) $value, $modes, true) || (int) $value === IMG_WEIGHTED4) {
				throw new TInvalidDataValueException('resizeimagerfilter_bad_mode', $value);
			}
			$value = (int) $value;
		} else {
			$value = strtolower(trim(TPropertyValue::ensureString($value)));
			if (!array_key_exists($value, $modes) || $value == 'weighted4') {
				throw new TInvalidDataValueException('resizeimagerfilter_bad_mode', $value);
			}
			$value = $modes[$value];
		}
		$this->_resizeMode = $value;
	}

	/**
	 * Calls GD imagecrop() and imagescale with the specified {@see getMaximumWidth
	 * MaximumWidth}, {@see getMaximumHeight MaximumHeight}, {@see getFixedWidth
	 * FixedWidth}, {@see getFixedHeight FixedHeight}, {@see getMaximumPixels
	 * MaximumPixels}, {@see getCropFill CropFill}, and {@see getResizeMode
	 * ResizeMode} [for scaling].  The size and the crop rectangle are computed by
	 * {@see computeResizeGeometry}, and the resulting dimensions are at least 1 pixel.
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

		$sx = imagesx($image);
		$sy = imagesy($image);
		['width' => $dstW, 'height' => $dstH, 'crop' => $crop] = $this->computeResizeGeometry($sx, $sy);
		if ($dstW === $sx && $dstH === $sy) {
			return false;
		}
		$cropImage = $image;
		if ($crop !== null) {
			$cropImage = static::cropImage($image, $crop);
			if (!$cropImage) {
				return false;
			}
		}
		$scaledImage = self::imageScaleWithMode($cropImage, $dstW, $dstH, $this->getResizeMode());
		if (!$scaledImage) {
			return false;
		}
		self::transferImageProperties($scaledImage, $image);
		$image = $scaledImage;
		return true;
	}

	/**
	 * Crops and resizes an Imagick image to the geometry of {@see computeResizeGeometry},
	 * the Imagick equal of {@see filterGdImage}: the crop rectangle becomes an Imagick
	 * cropImage() and the new size an Imagick resizeImage() with the resampling filter of
	 * {@see imagickScaleMode}.  The image is cropped and resized in place, so the crop
	 * offset is taken off the virtual canvas of the image.
	 *
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$sx = $image->getImageWidth();
		$sy = $image->getImageHeight();
		['width' => $dstW, 'height' => $dstH, 'crop' => $crop] = $this->computeResizeGeometry($sx, $sy);
		if ($dstW === $sx && $dstH === $sy) {
			return false;
		}
		if ($crop !== null) {
			$image->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
			$image->setImagePage(0, 0, 0, 0);
		}
		$image->resizeImage($dstW, $dstH, static::imagickScaleMode($this->getResizeMode()), 1.0);
		return true;
	}

	/**
	 * Computes the size the image is resized to, and the rectangle cropped from it first,
	 * from {@see getMaximumWidth MaximumWidth}, {@see getMaximumHeight MaximumHeight},
	 * {@see getFixedWidth FixedWidth}, {@see getFixedHeight FixedHeight}, {@see
	 * getMaximumPixels MaximumPixels}, and {@see getCropFill CropFill}.  This is the
	 * geometry that both {@see filterGdImage} and {@see filterImagickImage} resize to.
	 *
	 * The Fixed sizes are applied first, cropping the center of the image when both are
	 * specified with CropFill, then the Maximum sizes, then MaximumPixels.  The resulting
	 * dimensions are at least 1 pixel, and equal the source size when the image is not
	 * resized.  A new size is checked against {@see getMaximumOutputPixels
	 * MaximumOutputPixels}.
	 *
	 * @param int $sx The source width.
	 * @param int $sy The source height.
	 * @throws TInvalidDataValueException when the new size has more
	 *   pixels than MaximumOutputPixels.
	 * @return array{width: int, height: int, crop: null|array{x: int, y: int, width: int, height: int}}
	 *   The new size, and the rectangle to crop before scaling or null to scale the whole
	 *   image.
	 */
	protected function computeResizeGeometry(int $sx, int $sy): array
	{
		$dx = $sx;
		$dy = $sy;
		$mx = $this->getMaximumWidth();
		$my = $this->getMaximumHeight();
		$fx = $this->getFixedWidth();
		$fy = $this->getFixedHeight();
		$ox = 0;
		$oy = 0;

		if ($fx && $fy && $this->getCropFill()) {
			$widerThanHeight = ($dx * $fy > $fx * $dy);  // if ($dx / $dy > $fx / $fy) but without division.
			if ($widerThanHeight) {
				$ox = ($dx - ($dy * $fx / $fy)) / 2.0;
			} else {
				$oy = ($dy - ($dx * $fy / $fx)) / 2.0;
			}
			$dx = $fx;
			$dy = $fy;
		} else {
			$widerThanHeight = null;
			if ($fx && $fy) {
				$widerThanHeight = ($dx * $fy > $fx * $dy);  // if ($dx / $dy > $fx / $fy) but without division.
			}
			if (($fx && !$fy && $dx != $fx) || $widerThanHeight === true) {
				$dy = $dy * $fx / $dx;
				$dx = $fx;
			}
			if ((!$fx && $fy && $dy != $fy) || $widerThanHeight === false) {
				$dx = $dx * $fy / $dy;
				$dy = $fy;
			}
		}

		if ($mx && $dx > $mx) {
			$dy = $dy * $mx / $dx;
			$dx = $mx;
		}
		if ($my && $dy > $my) {
			$dx = $dx * $my / $dy;
			$dy = $my;
		}

		if (($mp = $this->getMaximumPixelCount()) && ($dp = $dx * $dy) > $mp) {
			$s = sqrt($mp / $dp);
			$dx = $dx * $s;
			$dy = $dy * $s;
		}
		if (floor($dx) != $sx || floor($dy) != $sy) {
			$this->ensureOutputSize(max(1.0, floor($dx)), max(1.0, floor($dy)));
		}
		$dstW = max(1, (int) floor($dx));
		$dstH = max(1, (int) floor($dy));
		$crop = null;
		if (($dstW !== $sx || $dstH !== $sy) && ($ox || $oy)) {
			$widerThanHeight = ($sx * $dy > $dx * $sy);
			$cx = $widerThanHeight ? $sy * $dx / $dy : $sx;
			$cy = !$widerThanHeight ? $sx * $dy / $dx : $sy;
			$cropX = max(0, min($sx - 1, (int) floor($ox)));
			$cropY = max(0, min($sy - 1, (int) floor($oy)));
			$crop = [
				'x' => $cropX,
				'y' => $cropY,
				'width' => max(1, min($sx - $cropX, (int) floor($cx))),
				'height' => max(1, min($sy - $cropY, (int) floor($cy))),
			];
		}
		return ['width' => $dstW, 'height' => $dstH, 'crop' => $crop];
	}

	/**
	 * Crops the image with GD imagecrop().  The rectangle is within the image,
	 * so this fails only when GD cannot allocate the cropped image.
	 *
	 * @param object $image The image to crop.
	 * @param array{x: int, y: int, width: int, height: int} $rect The crop rectangle.
	 * @return false|object The new cropped image, or false on failure.
	 */
	protected static function cropImage($image, array $rect)
	{
		return imagecrop($image, $rect);
	}
}
