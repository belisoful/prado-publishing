<?php

/**
 * TUppercaseAssetBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Util\TBehavior;

/**
 * TUppercaseAssetBehavior class.
 *
 * A test asset behavior that processes the written asset: its `onProcessAsset` handler
 * uppercases the file content, and records each processed path.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TUppercaseAssetBehavior extends TBehavior
{
	/** @var string[] the processed file paths, across instances. */
	public static array $processed = [];

	public function events()
	{
		return ['onProcessAsset' => 'uppercase'];
	}

	public function uppercase($sender, $param)
	{
		$path = $param->getFilePath();
		static::$processed[] = $path;
		if (is_file($path)) {
			file_put_contents($path, strtoupper(file_get_contents($path)));
		}
	}
}
