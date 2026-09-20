<?php

/**
 * TAssetImageMetaDataTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFIFFormat;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TPrivacyCategory;
use Prado\Web\Assets\Behaviors\Filters\TInterpolationImagerMode;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Assets\Behaviors\TAssetImageMetaDataMode;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetImageMetaDataTest class.
 *
 * Tests the image metadata on real image files built with prado-image: its
 * configuration, reading and editing the carriers, orientation, field access and
 * matching, and writing the metadata into a published image.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetImageMetaDataTest extends PublishingTestCase
{
	/**
	 * @return string a minimal valid ICC profile.
	 */
	protected static function iccProfile(): string
	{
		return pack('N', 132) . 'none' . pack('N', 0x02100000) . 'mntrRGB XYZ ' . str_repeat("\0", 12) . 'acsp' . str_repeat("\0", 88) . pack('N', 0);
	}

	/**
	 * Writes a JPEG carrying EXIF, IPTC, XMP, and an ICC profile.
	 * @param string $relative the source path.
	 * @param array<string, mixed> $options orientation, iptcRotation, thumbnail (bool),
	 *   pixelDimensions (bool), gps (bool).
	 * @return string the file path.
	 */
	protected function writeMetaJpeg(string $relative = 'photo.jpg', array $options = []): string
	{
		$image = static::createImage(40, 20);
		$jpeg = TJPEG::fromImage($image, 95);

		$exif = new TEXIF();
		$exif->setValueByName('Artist', 'Ann Author');
		$exif->setValueByName('Model', 'Camera X');
		if (isset($options['orientation'])) {
			$exif->getIfd0()->setTagValues(TAssetImageMetaData::ORIENTATION_TAG, TTIFFDataType::UShort, [$options['orientation']]);
		}
		if ($options['pixelDimensions'] ?? false) {
			$exif->setValueByName('PixelXDimension', 40);
			$exif->setValueByName('PixelYDimension', 20);
		}
		if ($options['gps'] ?? false) {
			$exif->setLatitude(45.5);
			$exif->setLongitude(-122.25);
		}
		if ($options['thumbnail'] ?? false) {
			ob_start();
			imagejpeg(static::createImage(8, 4), null, 80);
			$exif->setThumbnail(ob_get_clean());
		}
		$jpeg->setEXIF($exif);

		$iptc = new TIPTC();
		$iptc['Keywords'] = ['portfolio', 'sunset'];
		$iptc['Copyright'] = 'Ann Author 2026';
		$iptc[TIPTCTags::IPTCImageWidth] = 40;
		if (isset($options['iptcRotation'])) {
			$iptc[TIPTCTags::IPTCImageRotation] = $options['iptcRotation'];
		}
		$jpeg->setIPTC($iptc);

		$xmp = TXMP::blank();
		$xmp->setTitle('A Title');
		$jpeg->setXMP($xmp);
		$jpeg->setICCProfile(static::iccProfile());

		$path = $this->writeSource($relative, '');
		$jpeg->save($path);
		return $path;
	}

	/**
	 * @param string $source the source image.
	 * @param array<string, mixed> $properties the behavior properties.
	 * @return TAssetImageMetaData the behavior reading the source.
	 */
	protected function newMetaData(?string $source = null, array $properties = []): TAssetImageMetaData
	{
		$metaData = new TAssetImageMetaData();
		foreach ($properties as $name => $value) {
			$metaData->{'set' . $name}($value);
		}
		$metaData->setMetaDataSource($source);
		return $metaData;
	}

	/**
	 * Writes a GD image to a file of a format, as the imager's saveImage would.
	 * @param \GdImage $image the image.
	 * @param string $relative the target path; the extension selects the format.
	 * @return string the file path.
	 */
	protected function writeTarget(\GdImage $image, string $relative): string
	{
		return $this->writeImage($relative, 0, 0, $image);
	}

	public function testMetaInterpolationMode(): void
	{
		$metaData = new TAssetImageMetaData();
		self::assertSame(TInterpolationImagerMode::BilinearFixed, $metaData->getMetaInterpolationMode());
		$metaData->setMetaInterpolationMode('bicubic');
		self::assertSame(TInterpolationImagerMode::Bicubic, $metaData->getMetaInterpolationMode());
		$metaData->setMetaInterpolationMode((string) TInterpolationImagerMode::NearestNeighbour);
		self::assertSame(TInterpolationImagerMode::NearestNeighbour, $metaData->getMetaInterpolationMode());

		$this->expectException(TInvalidDataValueException::class);
		$metaData->setMetaInterpolationMode('NotAMode');
	}

	public function testMetaInterpolationModeRejectsAnUnknownNumber(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageMetaData())->setMetaInterpolationMode(99999);
	}

	public function testMetaDataPreserve(): void
	{
		$metaData = new TAssetImageMetaData();
		self::assertSame([TAssetImageMetaDataMode::All], $metaData->getMetaDataPreserve());
		self::assertTrue($metaData->getIsPreserved(TAssetImageMetaDataMode::EXIF));

		$metaData->setMetaDataPreserve('exif, Color');
		self::assertSame([TAssetImageMetaDataMode::EXIF, TAssetImageMetaDataMode::Color], $metaData->getMetaDataPreserve());
		self::assertTrue($metaData->getIsPreserved(TAssetImageMetaDataMode::EXIF));
		self::assertTrue($metaData->getIsPreserved(TAssetImageMetaDataMode::Color));
		self::assertFalse($metaData->getIsPreserved(TAssetImageMetaDataMode::IPTC));

		$metaData->setMetaDataPreserve('');
		self::assertSame([TAssetImageMetaDataMode::None], $metaData->getMetaDataPreserve());
		$metaData->setMetaDataPreserve(['All', 'None']);
		self::assertFalse($metaData->getIsPreserved(TAssetImageMetaDataMode::XMP), 'None wins.');

		$this->expectException(TInvalidDataValueException::class);
		$metaData->setMetaDataPreserve('EXIF, Bogus');
	}

	public function testScrub(): void
	{
		$metaData = new TAssetImageMetaData();
		self::assertSame(0, $metaData->getScrub());
		$metaData->setScrub('Location, author');
		self::assertSame(TPrivacyCategory::Location | TPrivacyCategory::Author, $metaData->getScrub());
		$metaData->setScrub('All');
		self::assertSame(TPrivacyCategory::All, $metaData->getScrub());
		$metaData->setScrub('None');
		self::assertSame(0, $metaData->getScrub());
		$metaData->setScrub(TPrivacyCategory::Identity);
		self::assertSame(TPrivacyCategory::Identity, $metaData->getScrub());

		$this->expectException(TInvalidDataValueException::class);
		$metaData->setScrub('Location, Nowhere');
	}

	public function testJFIFMode(): void
	{
		$metaData = new TAssetImageMetaData();
		self::assertSame(TJFIFFormat::JFIF, $metaData->getJFIFMode());
		$metaData->setJFIFMode('jfxxjpeg');
		self::assertSame(TJFIFFormat::JFXXJPEG, $metaData->getJFIFMode());
		$metaData->setJFIFMode(TJFIFFormat::Thumbnail);
		self::assertSame(TJFIFFormat::Thumbnail, $metaData->getJFIFMode());
		$metaData->setJFIFMode((string) TJFIFFormat::None);
		self::assertSame(TJFIFFormat::None, $metaData->getJFIFMode());

		$this->expectException(TInvalidDataValueException::class);
		$metaData->setJFIFMode('JFXXBogus');
	}

	public function testJFIFModeRejectsAnUnknownNumber(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageMetaData())->setJFIFMode(12345);
	}

	public function testSizesAndQualities(): void
	{
		$metaData = new TAssetImageMetaData();
		$metaData->setJFIFSize('100 x 50');
		self::assertSame('100 x 50', $metaData->getJFIFSize());
		$metaData->setExifThumbnailSize('64');
		self::assertSame('64', $metaData->getExifThumbnailSize());
		$metaData->setJFXXJpegQuality(150);
		self::assertSame(100, $metaData->getJFXXJpegQuality());
		$metaData->setExifThumbnailJpegQuality(-5);
		self::assertSame(0, $metaData->getExifThumbnailJpegQuality());
		$metaData->setJFIFCropFill('false');
		self::assertFalse($metaData->getJFIFCropFill());
		$metaData->setExifThumbnailCropFill('false');
		self::assertFalse($metaData->getExifThumbnailCropFill());
		$metaData->setExifThumbnail('true');
		self::assertTrue($metaData->getExifThumbnail());
		$metaData->setIPTCPublishEncoding('Preserve');
		self::assertSame('Preserve', $metaData->getIPTCPublishEncoding());

		self::assertSame([100, 50], TAssetImageMetaData::parseSize('100x50', 'k'));
		self::assertSame([64, 64], TAssetImageMetaData::parseSize('64', 'k'));
		self::assertSame([3, 4], TAssetImageMetaData::parseSize(' 3*4 ', 'k'));
	}

	public function testJFIFSizeTooLarge(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageMetaData())->setJFIFSize('256x10');
	}

	public function testMalformedSizes(): void
	{
		foreach (['', 'wide', '0x10', '10x0', '-4', '1.5x2'] as $size) {
			try {
				TAssetImageMetaData::parseSize($size, 'metadata_bad_jfif_size');
				self::fail("'$size' is rejected.");
			} catch (TInvalidDataValueException $e) {
				self::assertStringContainsString('metadata_bad_jfif_size', $e->getErrorCode());
			}
		}
	}

	public function testReadsTheCarriersOfAJpeg(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg());

		self::assertSame(IMAGETYPE_JPEG, $metaData->getImageType());
		self::assertInstanceOf(TJPEG::class, $metaData->getImageFile());
		self::assertSame('Ann Author', $metaData->getEXIF()->getValueByName('Artist'));
		self::assertSame(['portfolio', 'sunset'], $metaData->getIPTC()['Keywords']);
		self::assertSame('A Title', $metaData->getXMP()->getTitle());
		self::assertSame(static::iccProfile(), $metaData->getICCProfile());
		self::assertTrue($metaData->hasMetaData());
		self::assertSame($metaData->getEXIF(), $metaData->getEXIF(), 'A carrier is read once and edited in place.');
		self::assertFalse($metaData->getChanged());
	}

	public function testANonImageAndAnUnreadableFormat(): void
	{
		$text = $this->newMetaData($this->writeSource('a.txt', 'not an image'));
		self::assertFalse($text->getImageType());
		self::assertNull($text->getImageFile());
		self::assertNull($text->getEXIF());
		self::assertFalse($text->hasMetaData());

		$bmp = $this->newMetaData($this->writeImage('a.bmp'));
		self::assertSame(IMAGETYPE_BMP, $bmp->getImageType());
		self::assertNull($bmp->getImageFile(), 'prado-image does not read BMP.');

		$missing = $this->newMetaData($this->srcDir . '/missing.jpg');
		self::assertFalse($missing->getImageType());
		self::assertNull($missing->getImageFile());
	}

	public function testSettingTheSourceDiscardsLoadedMetaData(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg());
		$metaData->getEXIF();
		$metaData->setChanged(true);
		$metaData->setMetaDataSource($this->writeImage('plain.png'));

		self::assertNull($metaData->getEXIF());
		self::assertFalse($metaData->getChanged());
		self::assertSame(IMAGETYPE_PNG, $metaData->getImageType());
	}

	public function testOrientationFromExif(): void
	{
		foreach (range(1, 8) as $orientation) {
			$metaData = $this->newMetaData($this->writeMetaJpeg("o$orientation.jpg", ['orientation' => $orientation]));
			self::assertSame($orientation, $metaData->getOrientation());
		}
		self::assertSame(1, $this->newMetaData($this->writeImage('plain.jpg'))->getOrientation());
	}

	public function testOrientationFromIptcRotation(): void
	{
		foreach ([0 => 1, 1 => 6, 2 => 3, 3 => 8] as $rotation => $orientation) {
			$path = $this->writeMetaJpeg("r$rotation.jpg", ['iptcRotation' => $rotation]);
			$metaData = $this->newMetaData($path);
			$metaData->setEXIF(null);
			self::assertSame($orientation, $metaData->getOrientation(), "IPTC rotation $rotation.");
		}
	}

	public function testResetOrientation(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg('o.jpg', ['orientation' => 6, 'iptcRotation' => 1]));
		$metaData->resetOrientation();

		self::assertSame(1, $metaData->getOrientation());
		self::assertFalse($metaData->getIPTC()->contains(TIPTCTags::IPTCImageRotation));
		self::assertTrue($metaData->getChanged());

		$metaData->resetOrientation(null);
		self::assertNull($metaData->getEXIF()->getIfd0()->getTagValue(TAssetImageMetaData::ORIENTATION_TAG));
	}

	public function testResolveField(): void
	{
		self::assertSame(['IPTC', '2#025'], TAssetImageMetaData::resolveField('Keywords'));
		self::assertSame(['IPTC', '2#116'], TAssetImageMetaData::resolveField('2#116'));
		self::assertSame(['EXIF', 'Artist'], TAssetImageMetaData::resolveField('Artist'));
		self::assertSame(['EXIF', 'Copyright'], TAssetImageMetaData::resolveField('exif:Copyright'));
		self::assertSame(['IPTC', '2#025'], TAssetImageMetaData::resolveField('IPTC:Keywords'));
		self::assertSame(['XMP', 'dc:title'], TAssetImageMetaData::resolveField('XMP:dc:title'));
		self::assertNull(TAssetImageMetaData::resolveField('NotAField'));
		self::assertNull(TAssetImageMetaData::resolveField('XMP:title'));
		self::assertNull(TAssetImageMetaData::resolveField('EXIF:Keywords'));
		self::assertNull(TAssetImageMetaData::resolveField('IPTC:Artist'));
	}

	public function testGetAndSetMetaData(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg());

		self::assertSame(['portfolio', 'sunset'], $metaData->getMetaData('Keywords'));
		self::assertSame('Ann Author', $metaData->getMetaData('Artist'));
		self::assertSame('Camera X', $metaData->getMetaData('EXIF:Model'));
		self::assertNull($metaData->getMetaData('NotAField'));

		$metaData->setMetaData('Keywords', ['travel'], true);
		self::assertSame(['portfolio', 'sunset', 'travel'], $metaData->getMetaData('Keywords'));
		$metaData->setMetaData('Keywords', 'only');
		self::assertSame(['only'], (array) $metaData->getMetaData('Keywords'));
		$metaData->setMetaData('Copyright', null);
		self::assertNull($metaData->getMetaData('Copyright'));
		$metaData->setMetaData('EXIF:Artist', 'Bob');
		self::assertSame('Bob', $metaData->getMetaData('Artist'));
		$metaData->setMetaData('XMP:dc:rights', 'All rights reserved');
		self::assertSame('All rights reserved', $metaData->getXMP()->getRights());
		self::assertTrue($metaData->getChanged());
	}

	public function testSetMetaDataCreatesMissingCarriers(): void
	{
		$metaData = $this->newMetaData($this->writeImage('plain.png'));
		self::assertNull($metaData->getIPTC());

		$metaData->setMetaData('Keywords', ['new']);
		$metaData->setMetaData('Artist', 'Someone');
		$metaData->setMetaData('XMP:dc:subject', ['a', 'b'], true);

		self::assertSame(['new'], (array) $metaData->getIPTC()['Keywords']);
		self::assertSame('Someone', $metaData->getEXIF()->getValueByName('Artist'));
		self::assertSame(['a', 'b'], $metaData->getXMP()->getKeywords());
	}

	public function testSetMetaDataRejectsAnUnknownField(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageMetaData())->setMetaData('NotAField', 'x');
	}

	public function testMatch(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg());

		self::assertTrue($metaData->match('Keywords'));
		self::assertFalse($metaData->match('Headline'));
		self::assertTrue($metaData->match('Keywords=portfolio'));
		self::assertTrue($metaData->match('Keywords=^sun'), 'A value matches as a regular expression.');
		self::assertFalse($metaData->match('Keywords=winter'));
		self::assertTrue($metaData->match('Keywords==sunset'));
		self::assertFalse($metaData->match('Keywords==sun'));
		self::assertTrue($metaData->match('Keywords!=winter'));
		self::assertFalse($metaData->match('Keywords!=sunset'), 'A != must hold for every value.');
		self::assertTrue($metaData->match('Keywords!==winter'));
		self::assertTrue($metaData->match('Artist=ann'));
		self::assertTrue($metaData->match('EXIF:Model == "Camera X"'));
		self::assertTrue($metaData->match('Copyright > Ann'));
		self::assertTrue($metaData->match('Copyright >= Ann Author 2026'));
		self::assertTrue($metaData->match('Copyright < Bob'));
		self::assertTrue($metaData->match('Copyright <= Ann Author 2026'));
		self::assertTrue($metaData->match('Key*=sunset'), 'An unquoted * matches IPTC field names.');
		self::assertFalse($metaData->match('"Key*"=sunset'), 'A quoted name is literal.');
		self::assertFalse($metaData->match('NotAField=x'));
		self::assertTrue($metaData->match('Keywords=a/b|portfolio'), 'A slash in the pattern is escaped.');
		self::assertFalse($metaData->match('Keywords=(unclosed'), 'An invalid pattern does not match.');
	}

	public function testParseMatchString(): void
	{
		self::assertSame(['operator' => '+', 'value' => null, 'fields' => ['Keywords']], TAssetImageMetaData::parseMatchString('Keywords'));
		self::assertSame(['operator' => '<=', 'value' => '5', 'fields' => ['Urgency']], TAssetImageMetaData::parseMatchString(' Urgency <= "5" '));
		self::assertSame(['operator' => '=', 'value' => 'x', 'fields' => []], TAssetImageMetaData::parseMatchString('Bogus=x'));
	}

	public function testWriteMetaDataIntoAResizedJpeg(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg('photo.jpg', ['pixelDimensions' => true]));
		$image = imagescale(static::createImage(40, 20), 20, 10);
		$target = $this->writeTarget($image, 'out/photo.jpg');

		self::assertTrue($metaData->writeMetaData($target, $image, true));

		$published = TImageFile::fromFile($target);
		self::assertSame('Ann Author', $published->getEXIF()->getValueByName('Artist'));
		self::assertSame(20, $published->getEXIF()->getValueByName('PixelXDimension'));
		self::assertSame(10, $published->getEXIF()->getValueByName('PixelYDimension'));
		self::assertSame(20, $published->getIPTC()[TIPTCTags::IPTCImageWidth]);
		self::assertSame(10, $published->getIPTC()[TIPTCTags::IPTCImageHeight]);
		self::assertSame(['portfolio', 'sunset'], $published->getIPTC()['Keywords']);
		self::assertSame('UTF-8', $published->getIPTC()[TIPTCTags::CodedCharacterSet]);
		self::assertSame('A Title', $published->getXMP()->getTitle());
		self::assertSame(static::iccProfile(), $published->getICCProfile());
		self::assertNotNull($published->getJFIF(), 'The default JFIF mode writes the JFIF header.');
		self::assertFalse($published->getJFIF()->hasImage());
		self::assertNull($published->getJFXX());
	}

	public function testPreserveDropsUnkeptCarriers(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg(), ['MetaDataPreserve' => 'IPTC']);
		$image = static::createImage();
		$target = $this->writeTarget($image, 'out/photo.jpg');

		$metaData->writeMetaData($target, $image, true);

		$published = TImageFile::fromFile($target);
		self::assertNull($published->getEXIF());
		self::assertNull($published->getXMP());
		self::assertNull($published->getICCProfile());
		self::assertNotNull($published->getIPTC());
	}

	public function testScrubRemovesPrivateData(): void
	{
		$metaData = $this->newMetaData($this->writeMetaJpeg('photo.jpg', ['gps' => true]), ['Scrub' => 'Location, Author']);
		$image = static::createImage();
		$target = $this->writeTarget($image, 'out/photo.jpg');

		$metaData->writeMetaData($target, $image, true);

		$exif = TImageFile::fromFile($target)->getEXIF();
		self::assertNull($exif->getLatitude());
		self::assertNull($exif->getValueByName('Artist'));
		self::assertSame('Camera X', $exif->getValueByName('Model'), 'Unscrubbed categories are kept.');
	}

	public function testJFIFThumbnailModes(): void
	{
		$source = $this->writeMetaJpeg();
		$image = static::createImage(40, 20);

		$metaData = $this->newMetaData($source, ['JFIFMode' => 'Thumbnail', 'JFIFSize' => '16x16', 'JFIFCropFill' => true]);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'thumb.jpg'), $image, true);
		$jfif = TImageFile::fromFile($target)->getJFIF();
		self::assertSame([16, 16], [$jfif->getXThumbnail(), $jfif->getYThumbnail()]);

		$metaData = $this->newMetaData($source, ['JFIFMode' => 'Thumbnail', 'JFIFSize' => '16x16', 'JFIFCropFill' => false]);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'fit.jpg'), $image, true);
		$jfif = TImageFile::fromFile($target)->getJFIF();
		self::assertSame([16, 8], [$jfif->getXThumbnail(), $jfif->getYThumbnail()], 'A fitted thumbnail keeps the aspect ratio.');

		$metaData = $this->newMetaData($source, ['JFIFMode' => 'JFXXJPEG', 'JFIFSize' => '16x8']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'jfxx.jpg'), $image, true);
		$published = TImageFile::fromFile($target);
		self::assertNotNull($published->getJFXX());
		self::assertTrue($published->getJFXX()->hasImage());
		self::assertFalse($published->getJFIF()->hasImage());

		$metaData = $this->newMetaData($source, ['JFIFMode' => 'None']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'none.jpg'), $image, true);
		self::assertNull(TImageFile::fromFile($target)->getJFIF());
	}

	public function testExifThumbnailIsRegeneratedOrGenerated(): void
	{
		$image = imagescale(static::createImage(40, 20), 20, 10);

		$metaData = $this->newMetaData($this->writeMetaJpeg('with.jpg', ['thumbnail' => true]), ['ExifThumbnailSize' => '10x5']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'with-out.jpg'), $image, true);
		$thumbnail = TImageFile::fromFile($target)->getEXIF()->getThumbnail();
		self::assertNotNull($thumbnail);
		self::assertSame([10, 5], array_slice(getimagesizefromstring($thumbnail), 0, 2));

		$metaData = $this->newMetaData($this->writeMetaJpeg('without.jpg'));
		$metaData->writeMetaData($target = $this->writeTarget($image, 'without-out.jpg'), $image, true);
		self::assertNull(TImageFile::fromFile($target)->getEXIF()->getThumbnail(), 'No thumbnail is added by default.');

		$metaData = $this->newMetaData($this->writeMetaJpeg('forced.jpg'), ['ExifThumbnail' => true, 'ExifThumbnailSize' => '8']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'forced-out.jpg'), $image, true);
		self::assertSame([8, 8], array_slice(getimagesizefromstring(TImageFile::fromFile($target)->getEXIF()->getThumbnail()), 0, 2));
	}

	public function testIPTCPublishEncoding(): void
	{
		$image = static::createImage();

		$metaData = $this->newMetaData($this->writeMetaJpeg(), ['IPTCPublishEncoding' => 'None']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'none.jpg'), $image, true);
		self::assertFalse(TImageFile::fromFile($target)->getIPTC()->contains(TIPTCTags::CodedCharacterSet));

		$source = $this->writeMetaJpeg('preserve.jpg');
		$original = TImageFile::fromFile($source)->getIPTC()->contains(TIPTCTags::CodedCharacterSet);
		$metaData = $this->newMetaData($source, ['IPTCPublishEncoding' => 'Preserve']);
		$metaData->writeMetaData($target = $this->writeTarget($image, 'preserve-out.jpg'), $image, true);
		self::assertSame($original, TImageFile::fromFile($target)->getIPTC()->contains(TIPTCTags::CodedCharacterSet));
	}

	public function testWriteIntoFormatsWithFewerCarriers(): void
	{
		$image = static::createImage();

		$metaData = $this->newMetaData($this->writeMetaJpeg());
		self::assertTrue($metaData->writeMetaData($target = $this->writeTarget($image, 'photo.png'), $image, true));
		$png = TImageFile::fromFile($target);
		self::assertInstanceOf(TPNG::class, $png);
		self::assertSame('Ann Author', $png->getEXIF()->getValueByName('Artist'));
		self::assertSame(['portfolio', 'sunset'], $png->getIPTC()['Keywords']);
		self::assertSame('A Title', $png->getXMP()->getTitle());

		$metaData = $this->newMetaData($this->writeMetaJpeg('gif-source.jpg'));
		self::assertTrue($metaData->writeMetaData($target = $this->writeTarget($image, 'photo.gif'), $image, true));
		$gif = TImageFile::fromFile($target);
		self::assertInstanceOf(TGIF::class, $gif);
		self::assertNull($gif->getEXIF(), 'GIF has no EXIF carrier; it is dropped.');
		self::assertNull($gif->getIPTC(), 'GIF has no IPTC carrier; it is dropped.');
		self::assertSame('A Title', $gif->getXMP()->getTitle());

		$metaData = $this->newMetaData($this->writeMetaJpeg('bmp-source.jpg'));
		self::assertFalse($metaData->writeMetaData($this->writeTarget($image, 'photo.bmp'), $image, true), 'An unreadable format is not written.');
	}

	public function testMetaDataOnlyRewriteKeepsTheEncodedImage(): void
	{
		$source = $this->writeMetaJpeg();
		$metaData = $this->newMetaData($source, ['IPTCPublishEncoding' => 'Preserve']);
		self::assertFalse($metaData->getNeedsWrite(), 'Nothing to write for an unchanged image.');

		$metaData->setMetaData('Keywords', ['rewritten']);
		self::assertTrue($metaData->getNeedsWrite());
		$target = $this->tempDir . '/copy.jpg';
		copy($source, $target);
		$scan = TImageFile::fromFile($target)->getScan();

		self::assertTrue($metaData->writeMetaData($target, null, false));

		$published = TImageFile::fromFile($target);
		self::assertSame($scan, $published->getScan(), 'The entropy-coded image data is unchanged.');
		self::assertSame(['rewritten'], (array) $published->getIPTC()['Keywords']);
	}

	public function testNeedsWriteWhenACarrierIsDroppedOrScrubbed(): void
	{
		$source = $this->writeMetaJpeg();
		self::assertTrue($this->newMetaData($source, ['MetaDataPreserve' => 'EXIF'])->getNeedsWrite());
		self::assertTrue($this->newMetaData($source, ['Scrub' => 'Location'])->getNeedsWrite());
		self::assertFalse($this->newMetaData($this->writeImage('plain.jpg'), ['MetaDataPreserve' => 'None'])->getNeedsWrite(), 'Nothing to drop.');
	}

	public function testCreateThumbnail(): void
	{
		$metaData = new TAssetImageMetaData();
		$image = static::createImage(40, 20);

		$crop = $metaData->createThumbnail($image, 10, 10, true);
		self::assertSame([10, 10], [imagesx($crop), imagesy($crop)]);
		$fit = $metaData->createThumbnail($image, 10, 10, false);
		self::assertSame([10, 5], [imagesx($fit), imagesy($fit)]);
		$tall = $metaData->createThumbnail(static::createImage(20, 40), 10, 10, false);
		self::assertSame([5, 10], [imagesx($tall), imagesy($tall)]);
	}

	public function testCarriesMetaData(): void
	{
		foreach ([IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM] as $type) {
			self::assertTrue(TAssetImageMetaData::carriesMetaData($type), "The type $type carries metadata.");
		}
		foreach ([IMAGETYPE_BMP, IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_PSD] as $type) {
			self::assertFalse(TAssetImageMetaData::carriesMetaData($type), "The type $type has no prado-image container.");
		}
	}

	public function testWriteImageBytes(): void
	{
		$source = $this->writeSource('source.jpg', '');
		$jpeg = TJPEG::fromImage(static::createImage(40, 20), 90);
		$iptc = new TIPTC();
		$iptc['Keywords'] = ['portfolio'];
		$jpeg->setIPTC($iptc);
		$jpeg->save($source);
		$metaData = new TAssetImageMetaData();
		$metaData->setMetaDataSource($source);

		$encoded = (string) TPNG::fromImage($image = static::createImage(20, 10));
		$written = $metaData->writeImageBytes($encoded, $image, true);

		self::assertNotSame($encoded, $written);
		$published = TImageFile::fromString($written);
		self::assertInstanceOf(TPNG::class, $published, 'The encoded image keeps its format.');
		self::assertSame([20, 10], [$published->getWidth(), $published->getHeight()], 'The encoded pixels are kept.');
		self::assertSame(['portfolio'], (array) $published->getIPTC()[TIPTCTags::Keywords]);
	}

	public function testWriteImageBytesOfAFormatWithoutAContainer(): void
	{
		$metaData = new TAssetImageMetaData();
		$metaData->setMetaDataSource($this->writeImage('plain.png'));
		ob_start();
		imagebmp(static::createImage(8, 4));
		$bmp = (string) ob_get_clean();

		self::assertSame($bmp, $metaData->writeImageBytes($bmp), 'A BMP publishes without metadata.');
		self::assertSame('not an image', $metaData->writeImageBytes('not an image'));
	}

	public function testCreateThumbnailFromAnImagickImage(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$imagick = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(40, 20)), TImageGraphicsMode::Imagick);

		$thumbnail = (new TAssetImageMetaData())->createThumbnail($imagick, 20, 10, true);

		self::assertInstanceOf(\GdImage::class, $thumbnail, 'The thumbnail is scaled with GD.');
		self::assertSame([20, 10], [imagesx($thumbnail), imagesy($thumbnail)]);
	}
}
