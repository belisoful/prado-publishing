<?php

/**
 * TResizeImagerFilterTest class file.
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
use Prado\Web\Assets\Behaviors\Filters\TResizeImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\Fixtures\TFailingCropResizeImagerFilter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TResizeImagerFilterTest class.
 *
 * Tests the resize filter: its size and mode properties from their string forms, the
 * maximum, fixed, fit, and crop-fill sizes, and the same sizes and crops in Imagick
 * with the mapped resampling filter.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TResizeImagerFilterTest extends PublishingTestCase
{
	/**
	 * @param array<string, mixed> $properties the filter properties.
	 * @return TResizeImagerFilter the filter.
	 */
	protected static function newFilter(array $properties = []): TResizeImagerFilter
	{
		$filter = new TResizeImagerFilter();
		foreach ($properties as $name => $value) {
			$filter->{'set' . $name}($value);
		}
		return $filter;
	}

	/**
	 * @param array<string, mixed> $properties the filter properties.
	 * @param int $width the source width.
	 * @param int $height the source height.
	 * @param ?bool $expected the expected result.
	 * @return \GdImage the resized source image.
	 */
	protected static function resize(array $properties, int $width = 40, int $height = 20, ?bool $expected = true): \GdImage
	{
		$image = static::createImage($width, $height);
		self::assertSame($expected, static::newFilter($properties)->filterImage($image));
		return $image;
	}

	public function testDefaults(): void
	{
		$filter = new TResizeImagerFilter();

		self::assertSame(0.0, $filter->getMaximumWidth());
		self::assertSame(0.0, $filter->getMaximumHeight());
		self::assertSame(0, $filter->getMaximumPixels());
		self::assertSame(0.0, $filter->getFixedWidth());
		self::assertSame(0.0, $filter->getFixedHeight());
		self::assertTrue($filter->getCropFill());
		self::assertSame(IMG_BILINEAR_FIXED, $filter->getResizeMode());
		self::assertSame(['nearestneighbour' => IMG_NEAREST_NEIGHBOUR, 'bilinearfixed' => IMG_BILINEAR_FIXED, 'bicubic' => IMG_BICUBIC, 'bicubicfixed' => IMG_BICUBIC_FIXED], TResizeImagerFilter::SCALE_MODE);
	}

	public function testSizeProperties(): void
	{
		$filter = static::newFilter(['MaximumWidth' => '100.5', 'MaximumHeight' => '-4', 'FixedWidth' => '30', 'FixedHeight' => -1, 'CropFill' => 'false']);

		self::assertSame(100.5, $filter->getMaximumWidth());
		self::assertSame(0.0, $filter->getMaximumHeight(), 'A negative size is 0.');
		self::assertSame(30.0, $filter->getFixedWidth());
		self::assertSame(0.0, $filter->getFixedHeight());
		self::assertFalse($filter->getCropFill());
	}

	public function testMaximumPixels(): void
	{
		$filter = new TResizeImagerFilter();
		$cases = ['1000' => 1000000, '1920x1080' => 2073600, ' 1920 X 1080 ' => 2073600, '1920×1080' => 2073600, '10*5' => 50, '10.5' => 110, '' => 0, '-5' => 0];
		foreach ($cases as $value => $expected) {
			$filter->setMaximumPixels((string) $value);
			self::assertSame($expected, $filter->getMaximumPixels(), "MaximumPixels '$value'.");
		}
		$filter->setMaximumPixels(100);
		self::assertSame(10000, $filter->getMaximumPixels());
	}

	public function testBadMaximumPixels(): void
	{
		foreach (['big', '10x20x30', '10 by 20'] as $value) {
			try {
				(new TResizeImagerFilter())->setMaximumPixels($value);
				self::fail("An exception was expected for '$value'.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('resizeimagerfilter_bad_max_pixel_format', $e->getErrorCode());
			}
		}
	}

	public function testResizeMode(): void
	{
		$filter = new TResizeImagerFilter();

		$filter->setResizeMode('nearestneighbour');
		self::assertSame(IMG_NEAREST_NEIGHBOUR, $filter->getResizeMode());
		$filter->setResizeMode(' BicubicFixed ');
		self::assertSame(IMG_BICUBIC_FIXED, $filter->getResizeMode());
		$filter->setResizeMode((string) IMG_MITCHELL);
		self::assertSame(IMG_MITCHELL, $filter->getResizeMode());
	}

	public function testBadResizeMode(): void
	{
		foreach (['Weighted4', (string) IMG_WEIGHTED4, 'Sharpest', '999'] as $mode) {
			try {
				(new TResizeImagerFilter())->setResizeMode($mode);
				self::fail("An exception was expected for '$mode'.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('resizeimagerfilter_bad_mode', $e->getErrorCode());
			}
		}
	}

	public function testNoImageOrNoChange(): void
	{
		$image = null;
		self::assertNull((new TResizeImagerFilter())->filterImage($image));

		$image = static::resize([], 40, 20, false);
		self::assertSame(40, imagesx($image));
		static::resize(['MaximumWidth' => 50, 'MaximumHeight' => 20, 'MaximumPixels' => 800], 40, 20, false);
		static::resize(['FixedWidth' => 40, 'FixedHeight' => 20], 40, 20, false);
	}

	public function testMaximumSizes(): void
	{
		$cases = [
			'width' => [['MaximumWidth' => '20'], [20, 10]],
			'height' => [['MaximumHeight' => '5'], [10, 5]],
			'both, width limits' => [['MaximumWidth' => 10, 'MaximumHeight' => 10], [10, 5]],
			'pixels squared' => [['MaximumPixels' => '10'], [14, 7]],
			'pixel dimensions' => [['MaximumPixels' => '10x20'], [20, 10]],
		];
		foreach ($cases as $case => [$properties, $size]) {
			$image = static::resize($properties);
			self::assertSame($size, [imagesx($image), imagesy($image)], $case);
		}
	}

	public function testFixedSizes(): void
	{
		$cases = [
			'width' => [['FixedWidth' => '80'], [80, 40]],
			'height' => [['FixedHeight' => '10'], [20, 10]],
			'fit a wider image' => [['FixedWidth' => 30, 'FixedHeight' => 30, 'CropFill' => 'false'], [30, 15]],
			'fit a taller image' => [['FixedWidth' => 100, 'FixedHeight' => 10, 'CropFill' => false], [20, 10]],
			'crop fill' => [['FixedWidth' => 30, 'FixedHeight' => 30], [30, 30]],
			'fixed then maximum' => [['FixedWidth' => 80, 'MaximumWidth' => 60, 'MaximumHeight' => 30], [60, 30]],
		];
		foreach ($cases as $case => [$properties, $size]) {
			$image = static::resize($properties);
			self::assertSame($size, [imagesx($image), imagesy($image)], $case);
		}
	}

	public function testCropFillTakesTheCenter(): void
	{
		$image = static::resize(['FixedWidth' => 20, 'FixedHeight' => 20, 'ResizeMode' => 'NearestNeighbour']);
		self::assertSame([20, 20], [imagesx($image), imagesy($image)]);
		self::assertSame(0xFF0000, static::rgbAt($image, 5, 5), 'The right half of the mark is the left of the center crop.');
		self::assertSame(0x3366CC, static::rgbAt($image, 15, 5));
		self::assertSame(0x3366CC, static::rgbAt($image, 5, 15));

		$image = static::resize(['FixedWidth' => 20, 'FixedHeight' => 20, 'ResizeMode' => 'NearestNeighbour'], 20, 40);
		self::assertSame([20, 20], [imagesx($image), imagesy($image)]);
		self::assertSame(0xFF0000, static::rgbAt($image, 5, 5), 'The bottom half of the mark is the top of the center crop.');
		self::assertSame(0x3366CC, static::rgbAt($image, 5, 15));
		self::assertSame(0x3366CC, static::rgbAt($image, 15, 5));
	}

	public function testAnExtremeAspectKeepsOnePixel(): void
	{
		// Regression: a size that floored to 0 made imagescale() fail and dropped the image.
		$image = static::resize(['MaximumWidth' => 50], 1000, 10);
		self::assertSame([50, 1], [imagesx($image), imagesy($image)]);
	}

	public function testUnsupportedScaleModesResample(): void
	{
		// Regression: modes imagescale() does not support failed and dropped the image.
		foreach (['Bicubic', 'BicubicFixed', 'Mitchell'] as $mode) {
			$image = static::resize(['FixedWidth' => 20, 'ResizeMode' => $mode]);
			self::assertSame([20, 10], [imagesx($image), imagesy($image)], $mode);
			self::assertColorNear(0xFF0000, static::rgbAt($image, 3, 2), 40, $mode);
		}
	}

	public function testTheTransparentColorIsKept(): void
	{
		$image = static::createImage(40, 20);
		imagecolortransparent($image, 0x3366CC);
		self::assertTrue(static::newFilter(['MaximumWidth' => 20])->filterImage($image));
		self::assertSame(0x3366CC, imagecolortransparent($image));
	}

	public function testAFailedCropKeepsTheImage(): void
	{
		TFailingCropResizeImagerFilter::$rects = [];
		$filter = new TFailingCropResizeImagerFilter();
		$filter->setFixedWidth(20);
		$filter->setFixedHeight(20);
		$image = $original = static::createImage();

		self::assertFalse($filter->filterImage($image));

		self::assertSame($original, $image, 'The image is not replaced.');
		self::assertSame([40, 20], [imagesx($image), imagesy($image)]);
		self::assertSame([['x' => 10, 'y' => 0, 'width' => 20, 'height' => 20]], TFailingCropResizeImagerFilter::$rects);
	}

	public function testAFailedScaleKeepsTheImage(): void
	{
		// 65536 * 65536 pixels exceeds INT_MAX, so GD cannot create the scaled image.
		$filter = static::newFilter(['FixedWidth' => 65536, 'FixedHeight' => 65536]);
		$image = $original = static::createImage();
		$warnings = [];
		set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
			if (error_reporting() & $errno) {
				$warnings[] = $errstr; // Not a warning silenced with @.
			}
			return true;
		}, E_WARNING);
		try {
			self::assertFalse($filter->filterImage($image));
		} finally {
			restore_error_handler();
		}

		self::assertSame($original, $image, 'The image is not replaced.');
		self::assertSame([40, 20], [imagesx($image), imagesy($image)]);
		foreach ($warnings as $warning) {
			self::assertStringContainsString('imagecreatetruecolor', $warning, 'Only a GD allocation warning may be raised.');
		}
	}

	/**
	 * @param int $width the source width.
	 * @param int $height the source height.
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

	/**
	 * The Imagick equal of {@see resize}.
	 * @param array<string, mixed> $properties the filter properties.
	 * @param int $width the source width.
	 * @param int $height the source height.
	 * @param ?bool $expected the expected result.
	 * @return \Imagick the resized source image.
	 */
	protected static function resizeImagick(array $properties, int $width = 40, int $height = 20, ?bool $expected = true): \Imagick
	{
		$image = static::createImagick($width, $height);
		self::assertSame($expected, static::newFilter($properties)->filterImage($image));
		return $image;
	}

	public function testImagickNoChange(): void
	{
		$image = static::resizeImagick([], 40, 20, false);
		self::assertSame([40, 20], [$image->getImageWidth(), $image->getImageHeight()]);
		static::resizeImagick(['MaximumWidth' => 50, 'MaximumHeight' => 20, 'MaximumPixels' => 800], 40, 20, false);
		static::resizeImagick(['FixedWidth' => 40, 'FixedHeight' => 20], 40, 20, false);
	}

	public function testImagickMaximumSizes(): void
	{
		$cases = [
			'width' => [['MaximumWidth' => '20'], [20, 10]],
			'height' => [['MaximumHeight' => '5'], [10, 5]],
			'both, width limits' => [['MaximumWidth' => 10, 'MaximumHeight' => 10], [10, 5]],
			'pixels squared' => [['MaximumPixels' => '10'], [14, 7]],
			'pixel dimensions' => [['MaximumPixels' => '10x20'], [20, 10]],
		];
		foreach ($cases as $case => [$properties, $size]) {
			$image = static::resizeImagick($properties);
			self::assertSame($size, [$image->getImageWidth(), $image->getImageHeight()], $case);
		}

		// The image is reduced as GD reduces it, resampled rather than pixel identical.
		$gd = static::toGd(static::resizeImagick(['MaximumWidth' => '20']));
		self::assertSame([20, 10], [imagesx($gd), imagesy($gd)]);
		self::assertColorNear(0xFF0000, static::rgbAt($gd, 4, 2), 8, 'The mark is the top-left quadrant.');
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 15, 2), 8);
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 4, 7), 8);
	}

	public function testImagickFixedSizes(): void
	{
		$cases = [
			'width' => [['FixedWidth' => '80'], [80, 40]],
			'height' => [['FixedHeight' => '10'], [20, 10]],
			'fit a wider image' => [['FixedWidth' => 30, 'FixedHeight' => 30, 'CropFill' => 'false'], [30, 15]],
			'fit a taller image' => [['FixedWidth' => 100, 'FixedHeight' => 10, 'CropFill' => false], [20, 10]],
			'crop fill' => [['FixedWidth' => 30, 'FixedHeight' => 30], [30, 30]],
			'fixed then maximum' => [['FixedWidth' => 80, 'MaximumWidth' => 60, 'MaximumHeight' => 30], [60, 30]],
		];
		foreach ($cases as $case => [$properties, $size]) {
			$image = static::resizeImagick($properties);
			self::assertSame($size, [$image->getImageWidth(), $image->getImageHeight()], $case);
		}

		// Without CropFill the whole image fits within the fixed size, mark and all.
		$gd = static::toGd(static::resizeImagick(['FixedWidth' => 20, 'FixedHeight' => 20, 'CropFill' => false]));
		self::assertSame([20, 10], [imagesx($gd), imagesy($gd)]);
		self::assertColorNear(0xFF0000, static::rgbAt($gd, 4, 2), 8, 'The mark is the top-left quadrant.');
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 15, 2), 8);
	}

	public function testImagickCropFillTakesTheCenter(): void
	{
		$image = static::resizeImagick(['FixedWidth' => 20, 'FixedHeight' => 20, 'ResizeMode' => 'NearestNeighbour']);
		self::assertSame([20, 20], [$image->getImageWidth(), $image->getImageHeight()]);
		$page = $image->getImagePage();
		self::assertSame([0, 0], [$page['x'], $page['y']], 'The crop offset is off the virtual canvas.');
		$gd = static::toGd($image);
		self::assertSame([20, 20], [imagesx($gd), imagesy($gd)]);
		self::assertColorNear(0xFF0000, static::rgbAt($gd, 5, 5), 8, 'The right half of the mark is the left of the center crop.');
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 15, 5), 8);
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 5, 15), 8);

		$gd = static::toGd(static::resizeImagick(['FixedWidth' => 20, 'FixedHeight' => 20, 'ResizeMode' => 'NearestNeighbour'], 20, 40));
		self::assertSame([20, 20], [imagesx($gd), imagesy($gd)]);
		self::assertColorNear(0xFF0000, static::rgbAt($gd, 5, 5), 8, 'The bottom half of the mark is the top of the center crop.');
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 5, 15), 8);
		self::assertColorNear(0x3366CC, static::rgbAt($gd, 15, 5), 8);
	}

	public function testImagickScaleMode(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$expected = [
			IMG_NEAREST_NEIGHBOUR => \Imagick::FILTER_POINT,
			IMG_BILINEAR_FIXED => \Imagick::FILTER_TRIANGLE,
			IMG_TRIANGLE => \Imagick::FILTER_TRIANGLE,
			IMG_BICUBIC => \Imagick::FILTER_CATROM,
			IMG_BICUBIC_FIXED => \Imagick::FILTER_CATROM,
			IMG_CATMULLROM => \Imagick::FILTER_CATROM,
			IMG_BELL => \Imagick::FILTER_QUADRATIC,
			IMG_BESSEL => \Imagick::FILTER_BESSEL,
			IMG_BLACKMAN => \Imagick::FILTER_BLACKMAN,
			IMG_BOX => \Imagick::FILTER_BOX,
			IMG_BSPLINE => \Imagick::FILTER_CUBIC,
			IMG_GAUSSIAN => \Imagick::FILTER_GAUSSIAN,
			IMG_GENERALIZED_CUBIC => \Imagick::FILTER_CUBIC,
			IMG_HAMMING => \Imagick::FILTER_HAMMING,
			IMG_HANNING => \Imagick::FILTER_HANNING,
			IMG_HERMITE => \Imagick::FILTER_HERMITE,
			IMG_MITCHELL => \Imagick::FILTER_MITCHELL,
			IMG_QUADRATIC => \Imagick::FILTER_QUADRATIC,
			IMG_SINC => \Imagick::FILTER_SINC,
		];
		self::assertSame($expected, (new \ReflectionMethod(TResizeImagerFilter::class, 'imagickScaleModes'))->invoke(null));

		$method = new \ReflectionMethod(TResizeImagerFilter::class, 'imagickScaleMode');
		foreach ($expected as $mode => $filter) {
			self::assertSame($filter, $method->invoke(null, $mode), "The mode $mode.");
		}
		self::assertSame(\Imagick::FILTER_TRIANGLE, $method->invoke(null, IMG_POWER), 'A mode without an Imagick filter resamples bilinearly.');
	}

	public function testImagickResizeModesResample(): void
	{
		// Every mode resamples, whether or not it is mapped to an Imagick filter.
		foreach (['NearestNeighbour', 'Bicubic', 'Mitchell', 'Power'] as $mode) {
			$gd = static::toGd(static::resizeImagick(['FixedWidth' => 20, 'ResizeMode' => $mode]));
			self::assertSame([20, 10], [imagesx($gd), imagesy($gd)], $mode);
			self::assertColorNear(0xFF0000, static::rgbAt($gd, 3, 2), 40, $mode);
			self::assertColorNear(0x3366CC, static::rgbAt($gd, 16, 7), 40, $mode);
		}
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter(static::newFilter(['MaximumWidth' => 10]), $param));
		self::assertSame(10, imagesx($param->getImage()));
		self::assertTrue($param->getSaveImage());
	}
}
