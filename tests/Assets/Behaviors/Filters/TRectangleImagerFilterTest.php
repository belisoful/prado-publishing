<?php

/**
 * TRectangleImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPNG;
use Prado\Util\Helpers\TBitHelper;
use Prado\Web\Assets\Behaviors\Filters\TRectangleImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TRectangleImagerFilterTest class.
 *
 * Tests the filled rectangle filter: its color and corner properties from their
 * string forms, and the drawn rectangle.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRectangleImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param \GdImage $image the image.
	 * @param int $color the 0xRRGGBB color.
	 * @return ?array{0:int, 1:int, 2:int, 3:int} the inclusive [x1, x2, y1, y2] bounds
	 *   of the color, or null when absent.
	 */
	protected static function colorBounds(\GdImage $image, int $color): ?array
	{
		$x1 = $y1 = PHP_INT_MAX;
		$x2 = $y2 = -1;
		for ($y = 0, $h = imagesy($image); $y < $h; $y++) {
			for ($x = 0, $w = imagesx($image); $x < $w; $x++) {
				if (static::rgbAt($image, $x, $y) === $color) {
					[$x1, $x2, $y1, $y2] = [min($x1, $x), max($x2, $x), min($y1, $y), max($y2, $y)];
				}
			}
		}
		return $x2 < 0 ? null : [$x1, $x2, $y1, $y2];
	}

	public function testDefaults(): void
	{
		$filter = new TRectangleImagerFilter();

		self::assertSame('#00FFFFFF', $filter->getRectColor());
		self::assertSame(0.0, $filter->getRectX1());
		self::assertSame(0.0, $filter->getRectY1());
		self::assertTrue(TBitHelper::isNegativeZero($filter->getRectX2()));
		self::assertTrue(TBitHelper::isNegativeZero($filter->getRectY2()));
	}

	public function testRectColor(): void
	{
		$filter = new TRectangleImagerFilter();

		$filter->setRectColor('#00ff00');
		self::assertSame('#0000FF00', $filter->getRectColor());
		$filter->setRectColor('Blue');
		self::assertSame('#000000FF', $filter->getRectColor());
		$filter->setRectColor('#3F0000FF');
		self::assertSame('#3F0000FF', $filter->getRectColor());
		$filter->setRectColor(0x00FF00);
		self::assertSame('65280', $filter->getRectColor());

		$method = new \ReflectionMethod($filter, 'getRectColorIndex');
		$method->setAccessible(true);
		self::assertSame(0x00FF00, $method->invoke($filter));
		$filter->setRectColor('#3F102030');
		self::assertSame(0x3F102030, $method->invoke($filter));
	}

	public function testBadRectColor(): void
	{
		try {
			(new TRectangleImagerFilter())->setRectColor('#80FFFFFF');
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('propertyvalue_invalid_hex_color', $e->getErrorCode());
		}
	}

	public function testCorners(): void
	{
		$filter = new TRectangleImagerFilter();

		$filter->setRectX1('10');
		$filter->setRectY1('25%');
		$filter->setRectX2('-0');
		$filter->setRectY2(-5);
		self::assertSame(10.0, $filter->getRectX1());
		self::assertSame('25%', $filter->getRectY1());
		self::assertTrue(TBitHelper::isNegativeZero($filter->getRectX2()));
		self::assertSame(-5.0, $filter->getRectY2());

		$filter->setRectX1('-10%');
		$filter->setRectY1(3);
		$filter->setRectX2('50%');
		$filter->setRectY2('-0%');
		self::assertSame('-10%', $filter->getRectX1());
		self::assertSame(3.0, $filter->getRectY1());
		self::assertSame('50%', $filter->getRectX2());
		self::assertSame('-0%', $filter->getRectY2());
	}

	public function testNoImageOrAPaletteImage(): void
	{
		$filter = new TRectangleImagerFilter();
		$image = null;
		self::assertNull($filter->filterImage($image));
		$palette = imagecreate(4, 4);
		self::assertNull($filter->filterImage($palette));
	}

	public function testTheDefaultRectangleCoversTheImage(): void
	{
		$image = static::createImage();

		self::assertTrue((new TRectangleImagerFilter())->filterImage($image));
		self::assertSame([0, 39, 0, 19], static::colorBounds($image, 0xFFFFFF));
	}

	public function testRectangles(): void
	{
		$cases = [
			'pixels' => [['10', '5', '20', '15'], [10, 20, 5, 15]],
			'negative' => [['-20', '-10', '-0', '-5'], [79, 99, 39, 44]],
			'percent' => [['10%', '50%', '-10%', '-0%'], [10, 89, 25, 49]],
			'fractional pixels' => [['10.5', '2.4', '-10.4', '7.5'], [11, 89, 2, 8]],
			'reversed corners' => [['20', '15', '10', '5'], [10, 20, 5, 15]],
		];
		foreach ($cases as $case => [[$x1, $y1, $x2, $y2], $expected]) {
			// Regression: fractional pixel corners were passed to GD as floats.
			$filter = new TRectangleImagerFilter();
			$filter->setRectColor('#00FF00');
			$filter->setRectX1($x1);
			$filter->setRectY1($y1);
			$filter->setRectX2($x2);
			$filter->setRectY2($y2);
			$image = static::createImage(100, 50, 0xFF0000, 0xFF0000);

			self::assertTrue($filter->filterImage($image), $case);
			self::assertSame($expected, static::colorBounds($image, 0x00FF00), $case);
		}
	}

	public function testATransparentColorIsBlended(): void
	{
		$filter = new TRectangleImagerFilter();
		$filter->setRectColor('#3F0000FF');
		$image = static::createImage(10, 10, 0xFF0000, 0xFF0000);
		imagealphablending($image, false);

		self::assertTrue($filter->filterImage($image));
		self::assertColorNear(0x800080, static::rgbAt($image, 5, 5), 2);
		self::assertSame(0, (imagecolorat($image, 5, 5) >> 24) & 0x7F, 'The rectangle is blended onto the opaque image.');
	}

	/**
	 * Skips the test without Imagick.
	 * @param \GdImage $gd the image to convert.
	 * @return \Imagick the image in Imagick.
	 */
	protected static function imagickImage(\GdImage $gd): \Imagick
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		return TImageGraphics::decode((string) TPNG::fromImage($gd), TImageGraphicsMode::Imagick);
	}

	public function testTheFilterWorksInEitherLibrary(): void
	{
		self::assertNull((new TRectangleImagerFilter())->getGraphicsMode(), 'The rectangle is implemented in GD and in Imagick.');
	}

	public function testNoImagickImage(): void
	{
		$image = null;
		$method = new \ReflectionMethod(TRectangleImagerFilter::class, 'filterImagickImage');
		self::assertNull($method->invokeArgs(new TRectangleImagerFilter(), [&$image, null]));
	}

	public function testTheDefaultImagickRectangleCoversTheImage(): void
	{
		$image = static::imagickImage(static::createImage());

		self::assertTrue((new TRectangleImagerFilter())->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertSame([0, 39, 0, 19], static::colorBounds($gd, 0xFFFFFF));
	}

	public function testImagickRectangles(): void
	{
		$cases = [
			'pixels' => [['10', '5', '20', '15'], [10, 20, 5, 15]],
			'negative' => [['-20', '-10', '-0', '-5'], [79, 99, 39, 44]],
			'percent' => [['10%', '50%', '-10%', '-0%'], [10, 89, 25, 49]],
			'fractional pixels' => [['10.5', '2.4', '-10.4', '7.5'], [11, 89, 2, 8]],
			'reversed corners' => [['20', '15', '10', '5'], [10, 20, 5, 15]],
		];
		foreach ($cases as $case => [[$x1, $y1, $x2, $y2], $expected]) {
			$filter = new TRectangleImagerFilter();
			$filter->setRectColor('#00FF00');
			$filter->setRectX1($x1);
			$filter->setRectY1($y1);
			$filter->setRectX2($x2);
			$filter->setRectY2($y2);
			$image = static::imagickImage(static::createImage(100, 50, 0xFF0000, 0xFF0000));

			self::assertTrue($filter->filterImage($image), $case);

			$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
			self::assertSame($expected, static::colorBounds($gd, 0x00FF00), $case);
		}
	}

	public function testATransparentImagickColorIsBlended(): void
	{
		$filter = new TRectangleImagerFilter();
		$filter->setRectColor('#3F0000FF');
		$image = static::imagickImage(static::createImage(10, 10, 0xFF0000, 0xFF0000));

		self::assertTrue($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertColorNear(0x800080, static::rgbAt($gd, 5, 5), 2);
		self::assertSame(0, (imagecolorat($gd, 5, 5) >> 24) & 0x7F, 'The rectangle is composited onto the opaque image.');
	}

	public function testAnInvisibleImagickColorDrawsNothing(): void
	{
		$filter = new TRectangleImagerFilter();
		$filter->setRectColor('#7F0000FF');
		$image = static::imagickImage(static::createImage(10, 10, 0xFF0000, 0xFF0000));

		self::assertTrue($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertSame(0xFF0000, static::rgbAt($gd, 5, 5), 'A fully transparent color leaves the image.');
	}

	public function testAnImagickNumericColor(): void
	{
		$filter = new TRectangleImagerFilter();
		$filter->setRectColor(0x00FF00);
		$filter->setRectX1(2);
		$filter->setRectY1(2);
		$filter->setRectX2(6);
		$filter->setRectY2(6);
		$image = static::imagickImage(static::createImage(10, 10, 0xFF0000, 0xFF0000));

		self::assertTrue($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertSame([2, 6, 2, 6], static::colorBounds($gd, 0x00FF00), 'A numeric GD true color is drawn.');
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TRectangleImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
