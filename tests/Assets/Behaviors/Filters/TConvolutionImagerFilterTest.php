<?php

/**
 * TConvolutionImagerFilterTest class file.
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
use Prado\Web\Assets\Behaviors\Filters\TConvolutionImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TConvolutionImagerFilterTest class.
 *
 * Tests the convolution filter: the matrix from arrays and strings, its validation,
 * and the divisor and offset.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConvolutionImagerFilterTest extends PublishingTestCase
{
	public function testDefaults(): void
	{
		$filter = new TConvolutionImagerFilter();

		self::assertSame([[0, 0, 0], [0, 1, 0], [0, 0, 0]], $filter->getMatrix());
		self::assertSame(1.0, $filter->getDivisor());
		self::assertSame(0.0, $filter->getOffset());
	}

	public function testMatrixForms(): void
	{
		$filter = new TConvolutionImagerFilter();

		$filter->setMatrix([[1, 2, 3], [4, 5, 6], [7, 8, 9]]);
		self::assertSame([[1, 2, 3], [4, 5, 6], [7, 8, 9]], $filter->getMatrix());

		$filter->setMatrix([1, 2, 3, 4, 5, 6, 7, 8, 9]);
		self::assertSame([[1, 2, 3], [4, 5, 6], [7, 8, 9]], $filter->getMatrix(), 'Nine values are a 3x3 matrix.');

		$filter->setMatrix(['a' => [1, 0, 1], 'b' => ['x' => 0, 'y' => 3, 'z' => 0], 'c' => [1, 0, 1]]);
		self::assertSame([[1, 0, 1], [0, 3, 0], [1, 0, 1]], $filter->getMatrix(), 'The keys are reindexed.');

		$filter->setMatrix('-1, -1, -1, -1, 16, -1, -1, -1, -1');
		self::assertEquals([[-1, -1, -1], [-1, 16, -1], [-1, -1, -1]], $filter->getMatrix());

		$filter->setMatrix('([1, 0, 1], [0, 3, 0], [1, 0, 1])');
		self::assertEquals([[1, 0, 1], [0, 3, 0], [1, 0, 1]], $filter->getMatrix(), 'The filter.xml matrix form.');
	}

	public static function badMatrixProvider(): array
	{
		return [
			'three values' => ['1,2,3'],
			'two rows' => [[[1, 2, 3], [4, 5, 6]]],
			'short row' => [[[1, 2, 3], [4, 5], [7, 8, 9]]],
			'scalar row' => [[[1, 2, 3], 4, [7, 8, 9]]],
			'empty' => [''],
		];
	}

	/**
	 * @param mixed $matrix
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badMatrixProvider')]
	public function testBadMatrix($matrix): void
	{
		// Regression: a matrix of scalars threw a TypeError from count().
		$filter = new TConvolutionImagerFilter();
		try {
			$filter->setMatrix($matrix);
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('convolutionfilter_bad_matrix', $e->getErrorCode());
		}
		self::assertSame([[0, 0, 0], [0, 1, 0], [0, 0, 0]], $filter->getMatrix(), 'A rejected matrix is not stored.');
	}

	public function testDivisorAndOffset(): void
	{
		$filter = new TConvolutionImagerFilter();

		$filter->setDivisor('2.5');
		$filter->setOffset('-10');
		self::assertSame(2.5, $filter->getDivisor());
		self::assertSame(-10.0, $filter->getOffset());
	}

	public function testNoImage(): void
	{
		$image = null;
		self::assertNull((new TConvolutionImagerFilter())->filterImage($image));
	}

	public function testConvolution(): void
	{
		$filter = new TConvolutionImagerFilter();
		$image = static::createImage(10, 10, 0x404040, 0x404040);
		self::assertTrue($filter->filterImage($image));
		self::assertSame(0x404040, static::rgbAt($image, 5, 5), 'The identity matrix does not change the image.');

		$filter->setOffset('16');
		self::assertTrue($filter->filterImage($image));
		self::assertSame(0x505050, static::rgbAt($image, 5, 5), 'The offset is added.');

		$filter->setMatrix('0,0,0, 0,2,0, 0,0,0');
		$filter->setDivisor(4);
		$filter->setOffset(0);
		self::assertTrue($filter->filterImage($image));
		self::assertSame(0x282828, static::rgbAt($image, 5, 5), 'The sum is divided.');
	}

	/**
	 * Skips the test without Imagick.
	 * @param int $width the image width.
	 * @param int $height the image height.
	 * @param ?\GdImage $gd the image to convert, default {@see createImage}.
	 * @return \Imagick the test image in Imagick.
	 */
	protected static function imagickImage(int $width = 40, int $height = 20, ?\GdImage $gd = null): \Imagick
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		return TImageGraphics::decode((string) TPNG::fromImage($gd ?? static::createImage($width, $height)), TImageGraphicsMode::Imagick);
	}

	public function testTheFilterWorksInEitherLibrary(): void
	{
		self::assertNull((new TConvolutionImagerFilter())->getGraphicsMode(), 'The convolution is implemented in GD and in Imagick.');
	}

	public function testNoImagickImage(): void
	{
		$image = null;
		$method = new \ReflectionMethod(TConvolutionImagerFilter::class, 'filterImagickImage');
		self::assertNull($method->invokeArgs(new TConvolutionImagerFilter(), [&$image, null]));
	}

	public function testImagickConvolution(): void
	{
		$filter = new TConvolutionImagerFilter();
		$image = static::imagickImage();

		self::assertTrue($filter->filterImage($image));
		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 30, 15), 1, 'The identity matrix does not change the image.');
		self::assertColorNear(0xFF0000, static::rgbAt($gd, 5, 5), 1);

		$filter->setOffset('16');
		self::assertTrue($filter->filterImage($image));
		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertColorNear(0x4376DC, static::rgbAt($gd, 30, 15), 1, 'The offset is added.');

		$filter->setMatrix('0,0,0, 0,2,0, 0,0,0');
		$filter->setDivisor(4);
		$filter->setOffset(0);
		self::assertTrue($filter->filterImage($image));
		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertColorNear(0x213B6E, static::rgbAt($gd, 30, 15), 1, 'The sum is divided.');
	}

	public function testTheImagickKernelHasTheOrientationOfTheGdMatrix(): void
	{
		// The matrix takes the pixel to the left; Imagick applies a kernel rotated.
		$filter = new TConvolutionImagerFilter();
		$filter->setMatrix('0,0,0, 1,0,0, 0,0,0');
		$image = static::imagickImage();
		$gd = static::createImage();

		self::assertTrue($filter->filterImage($image));
		self::assertTrue($filter->filterImage($gd));

		$converted = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertSame(0xFF0000, static::rgbAt($gd, 20, 5), 'GD takes the mark from the pixel to the left.');
		self::assertColorNear(0xFF0000, static::rgbAt($converted, 20, 5), 1, 'And so does Imagick.');
		self::assertColorNear(0x3366CC, static::rgbAt($converted, 21, 5), 1);
	}

	public function testTheImagickAlphaChannelIsKeptOutOfTheConvolution(): void
	{
		$source = static::createImage(20, 10, 0x3366CC, 0x3366CC);
		imagealphablending($source, false);
		imagesavealpha($source, true);
		imagefilledrectangle($source, 0, 0, 9, 9, (63 << 24) | 0xFF0000);
		$image = static::imagickImage(20, 10, $source);
		$filter = new TConvolutionImagerFilter();
		$filter->setMatrix('1,1,1, 1,1,1, 1,1,1');
		$filter->setDivisor(9);

		self::assertTrue($filter->filterImage($image));
		self::assertTrue($filter->filterImage($source));

		$converted = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		foreach ([[5, 5], [9, 5], [10, 5], [15, 5]] as [$x, $y]) {
			self::assertColorNear(static::rgbAt($source, $x, $y), static::rgbAt($converted, $x, $y), 2, "The color at ($x,$y) is the GD color.");
			self::assertSame((imagecolorat($source, $x, $y) >> 24) & 0x7F, (imagecolorat($converted, $x, $y) >> 24) & 0x7F, "The alpha at ($x,$y) is not convolved.");
		}
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TConvolutionImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
