<?php

/**
 * TUnavailableCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\IO\Compression\ICompressor;

/**
 * TUnavailableCompressor class.
 *
 * A standalone test codec named "br" that is never available, as a command-backed codec
 * whose command is missing would be.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TUnavailableCompressor implements ICompressor
{
	/** The wire-format name, a TCompression content coding. */
	public const NAME = 'br';

	public static function isAvailable(): bool
	{
		return false;
	}

	public static function compress(string $data): string
	{
		throw new \LogicException('An unavailable codec does not compress.');
	}

	public static function decompress(string $data): string
	{
		throw new \LogicException('An unavailable codec does not decompress.');
	}
}
