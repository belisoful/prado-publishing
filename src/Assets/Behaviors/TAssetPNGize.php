<?php

/**
 * TAssetPNGize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetPNGize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to PNG
 * format of the specified {@see TAssetImageFilter::getPngQuality PngQuality} and optional
 * {@see TAssetImageFilter::getSaveAlpha SaveAlpha}.  By default, SaveAlpha is true.
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="PNGize" Class="Prado\Web\Assets\Behaviors\TAssetPNGize"
 *     AttachToClass="Prado\Web\Assets\TAsset" PNGQuality="7" />
 * ```
 *
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-png\.([^\/\.]*)$/i" MapToVirtual="${1}-png.${2}" />
 * <behavior name="PNGize" Class="Prado\Web\Assets\Behaviors\TAssetPNGize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-png.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 *
 * In the second example above, the {@see TAssetVirtualize} virtualizes real image
 * assets with a "-png.(ext)" and any virtual image asset with an "-png"
 * (without extension) at the end of the file name is converted into a
 * "-png.png".
 *
 * This results in a PNG image being published in place of the jpg with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.jpg %>  <br>
 * PNG Asset: <%~ myImage-png.jpg %> <= doesn't exist but is the
 *     original asset converted into a PNG with the virtualizer
 *     and with TAssetPNGize applied to the virtual asset.
 * ```
 *
 * {@see TAssetWebPize} is widely supported, higher quality, and smaller file
 * size than JPEG and PNG.  If reduction in bandwidth is the goal,
 * consider TAssetWebPize rather than TAssetPNGize or {@see TAssetJPEGize}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetPNGize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".png" file extension.
	 *
	 * @param null|string $filePath The file path to rewrite into a png if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode(IMAGETYPE_PNG)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.png', $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to PNG.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 *
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		$parentParam->setImageType(IMAGETYPE_PNG);
		$pngColorMode = $this->getPngColorMode();
		if ($pngColorMode == TAssetPNGColorMode::TrueColor) {
			$parentParam->setPaletteColors(0);
		} elseif ($pngColorMode == TAssetPNGColorMode::Palette) {
			$parentParam->setPaletteColors($this->getPaletteColors());
		}
		return parent::applyFilters($parentParam);
	}
}
