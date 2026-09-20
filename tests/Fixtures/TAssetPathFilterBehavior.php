<?php

/**
 * TAssetPathFilterBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Util\TBehavior;

/**
 * TAssetPathFilterBehavior class.
 *
 * A configurable test behavior implementing every TAsset dynamic event. Each event
 * applies its optional closure and records the call in the shared {@see $journal} as
 * "event:label", so tests can observe the filter values and the chain order.
 *
 * dySetAssetFilePathPre calls the chain first, as the TAsset contract requires; the
 * other filters apply their closure and then call the chain.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetPathFilterBehavior extends TBehavior
{
	/** @var array<array{0:string, 1:mixed}> the calls, across instances, as ["event:label", value]. */
	public static array $journal = [];

	/** @var string the label recorded in the journal. */
	public string $label = 'filter';

	/** @var ?\Closure filters the dySetAssetFilePathPre file path. */
	public ?\Closure $preFilter = null;

	/** @var ?\Closure filters the dySetAssetFilePathPost file path. */
	public ?\Closure $postFilter = null;

	/** @var ?\Closure filters the dyRewriteFilePath file path. */
	public ?\Closure $rewriteFilter = null;

	/** @var ?\Closure filters the dyGetAssetModificationDate time. */
	public ?\Closure $dateFilter = null;

	/** @var ?\Closure filters the dyWriteAsset return value, given ($return, $dst). */
	public ?\Closure $writeFilter = null;

	/** @var ?\Closure votes in dyResetFileCacheWithBehavior, given ($reset, $name, $behavior). */
	public ?\Closure $resetVote = null;

	/** @var int the number of dyResetFilePathCache calls received. */
	public int $resetCount = 0;

	public function dySetAssetFilePathPre($filePath, $callchain)
	{
		$filePath = $callchain->dySetAssetFilePathPre($filePath);
		static::$journal[] = ['pre:' . $this->label, $filePath];
		return $this->preFilter ? ($this->preFilter)($filePath) : $filePath;
	}

	public function dySetAssetFilePathPost($filePath, $callchain)
	{
		static::$journal[] = ['post:' . $this->label, $filePath];
		if ($this->postFilter) {
			$filePath = ($this->postFilter)($filePath);
		}
		return $callchain->dySetAssetFilePathPost($filePath);
	}

	public function dyRewriteFilePath($filePath, $callchain)
	{
		static::$journal[] = ['rewrite:' . $this->label, $filePath];
		if ($this->rewriteFilter) {
			$filePath = ($this->rewriteFilter)($filePath);
		}
		return $callchain->dyRewriteFilePath($filePath);
	}

	public function dyGetAssetModificationDate($time, $callchain)
	{
		static::$journal[] = ['date:' . $this->label, $time];
		if ($this->dateFilter) {
			$time = ($this->dateFilter)($time);
		}
		return $callchain->dyGetAssetModificationDate($time);
	}

	public function dyWriteAsset($return, $dst, $callchain)
	{
		static::$journal[] = ['write:' . $this->label, $dst];
		if ($this->writeFilter) {
			$return = ($this->writeFilter)($return, $dst);
		}
		return $callchain->dyWriteAsset($return, $dst);
	}

	public function dyResetFilePathCache($callchain)
	{
		$this->resetCount++;
		return $callchain->dyResetFilePathCache();
	}

	public function dyResetFileCacheWithBehavior($reset, $name, $behavior, $callchain)
	{
		static::$journal[] = ['vote:' . $this->label, $name];
		if ($this->resetVote) {
			$reset = ($this->resetVote)($reset, $name, $behavior);
		}
		return $callchain->dyResetFileCacheWithBehavior($reset, $name, $behavior);
	}
}
