<?php

/**
 * IAsset interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

use Prado\Web\IPublishable;

/**
 * IAsset interface
 *
 * The interface for assets published by {@see \Prado\Web\TPublishingManager}: files,
 * directories, generated files, and virtual assets.
 *
 * It extends {@see \Prado\Web\IPublishable}, the publishing primitive that
 * {@see \Prado\Web\TAssetManager} publishes directly, with the real source path an
 * asset publishes from. {@see \Prado\Web\Assets\TAsset} adds the behavior pipeline:
 * the file path dynamic events and the `onProcessAsset` event.
 *
 * An implementation that extends {@see \Prado\TComponent} may be renamed on
 * publishing by the `dyAlterAssetFilePath` dynamic event, which receives the asset
 * file path and returns the path the asset publishes under. Image format
 * conversions change the file extension there, and cache busting fingerprints the
 * file name.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @method string dyAlterAssetFilePath(string $filePath) Filters the asset file path
 *   into the path the asset publishes under.
 * @method void onProcessAsset(string $filePath) Raised on the written asset.
 */
interface IAsset extends IPublishable
{
	/**
	 * The real source file path the asset is published from, before any virtual file
	 * path translation.
	 * @return null|false|string the original source file path; null when publishing
	 *   is cancelled, false when invalid.
	 */
	public function getAssetOriginalFilePath();
}
