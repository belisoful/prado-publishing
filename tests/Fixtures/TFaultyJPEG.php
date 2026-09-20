<?php

/**
 * TFaultyJPEG class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TJPEG;

/**
 * TFaultyJPEG class.
 *
 * A test JPEG container whose metadata getters throw, as a corrupt carrier would.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFaultyJPEG extends TJPEG
{
	public function getEXIF(): ?TEXIF
	{
		throw new TIOException('faulty_exif');
	}

	public function getXMP(): ?TXMP
	{
		throw new TIOException('faulty_xmp');
	}

	public function getIPTC(): ?TIPTC
	{
		throw new TIOException('faulty_iptc');
	}

	public function getICCProfile(): ?string
	{
		throw new TIOException('faulty_icc');
	}

	public function getJFIF(): ?TJFIF
	{
		throw new TIOException('faulty_jfif');
	}

	public function getJFXX(): ?TJFXX
	{
		throw new TIOException('faulty_jfxx');
	}
}
