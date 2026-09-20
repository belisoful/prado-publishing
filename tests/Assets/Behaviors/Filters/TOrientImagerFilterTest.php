<?php

/**
 * TOrientImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\Web\Assets\Behaviors\Filters\TOrientImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TFixedOrientationMetaData;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TOrientImagerFilterTest class.
 *
 * Tests the orientation filter: each EXIF orientation, the IPTC rotation, and the
 * IPTC-only flips turn the image upright and reset the recorded orientation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TOrientImagerFilterTest extends PublishingTestCase
{
	/**
	 * Writes a 40x20 JPEG with an orientation.
	 * @param string $relative the source path.
	 * @param ?int $orientation the EXIF orientation, or null for none.
	 * @param ?int $iptcRotation the IPTC image rotation, or null for none.
	 * @return string the file path.
	 */
	protected function writeOrientedJpeg(string $relative, ?int $orientation, ?int $iptcRotation = null): string
	{
		$jpeg = TJPEG::fromImage(static::createImage(40, 20), 95);
		if ($orientation !== null) {
			$exif = new TEXIF();
			$exif->getIfd0()->setTagValues(TAssetImageMetaData::ORIENTATION_TAG, TTIFFDataType::UShort, [$orientation]);
			$jpeg->setEXIF($exif);
		}
		if ($iptcRotation !== null) {
			$iptc = new TIPTC();
			$iptc[TIPTCTags::IPTCImageRotation] = $iptcRotation;
			$jpeg->setIPTC($iptc);
		}
		$path = $this->writeSource($relative, '');
		$jpeg->save($path);
		return $path;
	}

	/**
	 * @param string $path the image path.
	 * @param ?TAssetImageMetaData $metaData the metadata, default one reading the path.
	 * @return TAssetEventParameter the publishing parameter of an asset, with its metadata.
	 */
	protected static function newParam(string $path, ?TAssetImageMetaData $metaData = null): TAssetEventParameter
	{
		if (!$metaData) {
			$metaData = new TAssetImageMetaData();
			$metaData->setMetaDataSource($path);
		}
		$param = new TAssetEventParameter('onProcessAsset', '', new TImageAsset($path));
		$param->setImageMetaData($metaData);
		return $param;
	}

	public function testNothingToOrient(): void
	{
		$filter = new TOrientImagerFilter();
		$image = static::createImage();
		$param = static::newParam($this->writeOrientedJpeg('o6.jpg', 6));

		$none = null;
		self::assertNull($filter->filterImage($none, $param));
		self::assertNull($filter->filterImage($image));
		self::assertNull($filter->filterImage($image, new TAssetEventParameter('onProcessAsset', '', new TImageAsset($this->writeImage('plain.png')))), 'An image without metadata is not oriented.');
		self::assertSame(40, imagesx($image));
	}

	public function testUprightImageIsUnchanged(): void
	{
		foreach (['o1.jpg' => 1, 'none.jpg' => null] as $file => $orientation) {
			$param = static::newParam($this->writeOrientedJpeg($file, $orientation));
			$image = static::createImage();

			self::assertFalse((new TOrientImagerFilter())->filterImage($image, $param), $file);
			self::assertFalse($param->getImageMetaData()->getChanged(), $file);
		}
	}

	public static function orientationProvider(): array
	{
		// The upright size and a point inside the source's top-left mark once upright.
		return [
			'mirrored' => [2, [40, 20], [35, 5]],
			'rotated 180' => [3, [40, 20], [35, 15]],
			'flipped' => [4, [40, 20], [5, 15]],
			'transposed' => [5, [20, 40], [5, 5]],
			'rotated 90 clockwise' => [6, [20, 40], [15, 5]],
			'transversed' => [7, [20, 40], [15, 35]],
			'rotated 90 counter-clockwise' => [8, [20, 40], [5, 35]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('orientationProvider')]
	public function testExifOrientation(int $orientation, array $size, array $mark): void
	{
		$param = static::newParam($this->writeOrientedJpeg("o$orientation.jpg", $orientation));
		$metaData = $param->getImageMetaData();
		$image = static::createImage(40, 20);

		self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param));

		self::assertSame($size, [imagesx($image), imagesy($image)]);
		self::assertSame(0xFF0000, static::rgbAt($image, ...$mark), 'The mark is at the upright top-left.');
		self::assertSame(1, $metaData->getOrientation(), 'The upright orientation is recorded.');
		self::assertTrue($metaData->getChanged());
	}

	public function testIptcRotation(): void
	{
		$param = static::newParam($this->writeOrientedJpeg('r1.jpg', null, 1));
		$metaData = $param->getImageMetaData();
		$image = static::createImage(40, 20);

		self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param));

		self::assertSame([20, 40], [imagesx($image), imagesy($image)]);
		self::assertSame(0xFF0000, static::rgbAt($image, 15, 5));
		self::assertFalse($metaData->getIPTC()->contains(TIPTCTags::IPTCImageRotation), 'The IPTC rotation is removed.');
		self::assertSame(1, $metaData->getOrientation());
	}

	public function testIptcOnlyFlips(): void
	{
		foreach ([9 => [5, 5], 10 => [15, 35]] as $orientation => $mark) {
			$metaData = new TFixedOrientationMetaData();
			$metaData->orientation = $orientation;
			$param = static::newParam($this->writeImage("f$orientation.png"), $metaData);
			$image = static::createImage(40, 20);

			self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param), "Orientation $orientation.");
			self::assertSame([20, 40], [imagesx($image), imagesy($image)], "Orientation $orientation.");
			self::assertSame(0xFF0000, static::rgbAt($image, ...$mark), "Orientation $orientation mark.");
			self::assertSame([1], $metaData->resets);
		}
	}

	public function testTheTransparentColorIsKept(): void
	{
		$param = static::newParam($this->writeOrientedJpeg('o8.jpg', 8));
		$image = static::createImage(40, 20);
		imagecolortransparent($image, 0x3366CC);

		self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param));
		self::assertSame(0x3366CC, imagecolortransparent($image));
	}

	/**
	 * @param int $width the width.
	 * @param int $height the height.
	 * @return \Imagick the marked image of {@see createImage}, in Imagick.
	 */
	protected static function createImagick(int $width = 40, int $height = 20): \Imagick
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		return TImageGraphics::decode((string) TPNG::fromImage(static::createImage($width, $height)), TImageGraphicsMode::Imagick);
	}

	/**
	 * @param \Imagick $image the image.
	 * @return \GdImage the image converted into GD, to read its pixels.
	 */
	protected static function toGd(\Imagick $image): \GdImage
	{
		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $gd);
		return $gd;
	}

	public function testImagickNothingToOrient(): void
	{
		$filter = new TOrientImagerFilter();
		$image = static::createImagick();

		self::assertNull($filter->filterImage($image), 'An image without metadata is not oriented.');
		self::assertFalse($filter->filterImage($image, static::newParam($this->writeOrientedJpeg('io1.jpg', 1))), 'An upright image is unchanged.');
		self::assertSame([40, 20], [$image->getImageWidth(), $image->getImageHeight()]);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('orientationProvider')]
	public function testImagickExifOrientation(int $orientation, array $size, array $mark): void
	{
		$image = static::createImagick();
		$param = static::newParam($this->writeOrientedJpeg("io$orientation.jpg", $orientation));
		$metaData = $param->getImageMetaData();

		self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param));

		$gd = static::toGd($image);
		self::assertSame($size, [imagesx($gd), imagesy($gd)], 'Imagick orients as GD does.');
		self::assertColorNear(0xFF0000, static::rgbAt($gd, ...$mark), 8, 'The mark is at the upright top-left.');
		self::assertSame(1, $metaData->getOrientation(), 'The upright orientation is recorded.');
		self::assertTrue($metaData->getChanged());
	}

	public function testImagickIptcOnlyFlips(): void
	{
		foreach ([9 => [5, 5], 10 => [15, 35]] as $orientation => $mark) {
			$image = static::createImagick();
			$metaData = new TFixedOrientationMetaData();
			$metaData->orientation = $orientation;
			$param = static::newParam($this->writeImage("if$orientation.png"), $metaData);

			self::assertTrue((new TOrientImagerFilter())->filterImage($image, $param), "Orientation $orientation.");

			$gd = static::toGd($image);
			self::assertSame([20, 40], [imagesx($gd), imagesy($gd)], "Orientation $orientation.");
			self::assertColorNear(0xFF0000, static::rgbAt($gd, ...$mark), 8, "Orientation $orientation mark.");
			self::assertSame([1], $metaData->resets);
		}
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = static::newParam($this->writeOrientedJpeg('o3.jpg', 3));
		$param->setImage(static::createImage(40, 20));

		self::assertTrue(static::applyFilter(new TOrientImagerFilter(), $param));
		self::assertSame(0xFF0000, static::rgbAt($param->getImage(), 35, 15));
		self::assertTrue($param->getSaveImage());
	}
}
