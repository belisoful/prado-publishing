<?php

/**
 * TRecordingAssetFinalizer class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TRecordingAssetFinalizer class.
 *
 * A test asset finalizer that records each call in the shared {@see $journal} and runs
 * an optional closure.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingAssetFinalizer implements IAssetFinalizer
{
	/** @var array<array{0:string, 1:string, 2:TAssetEventParameter}> the calls, as [label, dstFile, param]. */
	public static array $journal = [];

	/**
	 * @param string $label the label recorded in the journal.
	 * @param ?\Closure $action run on finalize with ($dstFile, $param).
	 */
	public function __construct(public string $label = 'finalizer', public ?\Closure $action = null)
	{
	}

	public function finalize(string $dstFile, TAssetEventParameter $param)
	{
		static::$journal[] = [$this->label, $dstFile, $param];
		if ($this->action) {
			($this->action)($dstFile, $param);
		}
	}
}
