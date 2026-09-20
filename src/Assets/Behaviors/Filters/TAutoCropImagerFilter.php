<?php

/**
 * TAutoCropImagerFilter class file.
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
 * TAutoCropImagerFilter class
 *
 * This applies a GD imagecropauto() on the image.  Set the {@see
 * setCropMode CropMode} to 'Default', 'Transparent', 'Black', 'White',
 * 'Sides', or 'Threshold' to configure imagecropauto().  The values
 * are defined in {@see TAutoCropImagerFilterMode}.
 *
 * In CropMode="Threshold", "Black", "White", and "Sides", set the
 * {@see setCropThreshold CropThreshold} (float) to include pixels
 * close to the specified/computed [autocrop] color.
 *
 * In CropMode="Threshold", set {@see setCropColor CropColor} to specify
 * the color to crop in imagecropauto().  The CropColor is a Web Color
 * (eg. "#RRGGBB" or "Red"), a hex color with alpha "#AARRGGBB" where AA
 * is [00..7F], or a numeric GD true color.
 *
 * This filter is written in GD, whose `imagecropauto()` has no Imagick equivalent for the
 * side and threshold modes. The imager converts an image of another graphics library into GD
 * for it, and skips the filter where GD is not installed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imagecropauto.php
 */
class TAutoCropImagerFilter extends TBaseImagerFilter
{
	/** @var int|string mode of the GD crop filter, default "Default" - which is "Transparent"  */
	private $_cropMode = TAutoCropImagerFilterMode::Default;

	/** @var ?float The Threshold for cropping "Threshold", "Black", "White", and "Sides". */
	private $_cropThreshold;

	/** @var numeric|string The Web Hex Color with Alpha "#AARRGGBB", AA is [00..7F] */
	private $_cropColor = '#00FFFFFF';

	/**
	 * The mode for imagecropauto.
	 *
	 * @return int|string The mode for imagecropauto, default "Default".
	 */
	public function getCropMode()
	{
		return $this->_cropMode;
	}

	/**
	 * This accepts all Modes in {@see TAutoCropImagerFilterMode}
	 * (case-insensitive) and PHP GD constants: IMG_CROP_DEFAULT, IMG_CROP_TRANSPARENT,
	 * IMG_CROP_BLACK, IMG_CROP_WHITE, IMG_CROP_SIDES, and IMG_CROP_THRESHOLD
	 * for the PHP GD method imagecropauto.  Numeric values are stored as integers.
	 *
	 * @param numeric|string $value The mode for imagecropauto.
	 * @throws TInvalidDataValueException when the mode is not valid.
	 * @see TAutoCropImagerFilterMode
	 */
	public function setCropMode($value)
	{
		if (is_numeric($value)) {
			$value = (int) $value;
			if (!in_array($value, [IMG_CROP_DEFAULT, IMG_CROP_TRANSPARENT,
				IMG_CROP_BLACK, IMG_CROP_WHITE, IMG_CROP_SIDES, IMG_CROP_THRESHOLD], true)) {
				throw new TInvalidDataValueException('autocropimagerfilter_bad_crop_mode', $value);
			}
		} else {
			$value = TPropertyValue::ensureEnum($value, TAutoCropImagerFilterMode::class);
		}
		$this->_cropMode = $value;
	}

	/**
	 * This can be used in all Modes except "Default" and "Transparent".
	 *
	 * @return ?float the threshold, default null.
	 *   Mode="Threshold" null is a threshold of 0.5.  For Mode="Black",
	 *   "White", and "Sides", then null is a threshold of 1 / 2048 (~0.0005).
	 */
	public function getCropThreshold()
	{
		return $this->_cropThreshold;
	}

	/**
	 * This can be used in all Modes except "Default" and "Transparent".
	 *
	 * @param null|float|string $value the threshold; null or "" for the default.
	 */
	public function setCropThreshold($value)
	{
		$this->_cropThreshold = ($value === null || $value === '') ? null : TPropertyValue::ensureFloat($value);
	}

	/**
	 * This is only used when Mode="Threshold".
	 *
	 * @return string The color for cropping by threshold in "#AARRGGBB"
	 *   format or a numeric GD true color, default '#00FFFFFF' (opaque white).
	 */
	public function getCropColor(): string
	{
		return $this->_cropColor;
	}

	/**
	 * @param null|object $image The image to allocate the color in, or null
	 *   to compute the GD true color value without an image.
	 * @return false|int The GD color of the {@see getCropColor CropColor}.
	 */
	protected function getCropColorIndex($image = null)
	{
		$cc = $this->getCropColor();
		if (is_numeric($cc)) {
			return (int) $cc;
		}
		$a = hexdec(substr($cc, 1, 2));
		$r = hexdec(substr($cc, 3, 2));
		$g = hexdec(substr($cc, 5, 2));
		$b = hexdec(substr($cc, 7, 2));
		if (!$image) {
			return ($a << 24) | ($r << 16) | ($g << 8) | $b;
		}
		return imageColorAllocateAlpha($image, $r, $g, $b, $a);
	}

	/**
	 * This is only used when Mode="Threshold". The value can be a numeric GD
	 * true color, "#AARRGGBB" where AA is the GD alpha [00..7F], or any Web
	 * Color accepted by {@see TPropertyValue::ensureHexColor} (eg.
	 * "#RRGGBB", "#RGB", or "White") which is made opaque.
	 *
	 * @param numeric|string $value color value for cropping by threshold.
	 */
	public function setCropColor($value)
	{
		if ((is_numeric($value) && $value >= 0)) {
			$this->_cropColor = $value;
			return;
		}
		$value = TPropertyValue::ensureString($value);
		if (preg_match('/^#[0-7][0-9a-fA-F]{7}$/', $value)) {
			$this->_cropColor = $value;
		} else {
			$value = TPropertyValue::ensureHexColor($value);
			$this->_cropColor = '#00' . substr($value, 1);
		}
	}

	/**
	 * Calls GD imagecropauto() with the proper Mode, Threshold, and Color.
	 *
	 * @param object &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 * @see https://www.php.net/manual/en/function.imagecropauto.php
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !imageistruecolor($image)) {
			return null;
		}
		$mode = $this->getCropMode();
		if (!is_numeric($mode)) {
			switch ($mode) {
				case TAutoCropImagerFilterMode::Default:
					$mode = IMG_CROP_DEFAULT;
					break;
				case TAutoCropImagerFilterMode::Transparent:
					$mode = IMG_CROP_TRANSPARENT;
					break;
				case TAutoCropImagerFilterMode::Black:
					$mode = IMG_CROP_BLACK;
					break;
				case TAutoCropImagerFilterMode::White:
					$mode = IMG_CROP_WHITE;
					break;
				case TAutoCropImagerFilterMode::Sides:
					$mode = IMG_CROP_SIDES;
					break;
				case TAutoCropImagerFilterMode::Threshold:
					$mode = IMG_CROP_THRESHOLD;
					break;
			}
		}
		$mode = (int) $mode;
		$color = ($mode === IMG_CROP_THRESHOLD) ? (int) $this->getCropColorIndex($image) : -1;
		return self::imageCropAuto($image, $mode, $this->getCropThreshold(), $color);
	}

	/**
	 * This is a more advanced version of PHP GD's imageCropAuto allowing
	 * for threshold on Sides, Black, and White besides Threshold.
	 *
	 * @param object &$image The image to crop, replaced by the cropped image on success.
	 * @param int $mode The mode to autoCrop
	 * @param ?float $threshold The threshold for Sides, Black, White, and Threshold.
	 * @param int $color The Color of the Threshold crop
	 * @return ?bool Successful or not, null is no action, false is fail, true is success.
	 * @see https://www.php.net/manual/en/function.imagecropauto.php
	 */
	public static function imageCropAuto(&$image, int $mode = IMG_CROP_DEFAULT, ?float $threshold = null, int $color = -1): ?bool
	{
		if (!$image || !imageistruecolor($image)) {
			return null;
		}
		if ($mode === IMG_CROP_THRESHOLD) {
			$threshold ??= 0.5;
		} elseif ($mode === IMG_CROP_TRANSPARENT) {
			if (imagecolortransparent($image) === -1) {
				return null;
			}
		} elseif ($mode === IMG_CROP_BLACK) {
			$mode = IMG_CROP_THRESHOLD;
			$threshold ??= 1 / 2048.0;
			$color = 0;
		} elseif ($mode === IMG_CROP_WHITE) {
			$mode = IMG_CROP_THRESHOLD;
			$threshold ??= 1 / 2048.0;
			$color = 0xFFFFFF;
		} elseif ($mode === IMG_CROP_SIDES) {
			$mode = IMG_CROP_THRESHOLD;
			$threshold ??= 1 / 2048.0;
			$sx = imagesx($image);
			$sy = imagesy($image);
			$corners = [
				imagecolorat($image, 0, 0),
				imagecolorat($image, $sx - 1, 0),
				imagecolorat($image, 0, $sy - 1),
				imagecolorat($image, $sx - 1, $sy - 1),
			];
			$color = 0;
			foreach ([24 => 0x7F, 16 => 0xFF, 8 => 0xFF, 0 => 0xFF] as $shift => $mask) {
				$sum = 0;
				foreach ($corners as $c) {
					$sum += ($c >> $shift) & $mask;
				}
				$color |= intdiv($sum + 2, 4) << $shift;
			}
		}
		if ($color < 0 && $mode === IMG_CROP_THRESHOLD) {
			return null;
		}
		if ($croppedImage = imagecropauto($image, $mode, $threshold ?? 0.5, $color)) {
			self::transferImageProperties($croppedImage, $image);
			$image = $croppedImage;
			return true;
		}
		return false;
	}
}
