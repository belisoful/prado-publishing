<?php

/**
 * IAssetReplacement class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * IAssetReplacement
 *
 * The interface for making Asset Matching Replacement Behaviors
 * uniform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.preg-replace.php
 */
interface IAssetReplacement extends IAssetMatching
{
	/**
	 * @return ?string the replacement expression for Asset Matching
	 */
	public function getMatchReplacement();

	/**
	 * @param string $value the replacement expression for Asset Matching
	 */
	public function setMatchReplacement($value);
}
