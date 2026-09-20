<?php

/**
 * TCircleMaskImagerFilterTest class file.
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
use Prado\Web\Assets\Behaviors\Filters\TCircleMaskImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TCircleMaskImagerFilterTest class.
 *
 * Tests the circle mask filter: the background color, a symmetric circle measured
 * from pixel centers, the anti-aliased edge, and the same circle in Imagick.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCircleMaskImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param \GdImage $image the image.
	 * @param int $x the x coordinate.
	 * @param int $y the y coordinate.
	 * @return int the GD alpha at the point.
	 */
	protected static function alphaAt(\GdImage $image, int $x, int $y): int
	{
		return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
	}

	/**
	 * Skips the test when Imagick is missing.
	 * @param \GdImage $image the image to convert.
	 * @return \Imagick the image in Imagick.
	 */
	protected static function imagickImage(\GdImage $image): \Imagick
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		return TImageGraphics::decode((string) TPNG::fromImage($image), TImageGraphicsMode::Imagick);
	}

	public function testBackgroundColor(): void
	{
		$filter = new TCircleMaskImagerFilter();
		self::assertSame('#000000', $filter->getBackgroundColor());

		$filter->setBackgroundColor('red');
		self::assertSame('#FF0000', $filter->getBackgroundColor());
		$filter->setBackgroundColor('#abc');
		self::assertSame('#AABBCC', $filter->getBackgroundColor());

		try {
			$filter->setBackgroundColor('#GGGGGG');
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('propertyvalue_invalid_hex_color', $e->getErrorCode());
		}
	}

	public function testNoImageOrAPaletteImage(): void
	{
		$filter = new TCircleMaskImagerFilter();
		$image = null;
		self::assertNull($filter->filterImage($image));
		$palette = imagecreate(10, 10);
		self::assertNull($filter->filterImage($palette));
		self::assertNull(TCircleMaskImagerFilter::imageCircleMask($palette, 0x7F000000));
		self::assertNull(TCircleMaskImagerFilter::imageCircleMask(static::createImage(), -1));
	}

	public function testMasksOutsideTheCircle(): void
	{
		$filter = new TCircleMaskImagerFilter();
		$filter->setBackgroundColor('#102030');
		$image = static::createImage(100, 100, 0x3366CC, 0x3366CC);

		self::assertTrue($filter->filterImage($image));

		foreach ([[0, 0], [99, 0], [0, 99], [99, 99], [10, 10]] as [$x, $y]) {
			self::assertSame(0x7F102030, imagecolorat($image, $x, $y), "The corner pixel $x,$y is the transparent background.");
		}
		foreach ([[50, 50], [50, 1], [1, 50], [98, 50], [50, 98]] as [$x, $y]) {
			self::assertSame(0x3366CC, imagecolorat($image, $x, $y), "The inner pixel $x,$y is unchanged.");
		}
	}

	public function testTheCircleIsSymmetric(): void
	{
		// Regression: distances were measured from pixel corners, shifting the circle.
		foreach ([[100, 100], [101, 100], [60, 90]] as [$width, $height]) {
			$image = static::createImage($width, $height, 0x3366CC, 0x3366CC);
			self::assertTrue((new TCircleMaskImagerFilter())->filterImage($image));

			for ($y = 0; $y < $height; $y++) {
				for ($x = 0; $x < $width; $x++) {
					$alpha = static::alphaAt($image, $x, $y);
					self::assertSame($alpha, static::alphaAt($image, $width - 1 - $x, $y), "{$width}x$height mirrored in x at $x,$y.");
					self::assertSame($alpha, static::alphaAt($image, $x, $height - 1 - $y), "{$width}x$height mirrored in y at $x,$y.");
				}
			}
		}
	}

	public function testTheEdgeIsAntiAliased(): void
	{
		// Regression: the edge alpha was scaled from the pixel alpha, leaving opaque pixels opaque.
		$image = static::createImage(100, 100, 0x3366CC, 0x3366CC);
		self::assertTrue((new TCircleMaskImagerFilter())->filterImage($image));

		$partial = 0;
		for ($y = 0; $y < 100; $y++) {
			for ($x = 0; $x < 100; $x++) {
				$alpha = static::alphaAt($image, $x, $y);
				if ($alpha > 0 && $alpha < 127) {
					$partial++;
					self::assertSame(0x3366CC, imagecolorat($image, $x, $y) & 0xFFFFFF, 'An edge pixel keeps its color.');
				}
			}
		}
		self::assertGreaterThan(100, $partial, 'The circle edge has partially transparent pixels.');

		// Row 50 (center 50.5) at x = 0: the pixel center is 50.0025 from the center, about half covered.
		$odd = static::createImage(101, 100, 0x3366CC, 0x3366CC);
		(new TCircleMaskImagerFilter())->filterImage($odd);
		self::assertSame(64, static::alphaAt($odd, 0, 50));
		self::assertSame(64, static::alphaAt($odd, 100, 50));
	}

	public function testSemiTransparentEdgePixelsBecomeMoreTransparent(): void
	{
		$image = imagecreatetruecolor(101, 100);
		imagealphablending($image, false);
		imagefilledrectangle($image, 0, 0, 100, 99, 0x3F3366CC);

		self::assertTrue(TCircleMaskImagerFilter::imageCircleMask($image, 0x7F000000));
		self::assertSame(95, static::alphaAt($image, 0, 50), 'Half of the 64 remaining opacity is removed.');
		self::assertSame(0x3F, static::alphaAt($image, 50, 50));
	}

	public function testTinyImages(): void
	{
		$one = static::createImage(1, 1);
		self::assertTrue(TCircleMaskImagerFilter::imageCircleMask($one, 0x7F000000));
		self::assertLessThan(127, static::alphaAt($one, 0, 0), 'The single pixel is inside the circle.');

		$thin = static::createImage(2, 3, 0x3366CC, 0x3366CC);
		self::assertTrue(TCircleMaskImagerFilter::imageCircleMask($thin, 0x7F000000));
		// Radius 1 about (1, 1.5): the corner pixel center is 1.118 away, the middle-left 0.5.
		self::assertSame(78, static::alphaAt($thin, 0, 0));
		self::assertSame(0, static::alphaAt($thin, 0, 1));
	}

	public function testImagickMasksOutsideTheCircle(): void
	{
		$filter = new TCircleMaskImagerFilter();
		$filter->setBackgroundColor('#102030');
		$imagick = static::imagickImage(static::createImage(40, 20, 0x3366CC, 0x3366CC));

		self::assertNull($filter->getGraphicsMode(), 'The filter runs in either graphics library.');
		self::assertTrue($filter->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		foreach ([[0, 0], [39, 0], [0, 19], [39, 19], [5, 5]] as [$x, $y]) {
			self::assertSame(127, static::alphaAt($image, $x, $y), "The corner pixel $x,$y is transparent.");
			self::assertColorNear(0x102030, static::rgbAt($image, $x, $y), 2, "The corner pixel $x,$y is the background color.");
		}
		foreach ([[20, 10], [20, 1], [11, 10], [28, 10]] as [$x, $y]) {
			self::assertSame(0, static::alphaAt($image, $x, $y), "The inner pixel $x,$y is opaque.");
			self::assertColorNear(0x3366CC, static::rgbAt($image, $x, $y), 2, "The inner pixel $x,$y keeps its color.");
		}
	}

	public function testImagickAntiAliasesTheEdge(): void
	{
		$imagick = static::imagickImage(static::createImage(100, 100, 0x3366CC, 0x3366CC));

		self::assertTrue((new TCircleMaskImagerFilter())->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		$gd = static::createImage(100, 100, 0x3366CC, 0x3366CC);
		(new TCircleMaskImagerFilter())->filterImage($gd);

		$partial = 0;
		for ($y = 0; $y < 100; $y++) {
			for ($x = 0; $x < 100; $x++) {
				$alpha = static::alphaAt($image, $x, $y);
				if ($alpha > 0 && $alpha < 127) {
					$partial++;
					self::assertColorNear(0x3366CC, static::rgbAt($image, $x, $y), 2, "The edge pixel $x,$y keeps its color.");
				}
				// Each library anti-aliases the edge itself, so only the edge differs.
				$gdAlpha = static::alphaAt($gd, $x, $y);
				if ($gdAlpha === 0 || $gdAlpha === 127) {
					self::assertLessThanOrEqual(64, abs($gdAlpha - $alpha), "The pixel $x,$y is on the same side of the circle as in GD.");
				}
			}
		}
		self::assertGreaterThan(100, $partial, 'The circle edge has partially transparent pixels.');
	}

	public function testImagickKeepsTheColorOfSemiTransparentPixels(): void
	{
		$source = imagecreatetruecolor(100, 100);
		imagealphablending($source, false);
		imagesavealpha($source, true);
		imagefilledrectangle($source, 0, 0, 99, 99, 0x3F3366CC);
		$imagick = static::imagickImage($source);

		self::assertTrue((new TCircleMaskImagerFilter())->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		self::assertSame(0x3F, static::alphaAt($image, 50, 50), 'The center keeps its transparency.');
		self::assertColorNear(0x3366CC, static::rgbAt($image, 50, 50), 2);

		$edge = 0;
		for ($y = 0; $y < 100; $y++) {
			for ($x = 0; $x < 100; $x++) {
				$alpha = static::alphaAt($image, $x, $y);
				if ($alpha > 0x3F && $alpha < 127) {
					$edge++;
					// Against the default black background color, a blended pixel would darken.
					self::assertColorNear(0x3366CC, static::rgbAt($image, $x, $y), 2, "The edge pixel $x,$y is not blended into the background color.");
				}
			}
		}
		self::assertGreaterThan(100, $edge, 'Half of the remaining opacity of an edge pixel is removed.');
	}

	public function testImagickWithoutAnImage(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$empty = new \Imagick();

		self::assertNull((new TCircleMaskImagerFilter())->filterImage($empty));
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TCircleMaskImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
