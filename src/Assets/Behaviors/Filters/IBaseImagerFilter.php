<?php

/**
 * IBaseImagerFilter interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * IBaseImagerFilter interface
 *
 * An image filter of an imager ({@see \Prado\Web\Assets\Behaviors\TAssetImagerBase}).
 * Filters are plain objects, not behaviors: the imager creates them from its
 * configuration ({@see TImagerFilterFactory}) and runs them in configuration order on the
 * image being published. {@see TBaseImagerFilter} is the base implementation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IBaseImagerFilter
{
	/**
	 * @return bool whether the filter runs.
	 */
	public function getEnabled(): bool;

	/**
	 * The graphics library the filter works in, a {@see \Prado\IO\Image\TImageGraphicsMode}
	 * mode: `GD` for a filter of GD calls, `Imagick` for one of Imagick calls, or null for a
	 * filter that works in either (one that does not touch the pixels). The imager converts
	 * the image being published into that library before the filter runs, and skips the
	 * filter when the library is not installed.
	 * @return ?string the graphics mode the filter needs, or null for either.
	 */
	public function getGraphicsMode(): ?string;

	/**
	 * Filters the image. The filter may replace the image with a new one.
	 * @param \GdImage|\Imagick &$image the image being filtered, in the library of
	 *   {@see getGraphicsMode}.
	 * @param ?TAssetEventParameter $param the publishing context: the asset, the image
	 *   types, and the image metadata ({@see TAssetEventParameter::getImageMetaData}).
	 * @return ?bool whether the image changed; a changed image is saved.
	 */
	public function filterImage(&$image, ?TAssetEventParameter $param = null): ?bool;
}
