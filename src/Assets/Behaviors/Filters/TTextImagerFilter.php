<?php

/**
 * TTextImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TApplicationMode;
use Prado\TPropertyValue;
use Prado\Web\Assets\TGDFAsset;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TTextImagerFilter class
 *
 * This renders a text watermark on the image.
 *
 * When {@see getText Text} is specified, the text is rendered with
 * imagestring() unless a TrueType font is specified with {@see setTTFont TTFont},
 * in which case, the more advanced imagefttext() is used.  The latter
 * provides font size, angles, linespacing, and text alignment in X and Y
 * functionality.
 *
 * The {@see setGDFont GDFont} can be a GD Font numeric or a file
 * path to your GDFont ".gdf" file.  The GD string render lacks font
 * size, render angle, line spacing, and alignment. {@see getFontNamespace
 * FontNamespace} can be used to specify the directory of your .gdf font file,
 * in which case, you can provide just the GDFont name.
 *
 * {@see getTextX TextX} and {@see getTextY TextY} are used to place
 * the text.  These can be pixels from the left top. When negative numbers
 * are specified, the pixels are subtracted from the size of the dimension.
 * Also a percentage can be specified.  Negative percentages start at the
 * maximum dimension.
 *
 * {@see getTTAlignX TTAlignX} and {@see getTTAlignY TTAlignY} only work
 * when a True Type font is specified.  The X alignment values can be:
 * "Left", "Center", "Right", and "" (blank); and the Y values can be: "Top",
 * "Center", "Bottom", and "" (blank).  When blank, the alignment is
 * automatic by the sign of TextX and TextY: positive values are Left and
 * Top aligned, and negative values are Right and Bottom aligned.
 *
 * This filter is written in GD, whose bitmap fonts (`.gdf`) Imagick cannot read. The imager
 * converts an image of another graphics library into GD for it, and skips the filter where GD
 * is not installed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see http://www.danceswithferrets.org/lab/gdfs/
 */
class TTextImagerFilter extends TBaseImagerFilter
{
	/** @var string the text of the water mark to render. */
	private string $_text = '';

	/** @var numeric|string the X location to render the text. Negative numbers subtract from the right side of the image */
	private $_textX = '80%';

	/** @var numeric|string the Y location to render the text. Negative numbers subtract from the bottom of the image */
	private $_textY = '-10';

	/** @var string The Web Hex Color of the text to render */
	private string $_textColor = '#00000000';

	/** @var bool Vertically render the GD String. Turns the text 90 CCW */
	private bool $_gdVertical = false;

	/** @var bool|string Verify the GD Font file machine byte order. ["Auto", "Convert", true, false] */
	private $_verifyGDFont = "Auto";

	/** @var int|string the GD Font index or font file path */
	private $_gdFont = 1;

	/** @var ?string the namespace directory of the Font for both GD and TrueType, when empty the GDFont or TTFont could be full paths. */
	private $_fontNamespace;

	/** @var ?string The TrueType font name, added to the FontNamespace when specified.  If no FontNamespace is available, this could be a file path */
	private $_ttFont;

	/** @var float The TrueType font size */
	private float $_ttFontSize = 12.0;

	/** @var float The TrueType font angle */
	private float $_ttAngle = 0.0;

	/** @var string The TrueType font X alignment, default 'Left'.  Possible values: Right, Center, Left */
	private string $_ttAlignX = 'Left';

	/** @var string The TrueType font Y alignment, default 'Bottom'.  Possible values: Top, Center, Bottom */
	private string $_ttAlignY = 'Bottom';

	/** @var ?float The TrueType font Line Spacing */
	private $_ttLineSpacing;


	/**
	 * @return string the text to render.
	 */
	public function getText()
	{
		return $this->_text;
	}

	/**
	 * @param string $value the text to render.
	 */
	public function setText($value)
	{
		$this->_text = TPropertyValue::ensureString($value);
	}

	/**
	 * This is the bottom left corner/baseline of the text from the top left corner
	 * of the image.  When {@see getGDVertical GDVertical} is true,
	 * the text is turned 90 degrees Counter-Clockwise.
	 *
	 * @return numeric|string The X placement of the text, default '80%'.
	 */
	public function getTextX()
	{
		return $this->_textX;
	}

	/**
	 * This is the bottom left corner/baseline of the text from the top left corner
	 * of the image.  When {@see getGDVertical GDVertical} is true,
	 * the text is turned 90 degrees Counter-Clockwise.
	 *
	 * @param numeric|string $value The X placement of the text.
	 */
	public function setTextX($value)
	{
		$this->_textX = TPropertyValue::ensureString($value);
	}

	/**
	 * This is the bottom left corner/baseline of the text from the top left corner
	 * of the image.  When {@see getGDVertical GDVertical} is true,
	 * the text is turned 90 degrees Counter-Clockwise.
	 *
	 * @return numeric|string The Y placement of the text, default '-10'.
	 */
	public function getTextY()
	{
		return $this->_textY;
	}

	/**
	 * This is the bottom left corner/baseline of the text from the top left corner
	 * of the image.  When {@see getGDVertical GDVertical} is true,
	 * the text is turned 90 degrees Counter-Clockwise.
	 *
	 * @param numeric|string $value The Y placement of the text.
	 */
	public function setTextY($value)
	{
		$this->_textY = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string The Color of the text in "#AARRGGBB" format or a numeric
	 *   GD true color, default '#00000000' (opaque black).
	 */
	public function getTextColor()
	{
		return $this->_textColor;
	}

	/**
	 * @param null|object $image The image to allocate the color in, or null
	 *   to compute the GD true color value without an image.
	 * @return false|int The GD color of the {@see getTextColor TextColor}.
	 */
	protected function getTextColorIndex($image = null)
	{
		$c = $this->getTextColor();
		if (is_numeric($c)) {
			return (int) $c;
		}
		$a = hexdec(substr($c, 1, 2));
		$r = hexdec(substr($c, 3, 2));
		$g = hexdec(substr($c, 5, 2));
		$b = hexdec(substr($c, 7, 2));
		if (!$image) {
			return ($a << 24) | ($r << 16) | ($g << 8) | $b;
		}
		return imageColorAllocateAlpha($image, $r, $g, $b, $a);
	}

	/**
	 * The value can be a numeric GD true color, "#AARRGGBB" where AA is the
	 * GD alpha [00..7F], or any Web Color accepted by
	 * {@see TPropertyValue::ensureHexColor} (eg. '#80FFA8', "#RGB", or "Red")
	 * which is made opaque.
	 * @param numeric|string $value The Color of the text.
	 */
	public function setTextColor($value)
	{
		if ((is_numeric($value) && $value >= 0)) {
			$this->_textColor = $value;
			return;
		}
		$value = TPropertyValue::ensureString($value);
		if (preg_match('/^#[0-7][0-9a-fA-F]{7}$/', $value)) {
			$this->_textColor = $value;
		} else {
			$value = TPropertyValue::ensureHexColor($value);
			$this->_textColor = '#00' . substr($value, 1);
		}
	}

	/**
	 * @return bool Vertically render the text. 90 CCW. Default false.
	 */
	public function getGDVertical()
	{
		return $this->_gdVertical;
	}

	/**
	 * @param bool $value Vertically render the text.
	 */
	public function setGDVertical($value)
	{
		$this->_gdVertical = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * This can be True, False, "Auto", or "Convert".  "Auto" verifies the GDFont endian
	 * is correct for the current machine when in Debug Mode.  "Convert" republishes the
	 * GDFont in the proper machine endian byte format.
	 *
	 * @return bool|string How to verify the GDFont byte order. Default 'Auto'.
	 */
	public function getVerifyGDFont()
	{
		return $this->_verifyGDFont;
	}

	/**
	 * @param bool|string $value How to verify the GDFont byte order: true, false,
	 *   "Auto", or "Convert".
	 */
	public function setVerifyGDFont($value)
	{
		if (is_string($value) && in_array(strtolower($value), ['auto', 'convert'])) {
			$this->_verifyGDFont = $value;
		} else {
			$this->_verifyGDFont = TPropertyValue::ensureBoolean($value);
		}
	}

	/**
	 * @return int|string The GDFont integer index of the
	 * GD built in fonts or a path (optionally relative to {@see
	 * getFontNamespace}) to the ".gdf" file, default 1.
	 */
	public function getGDFont()
	{
		return $this->_gdFont;
	}

	/**
	 * This can be an integer for built in fonts, with larger
	 * numbers being larger font sizes.  It can also be a path
	 * to the ".gdf", or the ".gdf" File name in the {@see
	 * getFontNamespace FontNamespace}.
	 * The GDF must be in the same endian byte format as the
	 * currently running machine.  A ".gdf" file in the {@see
	 * getFontNamespace FontNamespace} is verified for proper
	 * endianness when {@see getVerifyGDFont VerifyGDFont} is true
	 * or "Convert", or is "Auto", the default, and
	 * {@see \Prado\TApplication::getMode} is "Debug".
	 *
	 * @param int|string $value The GDFont integer index of the
	 * GD built in fonts or a path (optionally relative to {@see
	 * getFontNamespace}) to the ".gdf" file.
	 */
	public function setGDFont($value)
	{
		if (is_numeric($value)) {
			$this->_gdFont = TPropertyValue::ensureInteger($value);
			return;
		}
		$this->_gdFont = TPropertyValue::ensureString($value);
	}


	/**
	 * @return ?string The Prado Namespace of the optional
	 * Font path for GD (without a TrueType Font) or the optional
	 * font path for FreeType2 when the {@see getTTFont TrueType Font}
	 * is specified, default null.
	 */
	public function getFontNamespace()
	{
		return $this->_fontNamespace;
	}

	/**
	 * @param string $value The Prado Namespace of the optional
	 * Font path for GD imagestring and FreeType2 imagefttext.
	 */
	public function setFontNamespace($value)
	{
		$this->_fontNamespace = TPropertyValue::ensureNullIfEmpty(TPropertyValue::ensureString($value));
	}

	/**
	 * Specifying the TTFont will render text in the more advanced
	 * FreeType2 library with {@see getTTFontSize TTFontSize}, {@see getTTAngle
	 * TTAngle}, {@see getTTAlignX TTAlignX}, {@see getTTAlignY TTAlignY}, and
	 * {@see getTTLineSpacing TTLineSpacing}.
	 * When the {@see getFontNamespace FontNamespace} is used, this only
	 * needs to be the name of the font without the ".ttf" because the extension
	 * is automatically added.
	 *
	 * @return null|string The TrueType font name or a path
	 * (optionally relative to {@see getFontNamespace FontNamespace}) to the ".ttf" file.
	 * default, null.
	 */
	public function getTTFont()
	{
		return $this->_ttFont;
	}

	/**
	 * @param string $value The TrueType font name or a path.
	 */
	public function setTTFont($value)
	{
		$this->_ttFont = TPropertyValue::ensureNullIfEmpty(TPropertyValue::ensureString($value));
	}

	/**
	 * @return float The True Type font size of the text being rendered, default 12.0.
	 */
	public function getTTFontSize()
	{
		return $this->_ttFontSize;
	}

	/**
	 * @param float $value The True Type font size of the text being rendered.
	 */
	public function setTTFontSize($value)
	{
		$this->_ttFontSize = max(0, TPropertyValue::ensureFloat($value));
	}

	/**
	 * @return float The font angle of the text being rendered, default 0.
	 */
	public function getTTAngle()
	{
		return $this->_ttAngle;
	}

	/**
	 * @param float $value The font angle of the text being rendered.
	 */
	public function setTTAngle($value)
	{
		$this->_ttAngle = fmod(TPropertyValue::ensureFloat($value), 360.0);
	}

	/**
	 * Only functional when {@see getTTFont TTFont} is specified.
	 * Possible values: Left (default), Center, Right, (blank).  When blank
	 * the alignment depends on the direction of the {@see getTextX TextX} value.
	 * Positive TextX values are "Left" aligned and negative values
	 * are "Right" aligned, automatically.
	 * @return string Alignment on the X axis of the text relative to the
	 * {@see getTextX TextX} location.
	 */
	public function getTTAlignX()
	{
		return $this->_ttAlignX;
	}

	/**
	 * Only functional when {@see getTTFont TTFont} is specified.
	 * @param string $value Alignment on the X axis of the text relative
	 * to the {@see getTextX TextX} location: Left, Center, Right, or blank.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the alignment is not valid.
	 */
	public function setTTAlignX($value)
	{
		$value = trim(TPropertyValue::ensureString($value));
		TPropertyValue::ensureEnum(strtolower($value), ['', 'left', 'center', 'right']);
		$this->_ttAlignX = $value;
	}

	/**
	 * Only functional when {@see getTTFont TTFont} is specified.
	 * Possible values: Top, Center, Bottom (default), (blank).  When blank
	 * the alignment depends on the direction of the {@see getTextY TextY} value.
	 * Positive TextY values are "Top" aligned and negative values
	 * are "Bottom" aligned, automatically.  "Bottom" aligns the baseline of the text.
	 * @return string Alignment on the Y axis of the text relative to
	 * the {@see getTextY TextY} location.
	 */
	public function getTTAlignY()
	{
		return $this->_ttAlignY;
	}

	/**
	 * Only functional when {@see getTTFont TTFont} is specified.
	 *
	 * @param string $value Alignment on the Y axis of the text relative
	 * to the {@see getTextY TextY} location: Top, Center, Bottom, or blank.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the alignment is not valid.
	 */
	public function setTTAlignY($value)
	{
		$value = trim(TPropertyValue::ensureString($value));
		TPropertyValue::ensureEnum(strtolower($value), ['', 'top', 'center', 'bottom']);
		$this->_ttAlignY = $value;
	}

	/**
	 * @return ?float The line spacing of the text being rendered, default null for none.
	 */
	public function getTTLineSpacing()
	{
		return $this->_ttLineSpacing;
	}

	/**
	 * This is multiplied by the Font Size.  So 1.5 is 50% more than
	 * normal LineSpacing of 1.0.
	 *
	 * @param null|float|string $value The line spacing of the text being rendered.
	 */
	public function setTTLineSpacing($value)
	{
		if ($value === null || (is_string($value) && trim($value) === '')) {
			$this->_ttLineSpacing = null;
		} else {
			$this->_ttLineSpacing = TPropertyValue::ensureFloat($value);
		}
	}

	/**
	 * Renders the text watermark onto the image, with imagefttext() when a
	 * {@see getTTFont TTFont} is specified and imagestring()/imagestringup()
	 * otherwise.
	 *
	 * @param object &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @throws TConfigurationException when the FontNamespace does not resolve or
	 *   the GD font cannot be loaded.
	 * @return ?bool Was the image changed; null when there is no text.
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !($text = $this->getText())) {
			return null;
		}

		$rendered = null;
		$sx = imagesx($image);
		$sy = imagesy($image);

		$textX = self::computePlacement($this->getTextX(), $sx);
		$textY = self::computePlacement($this->getTextY(), $sy);
		$color = $this->getTextColorIndex($image);

		if ($ttFont = $this->getTTFont()) { // Render a FreeType2 string
			$fontSize = $this->getTTFontSize();
			$angle = $this->getTTAngle();

			$fontNamespace = $this->getFontNamespace();
			if ($fontNamespace) {
				$fontPath = Prado::getPathOfNamespace($fontNamespace);
				if ($fontPath !== null && ($realFontPath = realpath($fontPath))) {
					putenv('GDFONTPATH=' . $realFontPath);
				} else {
					throw new TConfigurationException('textimagerfilter_bad_font_namespace', $fontPath ?? $fontNamespace);
				}
			}

			$option = [];
			if (($linespacing = $this->getTTLineSpacing()) !== null) {
				$option['linespacing'] = $linespacing;
			}

			$alignX = strtolower($this->getTTAlignX());
			$alignY = strtolower($this->getTTAlignY());
			if ($alignX === '') {
				$alignX = self::isNegativePlacement($this->getTextX()) ? 'right' : 'left';
			}
			if ($alignY === '') {
				$alignY = self::isNegativePlacement($this->getTextY()) ? 'bottom' : 'top';
			}
			if (($alignX !== 'left' || $alignY !== 'bottom') && ($bounds = @imageftbbox($fontSize, $angle, $ttFont, $text, $option))) {
				if ($alignX === 'center') {
					$textX -= ($bounds[4] - $bounds[6]) / 2.0;
				} elseif ($alignX === 'right') {
					$textX -= $bounds[4] - $bounds[6];
				}
				if ($alignY === 'top') {
					$textY -= $bounds[5] - $bounds[3];
				} elseif ($alignY === 'center') {
					$textY -= ($bounds[5] - $bounds[3]) / 2.0;
				}
			}

			// imagefttext() warns when the font cannot be found or read; the text is then not rendered.
			$rendered = (bool) @imagefttext($image, $fontSize, $angle, (int) round($textX), (int) round($textY), $color, $ttFont, $text, $option);
		} else { // Render GD String
			$gdfont = null;
			$gdf = $this->getGDFont();
			if (!is_numeric($gdf) && ($fns = $this->getFontNamespace())) {
				$fontPath = Prado::getPathOfNamespace($fns);
				$gdf = $fontPath . DIRECTORY_SEPARATOR . $gdf;
				$verify = $this->getVerifyGDFont();
				$app = Prado::getApplication();
				$v = null;
				if ($verify === true || (is_string($verify) && (($v = strtolower($verify)) === 'convert' || ($v === 'auto' && $app && $app->getMode() == TApplicationMode::Debug)))) {
					$this->verifyGDFEndian($gdf, $v === 'convert');
				}
			}
			// imageloadfont() warns on a missing, empty, or malformed font file.
			if (is_numeric($gdf) || (is_file($gdf) && ($gdfont = @imageloadfont($gdf)))) {
				$font = $gdfont ?? (int) $gdf;
				$fontHeight = static::gdFontSize($gdfont !== null ? $gdf : (int) $gdf)[1];
				if ($this->getGDVertical()) {
					$textX -= $fontHeight;
					$rendered = imagestringup($image, $font, (int) round($textX), (int) round($textY), $text, $color);
				} else {
					$textY -= $fontHeight;
					$rendered = imagestring($image, $font, (int) round($textX), (int) round($textY), $text, $color);
				}
			} else {
				throw new TConfigurationException('textimagerfilter_bad_gd_font', $gdf);
			}
		}
		return $rendered;
	}

	/**
	 * The character width and height of a GD font. A built-in font (1..5) is measured by
	 * GD; a font file's size is read from its header (character count, first character,
	 * width, height, as 32-bit integers in the machine's byte order), as `imageloadfont()`
	 * reads it. This avoids `imagefontwidth()` and `imagefontheight()` on a loaded
	 * `GdFont`, which PHP 8.1 rejects with a TypeError ("must be of type GdFont|int,
	 * GdFont given").
	 * @param int|string $font a built-in font number, or a GD font file path.
	 * @throws TConfigurationException when the font file has no valid header.
	 * @return array{0:int, 1:int} the character width and height in pixels.
	 */
	public static function gdFontSize(int|string $font): array
	{
		if (is_int($font) || is_numeric($font)) {
			return [imagefontwidth((int) $font), imagefontheight((int) $font)];
		}
		$header = is_file($font) ? @file_get_contents($font, false, null, 0, 16) : false;
		if (!is_string($header) || strlen($header) !== 16 || !($size = unpack('Lchars/LstartChar/Lwidth/Lheight', $header)) || $size['width'] < 1 || $size['height'] < 1) {
			throw new TConfigurationException('textimagerfilter_bad_gd_font', $font);
		}
		return [$size['width'], $size['height']];
	}

	/**
	 * @param string &$gdf The GD font file path, replaced with the republished
	 *   path when the font is converted.
	 * @param bool $convert Enable conversion of the GDF to the proper machine
	 *   endian byte format.
	 * @throws TConfigurationException When the endian is incorrect for the
	 *   machine and $convert is false.
	 */
	public static function verifyGDFEndian(string &$gdf, bool $convert): void
	{
		$elements = null;
		if (is_file($gdf) && ($handle = @fopen($gdf, "rb"))) {
			if (($data = fread($handle, 16)) && strlen($data) === 16) {
				$elements = unpack('Lchars/LstartChar/Lwidth/Lheight', $data);
			}
			fclose($handle);
		}
		if ($elements && ($elements['startChar'] >> 1) > 0xFFFF) { // shift possible negative bit.
			$app = Prado::getApplication();
			/** @var \Prado\Web\TPublishingManager $assets the asset model requires the publishing manager */
			if ($convert && $app && ($assets = $app->getAssetManager())) {
				$gdfAsset = new TGDFAsset($gdf);
				$assets->publish($gdfAsset);
				$gdf = $gdfAsset->getPublishedPath();
			} else {
				throw new TConfigurationException('textimagerfilter_gdf_bad_endian', $gdf);
			}
		}
	}
}
