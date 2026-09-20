<?php

/**
 * TPlainImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TPlainImagerFilter class.
 *
 * A test imager filter that implements the interface without extending a component:
 * public properties and no init().
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPlainImagerFilter implements IBaseImagerFilter
{
	/** @var mixed a property set by the configuration. */
	public $amount;

	public function getEnabled(): bool
	{
		return true;
	}

	public function getGraphicsMode(): ?string
	{
		return null;
	}

	public function filterImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		return false;
	}
}
