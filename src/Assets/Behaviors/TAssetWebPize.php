<?php

/**
 * TAssetWebPize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
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
 * For a browser that does not accept WebP, the image is published in the
 * {@see getWebPFallback WebPFallback} format.  With the default "Preserve" fallback,
 * the image keeps its format, and the filters and the `<metadata>` settings, such as
 * a Scrub, still apply.
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
	 * The image type a matching image is published as: WebP when the browser accepts it,
	 * otherwise the {@see getWebPFallback WebPFallback} format. With the "Preserve"
	 * fallback, the image is processed by the filters and the metadata settings, and
	 * keeps its type.
	 * @return ?int the image type, or null when the image keeps its type.
	 */
	protected function getConversionType(): ?int
	{
		if (self::browserSupportsWebP()) {
			return IMAGETYPE_WEBP;
		}
		return match ($this->getWebPFallback()) {
			TAssetWebPFallbackMode::JPEG => IMAGETYPE_JPEG,
			TAssetWebPFallbackMode::PNG, TAssetWebPFallbackMode::PNGPalette => IMAGETYPE_PNG,
			default => null,
		};
	}

	/**
	 * Matched files are given the extension of their {@see getConversionType image type}:
	 * ".webp", or the ".jpg" or ".png" of the fallback. With the "Preserve" fallback, the
	 * file keeps its name.
	 *
	 * @param null|string $filePath The file path to rewrite into a webp if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		if (($type = $this->getConversionType()) !== null) {
			$filePath = $this->renameForConversion($filePath, $type, match ($type) {
				IMAGETYPE_JPEG => 'jpg',
				IMAGETYPE_PNG => 'png',
				default => 'webp',
			});
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to WebP, or to the fallback format when the
	 * browser does not accept WebP.  With the "Preserve" fallback, the image keeps its
	 * type and palette.  This is raised only for assets when their AssetFilePath matches
	 * MatchFiles.
	 *
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		if (($type = $this->getConversionType()) !== null) {
			$paletteColors = 0;
			if (!self::browserSupportsWebP() && $this->getWebPFallback() === TAssetWebPFallbackMode::PNGPalette) {
				$paletteColors = $this->getWebPFallbackPNGPaletteColors();
				$paletteColors = ($paletteColors >= 2) ? $paletteColors : 256;
			}
			$parentParam->setImageType($type);
			$parentParam->setPaletteColors($paletteColors);
		}
		return parent::applyFilters($parentParam);
	}
}
