<?php

/**
 * TBaseImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\TComponent;
use Prado\Util\IBaseBehavior;
use Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPNG;
use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TBoxBlurImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\Fixtures\TArrayConvolveImagerFilter;
use Prado\Web\Tests\Fixtures\TConvolveRecorder;
use Prado\Web\Tests\Fixtures\TDualImagerFilter;
use Prado\Web\Tests\Fixtures\TKernelConvolveImagerFilter;
use Prado\Web\Tests\Fixtures\TImagickImagerFilter;
use Prado\Web\Tests\Fixtures\TRecordingImagerFilter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TBaseImagerFilterTest class.
 *
 * Tests the base imager filter: a plain component with an Enabled property, run by an
 * imager with the publishing parameter (the replaced image and the save flag), and the
 * static helpers for image properties, placement, and scaling.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TBaseImagerFilterTest extends PublishingTestCase
{
	public function testIsAComponentNotABehavior(): void
	{
		$filter = new TRecordingImagerFilter();

		self::assertInstanceOf(TComponent::class, $filter);
		self::assertInstanceOf(IBaseImagerFilter::class, $filter);
		self::assertNotInstanceOf(IBaseBehavior::class, $filter);
	}

	public function testEnabled(): void
	{
		$filter = new TRecordingImagerFilter();
		self::assertTrue($filter->getEnabled());

		$filter->setEnabled('false');
		self::assertFalse($filter->getEnabled());
		$filter->setEnabled(true);
		self::assertTrue($filter->getEnabled());
	}

	public function testTheGraphicsModeFollowsTheImplementedFilters(): void
	{
		self::assertSame(TImageGraphicsMode::GD, (new TBoxBlurImagerFilter())->getGraphicsMode(), 'A filter of GD calls works in GD.');
		self::assertSame(TImageGraphicsMode::Imagick, (new TImagickImagerFilter())->getGraphicsMode(), 'A filter of Imagick calls works in Imagick.');
		self::assertNull((new TDualImagerFilter())->getGraphicsMode(), 'A filter of both works in either.');
		self::assertSame(TImageGraphicsMode::GD, (new TRecordingImagerFilter())->getGraphicsMode(), 'A filter that implements neither defaults to GD.');
	}

	public function testFilterImageDispatchesByTheLibraryOfTheImage(): void
	{
		$filter = new TDualImagerFilter();
		$gd = static::createImage(4, 4);

		self::assertTrue($filter->filterImage($gd));
		self::assertSame(['GD'], $filter->calls);

		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$imagick = TImageGraphics::decode((string) TPNG::fromImage($gd), TImageGraphicsMode::Imagick);
		self::assertTrue($filter->filterImage($imagick));
		self::assertSame(['GD', 'Imagick'], $filter->calls);
	}

	public function testAFilterWithoutTheLibraryOfTheImageDoesNothing(): void
	{
		$filter = new TBoxBlurImagerFilter();
		$image = null;

		self::assertNull(static::invokeFilter($filter, 'filterImagickImage', $image), 'The Imagick filter of a GD filter does nothing.');
		self::assertNull(static::invokeFilter(new TImagickImagerFilter(), 'filterGdImage', $image), 'And the other way around.');
	}

	/**
	 * @param object $filter the filter.
	 * @param string $method the protected filter method.
	 * @param mixed &$image the image passed by reference.
	 * @return ?bool the result of the method.
	 */
	protected static function invokeFilter($filter, string $method, &$image): ?bool
	{
		return (new \ReflectionMethod($filter, $method))->invokeArgs($filter, [&$image, null]);
	}

	public function testAChangedImageIsStoredAndSaved(): void
	{
		$filter = new TRecordingImagerFilter();
		$filter->replacement = static::createImage(10, 10);
		$original = static::createImage();
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage($original);

		self::assertTrue(static::applyFilter($filter, $param));

		self::assertSame($filter->replacement, $param->getImage());
		self::assertTrue($param->getSaveImage(), 'A changed image is flagged to be saved.');
		self::assertCount(1, $filter->calls);
		self::assertSame($original, $filter->calls[0]['image']);
		self::assertSame($param, $filter->calls[0]['param'], 'The filter is given the publishing parameter.');
	}

	public function testAnUnchangedImageIsNotSaved(): void
	{
		foreach ([false, null] as $result) {
			$filter = new TRecordingImagerFilter();
			$filter->result = $result;
			$filter->replacement = static::createImage(10, 10);
			$original = static::createImage();
			$param = new TAssetEventParameter('onProcessAsset');
			$param->setImage($original);

			self::assertFalse(static::applyFilter($filter, $param));
			self::assertSame($original, $param->getImage(), 'An unchanged image is not stored.');
			self::assertFalse($param->getSaveImage());
		}
	}

	public function testAnEarlierSaveFlagIsKept(): void
	{
		$filter = new TRecordingImagerFilter();
		$filter->result = false;
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());
		$param->setSaveImage(true);

		self::assertFalse(static::applyFilter($filter, $param));
		self::assertTrue($param->getSaveImage());
	}

	public function testADisabledFilterOrAMissingImageIsSkipped(): void
	{
		$filter = new TRecordingImagerFilter();
		self::assertFalse(static::applyFilter($filter, new TAssetEventParameter('onProcessAsset')));

		$filter->setEnabled(false);
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());
		self::assertFalse(static::applyFilter($filter, $param));
		self::assertSame([], $filter->calls);
		self::assertFalse($param->getSaveImage());
	}

	public function testConvolveImagickImagePassesTheKernelTheExtensionTakes(): void
	{
		$matrix = [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0], [7.0, 8.0, 9.0]];
		// Imagick 3.8 and later take an ImagickKernel.
		$recorder = new TConvolveRecorder();
		self::assertTrue((new \ReflectionMethod(TKernelConvolveImagerFilter::class, 'convolveImagickImage'))->invoke(null, $recorder, $matrix));
		self::assertInstanceOf(\ImagickKernel::class, $recorder->kernel);
		self::assertSame($matrix, $recorder->kernel->getMatrix());

		// The versions before it take the flat array of the matrix.
		$recorder = new TConvolveRecorder();
		self::assertTrue((new \ReflectionMethod(TArrayConvolveImagerFilter::class, 'convolveImagickImage'))->invoke(null, $recorder, $matrix));
		self::assertSame([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0], $recorder->kernel);
	}

	public function testUsesImagickKernelFollowsTheExtension(): void
	{
		$expected = ((new \ReflectionMethod(\Imagick::class, 'convolveImage'))->getParameters()[0] ?? null)?->getType();
		$expected = $expected instanceof \ReflectionNamedType && $expected->getName() === 'ImagickKernel';

		self::assertSame($expected, (new \ReflectionMethod(TBaseImagerFilter::class, 'usesImagickKernel'))->invoke(null));
	}

	public function testTransferImageProperties(): void
	{
		$source = imagecreate(4, 4);
		imagecolorallocate($source, 0, 0, 0);
		$clear = imagecolorallocatealpha($source, 10, 20, 30, 127);
		imagecolortransparent($source, $clear);
		$target = imagecreatetruecolor(4, 4);

		TBaseImagerFilter::transferImageProperties($target, $source);
		self::assertSame(0x7F0A141E, imagecolortransparent($target));

		$plain = imagecreatetruecolor(4, 4);
		TBaseImagerFilter::transferImageProperties($plain, imagecreatetruecolor(4, 4));
		self::assertSame(-1, imagecolortransparent($plain));

		TBaseImagerFilter::transferImageProperties(null, $source);
		TBaseImagerFilter::transferImageProperties($plain, false);
		self::assertSame(-1, imagecolortransparent($plain), 'A missing image is ignored.');
	}

	public static function placementProvider(): array
	{
		return [
			'null' => [null, 100, 0.0],
			'pixels' => [10, 100, 10.0],
			'numeric string' => ['10', 100, 10.0],
			'fractional pixels round' => ['10.5', 100, 11.0],
			'negative pixels' => [-10, 100, 89.0],
			'negative string' => ['-10', 100, 89.0],
			'negative zero string' => ['-0', 100, 99.0],
			'negative zero float' => [-0.0, 100, 99.0],
			'zero' => [0, 100, 0.0],
			'percent' => ['50%', 101, 50.0],
			'zero percent' => ['0%', 100, 0.0],
			'full percent' => ['100%', 100, 99.0],
			'negative zero percent' => ['-0%', 100, 99.0],
			'negative percent' => ['-10%', 101, 90.0],
			'padded percent' => [' 25% ', 101, 25.0],
		];
	}

	/**
	 * @param mixed $place
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('placementProvider')]
	public function testComputePlacement($place, float $size, float $expected): void
	{
		self::assertSame($expected, TBaseImagerFilter::computePlacement($place, $size));
	}

	public function testIsNegativePlacement(): void
	{
		foreach ([-1, '-1', -0.0, '-0', '-0%', '-25%', ' -5% '] as $place) {
			self::assertTrue(TBaseImagerFilter::isNegativePlacement($place), var_export($place, true) . ' is negative.');
		}
		foreach ([null, 0, 0.0, '0', '0%', '5', '25%'] as $place) {
			self::assertFalse(TBaseImagerFilter::isNegativePlacement($place), var_export($place, true) . ' is not negative.');
		}
	}

	public function testImageScaleWithMode(): void
	{
		self::assertFalse(TBaseImagerFilter::imageScaleWithMode(null, 10, 10));

		$scaled = TBaseImagerFilter::imageScaleWithMode(static::createImage(40, 20), 20, 10, IMG_NEAREST_NEIGHBOUR);
		self::assertSame([20, 10], [imagesx($scaled), imagesy($scaled)]);
		self::assertSame(0xFF0000, static::rgbAt($scaled, 2, 2));
		self::assertSame(0x3366CC, static::rgbAt($scaled, 17, 8));

		$tiny = TBaseImagerFilter::imageScaleWithMode(static::createImage(40, 20), 0, -5);
		self::assertSame([1, 1], [imagesx($tiny), imagesy($tiny)], 'The size is at least one pixel.');
	}

	public function testImageScaleWithAnUnsupportedModeResamples(): void
	{
		// imagescale() does not support Weighted4, so the image is resampled.
		$source = imagecreatetruecolor(10, 10);
		imagealphablending($source, false);
		imagefilledrectangle($source, 0, 0, 9, 9, 0x7F000000);
		imagefilledrectangle($source, 0, 0, 4, 9, 0x00FF00);

		$scaled = TBaseImagerFilter::imageScaleWithMode($source, 20, 20, IMG_WEIGHTED4);

		self::assertSame([20, 20], [imagesx($scaled), imagesy($scaled)]);
		self::assertSame(0x00FF00, imagecolorat($scaled, 2, 10));
		self::assertSame(127, (imagecolorat($scaled, 17, 10) >> 24) & 0x7F, 'The alpha channel is preserved.');
	}

	public function testImageScaleWithModeFailsForAnImageTooLargeForGd(): void
	{
		// 65536 * 65536 pixels exceeds INT_MAX, so both imagescale() and imagecreatetruecolor() fail.
		$warnings = [];
		set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
			if (error_reporting() & $errno) {
				$warnings[] = $errstr; // Not a warning silenced with @.
			}
			return true;
		}, E_WARNING);
		try {
			self::assertFalse(TBaseImagerFilter::imageScaleWithMode(static::createImage(2, 2), 65536, 65536));
		} finally {
			restore_error_handler();
		}
		self::assertSame([], $warnings, 'The failed GD allocation is silenced.');
	}
}
