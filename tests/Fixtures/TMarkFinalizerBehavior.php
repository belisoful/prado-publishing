<?php

/**
 * TMarkFinalizerBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Util\TBehavior;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TMarkFinalizerBehavior class.
 *
 * A test asset behavior that registers itself as a finalizer, at the default
 * priority, and appends {@see $mark} to the written file when finalized.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TMarkFinalizerBehavior extends TBehavior implements IAssetFinalizer
{
	/** @var string the text appended to the finalized file. */
	public string $mark = '|marked';

	public function events()
	{
		return ['onProcessAsset' => 'addFinalizer'];
	}

	public function addFinalizer($sender, $param)
	{
		$param->addFinalizer($this);
	}

	public function finalize(string $dstFile, TAssetEventParameter $param)
	{
		if (is_file($dstFile)) {
			file_put_contents($dstFile, $this->mark, FILE_APPEND);
		}
	}
}
