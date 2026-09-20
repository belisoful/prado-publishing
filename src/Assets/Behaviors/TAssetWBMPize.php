<?php

/**
 * TAssetWBMPize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetWBMPize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to WBMP
 * format.  This is a black and white 1 bit format for wireless devices.
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-wbmp\.([^\/\.]*)$/i" MapToVirtual="${1}-wbmp.${2}" />
 * <behavior name="WBMPize" Class="Prado\Web\Assets\Behaviors\TAssetWBMPize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-wbmp.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 *
 * In the example above, the {@see TAssetVirtualize} virtualizes real image
 * assets with a "-wbmp.(ext)" and any virtual image asset with a "-wbmp"
 * (without extension) is converted into a "-wbmp.wbmp".
 *
 * This results in a WBMP image being published in place of the jpg with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.jpg %>  <br>
 * WBMP Asset: <%~ myImage-wbmp.jpg %> <= doesn't exist but is the
 *     original asset converted into an wbmp with the virtualizer
 *     and with TAssetWBMPize applied to the virtual asset.
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetWBMPize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".wbmp" file extension.
	 *
	 * @param null|string $filePath The file path to rewrite into a wbmp if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode(IMAGETYPE_WBMP)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.wbmp', $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to WBMP.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 *
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		// Image type is automatically made black and white.
		$parentParam->setImageType(IMAGETYPE_WBMP);
		return parent::applyFilters($parentParam);
	}
}
