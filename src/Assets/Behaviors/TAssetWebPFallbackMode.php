<?php

/**
 * TAssetWebPFallbackMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * TAssetWebPFallbackMode class.
 * TAssetWebPFallbackMode defines the enumerable type for the possible formats
 * of {@see TAssetWebPize::setWebPFallback WebPFallback}, the format a matching
 * image is published in when the browser does not accept WebP.
 *
 * The following enumerable values are defined:
 * - Preserve: Preserve the original format; the image is not converted.
 * - JPEG: Convert the Image to save in the JPEG format.
 * - PNG: Convert the Image to save in a True Color PNG format.
 * - PNGPalette: Convert the Image to save in a Palette PNG format.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetWebPFallbackMode extends \Prado\TEnumerable
{
	/** The original format is preserved; the image is not converted. */
	public const Preserve = 'Preserve';

	/** The image is published as a JPEG. */
	public const JPEG = 'JPEG';

	/** The image is published as a True Color PNG. */
	public const PNG = 'PNG';

	/**
	 * The image is published as a Palette PNG of
	 * {@see TAssetWebPize::setWebPFallbackPngPaletteColors WebPFallbackPngPaletteColors}
	 * colors.
	 */
	public const PNGPalette = 'PNGPalette';
}
