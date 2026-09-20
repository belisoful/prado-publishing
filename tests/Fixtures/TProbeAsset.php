<?php

/**
 * TProbeAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\TAsset;

/**
 * TProbeAsset class.
 *
 * A test virtual asset that keeps every TAsset default implementation and exposes the
 * protected methods for direct testing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TProbeAsset extends TAsset
{
	public function probeIsDirectory(string $path): bool
	{
		return $this->isDirectory($path);
	}

	public function probeModificationTime($path)
	{
		return $this->modificationTime($path);
	}

	public function probeRewriteFilePath($path)
	{
		return $this->rewriteFilePath($path);
	}

	public function probeCopyDirectory(string $src, string $dst): void
	{
		$this->copyDirectory($src, $dst);
	}
}
