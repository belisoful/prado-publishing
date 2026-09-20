<?php

/**
 * TGeneratedAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\TAsset;

/**
 * TGeneratedAsset class.
 *
 * A test virtual asset whose content is generated rather than read from a source file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGeneratedAsset extends TAsset
{
	/** @var string the generated content. */
	public string $content = 'generated';

	/** @var int the modification time reported. */
	public int $modified = 0;

	/** @var string[] the paths the asset was written to. */
	public array $writtenTo = [];

	public function writeAsset(string $filepath): bool
	{
		$this->writtenTo[] = $filepath;
		return file_put_contents($filepath, $this->content) !== false;
	}

	protected function modificationTime($path)
	{
		return empty($path) ? false : $this->modified;
	}
}
