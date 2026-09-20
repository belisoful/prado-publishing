<?php

/**
 * TThrowingAssetBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Util\TBehavior;

/**
 * TThrowingAssetBehavior class.
 *
 * A test asset behavior whose `onProcessAsset` handler records each processed path and
 * throws when the asset file path matches {@see $failOn}, as a failing image filter or
 * compressor would after the file is written.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TThrowingAssetBehavior extends TBehavior
{
	/** @var ?string the regular expression of the asset file paths that fail; null for none. */
	public static ?string $failOn = null;

	/** @var string[] the processed file paths, across instances. */
	public static array $processed = [];

	public function events()
	{
		return ['onProcessAsset' => 'process'];
	}

	public function process($sender, $param)
	{
		static::$processed[] = $param->getFilePath();
		if (static::$failOn !== null && preg_match(static::$failOn, (string) $sender->getAssetFilePath())) {
			throw new \RuntimeException('Processing failed.');
		}
	}
}
