<?php

/**
 * TAssetGZCompress class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * TAssetGZCompress class
 *
 * The gzip preset of {@see TAssetCompress}: publishes matching assets gzip-compressed,
 * under their file name with ".gz" appended. It adds only the {@see GZIP_MAGIC} constant
 * and inherits the "gzip" {@see TAssetCompress::getMethod Method} default of
 * TAssetCompress, so a configuration names the coding by the class rather than by the
 * Method property.
 *
 * ```xml
 * <behavior name="gzip" Class="Prado\Web\Assets\Behaviors\TAssetGZCompress"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.(js|css|svg)$/i" CompressionLevel="9" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetGZCompress extends TAssetCompress
{
	/** The gzip member header magic bytes. */
	public const GZIP_MAGIC = "\x1f\x8b";
}
