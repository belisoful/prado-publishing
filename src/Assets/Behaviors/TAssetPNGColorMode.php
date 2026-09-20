<?php

/**
 * TAssetPNGColorMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * TAssetPNGColorMode class.
 * TAssetPNGColorMode defines the enumerable type for the possible color modes
 * of {@see TAssetImageFilter::setPngColorMode PngColorMode}, the color format
 * a published PNG image is saved in.
 *
 * The following enumerable values are defined:
 * - TrueColor: Convert the PNG Image to save in a True Color format.
 * - Preserve: Preserve the original format, true color or palette.
 * - Palette: Convert the PNG Image to save in a Palette format.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetPNGColorMode extends \Prado\TEnumerable
{
	/** The PNG image is saved in a True Color format. */
	public const TrueColor = 'TrueColor';

	/** The original color format, true color or palette, is preserved. */
	public const Preserve = 'Preserve';

	/** The PNG image is saved in a Palette format. */
	public const Palette = 'Palette';
}
