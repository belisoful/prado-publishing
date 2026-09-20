<?php

/**
 * TFilterImagerFilterTest class file.
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
use Prado\Web\Assets\Behaviors\Filters\TFilterImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TFilterImagerFilterTest class.
 *
 * Tests the imagefilter() filter: the effect from names and numbers, the arguments of
 * each effect, the effects that do nothing, and the Imagick equivalent of each effect.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFilterImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param int|string $effect the effect.
	 * @param array $args Arg1..Arg4.
	 * @return TFilterImagerFilter the filter.
	 */
	protected static function newFilter($effect, array $args = []): TFilterImagerFilter
	{
		$filter = new TFilterImagerFilter();
		$filter->setEffect($effect);
		foreach (array_values($args) as $i => $arg) {
			$filter->{'setArg' . ($i + 1)}($arg);
		}
		return $filter;
	}

	/**
	 * @param TFilterImagerFilter $filter the filter.
	 * @param int $color the color of the 4x4 image.
	 * @param ?bool $expected the expected result.
	 * @return int the color of the filtered image at 1,1.
	 */
	protected static function apply(TFilterImagerFilter $filter, int $color, ?bool $expected = true): int
	{
		$image = static::createImage(4, 4, $color, $color);
		self::assertSame($expected, $filter->filterImage($image));
		return static::rgbAt($image, 1, 1);
	}

	public function testFilterMap(): void
	{
		self::assertSame([
			'negate' => IMG_FILTER_NEGATE,
			'grayscale' => IMG_FILTER_GRAYSCALE,
			'brightness' => IMG_FILTER_BRIGHTNESS,
			'contrast' => IMG_FILTER_CONTRAST,
			'colorize' => IMG_FILTER_COLORIZE,
			'edgedetect' => IMG_FILTER_EDGEDETECT,
			'emboss' => IMG_FILTER_EMBOSS,
			'gaussianblur' => IMG_FILTER_GAUSSIAN_BLUR,
			'selectiveblur' => IMG_FILTER_SELECTIVE_BLUR,
			'meanremoval' => IMG_FILTER_MEAN_REMOVAL,
			'smooth' => IMG_FILTER_SMOOTH,
			'pixelate' => IMG_FILTER_PIXELATE,
			'scatter' => IMG_FILTER_SCATTER,
		], TFilterImagerFilter::FILTER_MAP);
	}

	public function testEffect(): void
	{
		$filter = new TFilterImagerFilter();
		self::assertNull($filter->getEffect());

		$filter->setEffect(' GaussianBlur ');
		self::assertSame('GaussianBlur', $filter->getEffect());
		$filter->setEffect('2');
		self::assertSame(IMG_FILTER_BRIGHTNESS, $filter->getEffect(), 'A numeric effect is stored as an integer.');
		$filter->setEffect(IMG_FILTER_SCATTER);
		self::assertSame(IMG_FILTER_SCATTER, $filter->getEffect());
	}

	public function testBadEffect(): void
	{
		foreach (['Sepia', '99', ''] as $effect) {
			try {
				(new TFilterImagerFilter())->setEffect($effect);
				self::fail("An exception was expected for '$effect'.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('filterimagerfilter_bad_effect', $e->getErrorCode());
			}
		}
	}

	public function testArguments(): void
	{
		$filter = new TFilterImagerFilter();
		foreach ([1, 2, 3, 4] as $i) {
			self::assertNull($filter->{'getArg' . $i}());
			$filter->{'setArg' . $i}("v$i");
			self::assertSame("v$i", $filter->{'getArg' . $i}());
		}
	}

	public function testNoImageOrNoEffect(): void
	{
		$image = null;
		self::assertNull(static::newFilter('Negate')->filterImage($image));
		$image = static::createImage();
		self::assertNull((new TFilterImagerFilter())->filterImage($image));

		$filter = new TFilterImagerFilter();
		$property = new \ReflectionProperty($filter, '_effect');
		$property->setAccessible(true);
		$property->setValue($filter, 'NotAnEffect');
		self::assertNull($filter->filterImage($image), 'An unknown effect name does nothing.');
	}

	public function testNumericEffectFromAString(): void
	{
		// Regression: a numeric string effect was looked up as a name and negated the image.
		self::assertSame(0x425262, static::apply(static::newFilter('2', ['50']), 0x102030));
	}

	public function testNegateAndGrayscale(): void
	{
		self::assertSame(0xEFDFCF, static::apply(static::newFilter('negate'), 0x102030));
		$gray = static::apply(static::newFilter('GRAYSCALE'), 0xFF0000);
		self::assertSame($gray & 0xFF, ($gray >> 8) & 0xFF);
		self::assertSame($gray & 0xFF, ($gray >> 16) & 0xFF);
	}

	public function testBrightness(): void
	{
		self::assertSame(0x000000, static::apply(static::newFilter('Brightness', ['-300']), 0x808080), 'The level is clamped to -255.');
		self::assertSame(0x405060, static::apply(static::newFilter('Brightness', [0x10]), 0x304050));
		self::assertSame(0x304050, static::apply(static::newFilter('Brightness', ['0']), 0x304050, false), 'Zero does nothing.');
		self::assertSame(0x304050, static::apply(static::newFilter('Brightness'), 0x304050, false));
	}

	public function testContrast(): void
	{
		self::assertNotSame(0x608040, static::apply(static::newFilter('Contrast', ['-50']), 0x608040));
		self::assertSame(0x608040, static::apply(static::newFilter('Contrast', [0]), 0x608040, false), 'Zero does nothing.');
	}

	public function testColorize(): void
	{
		self::assertSame(0x203040, static::apply(static::newFilter('Colorize', ['16', '32', '48']), 0x101010));
		self::assertSame(0xFF0080, static::apply(static::newFilter('Colorize', [500, -500, 0, 0]), 0x808080), 'The offsets are clamped.');
		self::assertSame(0x101010, static::apply(static::newFilter('Colorize', [0, 0, 0, 0]), 0x101010, false), 'No offsets do nothing.');

		$filter = static::newFilter('Colorize', [0, 0, 0, '64']);
		$image = static::createImage(4, 4, 0x101010, 0x101010);
		imagealphablending($image, false);
		self::assertTrue($filter->filterImage($image));
		self::assertSame(64, (imagecolorat($image, 1, 1) >> 24) & 0x7F, 'The alpha offset is applied.');
	}

	public function testConvolutionEffects(): void
	{
		foreach (['EdgeDetect', 'Emboss', 'GaussianBlur', 'SelectiveBlur', 'MeanRemoval'] as $effect) {
			$image = static::createImage(20, 20);
			self::assertTrue(static::newFilter($effect)->filterImage($image), $effect);
		}
	}

	public function testSmooth(): void
	{
		$image = static::createImage(20, 20, 0x000000, 0xFFFFFF);
		self::assertTrue(static::newFilter('Smooth', ['-4'])->filterImage($image));
		self::assertNotSame(0xFFFFFF, static::rgbAt($image, 9, 9), 'The mark corner is smoothed.');

		self::assertSame(0x102030, static::apply(static::newFilter('Smooth', ['2048']), 0x102030, false), 'A weight of 2048 or more does nothing.');
	}

	public function testPixelate(): void
	{
		$image = static::createImage(8, 8, 0x000000, 0x000000);
		imagesetpixel($image, 0, 0, 0xFFFFFF);
		self::assertTrue(static::newFilter('Pixelate', ['4', 'false'])->filterImage($image));
		self::assertSame(static::rgbAt($image, 0, 0), static::rgbAt($image, 3, 3), 'A 4 pixel block has one color.');

		$image = static::createImage(8, 8, 0x000000, 0x000000);
		imagesetpixel($image, 0, 0, 0xFFFFFF);
		self::assertTrue(static::newFilter('Pixelate', ['4', 'true'])->filterImage($image));
		self::assertSame(static::rgbAt($image, 0, 0), static::rgbAt($image, 3, 3), 'The advanced block has one color.');

		self::assertSame(0x102030, static::apply(static::newFilter('Pixelate', ['0']), 0x102030, false), 'A block of 0 does nothing.');
	}

	public function testScatter(): void
	{
		// Regression: a Scatter without colors passed null for the colors array.
		$image = static::createImage(20, 20);
		self::assertTrue(static::newFilter('Scatter', ['1', '3'])->filterImage($image));

		$image = static::createImage(20, 20);
		self::assertTrue(static::newFilter('Scatter', [1, 3, '16711680, 3368652'])->filterImage($image));
		$image = static::createImage(20, 20);
		self::assertTrue(static::newFilter('Scatter', [1, 3, ['a' => 0xFF0000]])->filterImage($image));
	}

	/**
	 * Creates an Imagick image of {@see createImage}, skipping the test without Imagick.
	 * @param int $width the width.
	 * @param int $height the height.
	 * @param int $color the fill color.
	 * @param int $mark the top-left quadrant color.
	 * @return \Imagick the image.
	 */
	protected static function newImagickImage(int $width = 40, int $height = 20, int $color = 0x3366CC, int $mark = 0xFF0000): \Imagick
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$image = TImageGraphics::decode((string) TPNG::fromImage(static::createImage($width, $height, $color, $mark)), TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		return $image;
	}

	/**
	 * Filters an Imagick image and converts the result back into GD to read the pixels.
	 * @param TFilterImagerFilter $filter the filter.
	 * @param \Imagick $image the image to filter.
	 * @param ?bool $expected the expected result.
	 * @return \GdImage the filtered image, in GD.
	 */
	protected static function applyImagick(TFilterImagerFilter $filter, \Imagick $image, ?bool $expected = true): \GdImage
	{
		self::assertSame($expected, $filter->filterImage($image));
		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $gd);
		return $gd;
	}

	public function testImagickGraphicsMode(): void
	{
		self::assertNull((new TFilterImagerFilter())->getGraphicsMode(), 'The filter is implemented in both libraries.');
	}

	public function testImagickNoEffect(): void
	{
		$image = static::newImagickImage(4, 4);
		self::assertNull((new TFilterImagerFilter())->filterImage($image), 'No effect does nothing.');

		$filter = new TFilterImagerFilter();
		$property = new \ReflectionProperty($filter, '_effect');
		$property->setAccessible(true);
		$property->setValue($filter, 'NotAnEffect');
		self::assertNull($filter->filterImage($image), 'An unknown effect name does nothing.');

		$property->setValue($filter, 99);
		self::assertNull($filter->filterImage($image), 'An effect without an Imagick equivalent runs in GD.');
	}

	public function testImagickNegateAndGrayscale(): void
	{
		$gd = static::applyImagick(static::newFilter('Negate'), static::newImagickImage(4, 4, 0x102030, 0x102030));
		self::assertSame(0xEFDFCF, static::rgbAt($gd, 1, 1), 'Imagick inverts every channel, as GD does.');

		$gd = static::applyImagick(static::newFilter('GRAYSCALE'), static::newImagickImage(4, 4, 0x3366CC, 0x3366CC));
		$gray = static::rgbAt($gd, 1, 1);
		self::assertSame($gray & 0xFF, ($gray >> 8) & 0xFF);
		self::assertSame($gray & 0xFF, ($gray >> 16) & 0xFF);
		self::assertColorNear(0x626262, $gray, 4, 'Imagick weighs the channels within a few of GD.');
	}

	public function testImagickBrightness(): void
	{
		$gd = static::applyImagick(static::newFilter('Brightness', [0x10]), static::newImagickImage(4, 4, 0x304050, 0x304050));
		self::assertColorNear(0x405060, static::rgbAt($gd, 1, 1), 2, 'Imagick adds the level, as GD does.');

		$gd = static::applyImagick(static::newFilter('Brightness', ['-300']), static::newImagickImage(4, 4, 0x808080, 0x808080));
		self::assertSame(0x000000, static::rgbAt($gd, 1, 1), 'The level is clamped to -255.');

		$gd = static::applyImagick(static::newFilter('Brightness', ['0']), static::newImagickImage(4, 4, 0x304050, 0x304050), false);
		self::assertSame(0x304050, static::rgbAt($gd, 1, 1), 'Zero does nothing.');
	}

	public function testImagickContrast(): void
	{
		$gd = static::applyImagick(static::newFilter('Contrast', ['100']), static::newImagickImage(4, 4, 0x203040, 0x203040));
		self::assertColorNear(0x808080, static::rgbAt($gd, 1, 1), 2, 'A level of 100 flattens the image to mid gray, as GD does.');

		// 0xA0 is above the midpoint and 0x60 below it; a channel at the midpoint stays put.
		$gd = static::applyImagick(static::newFilter('Contrast', ['-50']), static::newImagickImage(4, 4, 0x60A040, 0x60A040));
		$color = static::rgbAt($gd, 1, 1);
		self::assertLessThan(0x60, ($color >> 16) & 0xFF, 'A negative level pushes the channels below the midpoint down.');
		self::assertGreaterThan(0xA0, ($color >> 8) & 0xFF, 'A negative level pushes the channels above the midpoint up.');

		$gd = static::applyImagick(static::newFilter('Contrast', [0]), static::newImagickImage(4, 4, 0x608040, 0x608040), false);
		self::assertSame(0x608040, static::rgbAt($gd, 1, 1), 'Zero does nothing.');
	}

	public function testImagickColorize(): void
	{
		$gd = static::applyImagick(static::newFilter('Colorize', ['16', '32', '48']), static::newImagickImage(4, 4, 0x101010, 0x101010));
		self::assertColorNear(0x203040, static::rgbAt($gd, 1, 1), 4, 'Imagick blends toward the offsets.');

		$gd = static::applyImagick(static::newFilter('Colorize', [500, -500, 0, 0]), static::newImagickImage(4, 4, 0x808080, 0x808080));
		self::assertColorNear(0xFF0080, static::rgbAt($gd, 1, 1), 2, 'The offsets are clamped.');

		$gd = static::applyImagick(static::newFilter('Colorize', [0, 0, 0, 0]), static::newImagickImage(4, 4, 0x101010, 0x101010), false);
		self::assertSame(0x101010, static::rgbAt($gd, 1, 1), 'No offsets do nothing.');

		$image = static::newImagickImage(4, 4, 0x101010, 0x101010);
		self::assertNull(static::newFilter('Colorize', [0, 0, 0, '64'])->filterImage($image), 'An alpha offset has no Imagick equivalent.');
	}

	public function testImagickConvolutionEffects(): void
	{
		foreach (['EdgeDetect', 'Emboss', 'GaussianBlur', 'SelectiveBlur', 'MeanRemoval'] as $effect) {
			$gd = static::applyImagick(static::newFilter($effect), static::newImagickImage());
			self::assertNotSame(0x3366CC, static::rgbAt($gd, 20, 10), "$effect changes the edge of the mark.");
		}
		// The blurs and the mean removal leave a flat area; GD's +127 bias of the edge
		// detection and the emboss has no Imagick equivalent, so those do not.
		foreach (['GaussianBlur', 'SelectiveBlur', 'MeanRemoval'] as $effect) {
			$gd = static::applyImagick(static::newFilter($effect), static::newImagickImage());
			self::assertSame(0x3366CC, static::rgbAt($gd, 38, 18), "$effect leaves the flat corner of the fill.");
		}
	}

	public function testImagickSmooth(): void
	{
		$gd = static::applyImagick(static::newFilter('Smooth', ['-4']), static::newImagickImage());
		self::assertNotSame(0x3366CC, static::rgbAt($gd, 20, 10), 'The mark corner is smoothed.');
		self::assertSame(0x3366CC, static::rgbAt($gd, 38, 18), 'The kernel is divided by its own sum.');

		$gd = static::applyImagick(static::newFilter('Smooth', ['-8']), static::newImagickImage());
		self::assertSame(0x000000, static::rgbAt($gd, 38, 18), 'A kernel of a zero sum is not divided.');

		$gd = static::applyImagick(static::newFilter('Smooth', ['2048']), static::newImagickImage(4, 4, 0x102030, 0x102030), false);
		self::assertSame(0x102030, static::rgbAt($gd, 1, 1), 'A weight of 2048 or more does nothing.');
	}

	public function testImagickPixelate(): void
	{
		$gd = static::applyImagick(static::newFilter('Pixelate', ['8', 'false']), static::newImagickImage(8, 8, 0x000000, 0xFFFFFF));
		self::assertSame(static::rgbAt($gd, 0, 0), static::rgbAt($gd, 7, 7), 'An 8 pixel block has one color.');
		self::assertColorNear(0x000000, static::rgbAt($gd, 0, 0), 2, 'The block takes the color of the sample.');

		$gd = static::applyImagick(static::newFilter('Pixelate', ['8', 'true']), static::newImagickImage(8, 8, 0x000000, 0xFFFFFF));
		self::assertSame(static::rgbAt($gd, 0, 0), static::rgbAt($gd, 7, 7), 'The advanced block has one color.');
		self::assertColorNear(0x404040, static::rgbAt($gd, 0, 0), 2, 'The advanced block averages the quarter of white.');

		$gd = static::applyImagick(static::newFilter('Pixelate', ['0']), static::newImagickImage(4, 4, 0x102030, 0x102030), false);
		self::assertSame(0x102030, static::rgbAt($gd, 1, 1), 'A block of 0 does nothing.');
	}

	public function testImagickScatter(): void
	{
		$gd = static::applyImagick(static::newFilter('Scatter', ['1', '3']), static::newImagickImage());
		$moved = 0;
		for ($y = 0; $y < 20; $y++) {
			for ($x = 0; $x < 40; $x++) {
				if (static::rgbAt($gd, $x, $y) !== (($x < 20 && $y < 10) ? 0xFF0000 : 0x3366CC)) {
					$moved++;
				}
			}
		}
		self::assertGreaterThan(0, $moved, 'The pixels of the mark edge are scattered.');

		$gd = static::applyImagick(static::newFilter('Scatter', ['1', '3', []]), static::newImagickImage());
		self::assertSame(0x3366CC, static::rgbAt($gd, 38, 18), 'An empty list of colors scatters the image itself.');

		$gd = static::applyImagick(static::newFilter('Scatter', ['3', '1']), static::newImagickImage(4, 4, 0x102030, 0x102030), false);
		self::assertSame(0x102030, static::rgbAt($gd, 1, 1), 'A displacement of zero or less does nothing.');

		$image = static::newImagickImage();
		self::assertNull(static::newFilter('Scatter', [1, 3, '16711680, 3368652'])->filterImage($image), 'A list of colors has no Imagick equivalent.');
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());
		self::assertTrue(static::applyFilter(static::newFilter('Negate'), $param));
		self::assertTrue($param->getSaveImage());

		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());
		self::assertFalse(static::applyFilter(static::newFilter('Brightness', [0]), $param));
		self::assertFalse($param->getSaveImage(), 'An effect that does nothing is not saved.');
	}
}
