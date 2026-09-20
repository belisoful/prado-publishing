<?php

/**
 * IAssetFinalizer interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

/**
 * IAssetFinalizer interface
 *
 * This is the interface for behaviors to finalize the asset after raising the
 * {@see TAsset::onProcessAsset} event.  The {@see TAssetEventParameter::getFinalizers
 * Finalizer}s are used to finalize the publishing of the asset after being processed.
 *
 * The {@see \Prado\Web\Assets\Behaviors\TAssetImagerBase} finalizes the image
 * processing by writing the image to the temporary destination file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IAssetFinalizer
{
	/**
	 * Finalizes the publishing of an asset after {@see TAsset::onProcessAsset} is raised.
	 * @param string $dstFile the destination file to finalize
	 * @param TAssetEventParameter $param
	 */
	public function finalize(string $dstFile, TAssetEventParameter $param);
}
