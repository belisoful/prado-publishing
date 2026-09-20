<?php

/**
 * TAssetWebPize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetWebPize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to WebP
 * format of the specified {@see TAssetImageFilter::getImageQuality ImageQuality} and
 * optional {@see TAssetImageFilter::getSaveAlpha SaveAlpha}.  By default, SaveAlpha
 * is true.
 *
 * WebP format was created by Google and provides better image quality,
 * and smaller file size (with alpha channel) than jpeg and png.  It is
 * supported by all major browsers. see {@see https://caniuse.com/?search=webp}
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="WebPize" Class="Prado\Web\Assets\Behaviors\TAssetWebPize"
 *     AttachToClass="Prado\Web\Assets\TAsset" ImageQuality="60" />
 * <behavior name="WebPFallback" Class="Prado\Web\Assets\Behaviors\TAssetWebPize"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.webp$/i" ImageQuality="60" />
 * ```
 *
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-webp\.([^\/\.]*)$/i" MapToVirtual="${1}-webp.${2}" />
 * <behavior name="Webpize" Class="Prado\Web\Assets\Behaviors\TAssetWebPize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-webp.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 * The first example automatically applies to all supported images to
 * convert them to webp format.  The second example applies the fallback
 * webp mechanism to webp images, so any last browsers that do not support
 * webp can view the images.
 *
 * WebP is pronounced “Weppy”.
 *
 * In the second example above, the {@see TAssetVirtualize} virtualizes real image
 * assets with a "-webp.(ext)" and any virtual image asset with an "-webp"
 * (without extension) at the end of the file name is converted into a
 * "-webp.webp".
 *
 * This results in an WebP image being published in place of the jpg with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.jpg %>  <br>
 * WebP Asset: <%~ myImage-webp.jpg %> <= doesn't exist but is the
 *     original asset converted into an webp with the virtualizer
 *     and with TAssetWebPize applied to the virtual asset.
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetWebPize extends TAssetImageFilter
{
	/** @var string What to do when WebP is not supported by the browser. */
	private string $_webPFallback = TAssetWebPFallbackMode::Preserve;

	/** @var int The number of palette colors for a PNG Palette fallback image. */
	private int $_webPFallbackPngPaletteColors = 256;

	/**
	 * @return string What to do with the image if WebP is not supported,
	 *    default "Preserve".
	 */
	public function getWebPFallback(): string
	{
		return $this->_webPFallback;
	}

	/**
	 * The value must be defined in {@see TAssetWebPFallbackMode}.
	 *
	 * @param string $value What to do with the image if WebP is not supported.
	 */
	public function setWebPFallback($value): void
	{
		$this->_webPFallback = TPropertyValue::ensureEnum($value, TAssetWebPFallbackMode::class);
	}

	/**
	 * The number of palette colors of the PNG published when
	 * {@see getWebPFallback WebPFallback} is
	 * {@see TAssetWebPFallbackMode::PNGPalette}.
	 *
	 * @return int the palette size, 0 to 256, default 256.
	 */
	public function getWebPFallbackPngPaletteColors(): int
	{
		return $this->_webPFallbackPngPaletteColors;
	}

	/**
	 * The number of palette colors of the PNG published when
	 * {@see getWebPFallback WebPFallback} is
	 * {@see TAssetWebPFallbackMode::PNGPalette}.  The value is clamped to 0..256, and
	 * a value below 2 is published as 256 colors.
	 *
	 * @param int $value the palette size, 0 to 256.
	 */
	public function setWebPFallbackPngPaletteColors($value): void
	{
		$this->_webPFallbackPngPaletteColors = min(256, max(0, TPropertyValue::ensureInteger($value)));
	}

	/**
	 * @return bool Does the browser support WebP format.
	 */
	public static function browserSupportsWebP(): bool
	{
		return isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
	}

	/**
	 * We match only when WebP is supported by the browser, when the Fallback mode is not Preserve,
	 * or when the file itself is a WebP format for falling back.  WebP files don't get preserved,
	 * but are matched for Fallback.
	 * When matching in fallback mode, the resource can be converted into a JPEG, PNG True Color, or
	 * PNG Palette Color image.
	 *
	 * @param null|string $dstFile
	 * @param null|mixed $asset
	 * @return null|bool does MatchFiles match AssetFilePath and possibly with
	 *   MetaData search.
	 */
	public function hasMatch($dstFile = null, $asset = null)
	{
		if (self::browserSupportsWebP() || $this->getWebPFallback() !== TAssetWebPFallbackMode::Preserve || ($dstFile && strripos($dstFile, '.webp') === strlen($dstFile) - 5)) {
			return parent::hasMatch($dstFile, $asset);
		}
		return false;
	}

	/**
	 * Matched files are given the ".webp" file extension.
	 *
	 * @param null|string $filePath The file path to rewrite into a webp if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		$webPsupported = self::browserSupportsWebP();
		$type = IMAGETYPE_WEBP;
		$ext = 'webp';
		if (!$webPsupported) {
			$fallback = $this->getWebPFallback();
			if ($fallback === TAssetWebPFallbackMode::JPEG) {
				$type = IMAGETYPE_JPEG;
				$ext = 'jpg';
			} elseif ($fallback === TAssetWebPFallbackMode::PNG || $fallback === TAssetWebPFallbackMode::PNGPalette) {
				$type = IMAGETYPE_PNG;
				$ext = 'png';
			}
		}
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode($type)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.' . $ext, $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to WebP.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 *
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{	//This has already matched.
		$paletteColors = 0;
		if (self::browserSupportsWebP()) {
			$parentParam->setImageType(IMAGETYPE_WEBP);
		} elseif (($fallback = $this->getWebPFallback()) == TAssetWebPFallbackMode::JPEG) {
			$parentParam->setImageType(IMAGETYPE_JPEG);
		} elseif ($fallback == TAssetWebPFallbackMode::PNG) {
			$parentParam->setImageType(IMAGETYPE_PNG);
		} elseif ($fallback == TAssetWebPFallbackMode::PNGPalette) {
			$parentParam->setImageType(IMAGETYPE_PNG);
			$paletteColors = $this->getWebPFallbackPNGPaletteColors();
			$paletteColors = ($paletteColors >= 2) ? $paletteColors : 256;
		}
		$parentParam->setPaletteColors($paletteColors);
		return parent::applyFilters($parentParam);
	}
}
