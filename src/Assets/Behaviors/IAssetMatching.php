<?php

/**
 * IAssetMatching class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * IAssetMatching
 *
 * The interface for making Asset Matching Behaviors uniform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.regular-expressions.info
 */
interface IAssetMatching
{
	/**
	 * @return ?string The regular expression for matching Asset file paths
	 */
	public function getMatchFiles();

	/**
	 * @param string $value The regular expression for matching Asset file paths
	 */
	public function setMatchFiles($value);
}
