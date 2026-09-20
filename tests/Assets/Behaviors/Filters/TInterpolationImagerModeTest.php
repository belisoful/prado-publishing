<?php

/**
 * TInterpolationImagerModeTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\TEnumerable;
use Prado\TPropertyValue;
use Prado\Web\Assets\Behaviors\Filters\TInterpolationImagerMode;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TInterpolationImagerModeTest class.
 *
 * Tests the interpolation mode enumerable maps each name to its GD constant.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TInterpolationImagerModeTest extends PublishingTestCase
{
	public function testModesAreTheGDConstants(): void
	{
		self::assertTrue(is_subclass_of(TInterpolationImagerMode::class, TEnumerable::class));
		self::assertSame([
			'Bell' => IMG_BELL,
			'Bessel' => IMG_BESSEL,
			'Bicubic' => IMG_BICUBIC,
			'BicubicFixed' => IMG_BICUBIC_FIXED,
			'BilinearFixed' => IMG_BILINEAR_FIXED,
			'Blackman' => IMG_BLACKMAN,
			'Box' => IMG_BOX,
			'BSpline' => IMG_BSPLINE,
			'Catmullrom' => IMG_CATMULLROM,
			'Gaussian' => IMG_GAUSSIAN,
			'Cubic' => IMG_GENERALIZED_CUBIC,
			'Hermite' => IMG_HERMITE,
			'Hamming' => IMG_HAMMING,
			'Hanning' => IMG_HANNING,
			'Mitchell' => IMG_MITCHELL,
			'Power' => IMG_POWER,
			'Quadratic' => IMG_QUADRATIC,
			'Sinc' => IMG_SINC,
			'NearestNeighbour' => IMG_NEAREST_NEIGHBOUR,
			'Weighted4' => IMG_WEIGHTED4,
			'Triangle' => IMG_TRIANGLE,
		], (new \ReflectionClass(TInterpolationImagerMode::class))->getConstants());
	}

	public function testModeValuesResolveCaseInsensitively(): void
	{
		self::assertSame(IMG_NEAREST_NEIGHBOUR, TPropertyValue::ensureEnumValue('nearestneighbour', TInterpolationImagerMode::class));
		self::assertSame(IMG_MITCHELL, TPropertyValue::ensureEnumValue('MITCHELL', TInterpolationImagerMode::class));
	}
}
