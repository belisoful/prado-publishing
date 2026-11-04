<?php

/**
 * TUnavailableModeImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

/**
 * TUnavailableModeImagerFilter class.
 *
 * A test imager filter of a graphics library that is not installed, which the imager
 * skips.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TUnavailableModeImagerFilter extends TRecordingImagerFilter
{
	public function getGraphicsMode(): ?string
	{
		return 'NoSuchGraphicsLibrary';
	}
}
