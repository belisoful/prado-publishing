<?php

/**
 * TGammaCorrectImagerFilterTest class file.
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
use Prado\Web\Assets\Behaviors\Filters\TGammaCorrectImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TGammaCorrectImagerFilterTest class.
 *
 * Tests the gamma correction filter and its gamma validation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGammaCorrectImagerFilterTest extends PublishingTestCase
{
	public function testGammas(): void
	{
		$filter = new TGammaCorrectImagerFilter();
		self::assertSame(1.0, $filter->getInputGamma());
		self::assertSame(1.0, $filter->getOutputGamma());

		$filter->setInputGamma('2.2');
		$filter->setOutputGamma(0.5);
		self::assertSame(2.2, $filter->getInputGamma());
		self::assertSame(0.5, $filter->getOutputGamma());
	}

	public static function badGammaProvider(): array
	{
		return [
			'input zero' => ['setInputGamma', '0'],
			'input negative' => ['setInputGamma', -1],
			'input not a number' => ['setInputGamma', 'abc'],
			'input infinite' => ['setInputGamma', INF],
			'output zero' => ['setOutputGamma', 0.0],
			'output not a number' => ['setOutputGamma', NAN],
		];
	}

	/**
	 * @param mixed $value
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badGammaProvider')]
	public function testBadGamma(string $setter, $value): void
	{
		// Regression: a gamma that is not positive threw a ValueError when filtering.
		$filter = new TGammaCorrectImagerFilter();
		try {
			$filter->$setter($value);
			self::fail('An exception was expected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('gammacorrectimagerfilter_bad_gamma', $e->getErrorCode());
		}
		self::assertSame(1.0, $filter->getInputGamma());
		self::assertSame(1.0, $filter->getOutputGamma());
	}

	public function testNoImage(): void
	{
		$image = null;
		self::assertNull((new TGammaCorrectImagerFilter())->filterImage($image));
	}

	public function testGammaCorrection(): void
	{
		$filter = new TGammaCorrectImagerFilter();
		$filter->setOutputGamma('2.2');
		$image = static::createImage(4, 4, 0x808080, 0x808080);

		self::assertTrue($filter->filterImage($image));
		self::assertSame(0xBABABA, static::rgbAt($image, 1, 1));
	}

	public static function imagickGammaProvider(): array
	{
		// The gammas and the GD corrected value of a 0x808080 gray.
		return [
			'brightened' => [1.0, 2.2, 0xBABABA],
			'darkened' => [2.2, 0.5, 0x0C0C0C],
			'unchanged' => [1.5, 1.5, 0x808080],
		];
	}

	/**
	 * @param float $input the input gamma.
	 * @param float $output the output gamma.
	 * @param int $expected the 0xRRGGBB GD result of the correction.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('imagickGammaProvider')]
	public function testImagickGammaCorrection(float $input, float $output, int $expected): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TGammaCorrectImagerFilter();
		$filter->setInputGamma($input);
		$filter->setOutputGamma($output);
		$image = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(4, 4, 0x808080, 0x808080)), TImageGraphicsMode::Imagick);

		self::assertTrue($filter->filterImage($image));

		$gd = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $gd);
		self::assertColorNear($expected, static::rgbAt($gd, 1, 1), 2, 'Imagick corrects the gamma in the direction GD does.');
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter((new TGammaCorrectImagerFilter()), $param));
		self::assertTrue($param->getSaveImage());
	}
}
