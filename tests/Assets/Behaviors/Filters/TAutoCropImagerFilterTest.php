<?php

/**
 * TAutoCropImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Web\Assets\Behaviors\Filters\TAutoCropImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TAutoCropImagerFilterMode;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAutoCropImagerFilterTest class.
 *
 * Tests the auto-crop filter: its mode, threshold, and color properties from their
 * string forms, and the crop of each mode.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAutoCropImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param int $width the width.
	 * @param int $height the height.
	 * @param int $background the background color.
	 * @return \GdImage an image with an 11x11 green box at (50, 20).
	 */
	protected static function boxImage(int $width = 200, int $height = 100, int $background = 0xFF0000): \GdImage
	{
		$image = static::createImage($width, $height, $background, $background);
		imagefilledrectangle($image, 50, 20, 60, 30, 0x00FF00);
		return $image;
	}

	public function testDefaults(): void
	{
		$filter = new TAutoCropImagerFilter();

		self::assertSame(TAutoCropImagerFilterMode::Default, $filter->getCropMode());
		self::assertNull($filter->getCropThreshold());
		self::assertSame('#00FFFFFF', $filter->getCropColor());
	}

	public function testCropMode(): void
	{
		$filter = new TAutoCropImagerFilter();

		$filter->setCropMode('sides');
		self::assertSame(TAutoCropImagerFilterMode::Sides, $filter->getCropMode());
		$filter->setCropMode('THRESHOLD');
		self::assertSame(TAutoCropImagerFilterMode::Threshold, $filter->getCropMode());
		$filter->setCropMode('5');
		self::assertSame(IMG_CROP_THRESHOLD, $filter->getCropMode(), 'A numeric mode is stored as an integer.');
		$filter->setCropMode(IMG_CROP_BLACK);
		self::assertSame(IMG_CROP_BLACK, $filter->getCropMode());
	}

	public function testCropModeRejectsAnUnknownNumber(): void
	{
		try {
			(new TAutoCropImagerFilter())->setCropMode('7');
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('autocropimagerfilter_bad_crop_mode', $e->getErrorCode());
		}
	}

	public function testCropModeRejectsAnUnknownName(): void
	{
		try {
			(new TAutoCropImagerFilter())->setCropMode('Diagonal');
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('propertyvalue_enumvalue_invalid', $e->getErrorCode());
		}
	}

	public function testCropThreshold(): void
	{
		$filter = new TAutoCropImagerFilter();

		$filter->setCropThreshold('0.25');
		self::assertSame(0.25, $filter->getCropThreshold());
		$filter->setCropThreshold('');
		self::assertNull($filter->getCropThreshold());
		$filter->setCropThreshold(2);
		self::assertSame(2.0, $filter->getCropThreshold());
		$filter->setCropThreshold(null);
		self::assertNull($filter->getCropThreshold());
	}

	public function testCropColor(): void
	{
		$filter = new TAutoCropImagerFilter();

		$filter->setCropColor('#ff0000');
		self::assertSame('#00FF0000', $filter->getCropColor(), 'A web color is opaque.');
		$filter->setCropColor('White');
		self::assertSame('#00FFFFFF', $filter->getCropColor());
		$filter->setCropColor('#abc');
		self::assertSame('#00AABBCC', $filter->getCropColor());
		$filter->setCropColor('#7F112233');
		self::assertSame('#7F112233', $filter->getCropColor(), 'A color with a GD alpha is kept.');
		$filter->setCropColor('16711680');
		self::assertSame('16711680', $filter->getCropColor());
	}

	public function testCropColorRejectsAnInvalidColor(): void
	{
		foreach (['#GG0000', '#FF000000', 'NotAColor'] as $color) {
			try {
				(new TAutoCropImagerFilter())->setCropColor($color);
				self::fail("An exception was expected for $color.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('propertyvalue_invalid_hex_color', $e->getErrorCode(), $color);
			}
		}
	}

	public function testCropColorIndex(): void
	{
		$filter = new TAutoCropImagerFilter();
		$method = new \ReflectionMethod($filter, 'getCropColorIndex');
		$method->setAccessible(true);

		$filter->setCropColor('#3F102030');
		self::assertSame(0x3F102030, $method->invoke($filter));
		self::assertSame(0x3F102030, $method->invoke($filter, imagecreatetruecolor(2, 2)));
		$filter->setCropColor(0x00FF00);
		self::assertSame(0x00FF00, $method->invoke($filter));
	}

	public function testNoImageOrAPaletteImage(): void
	{
		$filter = new TAutoCropImagerFilter();
		$image = null;
		self::assertNull($filter->filterImage($image));
		$palette = imagecreate(10, 10);
		self::assertNull($filter->filterImage($palette));
		self::assertNull(TAutoCropImagerFilter::imageCropAuto($palette));
	}

	public function testThresholdMode(): void
	{
		foreach (['Threshold', '5'] as $mode) {
			$filter = new TAutoCropImagerFilter();
			$filter->setCropMode($mode);
			$filter->setCropColor('#FF0000');
			$image = static::boxImage();

			self::assertTrue($filter->filterImage($image), "Mode $mode.");
			self::assertSame([11, 11], [imagesx($image), imagesy($image)], "Mode $mode.");
			self::assertSame(0x00FF00, static::rgbAt($image, 5, 5));
		}
	}

	public function testBlackAndWhiteModes(): void
	{
		foreach (['Black' => 0x000000, 'White' => 0xFFFFFF] as $mode => $background) {
			$filter = new TAutoCropImagerFilter();
			$filter->setCropMode($mode);
			$image = static::boxImage(100, 50, $background);

			self::assertTrue($filter->filterImage($image), "Mode $mode.");
			self::assertSame([11, 11], [imagesx($image), imagesy($image)], "Mode $mode.");
		}
	}

	public function testSidesModeOnANonSquareImage(): void
	{
		// Regression: the corners were read with the width as the height.
		foreach ([[200, 100], [100, 200]] as [$width, $height]) {
			$filter = new TAutoCropImagerFilter();
			$filter->setCropMode(TAutoCropImagerFilterMode::Sides);
			$image = static::boxImage($width, $height, 0x010203);

			self::assertTrue($filter->filterImage($image), "{$width}x$height.");
			self::assertSame([11, 11], [imagesx($image), imagesy($image)], "{$width}x$height.");
		}
	}

	public function testSidesModeAveragesUnevenCorners(): void
	{
		// Regression: an uneven corner average was shifted as a float.
		$filter = new TAutoCropImagerFilter();
		$filter->setCropMode('Sides');
		$filter->setCropThreshold('5');
		$image = static::boxImage(100, 50, 0x010101);
		imagesetpixel($image, 0, 0, 0x020202);

		self::assertTrue($filter->filterImage($image));
		self::assertSame([11, 11], [imagesx($image), imagesy($image)]);
	}

	public function testDefaultModeHasNoThresholdDeprecation(): void
	{
		$filter = new TAutoCropImagerFilter();
		$image = static::boxImage();

		self::assertIsBool($filter->filterImage($image));
		self::assertInstanceOf(\GdImage::class, $image);
	}

	public function testTransparentMode(): void
	{
		$filter = new TAutoCropImagerFilter();
		$filter->setCropMode('Transparent');

		$opaque = static::boxImage();
		self::assertNull($filter->filterImage($opaque), 'An image without a transparent color is not cropped.');
		self::assertSame(200, imagesx($opaque));

		$image = static::boxImage(100, 50, 0x0000FF);
		imagecolortransparent($image, 0x0000FF);
		self::assertTrue($filter->filterImage($image));
		self::assertSame([11, 11], [imagesx($image), imagesy($image)]);
		self::assertSame(0x0000FF, imagecolortransparent($image), 'The transparent color is carried to the cropped image.');
	}

	public function testImageCropAuto(): void
	{
		$image = static::boxImage();
		self::assertNull(TAutoCropImagerFilter::imageCropAuto($image, IMG_CROP_THRESHOLD, null, -1), 'A threshold crop needs a color.');

		$uniform = static::createImage(20, 20, 0xFF0000, 0xFF0000);
		self::assertFalse(TAutoCropImagerFilter::imageCropAuto($uniform, IMG_CROP_THRESHOLD, 0.5, 0xFF0000), 'Cropping the whole image fails.');
		self::assertSame(20, imagesx($uniform));

		$image = static::boxImage();
		self::assertTrue(TAutoCropImagerFilter::imageCropAuto($image, IMG_CROP_WHITE, 0.0));
	}

	public function testHandlerFlagsTheSave(): void
	{
		$filter = new TAutoCropImagerFilter();
		$filter->setCropMode('sides');
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::boxImage());

		self::assertTrue(static::applyFilter($filter, $param));
		self::assertSame(11, imagesx($param->getImage()));
		self::assertTrue($param->getSaveImage());
	}
}
