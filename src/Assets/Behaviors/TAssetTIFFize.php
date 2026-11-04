<?php

/**
 * TAssetTIFFize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetTIFFize
 *
 * This is a sub-class of {@see TAssetImageFilter} that converts the published image of
 * matching file paths ({@see getMatchFiles MatchFiles}) to TIFF, with the compression of
 * {@see TAssetImageFilter::getTiffCompression TiffCompression}.
 *
 * GD has no TIFF encoder; the image is encoded by the
 * {@see https://github.com/belisoful/prado-image prado-image} TIFF container, which also
 * reads TIFF sources, so a TIFF publishes like any other image.  A TIFF carries its XMP,
 * IPTC, and ICC profile, and the kept EXIF of the source is written as the tags of its
 * first image directory, following MetaDataPreserve and Scrub.  A TIFF has no alpha
 * channel, so the image is flattened onto the
 * {@see TAssetImageFilter::getBackgroundColor BackgroundColor}.
 *
 * ```xml
 * <behavior name="TIFFize" Class="Prado\Web\Assets\Behaviors\TAssetTIFFize"
 *     AttachToClass="Prado\Web\Assets\TImageAsset" MatchFiles="/\/print\//i"
 *     TiffCompression="PackBits" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 */
class TAssetTIFFize extends TAssetImageFilter
{
	/**
	 * Matched files are given the ".tif" file extension.
	 * @param null|string $filePath The file path to rewrite into a tif if its an image
	 * @param null|\Prado\Util\TCallChain $callchain
	 * @return string the new file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		$filePath = $this->renameForConversion($filePath, IMAGETYPE_TIFF_II, 'tif');
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * @return int the image type this conversion writes, IMAGETYPE_TIFF_II.
	 */
	protected function getConversionType(): ?int
	{
		return IMAGETYPE_TIFF_II;
	}

	/**
	 * Changes the Image type to save the image as TIFF.  This is raised
	 * only for assets when their AssetFilePath matches MatchFiles.
	 * @param TAssetEventParameter $parentParam the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $parentParam): bool
	{
		$parentParam->setImageType(IMAGETYPE_TIFF_II);
		$parentParam->setPaletteColors(0);
		return parent::applyFilters($parentParam);
	}
}
