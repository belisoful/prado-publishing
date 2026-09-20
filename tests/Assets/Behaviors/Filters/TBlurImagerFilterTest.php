<?php

/**
 * TBlurImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPNG;
use Prado\Web\Assets\Behaviors\Filters\TBlurImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TBlurImagerFilterTest class.
 *
 * Tests the repeated gaussian blur filter.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TBlurImagerFilterTest extends PublishingTestCase
{
	public function testBlurCount(): void
	{
		$filter = new TBlurImagerFilter();
		self::assertSame(3, $filter->getBlurCount());

		$filter->setBlurCount('5');
		self::assertSame(5, $filter->getBlurCount());
		$filter->setBlurCount(-2);
		self::assertSame(0, $filter->getBlurCount());
	}

	public function testNoImageOrAPaletteImage(): void
	{
		$filter = new TBlurImagerFilter();
		$image = null;
		self::assertNull($filter->filterImage($image));
		$palette = imagecreate(4, 4);
		self::assertNull($filter->filterImage($palette));
	}

	public function testZeroCountDoesNothing(): void
	{
		$filter = new TBlurImagerFilter();
		$filter->setBlurCount(0);
		$image = static::createImage(20, 20, 0x000000, 0xFFFFFF);

		self::assertFalse($filter->filterImage($image));
		self::assertSame(0xFFFFFF, static::rgbAt($image, 9, 9));
	}

	public function testBlursTheEdges(): void
	{
		$once = new TBlurImagerFilter();
		$once->setBlurCount(1);
		$thrice = new TBlurImagerFilter();
		$a = static::createImage(20, 20, 0x000000, 0xFFFFFF);
		$b = static::createImage(20, 20, 0x000000, 0xFFFFFF);

		self::assertTrue($once->filterImage($a));
		self::assertTrue($thrice->filterImage($b));

		$edgeOnce = static::rgbAt($a, 10, 5) & 0xFF;
		$edgeThrice = static::rgbAt($b, 10, 5) & 0xFF;
		self::assertGreaterThan(0, $edgeOnce, 'The white mark bleeds across its edge.');
		self::assertGreaterThan($edgeOnce, $edgeThrice, 'More passes blur further.');
		self::assertLessThan(0xFF, static::rgbAt($b, 9, 5) & 0xFF);
	}

	public function testImagickZeroCountDoesNothing(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TBlurImagerFilter();
		$filter->setBlurCount(0);
		$image = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(20, 20, 0x000000, 0xFFFFFF)), TImageGraphicsMode::Imagick);

		self::assertFalse($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertSame(0xFFFFFF, static::rgbAt($gd, 9, 9), 'The mark has a hard edge still.');
		self::assertSame(0x000000, static::rgbAt($gd, 10, 10));
	}

	public static function imagickBlurCountProvider(): array
	{
		return [
			'once' => [1],
			'thrice' => [3],
			'five times' => [5],
		];
	}

	/**
	 * @param int $count the number of blur passes.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('imagickBlurCountProvider')]
	public function testImagickBlursLikeGd(int $count): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TBlurImagerFilter();
		$filter->setBlurCount($count);
		$image = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(20, 20, 0x000000, 0xFFFFFF)), TImageGraphicsMode::Imagick);
		$reference = static::createImage(20, 20, 0x000000, 0xFFFFFF);
		for ($pass = 0; $pass < $count; $pass++) {
			imagefilter($reference, IMG_FILTER_GAUSSIAN_BLUR);
		}

		self::assertTrue($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $gd);
		self::assertSame([20, 20], [imagesx($gd), imagesy($gd)]);
		// The one Imagick blur of the equal sigma is near, not identical to, the GD passes.
		foreach ([[5, 5], [9, 5], [10, 5], [11, 5], [9, 9], [10, 10], [15, 15]] as [$x, $y]) {
			self::assertColorNear(static::rgbAt($reference, $x, $y), static::rgbAt($gd, $x, $y), 16, "The blur at ($x, $y) of $count passes.");
		}
		self::assertGreaterThan(0, static::rgbAt($gd, 10, 5) & 0xFF, 'The white mark bleeds across its edge.');
		self::assertLessThan(0xFF, static::rgbAt($gd, 9, 5) & 0xFF);
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TBlurImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
