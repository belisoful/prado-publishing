<?php

/**
 * IBaseImager interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * IBaseImager interface
 *
 * The interface of the imagers, the asset behaviors that run image filters
 * ({@see \Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter}) on published images.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IBaseImager
{
	/**
	 * @return array<string, \Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter> the
	 *   filters, by name, in the order they run.
	 */
	public function getFilters(): array;

	/**
	 * Runs the filters on the parameter's image.
	 * @param TAssetEventParameter $param the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $param): bool;
}
