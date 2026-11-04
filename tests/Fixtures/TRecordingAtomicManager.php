<?php

/**
 * TRecordingAtomicManager class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\TPublishingManager;

/**
 * TRecordingAtomicManager class.
 *
 * A test publishing manager that records the file names it writes atomically.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingAtomicManager extends TPublishingManager
{
	/** @var string[] the file names written through writeAtomic, in order. */
	public array $atomicWrites = [];

	protected function writeAtomic(string $dst, callable $writer): string
	{
		$this->atomicWrites[] = basename($dst);
		return parent::writeAtomic($dst, $writer);
	}
}
