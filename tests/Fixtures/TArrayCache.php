<?php

/**
 * TArrayCache class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Caching\ICache;
use Prado\Caching\ICacheDependency;

/**
 * TArrayCache class.
 *
 * A test application cache that keeps its values in memory, without serializing them,
 * honors cache dependencies, and records the dependency stored with each key.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TArrayCache implements ICache
{
	/** @var array<string, array{0:mixed, 1:?ICacheDependency}> the stored values and dependencies. */
	public array $values = [];

	public static function getIsAvailable(): bool
	{
		return true;
	}

	public function get($id)
	{
		if (!isset($this->values[$id])) {
			return false;
		}
		[$value, $dependency] = $this->values[$id];
		return ($dependency instanceof ICacheDependency && $dependency->getHasChanged()) ? false : $value;
	}

	public function set($id, $value, $expire = 0, $dependency = null)
	{
		$this->values[$id] = [$value, $dependency];
		return true;
	}

	public function add($id, $value, $expire = 0, $dependency = null)
	{
		if (isset($this->values[$id])) {
			return false;
		}
		return $this->set($id, $value, $expire, $dependency);
	}

	public function delete($id)
	{
		unset($this->values[$id]);
		return true;
	}

	public function flush()
	{
		$this->values = [];
		return true;
	}
}
