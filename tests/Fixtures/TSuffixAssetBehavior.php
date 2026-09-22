<?php

/**
 * TSuffixAssetBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Util\TBehavior;

/**
 * TSuffixAssetBehavior class.
 *
 * A test asset behavior that renames the published file by appending {@see $suffix}
 * to its name, as a format conversion or cache buster does, counting the renames.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TSuffixAssetBehavior extends TBehavior
{
	/** @var string the suffix appended to the published file path. */
	public string $suffix = '.renamed';

	/** @var int the number of dyAlterAssetFilePath calls received. */
	public int $alterCount = 0;

	/** @var bool whether dyAlterAssetFilePath returns false, an invalid publish path. */
	public bool $invalid = false;

	public function getSuffix(): string
	{
		return $this->suffix;
	}

	public function setSuffix($value): void
	{
		$this->suffix = (string) $value;
	}

	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		$this->alterCount++;
		if ($this->invalid) {
			return false;
		}
		if (!empty($filePath) && substr($filePath, -1) !== DIRECTORY_SEPARATOR) {
			$filePath .= $this->suffix;
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}
}
