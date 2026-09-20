<?php

/**
 * TImageAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

/**
 * TImageAsset class
 *
 * The file asset for images. {@see \Prado\Web\TPublishingManager} instances image
 * files (see {@see \Prado\Web\TPublishingManager::IMAGE_EXTENSIONS}) as its
 * {@see \Prado\Web\TPublishingManager::getDefaultImageAssetClass DefaultImageAssetClass},
 * which is this class by default. Image behaviors can attach to this class, rather
 * than to {@see TAsset}, so they are instanced only for images.
 *
 * ```xml
 * <behavior name="webp" Class="Prado\Web\Assets\Behaviors\TAssetWebPize"
 *     AttachToClass="Prado\Web\Assets\TImageAsset" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TImageAsset extends TFileAsset
{
}
