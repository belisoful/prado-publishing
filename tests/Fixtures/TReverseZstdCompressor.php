<?php

/**
 * TReverseZstdCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

/**
 * TReverseZstdCompressor class.
 *
 * A {@see TReverseCompressor} named "zstd", so it selects the zstd format, its extension,
 * level range, and frame detection. It records into {@see TReverseCompressor::$calls}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TReverseZstdCompressor extends TReverseCompressor
{
	/** The wire-format name, a known compressed format. */
	public const NAME = 'zstd';
}
