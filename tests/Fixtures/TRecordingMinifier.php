<?php

/**
 * TRecordingMinifier class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Javascripts\IMinifier;

/**
 * TRecordingMinifier class.
 *
 * A test minifier: it prefixes the code with its mark and records the code of each call.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRecordingMinifier implements IMinifier
{
	/** @var string[] the code of each minify call. */
	public array $calls = [];

	/**
	 * @param string $mark the prefix of the minified code.
	 */
	public function __construct(public string $mark = '/*min*/')
	{
	}

	public function minify(string $code): string
	{
		$this->calls[] = $code;
		return $this->mark . $code;
	}
}
