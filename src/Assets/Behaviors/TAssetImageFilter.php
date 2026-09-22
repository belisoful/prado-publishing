<?php

/**
 * TAssetImageFilter class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\IImageGraphicsLibrary;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TTIFF;
use Prado\TPropertyValue;
use Prado\Web\UI\TWebColor;

/**
 * TAssetImageFilter
 *
 * The general image filter behavior. It attaches to {@see \Prado\Web\Assets\TAsset}
 * (or {@see \Prado\Web\Assets\TImageAsset}), runs its filters on the published
 * images that match {@see getMatchFiles MatchFiles}, and saves each in its original
 * format. The format conversions {@see TAssetJPEGize}, {@see TAssetPNGize},
 * {@see TAssetWebPize}, {@see TAssetGIFize}, {@see TAssetBMPize}, {@see TAssetWBMPize},
 * {@see TAssetXBMize}, and {@see TAssetTIFFize} extend it.
 *
 * ```xml
 * <behavior name="maxSize" Class="Prado\Web\Assets\Behaviors\TAssetImageFilter"
 *     AttachToClass="Prado\Web\Assets\TImageAsset" ImageQuality="85">
 *   <filter type="Orient" />
 *   <filter type="Resize" MaximumWidth="1920" MaximumHeight="1080" />
 * </behavior>
 * ```
 *
 * The saving properties control the encoding: {@see getImageQuality ImageQuality} for
 * JPEG and WebP, {@see getPngQuality PngQuality} and {@see getPngColorMode PngColorMode}
 * for PNG, the palette ({@see getPaletteColors PaletteColors},
 * {@see getPaletteDither PaletteDither}, and the palette alpha properties),
 * {@see getSaveAlpha SaveAlpha}, {@see getSaveInterlace SaveInterlace},
 * {@see getBackgroundColor BackgroundColor}, and {@see getToBlackAndWhite ToBlackAndWhite}.
 *
 * By default, MatchFiles excludes image files whose names end in ".full" or
 * ".original" before the extension, e.g. "photo.full.jpg", so those publish unchanged.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetImageFilter extends TAssetImagerBase
{
	/** The TIFF compressions of {@see setTiffCompression TiffCompression}, by lower case name. */
	public const TIFF_COMPRESSIONS = [
		'none' => TTIFF::CompressionNone,
		'ccittrle' => TTIFF::CompressionCcittRle,
		'group3' => TTIFF::CompressionGroup3,
		'group4' => TTIFF::CompressionGroup4,
		'lzw' => TTIFF::CompressionLzw,
		'packbits' => TTIFF::CompressionPackBits,
	];

	/** @var int JPEG output quality. */
	private int $_imageQuality = 75;

	/** @var int PNG compression quality. */
	private int $_pngQuality = -1;

	/** @var int the TIFF compression of published TIFF images. */
	private int $_tiffCompression = TTIFF::CompressionLzw;

	/** @var string The Color Mode for the PNG, default 'Preserve'. */
	private string $_pngColorMode = TAssetPNGColorMode::Preserve;

	/** @var bool PNG, WebP Alpha is saved. */
	private bool $_saveAlpha = true;

	/** @var bool PNG, WebP Alpha is interlaced. */
	private ?bool $_saveInterlace = null;

	/** @var numeric|string color of the background when removing alpha. */
	private $_backgroundColor = TWebColor::Black;

	/** @var bool Should the true color images be color restricted
	 *    to the number of palette colors before saving, default false. */
	private bool $_palettizeTrueColor = false;

	/** @var int Number of palette colors, default 0, preserves the number
	 *    of palette colors from the original image., */
	private int $_paletteColors = 0;

	/** @var bool When converting to palette, should it dither. default true. */
	private bool $_paletteDither = true;

	/** @var numeric|string The Alpha color when the image has no transparency color.
	 *   The default is the alpha-color of palette transparency colors for PNGs-
	 *   Default: '#7F000000'.  This can also be "Sides" for computing the
	 *   alpha color from the corners of the image.
	 */
	private $_paletteAlphaColor = '#7F000000';

	/** @var int When converting to palette, the alpha threshold for alpha values
	 *   that get the transparency color.  A value of 127 means that only full
	 *   alpha pixels (127) are made transparent.  0 Means that everything is
	 *   transparent. -1 means there is extra no pixel alpha value inclusion processing.
	 *   Values: [-1..127]. Default -1 for none.
	 */
	private int $_paletteAlphaThreshold = -1;

	/** @var bool When processing the Alpha channel for palette images,
	 *    is the color is made transparent regardless of alpha on true.
	 *    Normally, the alpha and color value must match the Transparent color
	 *    to be saved in the palette as transparent.  When this is true, all
	 *    colors that match the color, regardless of alpha, are made transparent.
	 *    Default: false.
	 */
	private bool $_paletteColorIsTransparent = false;

	/** @var bool Should the end result saved image be black and white. default false. */
	private bool $_toBlackAndWhite = false;

	/**
	 * Default 60.  [0..100] are acceptable.  0 is minimum
	 * quality with smallest file size, and 100 is maximum quality
	 * with largest file size.
	 * @return int the image quality of the resulting images
	 */
	public function getImageQuality(): int
	{
		return $this->_imageQuality;
	}

	/**
	 * @param int $quality the image quality of the resulting images
	 */
	public function setImageQuality($quality)
	{
		$this->_imageQuality = max(-1, min(100, TPropertyValue::ensureInteger($quality)));
	}

	/**
	 * Default -1.  [0..9] are acceptable, 0 is no compression
	 * 9 is maximum compression.  -1 is default compression.
	 * PNG is always lossless so this changes the time spent
	 * compressing.
	 *
	 * @return int the image quality of the resulting images
	 */
	public function getPngQuality(): int
	{
		return $this->_pngQuality;
	}

	/**
	 * @param int $quality the compression quality of the resulting images
	 */
	public function setPngQuality($quality)
	{
		$this->_pngQuality = max(-1, min(9, TPropertyValue::ensureInteger($quality)));
	}

	/**
	 * @param int $quality the image quality of the resulting images
	 * @param null|\Prado\Util\TCallChain $callchain
	 */
	public function dySetQuality($quality, $callchain = null)
	{
		$pngQ = $q = max(-1, min(100, TPropertyValue::ensureInteger($quality)));
		if ($q != -1) { // 0..100 mapped to 9 to 0, only 100 gets mapped to 0, 99 is mapped to 1
			$pngQ = (int) ceil((100 - $q) / 11.13);
		}
		$this->setPngQuality($pngQ);
		$this->setImageQuality($quality);
		return $callchain ? $callchain->dySetQuality($quality) : $quality;
	}

	/**
	 * The TIFF compression of published TIFF images, a {@see \Prado\IO\Image\TTIFF}
	 * compression: None, CcittRle, Group3, Group4, Lzw (default), or PackBits.
	 * @return int the TIFF compression, default LZW.
	 */
	public function getTiffCompression(): int
	{
		return $this->_tiffCompression;
	}

	/**
	 * @param int|string $value the TIFF compression, a TTIFF compression constant or its
	 *   name ("Lzw", "PackBits", "None", "CcittRle", "Group3", "Group4").
	 * @throws TInvalidDataValueException when the value is not a TIFF compression.
	 */
	public function setTiffCompression($value): void
	{
		if (!is_numeric($value)) {
			$name = strtolower(trim(TPropertyValue::ensureString($value)));
			if (!array_key_exists($name, self::TIFF_COMPRESSIONS)) {
				throw new TInvalidDataValueException('assetimagefilter_bad_tiff_compression', $value);
			}
			$value = self::TIFF_COMPRESSIONS[$name];
		}
		if (!in_array($value = TPropertyValue::ensureInteger($value), self::TIFF_COMPRESSIONS, true)) {
			throw new TInvalidDataValueException('assetimagefilter_bad_tiff_compression', $value);
		}
		$this->_tiffCompression = $value;
	}

	/**
	 * Possible Modes are specified in {@see TAssetPNGColorMode}.
	 * Possible Values are:
	 * 		- "TrueColor" - Forces PNGs to save as true color.
	 *		- "Preserve" - Preserves format of the source.
	 *		- "Palette" - Forces PNGS to save with a restricted color
	 *		  palette for smaller size.
	 *
	 * @return string Specifies the Color Mode to save PNG files ("TrueColor"
	 *    vs "Palette"), default "Preserve".
	 */
	public function getPngColorMode(): string
	{
		return $this->_pngColorMode;
	}

	/**
	 * @param bool $value Specifies the Color Mode to save PNG files ("TrueColor"
	 *    vs "Palette") [or "Preserve"].
	 */
	public function setPngColorMode($value)
	{
		$this->_pngColorMode = TPropertyValue::ensureEnum($value, TAssetPNGColorMode::class);
	}

	/**
	 * @return bool Save the alpha channel when writing PNG, WebP, and GIF.
	 */
	public function getSaveAlpha(): bool
	{
		return $this->_saveAlpha;
	}

	/**
	 * @param bool $value save the alpha channel when writing PNG, WebP, and GIF
	 */
	public function setSaveAlpha($value)
	{
		$this->_saveAlpha = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return ?bool Save as interlace when writing JPEG or PNG.
	 */
	public function getSaveInterlace(): ?bool
	{
		return $this->_saveInterlace;
	}

	/**
	 * @param ?bool $value save as interlaced when writing JPEG or PNG
	 */
	public function setSaveInterlace($value)
	{
		if (!is_bool($value) && ($value === null || $value === '')) {
			$this->_saveInterlace = null;
		} else {
			$this->_saveInterlace = TPropertyValue::ensureBoolean($value);
		}
	}

	/**
	 * @return string The background color of transparency when
	 *   removing alpha, default "#000000".
	 */
	public function getBackgroundColor(): string
	{
		return $this->_backgroundColor;
	}

	/**
	 * @param mixed $image
	 * @return int The background color of transparency when
	 *   removing alpha, default "#000000".
	 */
	protected function getBackgroundColorIndex($image): int
	{
		$bc = $this->getBackgroundColor();
		return imagecolorallocate($image, hexdec(substr($bc, 1, 2)), hexdec(substr($bc, 3, 2)), hexdec(substr($bc, 5, 2)));
	}

	/**
	 * @param string $value The background color of transparency when
	 *   removing alpha.
	 */
	public function setBackgroundColor($value)
	{
		$this->_backgroundColor = TPropertyValue::ensureHexColor($value);
	}

	/**
	 * @return bool Should the True color formats (JPEG & WebP) have
	 *    the color palette be applied, default false.
	 */
	public function getPalettizeTrueColor(): bool
	{
		return $this->_palettizeTrueColor;
	}

	/**
	 * @param bool $value Should the True color formats (JPEG & WebP) have
	 *    the color palette be applied.
	 */
	public function setPalettizeTrueColor($value)
	{
		$this->_palettizeTrueColor = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * This is the number of Palette Colors the image is saved with.
	 * GIF, BMP are automatically converted to 256 palette colors (
	 * or however many is specified here) on being saved.
	 *
	 * When this is 0, the default, the image is saved as true color
	 * (except for GIF and BMP).  JPEG is always saved in true color.
	 *
	 * PNG and WebP can be saved as True Color or Palette Image.  When
	 * the number of PaletteColors is specified for these types, only
	 * then will the image be saved as a Palette Image.  Leaving this
	 * value at the default 0, it will save PNG and WebP as true color
	 * images.
	 *
	 * @return int The number of palette colors for the saved image.
	 *   Default 0, meaning saved as true color, without a palette.
	 */
	public function getPaletteColors(): int
	{
		return $this->getToBlackAndWhite() ? 2 : $this->_paletteColors;
	}

	/**
	 *
	 *
	 * @param int $value The number of palette colors for the saved image.
	 */
	public function setPaletteColors($value)
	{
		$this->_paletteColors = min(256, max(0, TPropertyValue::ensureInteger($value)));
		if ($this->_paletteColors === 1) {
			$this->_paletteColors = 0;
		}
	}

	/**
	 * @return bool Dither the colors when converting into a Palette
	 *   on saving, default true.
	 */
	public function getPaletteDither(): bool
	{
		return $this->_paletteDither;
	}

	/**
	 * @param bool $value Dither the colors when converting into a Palette
	 *   on saving.
	 */
	public function setPaletteDither($value)
	{
		$this->_paletteDither = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int|string The ARGB color of the transparent color
	 *    in palette color Mode, default "#7F000000".
	 */
	public function getPaletteAlphaColor()
	{
		return $this->_paletteAlphaColor;
	}

	/**
	 * @param null|object $image
	 * @return int|string The color of the transparent palette index.
	 */
	protected function getPaletteAlphaColorIndex($image = null)
	{
		$ac = $this->getPaletteAlphaColor();
		if (is_numeric($ac)) {
			return (int) $ac;
		} elseif (strtolower($ac) == 'sides') {
			return $ac;
		}
		$a = hexdec(substr($ac, 1, 2));
		$r = hexdec(substr($ac, 3, 2));
		$g = hexdec(substr($ac, 5, 2));
		$b = hexdec(substr($ac, 7, 2));
		if (!$image) {
			return ($a << 24) | ($r << 16) | ($g << 8) | $b;
		}
		return imageColorAllocateAlpha($image, $r, $g, $b, $a);
	}

	/**
	 * For PNG Palette to save a color as a transparent Color Index, the Alpha
	 * must be 0x7F (127 in decimal).  If the alpha is not the maximum (0x7F), then
	 * GD imagepng() will save the imageColorTransparent as a normal palette color
	 * with set alpha.
	 *
	 * @param string $value The "#AARRGGBB" hexidecimal color of the transparent
	 *    color in Palette Color Mode, or a GD Color, or "Sides".
	 */
	public function setPaletteAlphaColor($value)
	{
		if (is_numeric($value) || (is_string($value) && strtolower($value) == 'sides')) {
			$this->_paletteAlphaColor = $value;
			return;
		}
		$value = TPropertyValue::ensureString($value);
		if (preg_match('/^#[0-7][0-9a-fA-F]{7}$/', $value)) {
			$this->_paletteAlphaColor = $value;
		} else {
			$value = TPropertyValue::ensureHexColor($value);
			$this->_paletteAlphaColor = '#00' . substr($value, 1);
		}
	}

	/**
	 * @return int Dither the colors when converting into a Palette
	 *   on saving, default true.
	 */
	public function getPaletteAlphaThreshold()
	{
		return $this->_paletteAlphaThreshold;
	}

	/**
	 * When saving the alpha in palette mode, pixels of
	 * this alpha or more are made into the transparent color.
	 * @param int $value The palette alpha threshold to make
	 *   the pixel the transparent color.
	 */
	public function setPaletteAlphaThreshold($value)
	{
		$this->_paletteAlphaThreshold = min(127, max(-1, TPropertyValue::ensureInteger($value)));
	}

	/**
	 * @return bool Is the color transparent regardless of
	 *    the alpha in {@see getPaletteAlphaColor PaletteAlphaColor}.
	 *    Default: false.
	 */
	public function getPaletteColorIsTransparent(): bool
	{
		return $this->_paletteColorIsTransparent;
	}

	/**
	 * @param bool $value Is the color transparent regardless of
	 *    the alpha in {@see getPaletteAlphaColor PaletteAlphaColor}.
	 */
	public function setPaletteColorIsTransparent($value)
	{
		$this->_paletteColorIsTransparent = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Dither the colors when converting into a Palette
	 *   on saving, default true.
	 */
	public function getToBlackAndWhite(): bool
	{
		return $this->_toBlackAndWhite;
	}

	/**
	 * @param bool $value Dither the colors when converting into a Palette
	 *   on saving.
	 */
	public function setToBlackAndWhite($value)
	{
		$this->_toBlackAndWhite = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Process a True Color image such that the image's Transparent Color
	 * is retained by replacing with $transparentColor, Alpha values over
	 * $threshold are also made the $transparentColor, and $matchColor
	 * specifies whether to match the color without regard for alpha value.
	 *
	 * Normal imageColorTransparent is to set the transparent color.  This
	 * method can retain transparency when changing the Transparent Color.
	 * This also adds setting pixels above an alpha threshold to be transparent.
	 * Lastly, this allows colors to match and be made transparent when alpha
	 * values do not match.
	 *
	 * @param object $image The image to process the Alpha Channel
	 * @param int|string $transparentColor The transparent color or "Sides" to get the Alpha
	 *   from the corners of the image
	 * @param int $backgroundColor The color to fade alpha values with.
	 * @param int $threshold pixels with alpha values of this or greater get
	 *   the Transparent Color, default -1 for no threshold.
	 * @param bool $matchColor Should $alphaColor without Alpha be matched, default
	 *   false.
	 * @return bool Was the function successful.
	 */
	public static function imagePalettizeAlpha($image, $transparentColor, int $backgroundColor = 0, int $threshold = -1, bool $matchColor = false): bool
	{
		if (!$image || !imageIsTrueColor($image)) {
			return false;
		}
		if (is_numeric($transparentColor) && $transparentColor < 0) {
			self::imageRemoveAlpha($image, $backgroundColor);
			return true;
		}
		$threshold = min(127, max(-1, $threshold));
		$priorTransparent = imageColorTransparent($image);
		$sx = imageSx($image);
		$sy = imageSy($image);
		$br = ($backgroundColor >> 16) & 0xFF;
		$bg = ($backgroundColor >> 8) & 0xFF;
		$bb = $backgroundColor & 0xFF;

		if (is_string($transparentColor) && strtolower($transparentColor) === 'sides') {
			$c1 = imagecolorat($image, 0, 0);
			$c2 = imagecolorat($image, $sx - 1, 0);
			$c3 = imagecolorat($image, 0, $sy - 1);
			$c4 = imagecolorat($image, $sx - 1, $sy - 1);
			$transparentColor =
				(intdiv((($c1 >> 24) & 0x7F) + (($c2 >> 24) & 0x7F) + (($c3 >> 24) & 0x7F) + (($c4 >> 24) & 0x7F), 4) << 24) |
				(intdiv((($c1 >> 16) & 0xFF) + (($c2 >> 16) & 0xFF) + (($c3 >> 16) & 0xFF) + (($c4 >> 16) & 0xFF), 4) << 16) |
				(intdiv((($c1 >> 8) & 0xFF) + (($c2 >> 8) & 0xFF) + (($c3 >> 8) & 0xFF) + (($c4 >> 8) & 0xFF), 4) << 8) |
				intdiv(($c1 & 0xFF) + ($c2 & 0xFF) + ($c3 & 0xFF) + ($c4 & 0xFF), 4);
		}

		if ($threshold >= 0 || $matchColor || ($priorTransparent != -1 && $priorTransparent != $transparentColor)) {
			$clearColor = $transparentColor & 0xFFFFFF;

			if ($threshold >= 0 && $matchColor) {
				for ($y = 0; $y < $sy; $y++) {
					for ($x = 0; $x < $sx; $x++) {
						$c = imageColorAt($image, $x, $y);
						if ($c === $transparentColor) {
							continue;
						}
						$a = (($c >> 24) & 0x7F);
						if ($c === $priorTransparent || $a >= $threshold || ($c & 0xFFFFFF) === $clearColor) {
							imageSetPixel($image, $x, $y, $transparentColor);
						} elseif ($a && $backgroundColor) {
							$r = ($c >> 16) & 0xFF;
							$g = ($c >> 8) & 0xFF;
							$b = $c & 0xFF;
							$omn = 127 - $a;
							$nc = (intdiv($r * $omn + $br * $a, 127) << 16) |
									(intdiv($g * $omn + $bg * $a, 127) << 8) |
									 intdiv($b * $omn + $bb * $a, 127);
							imageSetPixel($image, $x, $y, $nc);
						} elseif ($a) {
							imageSetPixel($image, $x, $y, $c & 0x00FFFFFF);
						}
					}
				}
			} elseif ($threshold >= 0) { // not $matchColor
				for ($y = 0; $y < $sy; $y++) {
					for ($x = 0; $x < $sx; $x++) {
						$c = imageColorAt($image, $x, $y);
						if ($c === $transparentColor) {
							continue;
						}
						$a = (($c >> 24) & 0x7F);
						if ($c === $priorTransparent || $a >= $threshold) {
							imageSetPixel($image, $x, $y, $transparentColor);
						} elseif ($a && $backgroundColor) {
							$r = ($c >> 16) & 0xFF;
							$g = ($c >> 8) & 0xFF;
							$b = $c & 0xFF;
							$omn = 127 - $a;
							$nc = (intdiv($r * $omn + $br * $a, 127) << 16) |
									(intdiv($g * $omn + $bg * $a, 127) << 8) |
									 intdiv($b * $omn + $bb * $a, 127);
							imageSetPixel($image, $x, $y, $nc);
						} elseif ($a) {
							imageSetPixel($image, $x, $y, $c & 0x00FFFFFF);
						}
					}
				}
			} else { // $matchColor || $priorTransparent
				for ($y = 0; $y < $sy; $y++) {
					for ($x = 0; $x < $sx; $x++) {
						$c = imageColorAt($image, $x, $y);
						if ($c === $transparentColor) {
							continue;
						}
						$a = (($c >> 24) & 0x7F);
						if ($c === $priorTransparent || ($matchColor && ($c & 0xFFFFFF) === $clearColor)) {
							imageSetPixel($image, $x, $y, $transparentColor);
						} elseif ($a && $backgroundColor) {
							$r = ($c >> 16) & 0xFF;
							$g = ($c >> 8) & 0xFF;
							$b = $c & 0xFF;
							$omn = 127 - $a;
							$nc = (intdiv($r * $omn + $br * $a, 127) << 16) |
									(intdiv($g * $omn + $bg * $a, 127) << 8) |
									 intdiv($b * $omn + $bb * $a, 127);
							imageSetPixel($image, $x, $y, $nc);
						} elseif ($a) {
							imageSetPixel($image, $x, $y, $c & 0x00FFFFFF);
						}
					}
				}
			}
		} else {
			for ($y = 0; $y < $sy; $y++) {
				for ($x = 0; $x < $sx; $x++) {
					$c = imageColorAt($image, $x, $y);
					if ($c === $transparentColor) {
						continue;
					}
					$a = (($c >> 24) & 0x7F);
					if ($c === $priorTransparent) {
						imageSetPixel($image, $x, $y, $transparentColor);
					} elseif ($a && $backgroundColor) {
						$r = ($c >> 16) & 0xFF;
						$g = ($c >> 8) & 0xFF;
						$b = $c & 0xFF;
						$omn = 127 - $a;
						$nc = (intdiv($r * $omn + $br * $a, 127) << 16) |
								(intdiv($g * $omn + $bg * $a, 127) << 8) |
								 intdiv($b * $omn + $bb * $a, 127);
						imageSetPixel($image, $x, $y, $nc);
					} elseif ($a) {
						imageSetPixel($image, $x, $y, $c & 0x00FFFFFF);
					}
				}
			}
		}
		imageColorTransparent($image, $transparentColor);
		return true;
	}

	/**
	 * This calculates the brightness of a color in the HSP color model.
	 *
	 * @param array|int $color The color to compute the HSP Brightness.
	 * @param bool $squared Return the squared value, to reduce computations.
	 * @return float The HSP Model Brightness.
	 */
	public static function imageColorBrightness($color, bool $squared = false): float
	{
		if (is_array($color) && $squared) {
			$a = (127 - $color['alpha']) / 127;
			$r = $color['red'];
			$g = $color['green'];
			$b = $color['blue'];
			return $a * (0.299 * $r * $r + 0.587 * $g * $g + 0.114 * $b * $b); //  [0..66535]
		} elseif (is_array($color)) {
			$a = (127 - $color['alpha']) / 127;
			$r = $color['red'];
			$g = $color['green'];
			$b = $color['blue'];
			return sqrt($a * (0.299 * $r * $r + 0.587 * $g * $g + 0.114 * $b * $b)); // [0..255]
		} elseif ($squared) {
			$a = (127 - (($color >> 24) & 0x7F)) / 127;
			$r = (($color >> 16) & 0xFF);
			$g = (($color >> 8) & 0xFF);
			$b = $color & 0xFF;
			return $a * (0.299 * $r * $r + 0.587 * $g * $g + 0.114 * $b * $b);
		} else { //not $squared
			$a = (127 - (($color >> 24) & 0x7F)) / 127;
			$r = (($color >> 16) & 0xFF);
			$g = (($color >> 8) & 0xFF);
			$b = $color & 0xFF;
			return sqrt($a * (0.299 * $r * $r + 0.587 * $g * $g + 0.114 * $b * $b));
		}
	}

	/**
	 * This takes an $image and makes converts it to the best
	 * palette image it can based upon the number oc $colors
	 * in the palette, $dither, and $blackNwhite.
	 *
	 * Palette Colors average the alpha channel along with the colors
	 * of the image into the palette and does not create different colors
	 * based upon alpha (only the color [without alpha] is used).  The saving of the image may or
	 * may not respect the alpha channel.
	 *
	 * When an imageColorTransparent is set, an additional color is added
	 * and results in the number of palette colors to max(256, $colors+1).
	 *
	 * @param object $image
	 * @param ?int $colors How many colors in the palette, default 256
	 *    Minimum 2.  When $colors = [0, 1], then $colors = 256.
	 * @param bool $dither Dither when converting to palette, default true.
	 * @param bool $blackNwhite Make the image Black and White, default false.
	 * @return bool Successful, or not.
	 */
	public static function imagePalettize($image, ?int $colors = 256, bool $dither = true, bool $blackNwhite = false): bool
	{
		if (!$image) {
			return false;
		}
		$colors = min(256, max(0, $colors));
		$colors = ($colors >= 2) ? $colors : 256;
		$colors = $blackNwhite ? 2 : $colors;
		if (!imageIsTrueColor($image)) {
			if ($blackNwhite) {
				if (($transparent = imageColorTransparent($image)) >= 0) {
					$c = imageColorsForIndex($image, $transparent);
					imageColorSet($image, $transparent, $c['red'], $c['green'], $c['blue'], 0);
				}
				imageColorTransparent($image, -1);
				if (imageColorsTotal($image) === 2) {
					$c0 = imageColorsForIndex($image, 0);
					$c1 = imageColorsForIndex($image, 1);
					if (self::imageColorBrightness($c0, true) < self::imageColorBrightness($c1, true)) {
						imageColorSet($image, 0, 0, 0, 0);
						imageColorSet($image, 1, 255, 255, 255);
					} else {
						imageColorSet($image, 0, 255, 255, 255);
						imageColorSet($image, 1, 0, 0, 0);
					}
					return true;
				} elseif (imageColorsTotal($image) === 1) {
					$c0 = imageColorsForIndex($image, 0);
					if (self::imageColorBrightness($c0) < 128) {
						imageColorSet($image, 0, 0, 0, 0);
					} else {
						imageColorSet($image, 0, 255, 255, 255);
					}
					return true;
				} else {
					imagePaletteToTrueColor($image);
				}
			} elseif ($colors < imageColorsTotal($image)) {
				imagePaletteToTrueColor($image);
			} else {
				return false;
			}
		}
		if ($blackNwhite) {
			imageFilter($image, IMG_FILTER_GRAYSCALE);
		}
		$colorImage = imageCreateTrueColor($sx = imagesx($image), $sy = imagesy($image));
		imageAlphaBlending($colorImage, false);
		imageCopy($colorImage, $image, 0, 0, 0, 0, $sx, $sy);
		$t = null;
		if (($transparentIndex = imageColorTransparent($image)) >= 0) {
			$t = imagecolorsforindex($image, $transparentIndex);
		}
		imageTrueColorToPalette($image, $dither, $colors);
		imageColorMatch($colorImage, $image);
		if ($t !== null && ($index = imageColorTransparent($image)) >= 0) {
			imagecolorset($image, $index, $t['red'], $t['green'], $t['blue'], $t['alpha']);
		}
		if ($blackNwhite) {
			if (imagecolorstotal($image) == 2) {//force the image into black and white.
				$c0 = imageColorsForIndex($image, 0);
				$c1 = imageColorsForIndex($image, 1);
				if (self::imageColorBrightness($c0, true) < self::imageColorBrightness($c1, true)) {
					imageColorSet($image, 0, 0, 0, 0);
					imageColorSet($image, 1, 255, 255, 255);
				} else {
					imageColorSet($image, 0, 255, 255, 255);
					imageColorSet($image, 1, 0, 0, 0);
				}
			} else { // one color
				$c0 = imageColorsForIndex($image, 0);
				if (self::imageColorBrightness($c0) < 128) {
					imageColorSet($image, 0, 0, 0, 0);
				} else {
					imageColorSet($image, 0, 255, 255, 255);
				}
			}
		}
		return true;
	}

	/**
	 * Removes the alpha channel from an image with a specified
	 * Background color.
	 *
	 * @param object $image GD image to remove alpha from.
	 * @param null|int $backgroundColor the Color Indexd of transparency
	 * @return bool Successful, or not.
	 */
	public static function imageRemoveAlpha($image, $backgroundColor = null): bool
	{
		if (!$image) {
			return false;
		}
		if (!imageIsTrueColor($image)) {
			$total = imageColorsTotal($image);
			if ($backgroundColor !== null) {
				$backgroundColor = imagecolorsforindex($image, $backgroundColor);
				$br = $backgroundColor['red'];
				$bg = $backgroundColor['green'];
				$bb = $backgroundColor['blue'];
			} else {
				$br = $bg = $bb = 0;
			}
			$transparent = imageColorTransparent($image);
			for ($i = 0; $i < $total; $i++) {
				if ($i === $transparent) {
					continue;
				}
				$color = imagecolorsforindex($image, $i);
				if ($color['alpha'] > 0) {
					$a = (127 - $color['alpha']) / 127;
					imagecolorset($image, $i, (int) round($color['red'] * $a + $br * (1 - $a)), (int) round($color['green'] * $a + $bg * (1 - $a)), (int) round($color['blue'] * $a + $bb * (1 - $a)));
				}
			}
			imageColorTransparent($image, -1);
			return true;
		}
		$colorImage = imageCreateTrueColor($sx = imagesx($image), $sy = imagesy($image));
		$backgroundColor = ($backgroundColor !== null) ? $backgroundColor : 0;
		imageFilledRectangle($colorImage, 0, 0, $sx, $sy, $backgroundColor);
		imageAlphaBlending($colorImage, true);
		imageCopy($colorImage, $image, 0, 0, 0, 0, $sx, $sy);
		imageCopy($image, $colorImage, 0, 0, 0, 0, $sx, $sy);
		imageColorTransparent($image, -1);
		return true;
	}

	/**
	 * This saves the true color image back to the specified
	 * file image format and palette colors.  An Imagick image is converted to GD for the
	 * GD encoders and the saving properties, as {@see encodeImage} does.
	 *
	 * @param \GdImage|\Imagick|object $image the image so save to file.
	 * @param int $type the original GD type of the image
	 * @param ?int $paletteColors number of colors in the palette, 0 = true color
	 * @param string $filePath the path to save the image to
	 * @return ?bool Was the image saved.
	 */
	public function saveImage($image, int $type, ?int $paletteColors, string $filePath): ?bool
	{
		if (static::hasGdEncoder($type)) {
			if (!($image instanceof \GdImage) && ($gd = static::convertImage($image, TImageGraphicsMode::GD)) !== false) {
				$image = $gd; // The saving properties and the GD encoders need a GD image.
			}
			return $this->writeImage($image, $type, $paletteColors, $filePath);
		}
		// A type GD cannot write, such as TIFF, is encoded by prado-image.
		$bytes = $this->encodeImage($image, $type, $paletteColors);
		return $bytes === false ? false : @file_put_contents($filePath, $bytes) !== false;
	}

	/**
	 * @param int $type the GD image type.
	 * @return bool whether GD writes the type, rather than a prado-image container.
	 */
	protected static function hasGdEncoder(int $type): bool
	{
		return in_array($type, [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP, IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_WEBP], true);
	}

	/**
	 * Encodes the image as {@see saveImage} writes it, returning the bytes, so the
	 * metadata can be written into them before the file is created.
	 * @param object $image the image to encode.
	 * @param int $type the GD image type to encode the image as.
	 * @param ?int $paletteColors the number of palette colors, 0 for true color.
	 * @return false|string the encoded image, or false when the type cannot be encoded.
	 */
	public function encodeImage($image, int $type, ?int $paletteColors): false|string
	{
		if (!($image instanceof \GdImage) && ($gd = static::convertImage($image, TImageGraphicsMode::GD)) !== false) {
			$image = $gd; // The saving properties and the GD encoders need a GD image.
		}
		if ($image instanceof \GdImage) {
			$this->prepareImage($image, $type, $paletteColors);
			if ($type === IMAGETYPE_TIFF_II || $type === IMAGETYPE_TIFF_MM) {
				return TTIFF::fromImage($image, $this->getTiffCompression())->toBinary();
			}
			ob_start();
			$written = $this->writeImage($image, $type, $paletteColors, null, false);
			$bytes = (string) ob_get_clean();
			return $written ? $bytes : false;
		}
		return $this->encodeWithGraphics($image, $type);
	}

	/**
	 * Encodes an image of a graphics library other than GD, without the GD saving
	 * properties: the palette conversion, the alpha channel, and the interlacing are GD
	 * work. JPEG, PNG, and WebP are encoded by the library ({@see TImageGraphics::encode}),
	 * GIF and TIFF by their prado-image containers, and the GD-only formats (BMP, WBMP,
	 * XBM) cannot be written without GD.
	 * @param object $image the image to encode.
	 * @param int $type the GD image type to encode the image as.
	 * @return false|string the encoded image, or false when the type cannot be encoded.
	 */
	protected function encodeWithGraphics($image, int $type): false|string
	{
		switch ($type) {
			case IMAGETYPE_JPEG:
				return TImageGraphics::encode($image, IImageGraphicsLibrary::FormatJpeg, $this->getImageQuality());
			case IMAGETYPE_WEBP:
				return TImageGraphics::encode($image, IImageGraphicsLibrary::FormatWebP, $this->getImageQuality());
			case IMAGETYPE_PNG:
				// The library takes a quality, where PNG has a compression level [0..9].
				$png = $this->getPngQuality();
				return TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng, $png < 0 ? 75 : (int) round(100 - min(9, $png) * 11.2));
			case IMAGETYPE_GIF:
				return TGIF::fromImage($image)->toBinary();
			case IMAGETYPE_TIFF_II:
			case IMAGETYPE_TIFF_MM:
				return TTIFF::fromImage($image, $this->getTiffCompression())->toBinary();
		}
		return false;
	}

	/**
	 * Whether the imager can encode an image of a type on this installation, which the
	 * format conversions ask before they rename a file: a file renamed to a format that
	 * cannot be written would publish its original bytes under the new name.
	 * @param int $type the GD image type.
	 * @return bool whether an image of the type can be encoded.
	 */
	public function canEncode(int $type): bool
	{
		if (!$this->hasGraphics()) {
			return false;
		}
		if (TImageGraphics::hasGd() && static::hasGdEncoder($type)) {
			return true;
		}
		return match ($type) {
			IMAGETYPE_JPEG => TImageGraphics::supports(IImageGraphicsLibrary::CapabilityJpeg),
			IMAGETYPE_PNG => TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPng),
			IMAGETYPE_WEBP => TImageGraphics::supports(IImageGraphicsLibrary::CapabilityWebP),
			IMAGETYPE_GIF, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM => true, // The prado-image containers.
			default => false,
		};
	}

	/**
	 * Prepares the image for encoding and writes it: the palette conversion, the alpha
	 * channel, and the interlacing of the saving properties, then the GD encoder of the
	 * type. A null $filePath writes the image to the output buffer.
	 * @param object $image the image to write.
	 * @param int $type the GD image type to write the image as.
	 * @param ?int $paletteColors the number of palette colors, 0 for true color.
	 * @param ?string $filePath the file to write, or null for the output buffer.
	 * @param bool $prepare whether the saving properties are applied, false when the
	 *   caller has already applied them.
	 * @return ?bool Was the image written.
	 */
	protected function writeImage($image, int $type, ?int $paletteColors, ?string $filePath, bool $prepare = true): ?bool
	{
		if ($prepare) {
			$paletteColors = $this->prepareImage($image, $type, $paletteColors);
		}
		switch ($type) {
			case IMAGETYPE_GIF: //palette
				return imageGif($image, $filePath);
			case IMAGETYPE_JPEG: //true color
				return imageJpeg($image, $filePath, $this->getImageQuality());
			case IMAGETYPE_PNG: //palette optional
				return imagePng($image, $filePath, $this->getPngQuality());
			case IMAGETYPE_BMP: //palette
				return imageBmp($image, $filePath, true);
			case IMAGETYPE_WBMP: // black and white
				return imageWbmp($image, $filePath);
			case IMAGETYPE_XBM:  // black and white
				return imageXbm($image, $filePath);
			case IMAGETYPE_WEBP: //true color
				return imageWebP($image, $filePath, $this->getImageQuality());
		}
		return false;
	}

	/**
	 * Applies the saving properties to the image before it is encoded: the palette
	 * conversion of {@see getPaletteColors PaletteColors} and the palette alpha
	 * properties, the alpha channel of {@see getSaveAlpha SaveAlpha}, and
	 * {@see getSaveInterlace SaveInterlace}.
	 * @param object $image the image to prepare.
	 * @param int $type the GD image type the image is encoded as.
	 * @param ?int $paletteColors the number of palette colors of the source, 0 for true color.
	 * @return int the number of palette colors the image was converted to, 0 for true color.
	 */
	protected function prepareImage($image, int $type, ?int $paletteColors): int
	{
		$paletteColors = ($paletteColors === null) ? 0 : $paletteColors;
		$pc = $this->getPaletteColors();
		$pngColorMode = $this->getPngColorMode();
		$trueColor = $type === IMAGETYPE_JPEG || $type === IMAGETYPE_WEBP || ($type === IMAGETYPE_PNG && $pngColorMode === TAssetPNGColorMode::TrueColor);
		$bNw = $this->getToBlackAndWhite() || $type === IMAGETYPE_WBMP || $type === IMAGETYPE_XBM;

		if (!$this->getPalettizeTrueColor() && $trueColor) {
			$pc = $paletteColors = 0;
		} elseif ((($type === IMAGETYPE_PNG && $pngColorMode === TAssetPNGColorMode::Palette) ||
			$type === IMAGETYPE_GIF || $type === IMAGETYPE_BMP) && !$paletteColors) {
			//ensure PNG-Palette, gif, bmp have palette colors when none is provided.
			$pc = ($pc >= 2) ? $pc : 256;
		} elseif ($bNw) {
			// wbmp and xbm are black and white
			$pc = 2;
		}
		$alphaRemoved = false;
		$paletteColors = ($pc >= 2) ? $pc : ($paletteColors >= 2 ? $paletteColors : 0);
		if ($paletteColors) {
			// Pixels are replaced, not blended, when marking transparency.
			imageAlphaBlending($image, false);
			$bNw = $this->getToBlackAndWhite() || $type === IMAGETYPE_WBMP || $type === IMAGETYPE_XBM;
			if (!$this->getSaveAlpha() || $bNw) {
				$alphaRemoved = true;
				self::imageRemoveAlpha($image, $c = $this->getBackgroundColorIndex($image));
				imageColorDeallocate($image, $c);
			} else {
				$alphaColor = $this->getPaletteAlphaColorIndex($image);
				if ($alphaColor === -1) {
					$alphaColor = imagecolortransparent($image);
				}
				$c = $this->getBackgroundColorIndex($image);
				self::imagePalettizeAlpha($image, $alphaColor, $c, $this->getPaletteAlphaThreshold(), $this->getPaletteColorIsTransparent());
				imageColorDeallocate($image, $c);
				$alphaRemoved = true;
			}
			self::imagePalettize($image, $paletteColors, $this->getPaletteDither(), $bNw);
			if ($trueColor) {
				self::imagePaletteToTrueColor($image);
			}
		}
		imageAlphaBlending($image, false);
		imageSaveAlpha($image, $saveAlpha = $this->getSaveAlpha());
		if (!$saveAlpha && !$alphaRemoved) {
			//WebP doesn't respect imageSaveAlpha(false), remove alpha.
			self::imageRemoveAlpha($image, $c = $this->getBackgroundColorIndex($image));
			imageColorDeallocate($image, $c);
		}
		if (($interlace = $this->getSaveInterlace()) !== null) {
			imageInterlace($image, $interlace);
		}
		return $paletteColors;
	}
}
