<?php

/**
 * TAutoCropImagerFilterMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

/**
 * TAutoCropImagerFilterMode class.
 * TAutoCropImagerFilterMode defines the possible mode that a TAutoCropImagerFilter
 * can be set at by setting {@see TAutoCropImagerFilter::setCropMode CropMode}.
 * In particular, the following modes are defined:
 * - Default: Same as 'Transparent'.
 * - Transparent: Crops out a transparent background: the transparent color of an image
 *   that has one, and otherwise the fully transparent pixels of the alpha channel.
 * - Black: Crops out a black background.
 * - White: Crops out a white background.
 * - Sides: Uses the 4 corners of the image to attempt to detect the background to crop.
 * - Threshold: Crops an image using the given threshold and color.
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAutoCropImagerFilterMode extends \Prado\TEnumerable
{
	public const Default = 'Default';
	public const Transparent = 'Transparent';
	public const Black = 'Black';
	public const White = 'White';
	public const Sides = 'Sides';
	public const Threshold = 'Threshold';
}
