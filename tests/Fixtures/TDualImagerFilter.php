<?php

/**
 * TDualImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TDualImagerFilter class.
 *
 * A test imager filter implemented in both graphics libraries, which records the library
 * each call ran in.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TDualImagerFilter extends TBaseImagerFilter
{
	/** @var string[] the library of each call, "GD" or "Imagick". */
	public array $calls = [];

	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$this->calls[] = 'GD';
		return true;
	}

	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$this->calls[] = 'Imagick';
		return true;
	}
}
