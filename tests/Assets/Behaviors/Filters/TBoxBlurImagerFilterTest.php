<?php

/**
 * TBoxBlurImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Web\Assets\Behaviors\Filters\TBoxBlurImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TBoxBlurImagerFilterTest class.
 *
 * Tests the box blur filter: its blur properties, the centered and wrapping moving
 * average, rounding, alpha, a box larger than the image, and bounded memory.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TBoxBlurImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param \GdImage $image the image.
	 * @param bool $vertical read the first column instead of the first row.
	 * @return int[] the blue channel of the first row or column.
	 */
	protected static function line(\GdImage $image, bool $vertical = false): array
	{
		$values = [];
		$count = $vertical ? imagesy($image) : imagesx($image);
		for ($i = 0; $i < $count; $i++) {
			$values[] = imagecolorat($image, $vertical ? 0 : $i, $vertical ? $i : 0) & 0xFF;
		}
		return $values;
	}

	public function testBlurProperties(): void
	{
		$filter = new TBoxBlurImagerFilter();
		self::assertSame(5.0, $filter->getBlurX());
		self::assertSame(5.0, $filter->getBlurY());

		$filter->setBlurX('2.5');
		$filter->setBlurY('7');
		self::assertSame(2.5, $filter->getBlurX());
		self::assertSame(7.0, $filter->getBlurY());

		$filter->setBlurX(-3);
		$filter->setBlurY('-1');
		self::assertSame(0.0, $filter->getBlurX());
		self::assertSame(0.0, $filter->getBlurY());
	}

	public function testNoImageOrAPaletteImage(): void
	{
		$filter = new TBoxBlurImagerFilter();
		$image = null;
		self::assertNull($filter->filterImage($image));
		$palette = imagecreate(4, 4);
		self::assertNull($filter->filterImage($palette));
		self::assertNull(TBoxBlurImagerFilter::imageBoxBlur(null, 1, 1));
	}

	public function testNoBlurDoesNothing(): void
	{
		$filter = new TBoxBlurImagerFilter();
		$filter->setBlurX(0.4);
		$filter->setBlurY(0);
		$image = static::createImage();

		self::assertFalse($filter->filterImage($image));
		self::assertFalse(TBoxBlurImagerFilter::imageBoxBlur($image, -2, -5));
		self::assertSame(0xFF0000, static::rgbAt($image, 0, 0));
	}

	public function testTheHorizontalAverageIsCentered(): void
	{
		// Regression: the moving average trailed the pixel and shifted the image.
		$filter = new TBoxBlurImagerFilter();
		$filter->setBlurX(4);
		$filter->setBlurY(0);
		$image = static::createImage(21, 1, 0x000000, 0x000000);
		imagesetpixel($image, 10, 0, 0xFFFFFF);

		self::assertTrue($filter->filterImage($image));
		self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 51, 51, 51, 51, 51, 0, 0, 0, 0, 0, 0, 0, 0], static::line($image));
	}

	public function testTheVerticalAverageIsCentered(): void
	{
		$filter = new TBoxBlurImagerFilter();
		$filter->setBlurX(0);
		$filter->setBlurY('4');
		$image = static::createImage(1, 21, 0x000000, 0x000000);
		imagesetpixel($image, 0, 10, 0xFFFFFF);

		self::assertTrue($filter->filterImage($image));
		self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 51, 51, 51, 51, 51, 0, 0, 0, 0, 0, 0, 0, 0], static::line($image, true));
	}

	public function testTheAverageWrapsAroundTheEdges(): void
	{
		$image = static::createImage(9, 1, 0x000000, 0x000000);
		imagesetpixel($image, 0, 0, 0xFFFFFF);

		self::assertTrue(TBoxBlurImagerFilter::imageBoxBlur($image, 2, 0));
		self::assertSame([85, 85, 0, 0, 0, 0, 0, 0, 85], static::line($image));
	}

	public function testAverageIsRounded(): void
	{
		// Regression: fractional channels were truncated with a float-to-int deprecation.
		$image = static::createImage(2, 1, 0x000000, 0x000000);
		imagesetpixel($image, 1, 0, 0x0000FF);

		self::assertTrue(TBoxBlurImagerFilter::imageBoxBlur($image, 1, 0));
		self::assertSame([128, 128], static::line($image), '255 / 2 rounds to 128.');
	}

	public function testABoxLargerThanTheImageAveragesTheWholeLine(): void
	{
		// Regression: a box wider than the image read negative pixel indexes.
		$image = static::createImage(3, 3, 0x000000, 0x000000);
		imagesetpixel($image, 1, 1, 0xFFFFFF);

		self::assertTrue(TBoxBlurImagerFilter::imageBoxBlur($image, 5, 5));
		for ($y = 0; $y < 3; $y++) {
			for ($x = 0; $x < 3; $x++) {
				self::assertSame(0x1C1C1C, static::rgbAt($image, $x, $y), "Pixel $x,$y is the average of the image.");
			}
		}
	}

	public function testBoxBlurLine(): void
	{
		$method = new \ReflectionMethod(TBoxBlurImagerFilter::class, 'boxBlurLine');
		$method->setAccessible(true);

		self::assertSame([0x10, 0xFF], $method->invoke(null, [0x10, 0xFF], 1), 'A box of one pixel is the line.');
		self::assertSame([0x40, 0x40, 0x40], $method->invoke(null, [0x00, 0x00, 0xC0], 9), 'The box is limited to the line.');
	}

	public function testAlphaIsAveraged(): void
	{
		$image = imagecreatetruecolor(2, 1);
		imagealphablending($image, false);
		imagesetpixel($image, 0, 0, 0x7F00FF00);
		imagesetpixel($image, 1, 0, 0x0000FF00);

		self::assertTrue(TBoxBlurImagerFilter::imageBoxBlur($image, 1, 0));
		self::assertSame(0x4000FF00, imagecolorat($image, 0, 0));
		self::assertSame(0x4000FF00, imagecolorat($image, 1, 0));
	}

	public function testFractionalBlurIsRounded(): void
	{
		$filter = new TBoxBlurImagerFilter();
		$filter->setBlurX(3.6);
		$filter->setBlurY(0);
		$image = static::createImage(21, 1, 0x000000, 0x000000);
		imagesetpixel($image, 10, 0, 0xFFFFFF);

		self::assertTrue($filter->filterImage($image));
		self::assertSame(51, static::line($image)[8], 'A blur of 3.6 is a box of 5 pixels.');
	}

	public function testMemoryIsBounded(): void
	{
		// Regression: the whole image was held as nested PHP arrays, twice.
		$image = static::createImage(640, 480);
		$before = memory_get_usage();

		self::assertTrue(TBoxBlurImagerFilter::imageBoxBlur($image, 20, 20));
		self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TBoxBlurImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
		self::assertNotSame(0xFF0000, static::rgbAt($param->getImage(), 19, 9), 'The mark edge is blurred.');
	}
}
