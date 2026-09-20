<?php

/**
 * TProbeFileAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\TFileAsset;

/**
 * TProbeFileAsset class.
 *
 * A test file asset that exposes the protected TFileAsset methods for direct testing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TProbeFileAsset extends TFileAsset
{
	public function probeIsDirectory(string $path): bool
	{
		return $this->isDirectory($path);
	}

	public function probeModificationTime($path)
	{
		return $this->modificationTime($path);
	}

	public function probePutFile(string $src, string $dst): bool
	{
		return $this->putFile($src, $dst);
	}
}
