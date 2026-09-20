<?php

/**
 * TAssetBMPize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetBMPize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to BMP
 * format with the {@see TAssetImageFilter} Palette options.
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-bmp\.([^\/\.]*)$/i" MapToVirtual="${1}-bmp.${2}" />
 * <behavior name="BMPize" Class="Prado\Web\Assets\Behaviors\TAssetBMPize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-bmp.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 *
 * In the second example above, the {@see TAssetVirtualize} virtualizes real image
 * assets with a "-bmp.(ext)" and any virtual image asset with an "-bmp"
 * (without extension) at the end of the file name is converted into a
 * "-bmp.bmp".
 *
 * This results in a BMP image being published in place of the jpg with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.jpg %>  <br>
 * BMP Asset: <%~ myImage-bmp.jpg %> <= doesn't exist but is the
 *     original asset converted into a BMP with the virtualizer
 *     and with TAssetBMPize applied to the virtual asset.
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetBMPize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".bmp" file extension.
	 * @param null|string $filePath The file path to rewrite into a bmp if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode(IMAGETYPE_BMP)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.bmp', $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to BMP.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		$parentParam->setImageType(IMAGETYPE_BMP);
		$parentParam->setPaletteColors($this->getPaletteColors());
		return parent::applyFilters($parentParam);
	}
}
