<?php

/**
 * TRecordingImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TRecordingImagerFilter class.
 *
 * A test imager filter that records each call of {@see filterImage}, optionally
 * replaces the image, and returns a configured result.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingImagerFilter extends TBaseImagerFilter
{
	/** @var ?bool the result of filterImage. */
	public ?bool $result = true;

	/** @var ?\GdImage the image that replaces the filtered image, when set. */
	public ?\GdImage $replacement = null;

	/** @var array<array{image:mixed, param:?TAssetEventParameter}> the recorded calls. */
	public array $calls = [];

	/** @var ?\ArrayObject a log shared by filters, to record the order they run in. */
	public ?\ArrayObject $log = null;

	/** @var mixed the configuration given to init(). */
	public $config = 'not initialized';

	public function init($config): void
	{
		$this->config = $config;
	}

	public function filterImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		$this->calls[] = ['image' => $image, 'param' => $param];
		$this->log?->append($this);
		if ($this->replacement) {
			$image = $this->replacement;
		}
		return $this->result;
	}
}
