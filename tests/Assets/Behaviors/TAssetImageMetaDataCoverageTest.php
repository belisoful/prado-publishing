<?php

/**
 * TAssetImageMetaDataCoverageTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TJFIFFormat;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TJPEG;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Tests\Fixtures\TFaultyImageMetaData;
use Prado\Web\Tests\Fixtures\TFaultyJPEG;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetImageMetaDataCoverageTest class.
 *
 * Tests the image metadata's less common paths: carriers whose reading fails,
 * setting the ICC profile, field reads that fail, XMP field errors and removal,
 * matching array-valued EXIF tags, and the JFXX palette, color, and efficiency
 * thumbnail modes.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetImageMetaDataCoverageTest extends PublishingTestCase
{
	public function testACarrierThatFailsToReadIsNull(): void
	{
		$metaData = new TFaultyImageMetaData();
		$metaData->imageFile = TFaultyJPEG::fromImage(static::createImage(), 90);

		self::assertInstanceOf(TFaultyJPEG::class, $metaData->getImageFile());
		$metaData->loadMetaData();

		self::assertNull($metaData->getEXIF());
		self::assertNull($metaData->getXMP());
		self::assertNull($metaData->getIPTC());
		self::assertNull($metaData->getICCProfile());
		self::assertFalse($metaData->hasMetaData());
		self::assertFalse($metaData->getNeedsWrite());
	}

	public function testSetICCProfile(): void
	{
		$metaData = new TAssetImageMetaData();
		$metaData->setMetaDataSource($this->writeImage('plain.png'));
		self::assertNull($metaData->getICCProfile());
		self::assertFalse($metaData->getChanged());

		$metaData->setICCProfile('profile-bytes');
		self::assertSame('profile-bytes', $metaData->getICCProfile());
		self::assertTrue($metaData->getChanged());
		self::assertTrue($metaData->hasMetaData());

		$metaData->setChanged(false);
		$metaData->setICCProfile(null);
		self::assertNull($metaData->getICCProfile());
		self::assertTrue($metaData->getChanged(), 'Removing the profile is a change.');
	}

	public function testGetMetaDataIsNullWhenTheCarrierFails(): void
	{
		$metaData = new TFaultyImageMetaData();
		$metaData->setMetaData('Keywords', ['kept']);
		$metaData->setMetaData('Artist', 'Ann');
		$metaData->setMetaData('XMP:dc:rights', 'Reserved');
		self::assertSame(['kept'], (array) $metaData->getMetaData('Keywords'));

		$metaData->faulty = true;

		self::assertNull($metaData->getMetaData('Keywords'));
		self::assertNull($metaData->getMetaData('EXIF:Artist'));
		self::assertNull($metaData->getMetaData('XMP:dc:rights'));
		self::assertFalse($metaData->match('Keywords=kept'), 'A failing read does not match.');
	}

	public function testSetMetaDataRejectsAnUnknownXmpPrefix(): void
	{
		$metaData = new TAssetImageMetaData();
		try {
			$metaData->setMetaData('XMP:bogus:title', 'x');
			self::fail('An unknown XMP namespace prefix throws.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('metadata_bad_field', $e->getErrorCode());
		}
		self::assertNull($metaData->getMetaData('XMP:bogus:title'));
		self::assertNull($metaData->getXMP(), 'No XMP carrier is created for an unknown prefix.');
	}

	public function testSetMetaDataRemovesAnXmpProperty(): void
	{
		$metaData = new TAssetImageMetaData();
		$metaData->setMetaData('XMP:dc:rights', 'All rights reserved');
		$metaData->setMetaData('XMP:dc:creator', ['Ann'], true);
		self::assertSame('All rights reserved', $metaData->getXMP()->getRights());
		$metaData->setChanged(false);

		$metaData->setMetaData('XMP:dc:rights', null);

		self::assertNull($metaData->getXMP()->getRights());
		self::assertNull($metaData->getMetaData('XMP:dc:rights'));
		self::assertNotNull($metaData->getMetaData('XMP:dc:creator'), 'Other properties are kept.');
		self::assertTrue($metaData->getChanged());
	}

	public function testMatchArrayValuedExifTag(): void
	{
		$metaData = new TAssetImageMetaData();
		$exif = new TEXIF();
		$exif->setLatitude(45.5);
		$metaData->setEXIF($exif);
		self::assertSame([[45, 1], [30, 1], [0, 10000]], $metaData->getMetaData('GPSLatitude'));

		self::assertTrue($metaData->match('GPSLatitude'));
		self::assertTrue($metaData->match('GPSLatitude==45/1'), 'A rational matches as "numerator/denominator".');
		self::assertTrue($metaData->match('GPSLatitude==30/1'));
		self::assertFalse($metaData->match('GPSLatitude==45'));
		self::assertTrue($metaData->match('GPSLatitude=^45/'));
		self::assertTrue($metaData->match('GPSLatitude!=^9'));
		self::assertFalse($metaData->match('GPSLatitude!==45/1'), 'A negation holds for every value.');
	}

	public function testJFXXPaletteColorAndEfficiencyModes(): void
	{
		$image = static::createImage(40, 20);
		$source = $this->writeImage('photo.jpg');
		$modes = [
			'JFXXPalette' => TJFXX::PALETTE_THUMB,
			'JFXXColor' => TJFXX::COLOR_THUMB,
			'JFXXEfficiency' => null,
		];
		foreach ($modes as $mode => $format) {
			$metaData = new TAssetImageMetaData();
			$metaData->setJFIFMode($mode);
			$metaData->setJFIFSize('16x8');
			$metaData->setMetaDataSource($source);
			self::assertSame(constant(TJFIFFormat::class . '::' . $mode), $metaData->getJFIFMode());
			$target = $this->writeImage("out/$mode.jpg", 0, 0, $image);

			self::assertTrue($metaData->writeMetaData($target, $image, true));

			$published = TImageFile::fromFile($target);
			self::assertInstanceOf(TJPEG::class, $published);
			self::assertNotNull($published->getJFIF(), "$mode keeps the JFIF header.");
			self::assertFalse($published->getJFIF()->hasImage(), "$mode has no JFIF thumbnail.");
			if ($format === null) {
				// Efficiency picks the smallest encoding of the thumbnail.
				$expected = new TJFXX();
				$expected->setImage($metaData->createThumbnail($image, 16, 8, true), TJFXX::EFFICIENCY_THUMB, $metaData->getJFXXJpegQuality());
				$format = $expected->getFormat();
			}
			$jfxx = $published->getJFXX();
			self::assertNotNull($jfxx, "$mode writes a JFXX extension.");
			self::assertTrue($jfxx->hasImage());
			self::assertSame([16, 8], [$jfxx->getXThumbnail(), $jfxx->getYThumbnail()]);
			self::assertSame($format, $jfxx->getFormat(), "$mode writes its thumbnail format.");
		}
	}
}
