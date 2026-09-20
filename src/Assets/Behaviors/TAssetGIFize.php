<?php

/**
 * TAssetGIFize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetGIFize
 *
 * This is a sub-class of {@see TAssetImageFilter} that attaches to the
 * {@see \Prado\Web\Assets\TAsset} class (adding image processing).  File paths that
 * match the regex in {@see getMatchFiles MatchFiles} are converted to GIF
 * format with the {@see TAssetImageFilter} Palette options.
 *
 * Attach this to the {@see \Prado\Web\Assets\TAsset} class with the following
 * {@see \Prado\Util\TBehaviorsModule} configuration:
 * ```xml
 * <behavior name="virtualizeFormat" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)-gif\.([^\/\.]*)$/i" MapToVirtual="${1}-gif.${2}" />
 * <behavior name="GIFize" Class="Prado\Web\Assets\Behaviors\TAssetGIFize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     MatchFiles="/-gif.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i" />
 * ```
 *
 * In the second example above, the {@see TAssetVirtualize} virtualizes real image
 * assets with a "-gif.(ext)" and any virtual image asset with an "-gif"
 * (without extension) at the end of the file name is converted into a
 * "-gif.gif".
 *
 * This results in a GIF image being published in place of the jpg with
 * the following code:
 * ```xml
 * Original Asset: <%~ myImage.jpg %>  <br>
 * GIF Asset: <%~ myImage-gif.jpg %> <= doesn't exist but is the
 *     original asset converted into a GIF with the virtualizer
 *     and with TAssetGIFize applied to the virtual asset.
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetGIFize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".gif" file extension.
	 * @param null|string $filePath The file path to rewrite into a gif if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		// The file is only renamed when the image can be written in the format.
		if (!empty($filePath) && $this->hasMatch() && $this->canEncode(IMAGETYPE_GIF)) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace('/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/i', '${1}.gif', $filePath);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Changes the Image type to save the image to GIF.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		$parentParam->setImageType(IMAGETYPE_GIF);
		$parentParam->setPaletteColors($this->getPaletteColors());
		return parent::applyFilters($parentParam);
	}
}
