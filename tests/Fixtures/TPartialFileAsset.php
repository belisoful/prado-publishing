<?php

/**
 * TPartialFileAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\TFileAsset;

/**
 * TPartialFileAsset class.
 *
 * A test file asset whose write leaves partial content at the destination and fails.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPartialFileAsset extends TFileAsset
{
	protected function putFile(string $src, string $dst): bool
	{
		file_put_contents($dst, 'PARTIAL');
		return false;
	}
}
