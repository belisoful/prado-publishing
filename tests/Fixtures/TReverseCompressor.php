<?php

/**
 * TReverseCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\IO\Compression\ICompressor;

/**
 * TReverseCompressor class.
 *
 * A standalone test codec without a NAME constant or an isAvailable method: it
 * "compresses" by reversing the data, and records the arguments of each compression.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TReverseCompressor implements ICompressor
{
	/** @var array<array> the arguments of each compress call, across instances. */
	public static array $calls = [];

	public static function compress(string $data): string
	{
		static::$calls[] = func_get_args();
		return strrev($data);
	}

	public static function decompress(string $data): string
	{
		return strrev($data);
	}
}
