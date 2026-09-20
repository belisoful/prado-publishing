<?php

/**
 * TAssetImageMetaDataMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * TAssetImageMetaDataMode class.
 * TAssetImageMetaDataMode defines the enumerable type for the image metadata
 * carriers of {@see TAssetImageMetaData::setMetaDataPreserve MetaDataPreserve},
 * the carriers kept when the published image is written.
 *
 * The following enumerable values are defined:
 * - None: no metadata is preserved.
 * - Color: the ICC color profile is preserved.
 * - EXIF: the EXIF metadata is preserved.
 * - IPTC: the IPTC metadata is preserved.
 * - XMP: the XMP metadata is preserved.
 * - All: every carrier the published format has a place for is preserved.
 *
 * MetaDataPreserve takes a list, so the carriers can be combined, e.g.
 * "EXIF, Color".  A carrier the published format has no place for is dropped.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetImageMetaDataMode extends \Prado\TEnumerable
{
	/** No metadata is preserved. */
	public const None = 'None';

	/** The ICC color profile is preserved; the JPEG APP2, APP11, and APP14 segments. */
	public const Color = 'Color';

	/** The EXIF metadata is preserved. */
	public const EXIF = 'EXIF';

	/** The IPTC metadata is preserved. */
	public const IPTC = 'IPTC';

	/** The XMP metadata is preserved. */
	public const XMP = 'XMP';

	/** Every carrier the published format has a place for is preserved. */
	public const All = 'All';
}
