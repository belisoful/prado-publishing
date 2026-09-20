<?php

/**
 * TImageImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TImageImagerFilter class
 *
 * This renders a [watermark] image on the image being published.
 *
 * The [watermark] image {@see getImageFileName ImageFileName} in the directory
 * {@see getImageNamespace ImageNamespace} is rendered into the image being
 * published.
 * The [watermark] image is located at {@see getImageX ImageX} and
 * {@see getImageY ImageY} on the publishing image.  When {@see
 * getImageFixedWidth ImageFixedWidth} or {@see getImageFixedHeight
 * ImageFixedHeight} is specified, the watermark image is scaled to the fixed
 * size with the {@see getImageInterpolationMode ImageInterpolationMode}.  If
 * both ImageFixedWidth and ImageFixedHeight are specified, {@see
 * getImageCropFill ImageCropFill} is used to determine whether to crop the
 * image to fill both fixed dimensions (true) or merely scale the image to fit
 * within the fixed size (false).
 *
 * The ImageX and ImageY values can be specified as a positive numeric -from
 * the left top-, a negative numeric -from the bottom right- subtracting from
 * the size of the dimension (including "-0" for the last pixel of the
 * dimension), or a percentage (eg. "90%").  The percentage will multiply the
 * size of the dimension by the percent to generate a pixel location.  Negative
 * percent starts with -0% in the lower right corner.
 *
 * {@see getImageAlignX ImageAlignX} values can be: "Left", "Center", "Right",
 * or "" (blank, the default); and the {@see getImageAlignY ImageAlignY} values
 * can be: "Top", "Center", "Bottom", or "" (blank, the default).  When blank,
 * positive values of ImageX and ImageY align the image to the Left-Top and
 * negative values align the image to the Right-Bottom.
 *
 * The filter works in both graphics libraries and places the watermark the same,
 * but each library scales it itself: Imagick resizes with the filter closest to the
 * ImageInterpolationMode -the default when it has none-, so a scaled watermark is
 * not pixel for pixel the one GD interpolates.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TImageImagerFilter extends TBaseImagerFilter
{
	/** @var string the namespace directory path of the image */
	private string $_imageNamespace = 'Application.Pages';

	/** @var ?string the file of the image watermark */
	private $_imageFileName;

	/** @var float|string the X location to render the watermark image. Negative numbers subtract from the right side of the image */
	private $_imageX = 0.0;

	/** @var float|string the Y location to render the watermark image. Negative numbers subtract from the bottom of the image */
	private $_imageY = 0.0;

	/** @var float the fixed width for scaling the watermark image */
	private float $_imageFixedWidth = 0.0;

	/** @var float the fixed height for scaling the watermark image */
	private float $_imageFixedHeight = 0.0;

	/** @var bool Should the watermark image be crop filled when both FixedWidth and FixedHeight is specified,
	 * otherwise the watermark image is scaled to fit in the Fixed dimensions, default true. */
	private bool $_imageCropFill = true;

	/** @var string The X-axis Alignment, default '' for automatic.  Possible values: Right, Center, Left, '' */
	private string $_imageAlignX = '';

	/** @var string The Y-axis Alignment, default '' for automatic.  Possible values: Top, Center, Bottom, '' */
	private string $_imageAlignY = '';

	/** @var int The imagescale() interpolation mode for scaling the watermark. */
	private int $_imageInterpolationMode = IMG_BILINEAR_FIXED;

	/**
	 * @return string the image namespace path, default "Application.Pages"
	 */
	public function getImageNamespace(): string
	{
		return $this->_imageNamespace;
	}

	/**
	 * @param string $value the image namespace path.
	 */
	public function setImageNamespace($value)
	{
		$this->_imageNamespace = TPropertyValue::ensureString($value);
	}

	/**
	 * @return ?string the image file name in the file Namespace, default null.
	 */
	public function getImageFileName(): ?string
	{
		return $this->_imageFileName;
	}

	/**
	 * @param string $value The image file name in the file Namespace.
	 */
	public function setImageFileName($value)
	{
		$this->_imageFileName = TPropertyValue::ensureNullIfEmpty(TPropertyValue::ensureString($value));
	}

	/**
	 * @return float|string The X placement of the [watermark] image, default 0.0.
	 */
	public function getImageX()
	{
		return $this->_imageX;
	}

	/**
	 * @param float|string $value The X placement of the [watermark] image.
	 */
	public function setImageX($value)
	{
		if (is_numeric($value)) {
			$this->_imageX = TPropertyValue::ensureFloat($value);
		} else {
			$this->_imageX = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * @return float|string The Y placement of the [watermark] image, default 0.0.
	 */
	public function getImageY()
	{
		return $this->_imageY;
	}

	/**
	 * @param float|string $value The Y placement of the [watermark] image.
	 */
	public function setImageY($value)
	{
		if (is_numeric($value)) {
			$this->_imageY = TPropertyValue::ensureFloat($value);
		} else {
			$this->_imageY = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * @return float The fixed width of the [watermark] image, default 0.0 for none.
	 */
	public function getImageFixedWidth(): float
	{
		return $this->_imageFixedWidth;
	}

	/**
	 * @param float $value The fixed width of the [watermark] image, negative values are 0.
	 */
	public function setImageFixedWidth($value)
	{
		$this->_imageFixedWidth = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return float The fixed height of the [watermark] image, default 0.0 for none.
	 */
	public function getImageFixedHeight(): float
	{
		return $this->_imageFixedHeight;
	}

	/**
	 * @param float $value The fixed height of the [watermark] image, negative values are 0.
	 */
	public function setImageFixedHeight($value)
	{
		$this->_imageFixedHeight = max(0.0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return bool When both dimensions are Fixed, Crop to fill? or scale to fit.
	 * default true
	 */
	public function getImageCropFill(): bool
	{
		return $this->_imageCropFill;
	}

	/**
	 * @param bool $value When both dimensions are Fixed, Crop to fill? or scale to fit.
	 */
	public function setImageCropFill($value)
	{
		$this->_imageCropFill = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Possible values: Left, Center, Right, and blank (default).  When blank
	 * the alignment depends on the direction of the {@see getImageX ImageX} value.
	 * Positive ImageX values are "Left" aligned and negative values
	 * are "Right" aligned, automatically.
	 * @return string Alignment on the X axis of the [watermark] image
	 * relative to the {@see getImageX ImageX} location.
	 */
	public function getImageAlignX(): string
	{
		return $this->_imageAlignX;
	}

	/**
	 * @param string $value Alignment on the X axis of the [watermark] image
	 * relative to the {@see getImageX ImageX} location: Left, Center, Right, or blank.
	 * @throws TInvalidDataValueException when the alignment is not valid.
	 */
	public function setImageAlignX($value)
	{
		$value = trim(TPropertyValue::ensureString($value));
		TPropertyValue::ensureEnum(strtolower($value), ['', 'left', 'center', 'right']);
		$this->_imageAlignX = $value;
	}

	/**
	 * Possible values: Top, Center, Bottom, and blank (default).  When blank
	 * the alignment depends on the direction of the {@see getImageY ImageY} value.
	 * Positive ImageY values are "Top" aligned and negative values
	 * are "Bottom" aligned, automatically.
	 * @return string Alignment on the Y axis of the [watermark] image
	 * relative to the {@see getImageY ImageY} location.
	 */
	public function getImageAlignY(): string
	{
		return $this->_imageAlignY;
	}

	/**
	 * @param string $value Alignment on the Y axis of the [watermark] image
	 * relative to the {@see getImageY ImageY} location: Top, Center, Bottom, or blank.
	 * @throws TInvalidDataValueException when the alignment is not valid.
	 */
	public function setImageAlignY($value)
	{
		$value = trim(TPropertyValue::ensureString($value));
		TPropertyValue::ensureEnum(strtolower($value), ['', 'top', 'center', 'bottom']);
		$this->_imageAlignY = $value;
	}

	/**
	 * @return int The interpolation mode for scaling the [watermark] image,
	 *   default IMG_BILINEAR_FIXED.
	 */
	public function getImageInterpolationMode(): int
	{
		return $this->_imageInterpolationMode;
	}

	/**
	 * The mode is a (case-insensitive) name in {@see TInterpolationImagerMode}
	 * or its integer value.  When the GD build does not support the mode in
	 * imagescale(), the watermark is resampled with imagecopyresampled().
	 * @param int|string $value The interpolation mode for scaling the [watermark] image.
	 * @throws TInvalidDataValueException when the mode is not valid.
	 */
	public function setImageInterpolationMode($value)
	{
		static $modes;
		if (!$modes) {
			$reflect = new \ReflectionClass(TInterpolationImagerMode::class);
			$modes = $reflect->getConstants();
			$modes = array_change_key_case($modes);
		}

		if (is_numeric($value)) {
			if (!in_array((int) $value, $modes, true)) {
				throw new TInvalidDataValueException('imageimagerfilter_bad_mode', $value);
			}
			$value = (int) $value;
		} else {
			$value = strtolower(trim(TPropertyValue::ensureString($value)));
			if (!array_key_exists($value, $modes)) {
				throw new TInvalidDataValueException('imageimagerfilter_bad_mode', $value);
			}
			$value = $modes[$value];
		}
		$this->_imageInterpolationMode = $value;
	}

	/**
	 * The file of the [watermark] image, from the {@see getImageFileName ImageFileName}
	 * in the {@see getImageNamespace ImageNamespace}.
	 * @throws TInvalidDataValueException when the watermark image file does not exist.
	 * @return ?string the watermark image file, or null when there is no watermark to
	 *   render.
	 */
	protected function resolveImageFile(): ?string
	{
		$ns = $this->getImageNamespace();
		$imageFile = $this->getImageFileName();

		if (empty($ns) || empty($imageFile)) {
			return null;
		}
		$filename = Prado::getPathOfNamespace($ns) . DIRECTORY_SEPARATOR . $imageFile;

		if (!is_file($filename)) {
			throw new TInvalidDataValueException('imageimagerfilter_bad_image_file_path', $imageFile, $ns);
		}
		return $filename;
	}

	/**
	 * Computes where the [watermark] image is rendered, in both graphics libraries: the
	 * region of the watermark that is drawn -the whole watermark unless {@see
	 * getImageCropFill ImageCropFill} crops it to the fixed aspect ratio-, the size it
	 * is scaled to by {@see getImageFixedWidth ImageFixedWidth} and {@see
	 * getImageFixedHeight ImageFixedHeight}, and the point it is drawn at from {@see
	 * getImageX ImageX}, {@see getImageY ImageY}, and the alignments.
	 *
	 * @param int $sx The width of the image being filtered.
	 * @param int $sy The height of the image being filtered.
	 * @param int $wx The width of the watermark image.
	 * @param int $wy The height of the watermark image.
	 * @return array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int, 6:int, 7:int} the source
	 *   [x, y, width, height] of the watermark, the [width, height] it is drawn at, and
	 *   the [x, y] it is drawn at on the image.
	 */
	protected function computeImageGeometry(int $sx, int $sy, int $wx, int $wy): array
	{
		$imageX = self::computePlacement($this->getImageX(), $sx);
		$imageY = self::computePlacement($this->getImageY(), $sy);

		$dx = $wx;
		$dy = $wy;

		$fx = $this->getImageFixedWidth();
		$fy = $this->getImageFixedHeight();
		$ox = $oy = 0;

		if ($fx && $fy && $this->getImageCropFill()) {
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

		// The source region of the watermark with the aspect ratio of the destination.
		$widerThanHeight = ($wx * $dy > $dx * $wy);
		$cx = $widerThanHeight ? $wy * $dx / $dy : $wx;
		$cy = !$widerThanHeight ? $wx * $dy / $dx : $wy;

		$srcX = max(0, min($wx - 1, (int) floor($ox)));
		$srcY = max(0, min($wy - 1, (int) floor($oy)));
		$srcW = max(1, min($wx - $srcX, (int) floor($cx)));
		$srcH = max(1, min($wy - $srcY, (int) floor($cy)));
		$dstW = max(1, (int) floor($dx));
		$dstH = max(1, (int) floor($dy));

		$alignX = strtolower($this->getImageAlignX());
		$alignY = strtolower($this->getImageAlignY());
		if ($alignX === '') {
			$alignX = self::isNegativePlacement($this->getImageX()) ? 'right' : 'left';
		}
		if ($alignY === '') {
			$alignY = self::isNegativePlacement($this->getImageY()) ? 'bottom' : 'top';
		}
		// ImageX and ImageY are pixel positions; right/bottom align the last pixel to that position.
		if ($alignX === 'center') {
			$imageX -= ($dstW - 1) / 2.0;
		} elseif ($alignX === 'right') {
			$imageX -= $dstW - 1;
		}
		if ($alignY === 'center') {
			$imageY -= ($dstH - 1) / 2.0;
		} elseif ($alignY === 'bottom') {
			$imageY -= $dstH - 1;
		}

		return [$srcX, $srcY, $srcW, $srcH, $dstW, $dstH, (int) round($imageX), (int) round($imageY)];
	}

	/**
	 * Renders the [watermark] image onto the image.
	 * @param \GdImage &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @throws TInvalidDataValueException when the watermark image file does not exist.
	 * @return ?bool Was the image changed.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image) {
			return null;
		}
		if (($filename = $this->resolveImageFile()) === null) {
			return null;
		}

		$rendered = false;

		// imagecreatefromstring() warns on unrecognized data and throws on empty data.
		$data = file_get_contents($filename);
		$waterImage = ($data !== false && $data !== '') ? @imagecreatefromstring($data) : false;
		if (!$waterImage) {
			return false;
		}
		if (!imageistruecolor($waterImage)) {
			imagepalettetotruecolor($waterImage);
		}
		$wx = imagesx($waterImage);
		$wy = imagesy($waterImage);
		[$srcX, $srcY, $srcW, $srcH, $dstW, $dstH, $dstX, $dstY] = $this->computeImageGeometry(imagesx($image), imagesy($image), $wx, $wy);

		$srcImage = $waterImage;
		if ($srcX !== 0 || $srcY !== 0 || $srcW !== $wx || $srcH !== $wy) {
			imagealphablending($waterImage, false);
			$srcImage = imagecrop($waterImage, ['x' => $srcX, 'y' => $srcY, 'width' => $srcW, 'height' => $srcH]);
		}
		$scaledImage = $srcImage;
		if ($srcImage && ($dstW !== $srcW || $dstH !== $srcH)) {
			$scaledImage = self::imageScaleWithMode($srcImage, $dstW, $dstH, $this->getImageInterpolationMode());
		}

		if ($scaledImage) {
			imagealphablending($image, true);
			if (imagecopy($image, $scaledImage, $dstX, $dstY, 0, 0, $dstW, $dstH)) {
				$rendered = true;
			}
		}

		return $rendered;
	}

	/**
	 * Renders the [watermark] image onto the image.  The watermark is placed and scaled
	 * as in {@see filterGdImage}, with the Imagick resize filter of the {@see
	 * getImageInterpolationMode ImageInterpolationMode}, and composited over the image.
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @throws TInvalidDataValueException when the watermark image file does not exist.
	 * @return ?bool Was the image changed.
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image->getNumberImages()) {
			return null;
		}
		if (($filename = $this->resolveImageFile()) === null) {
			return null;
		}
		// The file is read as data, as in GD, so the file name is not an Imagick
		// specification of a format, a scene, or a resource to fetch.
		$data = file_get_contents($filename);
		$waterImage = new \Imagick();
		try {
			// readImageBlob() throws on data no delegate reads, and on empty data.
			$waterImage->readImageBlob((string) $data);
		} catch (\ImagickException $e) {
			return false;
		}
		$wx = $waterImage->getImageWidth();
		$wy = $waterImage->getImageHeight();
		[$srcX, $srcY, $srcW, $srcH, $dstW, $dstH, $dstX, $dstY] = $this->computeImageGeometry($image->getImageWidth(), $image->getImageHeight(), $wx, $wy);

		if ($srcX !== 0 || $srcY !== 0 || $srcW !== $wx || $srcH !== $wy) {
			$waterImage->cropImage($srcW, $srcH, $srcX, $srcY);
			$waterImage->setImagePage(0, 0, 0, 0); // The crop leaves the page of the original.
		}
		if ($dstW !== $srcW || $dstH !== $srcH) {
			$waterImage->resizeImage($dstW, $dstH, static::imagickResizeFilter($this->getImageInterpolationMode()), 1.0);
		}
		return $image->compositeImage($waterImage, \Imagick::COMPOSITE_OVER, $dstX, $dstY);
	}

	/**
	 * The Imagick resize filter closest to a GD interpolation mode.  The modes GD
	 * implements as an interpolation of its own, and the modes Imagick has no filter
	 * for, resize with the Imagick filter of the default BilinearFixed.
	 * @param int $mode The GD interpolation mode, eg. IMG_BILINEAR_FIXED.
	 * @return int The Imagick filter, eg. \Imagick::FILTER_TRIANGLE.
	 */
	protected static function imagickResizeFilter(int $mode): int
	{
		$filters = [
			IMG_NEAREST_NEIGHBOUR => \Imagick::FILTER_POINT,
			IMG_BOX => \Imagick::FILTER_BOX,
			IMG_TRIANGLE => \Imagick::FILTER_TRIANGLE,
			IMG_BILINEAR_FIXED => \Imagick::FILTER_TRIANGLE,
			IMG_HERMITE => \Imagick::FILTER_HERMITE,
			IMG_HANNING => \Imagick::FILTER_HANNING,
			IMG_HAMMING => \Imagick::FILTER_HAMMING,
			IMG_BLACKMAN => \Imagick::FILTER_BLACKMAN,
			IMG_GAUSSIAN => \Imagick::FILTER_GAUSSIAN,
			IMG_QUADRATIC => \Imagick::FILTER_QUADRATIC,
			IMG_BICUBIC => \Imagick::FILTER_CUBIC,
			IMG_BICUBIC_FIXED => \Imagick::FILTER_CUBIC,
			IMG_GENERALIZED_CUBIC => \Imagick::FILTER_CUBIC,
			IMG_BSPLINE => \Imagick::FILTER_CUBIC, // The Imagick cubic filter is a B-spline.
			IMG_CATMULLROM => \Imagick::FILTER_CATROM,
			IMG_MITCHELL => \Imagick::FILTER_MITCHELL,
			IMG_SINC => \Imagick::FILTER_SINC,
			IMG_BESSEL => \Imagick::FILTER_BESSEL,
		];
		return $filters[$mode] ?? \Imagick::FILTER_TRIANGLE;
	}
}
