<?php

/**
 * TAssetJPEGize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetJPEGize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to JPEG
 * format of the specified {@see TAssetImageFilter::getImageQuality ImageQuality}.
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="JPEGize" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
 *     AttachToClass="Prado\Web\Assets\TAsset" ImageQuality="50" />
 * ```
 *
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-jpg\.([^\/\.]*)$/i"
 *     MapFromVirtual="${1}.${2}" MapToVirtual="${1}-jpg.${2}" />
 * <behavior name="JPEGize" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-jpg\.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 *
 * In the second example above, {@see TAssetVirtualize} maps a virtual "name-jpg.ext" onto
 * the real "name.ext" and publishes it under the virtual name, and TAssetJPEGize converts
 * the matching virtual asset into "name-jpg.jpg".
 *
 * This results in a JPEG image being published in place of the png with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.png %>  <br>
 * JPEG Asset: <%~ myImage-jpg.png %> <= doesn't exist but is the
 *     original asset converted into a JPEG with the virtualizer
 *     and with TAssetJPEGize applied to the virtual asset.
 * ```
 *
 * {@see TAssetWebPize} is widely supported, higher quality, and smaller file
 * size than JPEG and PNG.  If reduction in bandwidth is the goal,
 * consider TAssetWebPize rather than TAssetJPEGize or {@see TAssetPNGize}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetJPEGize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".jpg" file extension.
	 *
	 * @param null|string $filePath The file path to rewrite into a jpg if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode(IMAGETYPE_JPEG)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.jpg', $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to JPEG.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 *
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		$parentParam->setImageType(IMAGETYPE_JPEG);
		$parentParam->setPaletteColors(0);
		return parent::applyFilters($parentParam);
	}
}
