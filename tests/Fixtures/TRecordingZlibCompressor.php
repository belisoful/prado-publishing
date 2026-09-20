<?php

/**
 * TRecordingZlibCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\IO\Compression\TZlibCompressor;

/**
 * TRecordingZlibCompressor class.
 *
 * A test zlib codec named "deflate", so it selects the deflate content coding, that
 * records the arguments of each compression.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingZlibCompressor extends TZlibCompressor
{
	/** The wire-format name, a TCompression content coding. */
	public const NAME = 'deflate';

	/** @var array<array> the arguments of each compress call, across instances. */
	public static array $calls = [];

	public static function compress(string $data, int $level = -1): string
	{
		static::$calls[] = func_get_args();
		return parent::compress($data, $level);
	}
}
