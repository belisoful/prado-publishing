<?php

/**
 * TImageImagerFilterTest class file.
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
use Prado\Prado;
use Prado\Web\Assets\Behaviors\Filters\TImageImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TInterpolationImagerMode;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TImageImagerFilterTest class.
 *
 * Tests the watermark image filter: its properties from their string forms, the
 * placement and alignment of the watermark, fixed sizes with crop fill, the
 * interpolation mode, transparency, the missing or unreadable watermark, and the
 * same watermark rendered by Imagick.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImageImagerFilterTest extends PublishingTestCase
{
	/** The alias of the watermark directory. */
	public const WATERMARK_ALIAS = 'ImageImagerFilterTestWatermarks';

	protected function setUp(): void
	{
		parent::setUp();
		mkdir($this->srcDir . DIRECTORY_SEPARATOR . 'wm');
		Prado::setPathOfAlias(static::WATERMARK_ALIAS, $this->srcDir . DIRECTORY_SEPARATOR . 'wm');
	}

	/**
	 * Writes a solid watermark.
	 * @param string $name the file name in the watermark directory.
	 * @param int $width the width.
	 * @param int $height the height.
	 * @param int $color the color, 0xAARRGGBB.
	 * @return string the file name.
	 */
	protected function writeWatermark(string $name = 'green.png', int $width = 10, int $height = 10, int $color = 0x00FF00): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
		$this->writeImage('wm/' . $name, 0, 0, $image);
		return $name;
	}

	/**
	 * @param string $file the watermark file name.
	 * @param array<string, mixed> $properties the filter properties.
	 * @return TImageImagerFilter the filter.
	 */
	protected static function newFilter(string $file, array $properties = []): TImageImagerFilter
	{
		$filter = new TImageImagerFilter();
		$filter->setImageNamespace(static::WATERMARK_ALIAS);
		$filter->setImageFileName($file);
		foreach ($properties as $name => $value) {
			$filter->{'set' . $name}($value);
		}
		return $filter;
	}

	/**
	 * @param \GdImage $image the image.
	 * @param int $color the 0xRRGGBB color.
	 * @return ?array{0:int, 1:int, 2:int, 3:int, 4:int} the count and the inclusive
	 *   [x1, x2, y1, y2] bounds of the color, or null when absent.
	 */
	protected static function colorBounds(\GdImage $image, int $color): ?array
	{
		$count = 0;
		$x1 = $y1 = PHP_INT_MAX;
		$x2 = $y2 = -1;
		for ($y = 0, $h = imagesy($image); $y < $h; $y++) {
			for ($x = 0, $w = imagesx($image); $x < $w; $x++) {
				if (static::rgbAt($image, $x, $y) === $color) {
					$count++;
					$x1 = min($x1, $x);
					$x2 = max($x2, $x);
					$y1 = min($y1, $y);
					$y2 = max($y2, $y);
				}
			}
		}
		return $count ? [$count, $x1, $x2, $y1, $y2] : null;
	}

	public function testDefaults(): void
	{
		$filter = new TImageImagerFilter();

		self::assertSame('Application.Pages', $filter->getImageNamespace());
		self::assertNull($filter->getImageFileName());
		self::assertSame(0.0, $filter->getImageX());
		self::assertSame(0.0, $filter->getImageY());
		self::assertSame(0.0, $filter->getImageFixedWidth());
		self::assertSame(0.0, $filter->getImageFixedHeight());
		self::assertTrue($filter->getImageCropFill());
		self::assertSame('', $filter->getImageAlignX());
		self::assertSame('', $filter->getImageAlignY());
		self::assertSame(IMG_BILINEAR_FIXED, $filter->getImageInterpolationMode());
	}

	public function testProperties(): void
	{
		$filter = new TImageImagerFilter();

		$filter->setImageNamespace('Some.Path');
		$filter->setImageFileName('logo.png');
		self::assertSame('Some.Path', $filter->getImageNamespace());
		self::assertSame('logo.png', $filter->getImageFileName());
		$filter->setImageFileName('');
		self::assertNull($filter->getImageFileName());

		$filter->setImageX('-12.5');
		$filter->setImageY('75%');
		self::assertSame(-12.5, $filter->getImageX());
		self::assertSame('75%', $filter->getImageY());

		$filter->setImageFixedWidth('20');
		$filter->setImageFixedHeight('-5');
		self::assertSame(20.0, $filter->getImageFixedWidth());
		self::assertSame(0.0, $filter->getImageFixedHeight(), 'A negative size is 0.');

		$filter->setImageCropFill('false');
		self::assertFalse($filter->getImageCropFill());

		$filter->setImageAlignX(' Right ');
		$filter->setImageAlignY('CENTER');
		self::assertSame('Right', $filter->getImageAlignX());
		self::assertSame('CENTER', $filter->getImageAlignY());
		$filter->setImageAlignX('');
		self::assertSame('', $filter->getImageAlignX());
	}

	public function testBadAlignment(): void
	{
		foreach (['setImageAlignX' => 'Top', 'setImageAlignY' => 'Left'] as $setter => $value) {
			try {
				(new TImageImagerFilter())->$setter($value);
				self::fail("An exception was expected for $setter($value).");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('propertyvalue_enumvalue_invalid', $e->getErrorCode());
			}
		}
	}

	public function testInterpolationMode(): void
	{
		// Regression: the mode class was referenced in a misspelled namespace.
		$filter = new TImageImagerFilter();

		$filter->setImageInterpolationMode('bicubic');
		self::assertSame(TInterpolationImagerMode::Bicubic, $filter->getImageInterpolationMode());
		$filter->setImageInterpolationMode((string) IMG_NEAREST_NEIGHBOUR);
		self::assertSame(IMG_NEAREST_NEIGHBOUR, $filter->getImageInterpolationMode());
		$filter->setImageInterpolationMode(' Mitchell ');
		self::assertSame(IMG_MITCHELL, $filter->getImageInterpolationMode());
	}

	public function testBadInterpolationMode(): void
	{
		foreach (['Sharpest', '999'] as $mode) {
			try {
				(new TImageImagerFilter())->setImageInterpolationMode($mode);
				self::fail("An exception was expected for $mode.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('imageimagerfilter_bad_mode', $e->getErrorCode());
			}
		}
	}

	public function testNothingToRender(): void
	{
		$image = null;
		self::assertNull(static::newFilter('green.png')->filterImage($image));

		$image = static::createImage();
		self::assertNull((new TImageImagerFilter())->filterImage($image), 'No file name.');
		$filter = static::newFilter('green.png');
		$filter->setImageNamespace('');
		self::assertNull($filter->filterImage($image), 'No namespace.');
	}

	public function testMissingWatermark(): void
	{
		$image = static::createImage();
		try {
			static::newFilter('missing.png')->filterImage($image);
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('imageimagerfilter_bad_image_file_path', $e->getErrorCode());
		}
	}

	public function testUnreadableWatermark(): void
	{
		// Regression: unrecognized data warned and empty data threw a ValueError.
		$this->writeSource('wm/text.png', 'not an image');
		$this->writeSource('wm/empty.png', '');
		$image = static::createImage();

		self::assertFalse(static::newFilter('text.png')->filterImage($image));
		self::assertFalse(static::newFilter('empty.png')->filterImage($image));
	}

	public function testDefaultPlacementIsTopLeft(): void
	{
		// Regression: the default Y alignment drew the watermark above the image.
		$image = static::createImage(100, 100, 0xFF0000, 0xFF0000);

		self::assertTrue(static::newFilter($this->writeWatermark())->filterImage($image));
		self::assertSame([100, 0, 9, 0, 9], static::colorBounds($image, 0x00FF00));
	}

	public static function placementProvider(): array
	{
		return [
			'pixels' => [['ImageX' => '5', 'ImageY' => 7], [100, 5, 14, 7, 16]],
			'negative zero is bottom right' => [['ImageX' => '-0', 'ImageY' => '-0'], [100, 90, 99, 90, 99]],
			'negative zero percent' => [['ImageX' => '-0%', 'ImageY' => '-0%'], [100, 90, 99, 90, 99]],
			'negative pixels' => [['ImageX' => '-5', 'ImageY' => '-10'], [100, 85, 94, 80, 89]],
			'explicit right bottom' => [['ImageX' => '99', 'ImageY' => '99', 'ImageAlignX' => 'Right', 'ImageAlignY' => 'Bottom'], [100, 90, 99, 90, 99]],
			'explicit left top on negative' => [['ImageX' => '-0', 'ImageY' => '-0', 'ImageAlignX' => 'left', 'ImageAlignY' => 'top'], [1, 99, 99, 99, 99]],
			'center' => [['ImageX' => '50%', 'ImageY' => '50%', 'ImageAlignX' => 'Center', 'ImageAlignY' => 'center'], [100, 46, 55, 46, 55]],
			'center on a pixel' => [['ImageX' => 50, 'ImageY' => 50, 'ImageAlignX' => 'Center', 'ImageAlignY' => 'Center'], [100, 46, 55, 46, 55]],
			'percent' => [['ImageX' => '10%', 'ImageY' => '20%'], [100, 10, 19, 20, 29]],
			'fractional pixels' => [['ImageX' => '10.5', 'ImageY' => '0.4'], [100, 11, 20, 0, 9]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('placementProvider')]
	public function testPlacementAndAlignment(array $properties, array $expected): void
	{
		// Regression: right and bottom alignment were one pixel short of "-0".
		$image = static::createImage(100, 100, 0xFF0000, 0xFF0000);

		self::assertTrue(static::newFilter($this->writeWatermark(), $properties)->filterImage($image));
		self::assertSame($expected, static::colorBounds($image, 0x00FF00));
	}

	public function testFixedSizes(): void
	{
		$file = $this->writeWatermark('green.png', 10, 20);
		$cases = [
			'width only' => [['ImageFixedWidth' => 5], [50, 0, 4, 0, 9]],
			'height only' => [['ImageFixedHeight' => '40'], [800, 0, 19, 0, 39]],
			'crop fill' => [['ImageFixedWidth' => 30, 'ImageFixedHeight' => 30], [900, 0, 29, 0, 29]],
			'fit wider' => [['ImageFixedWidth' => 30, 'ImageFixedHeight' => 30, 'ImageCropFill' => 'false'], [450, 0, 14, 0, 29]],
			'fit taller' => [['ImageFixedWidth' => 5, 'ImageFixedHeight' => 30, 'ImageCropFill' => false], [50, 0, 4, 0, 9]],
		];
		foreach ($cases as $case => [$properties, $expected]) {
			$image = static::createImage(100, 100, 0xFF0000, 0xFF0000);
			self::assertTrue(static::newFilter($file, $properties + ['ImageInterpolationMode' => 'NearestNeighbour'])->filterImage($image), $case);
			self::assertSame($expected, static::colorBounds($image, 0x00FF00), $case);
		}
	}

	public function testCropFillTakesTheCenter(): void
	{
		$wide = imagecreatetruecolor(30, 10);
		imagefilledrectangle($wide, 0, 0, 9, 9, 0xFF00FF);
		imagefilledrectangle($wide, 10, 0, 19, 9, 0x00FF00);
		imagefilledrectangle($wide, 20, 0, 29, 9, 0x0000FF);
		$this->writeImage('wm/wide.png', 0, 0, $wide);
		$tall = imagerotate($wide, 90, 0);
		$this->writeImage('wm/tall.png', 0, 0, $tall);

		foreach (['wide.png', 'tall.png'] as $file) {
			$image = static::createImage(40, 40, 0xFF0000, 0xFF0000);
			$filter = static::newFilter($file, ['ImageFixedWidth' => 20, 'ImageFixedHeight' => 20, 'ImageInterpolationMode' => 'NearestNeighbour']);

			self::assertTrue($filter->filterImage($image), $file);
			self::assertSame([400, 0, 19, 0, 19], static::colorBounds($image, 0x00FF00), "$file: only the center third is drawn.");
			self::assertNull(static::colorBounds($image, 0xFF00FF), $file);
			self::assertNull(static::colorBounds($image, 0x0000FF), $file);
		}
	}

	public function testInterpolationModeChangesTheScaledWatermark(): void
	{
		// Regression: imagecopyresampled ignored the interpolation mode.
		$source = imagecreatetruecolor(7, 5);
		for ($y = 0; $y < 5; $y++) {
			for ($x = 0; $x < 7; $x++) {
				imagesetpixel($source, $x, $y, (($x * 37 + $y * 71) * 0x10101) & 0xFFFFFF);
			}
		}
		$this->writeImage('wm/pattern.png', 0, 0, $source);

		$renders = [];
		foreach (['NearestNeighbour', 'BilinearFixed', 'Bicubic'] as $mode) {
			$image = static::createImage(40, 40, 0, 0);
			$filter = static::newFilter('pattern.png', ['ImageFixedWidth' => 35, 'ImageFixedHeight' => 25, 'ImageInterpolationMode' => $mode]);
			self::assertTrue($filter->filterImage($image), $mode);
			$renders[$mode] = md5(serialize(array_map(fn ($x) => static::rgbAt($image, $x, 12), range(0, 34))));
		}
		self::assertCount(3, array_unique($renders), 'Each mode renders differently.');

		$image = static::createImage(40, 40, 0, 0);
		imagesetinterpolation($image, IMG_MITCHELL);
		static::newFilter('pattern.png', ['ImageFixedWidth' => 14, 'ImageInterpolationMode' => 'Box'])->filterImage($image);
		self::assertSame(IMG_MITCHELL, imagegetinterpolation($image), 'The image interpolation mode is not reset.');
	}

	public function testTransparency(): void
	{
		$image = static::createImage(10, 10, 0xFF0000, 0xFF0000);
		self::assertTrue(static::newFilter($this->writeWatermark('half.png', 4, 4, 0x3F0000FF))->filterImage($image));
		self::assertColorNear(0x800080, static::rgbAt($image, 1, 1), 2, 'A half transparent watermark is blended.');
		self::assertSame(0xFF0000, static::rgbAt($image, 5, 5));

		$palette = imagecreate(6, 6);
		$clear = imagecolorallocate($palette, 0, 0, 0);
		imagecolortransparent($palette, $clear);
		imagefilledrectangle($palette, 0, 0, 2, 5, imagecolorallocate($palette, 0, 255, 0));
		$this->writeImage('wm/palette.gif', 0, 0, $palette);
		$image = static::createImage(10, 10, 0xFF0000, 0xFF0000);
		self::assertTrue(static::newFilter('palette.gif')->filterImage($image));
		self::assertSame([18, 0, 2, 0, 5], static::colorBounds($image, 0x00FF00));
		self::assertSame(0xFF0000, static::rgbAt($image, 4, 4), 'The transparent color is not drawn.');
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

	public function testImagickNothingToRender(): void
	{
		$imagick = static::imagickImage(static::createImage());

		self::assertNull(static::newFilter('green.png')->getGraphicsMode(), 'The filter runs in either graphics library.');
		self::assertNull((new TImageImagerFilter())->filterImage($imagick), 'No file name.');
		$filter = static::newFilter('green.png');
		$filter->setImageNamespace('');
		self::assertNull($filter->filterImage($imagick), 'No namespace.');

		$empty = new \Imagick();
		self::assertNull(static::newFilter('green.png')->filterImage($empty), 'No image.');
	}

	public function testImagickMissingWatermark(): void
	{
		$imagick = static::imagickImage(static::createImage());

		try {
			static::newFilter('missing.png')->filterImage($imagick);
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('imageimagerfilter_bad_image_file_path', $e->getErrorCode());
		}
	}

	public function testImagickUnreadableWatermark(): void
	{
		$this->writeSource('wm/text.png', 'not an image');
		$this->writeSource('wm/empty.png', '');
		$imagick = static::imagickImage(static::createImage());

		self::assertFalse(static::newFilter('text.png')->filterImage($imagick), 'Data Imagick does not read.');
		self::assertFalse(static::newFilter('empty.png')->filterImage($imagick), 'An empty file.');
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('placementProvider')]
	public function testImagickPlacementAndAlignment(array $properties, array $expected): void
	{
		$imagick = static::imagickImage(static::createImage(100, 100, 0xFF0000, 0xFF0000));

		self::assertTrue(static::newFilter($this->writeWatermark(), $properties)->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		self::assertSame($expected, static::colorBounds($image, 0x00FF00), 'The watermark is placed as in GD.');
	}

	public function testImagickFixedSizes(): void
	{
		$file = $this->writeWatermark('green.png', 10, 20);
		$cases = [
			'width only' => [['ImageFixedWidth' => 5], [50, 0, 4, 0, 9]],
			'height only' => [['ImageFixedHeight' => '40'], [800, 0, 19, 0, 39]],
			'crop fill' => [['ImageFixedWidth' => 30, 'ImageFixedHeight' => 30], [900, 0, 29, 0, 29]],
			'fit wider' => [['ImageFixedWidth' => 30, 'ImageFixedHeight' => 30, 'ImageCropFill' => 'false'], [450, 0, 14, 0, 29]],
			'fit taller' => [['ImageFixedWidth' => 5, 'ImageFixedHeight' => 30, 'ImageCropFill' => false], [50, 0, 4, 0, 9]],
		];
		foreach ($cases as $case => [$properties, $expected]) {
			$imagick = static::imagickImage(static::createImage(100, 100, 0xFF0000, 0xFF0000));
			self::assertTrue(static::newFilter($file, $properties + ['ImageInterpolationMode' => 'NearestNeighbour'])->filterImage($imagick), $case);

			$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
			self::assertSame($expected, static::colorBounds($image, 0x00FF00), $case);
		}
	}

	public function testImagickCropFillTakesTheCenter(): void
	{
		$wide = imagecreatetruecolor(30, 10);
		imagefilledrectangle($wide, 0, 0, 9, 9, 0xFF00FF);
		imagefilledrectangle($wide, 10, 0, 19, 9, 0x00FF00);
		imagefilledrectangle($wide, 20, 0, 29, 9, 0x0000FF);
		$this->writeImage('wm/wide.png', 0, 0, $wide);
		$tall = imagerotate($wide, 90, 0);
		$this->writeImage('wm/tall.png', 0, 0, $tall);

		foreach (['wide.png', 'tall.png'] as $file) {
			$imagick = static::imagickImage(static::createImage(40, 40, 0xFF0000, 0xFF0000));
			$filter = static::newFilter($file, ['ImageFixedWidth' => 20, 'ImageFixedHeight' => 20, 'ImageInterpolationMode' => 'NearestNeighbour']);

			self::assertTrue($filter->filterImage($imagick), $file);

			$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
			self::assertSame([400, 0, 19, 0, 19], static::colorBounds($image, 0x00FF00), "$file: only the center third is drawn.");
			self::assertNull(static::colorBounds($image, 0xFF00FF), $file);
			self::assertNull(static::colorBounds($image, 0x0000FF), $file);
		}
	}

	public function testImagickInterpolationMode(): void
	{
		$source = imagecreatetruecolor(7, 5);
		for ($y = 0; $y < 5; $y++) {
			for ($x = 0; $x < 7; $x++) {
				imagesetpixel($source, $x, $y, (($x * 37 + $y * 71) * 0x10101) & 0xFFFFFF);
			}
		}
		$this->writeImage('wm/pattern.png', 0, 0, $source);

		$renders = [];
		// Power has no Imagick filter of its own; it scales as the default BilinearFixed.
		foreach (['NearestNeighbour', 'BilinearFixed', 'Bicubic', 'Power'] as $mode) {
			$imagick = static::imagickImage(static::createImage(40, 40, 0, 0));
			$filter = static::newFilter('pattern.png', ['ImageFixedWidth' => 35, 'ImageFixedHeight' => 25, 'ImageInterpolationMode' => $mode]);
			self::assertTrue($filter->filterImage($imagick), $mode);

			$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
			$renders[$mode] = array_map(fn ($x) => static::rgbAt($image, $x, 12), range(0, 34));
		}
		self::assertCount(3, array_unique(array_map('serialize', $renders)), 'Each Imagick filter renders differently.');
		self::assertSame($renders['BilinearFixed'], $renders['Power'], 'An unmapped mode falls back to the default filter.');
	}

	public function testImagickTransparency(): void
	{
		$imagick = static::imagickImage(static::createImage(10, 10, 0xFF0000, 0xFF0000));
		self::assertTrue(static::newFilter($this->writeWatermark('half.png', 4, 4, 0x3F0000FF))->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		self::assertColorNear(0x800080, static::rgbAt($image, 1, 1), 4, 'A half transparent watermark is blended.');
		self::assertSame(0xFF0000, static::rgbAt($image, 5, 5));

		$palette = imagecreate(6, 6);
		$clear = imagecolorallocate($palette, 0, 0, 0);
		imagecolortransparent($palette, $clear);
		imagefilledrectangle($palette, 0, 0, 2, 5, imagecolorallocate($palette, 0, 255, 0));
		$this->writeImage('wm/palette.gif', 0, 0, $palette);
		$imagick = static::imagickImage(static::createImage(10, 10, 0xFF0000, 0xFF0000));
		self::assertTrue(static::newFilter('palette.gif')->filterImage($imagick));

		$image = TAssetImagerBase::convertImage($imagick, TImageGraphicsMode::GD);
		self::assertSame([18, 0, 2, 0, 5], static::colorBounds($image, 0x00FF00));
		self::assertSame(0xFF0000, static::rgbAt($image, 4, 4), 'The transparent color is not drawn.');
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter(static::newFilter($this->writeWatermark()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
