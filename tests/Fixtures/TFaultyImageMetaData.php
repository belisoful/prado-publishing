<?php

/**
 * TFaultyImageMetaData class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TImageFile;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;

/**
 * TFaultyImageMetaData class.
 *
 * A test image metadata that reads a given image container, and whose EXIF,
 * XMP, and IPTC getters throw when {@see $faulty} is set.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFaultyImageMetaData extends TAssetImageMetaData
{
	/** @var ?TImageFile the container read, or null for the source file's. */
	public ?TImageFile $imageFile = null;

	/** @var bool whether the carrier getters throw. */
	public bool $faulty = false;

	public function getImageFile(): ?TImageFile
	{
		return $this->imageFile ?? parent::getImageFile();
	}

	public function getEXIF(): ?TEXIF
	{
		$this->fault('EXIF');
		return parent::getEXIF();
	}

	public function getXMP(): ?TXMP
	{
		$this->fault('XMP');
		return parent::getXMP();
	}

	public function getIPTC(): ?TIPTC
	{
		$this->fault('IPTC');
		return parent::getIPTC();
	}

	/**
	 * @param string $carrier the carrier read.
	 * @throws TIOException when faulty.
	 */
	protected function fault(string $carrier): void
	{
		if ($this->faulty) {
			throw new TIOException('faulty_carrier', $carrier);
		}
	}
}
