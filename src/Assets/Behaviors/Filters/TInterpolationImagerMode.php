<?php

/**
 * TInterpolationImagerMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

/**
 * TInterpolationImagerMode class.
 * TInterpolationImagerMode defines the possible interpolation modes that a
 * TResizeImagerFilter can be set at by setting {@see TResizeImagerFilter::setResizeMode
 * ResizeMode}, and a TImageImagerFilter by setting {@see
 * TImageImagerFilter::setImageInterpolationMode ImageInterpolationMode}.
 * Some GD builds do not support every mode in imagescale() (eg. Bicubic,
 * BicubicFixed, and Weighted4 with a system libgd); the filters then fall back
 * to imagecopyresampled().
 * In particular, the following modes are defined:
 * - Bell: Bell filter.
 * - Bessel: Bessel filter.
 * - Bicubic: 1-pass, Bicubic interpolation.
 * - BicubicFixed: 1-pass, Fixed point implementation of the bicubic interpolation.
 * - BilinearFixed: 1-pass, Fixed point implementation of the bilinear interpolation (default).
 * - Blackman: Blackman window function.
 * - Box: Box blur filter.
 * - BSpline: Spline interpolation.
 * - Catmullrom: Cubic Hermite spline interpolation.
 * - Gaussian: Gaussian function.
 * - Cubic: Generalized cubic spline fractal interpolation.
 * - Hermite: Hermite interpolation.
 * - Hamming: Hamming filter.
 * - Hanning: Hanning filter.
 * - Mitchell: Mitchell filter.
 * - Power: Power interpolation.
 * - Quadratic: Inverse quadratic interpolation.
 * - Sinc: Sinc function.
 * - NearestNeighbour: 1-pass, Nearest neighbour interpolation.
 * - Weighted4: Weighting filter.
 * - Triangle: Triangle interpolation.
 *
 * All the modes are two pass unless specified.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TInterpolationImagerMode extends \Prado\TEnumerable
{
	public const Bell = IMG_BELL;
	public const Bessel = IMG_BESSEL;
	public const Bicubic = IMG_BICUBIC;
	public const BicubicFixed = IMG_BICUBIC_FIXED;
	public const BilinearFixed = IMG_BILINEAR_FIXED; // Default
	public const Blackman = IMG_BLACKMAN;
	public const Box = IMG_BOX;
	public const BSpline = IMG_BSPLINE;
	public const Catmullrom = IMG_CATMULLROM;
	public const Gaussian = IMG_GAUSSIAN;
	public const Cubic = IMG_GENERALIZED_CUBIC;
	public const Hermite = IMG_HERMITE;
	public const Hamming = IMG_HAMMING;
	public const Hanning = IMG_HANNING;
	public const Mitchell = IMG_MITCHELL;
	public const Power = IMG_POWER;
	public const Quadratic = IMG_QUADRATIC;
	public const Sinc = IMG_SINC;
	public const NearestNeighbour = IMG_NEAREST_NEIGHBOUR;
	public const Weighted4 = IMG_WEIGHTED4; //Not supported in ImageScale()
	public const Triangle = IMG_TRIANGLE;
}
