<?php

/**
 * TAssetImageMetaData class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TEXIFTags;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFIFFormat;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPrivacyCategory;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TInterpolationImagerMode;

/**
 * TAssetImageMetaData class
 *
 * The image metadata of an asset being published by a {@see TAssetImagerBase}. The
 * imager creates it, configured by the imager's `<metadata>` element, and shares it with
 * the filters as the publishing parameter's
 * {@see \Prado\Web\Assets\TAssetEventParameter::getImageMetaData ImageMetaData}. It loads
 * the {@see getMetaDataSource MetaDataSource} image with the
 * {@see https://github.com/belisoful/prado-image prado-image} containers
 * ({@see \Prado\IO\Image\TImageFile}): JPEG, PNG, WebP, GIF, and TIFF.
 *
 * The EXIF, XMP, IPTC, and ICC profile carriers are read once and held here, so the
 * image filters edit them ({@see \Prado\Web\Assets\Behaviors\Filters\TOrientImagerFilter}
 * resets the orientation, {@see \Prado\Web\Assets\Behaviors\Filters\TMetaDataImagerFilter}
 * sets fields). When the image is saved, {@see writeMetaData} writes them into the
 * published image:
 * - {@see getMetaDataPreserve MetaDataPreserve} selects the carriers kept: `EXIF`,
 *   `IPTC`, `XMP`, `Color` (the ICC profile), `All` (default), or `None`.
 * - {@see getScrub Scrub} removes identifying information by
 *   {@see \Prado\IO\Image\TPrivacyCategory} (e.g. "Location, Author" or "All").
 * - The dimensions recorded in EXIF and IPTC follow a resized image, and a stale EXIF
 *   thumbnail is regenerated.
 * - A JPEG gets the JFIF header and thumbnail selected by {@see getJFIFMode JFIFMode}.
 * - {@see getIPTCPublishEncoding IPTCPublishEncoding} sets the IPTC character set.
 *
 * A carrier the published format has no place for is dropped: GIF has no EXIF, and
 * GIF and WebP have no IPTC. A format prado-image does not read (BMP, WBMP, XBM)
 * publishes without metadata.
 *
 * ```xml
 * <behavior name="photos" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
 *     AttachToClass="Prado\Web\Assets\TImageAsset" ImageQuality="80">
 *   <metadata Scrub="Location, SerialNumber" JFIFMode="Thumbnail" JFIFSize="120x90" />
 *   <filter type="Orient" />
 *   <filter type="Resize" MaximumWidth="1920" MaximumHeight="1080" />
 * </behavior>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetImageMetaData extends TComponent
{
	/** The EXIF IFD0 Orientation tag. */
	public const ORIENTATION_TAG = 274;

	/** @var ?string the image file the metadata is read from. */
	private ?string $_filePath = null;

	/** @var null|array|false the getimagesize() result of the source, false when not an image. */
	private $_info;

	/** @var null|false|TImageFile the source container, false when prado-image cannot read it. */
	private $_imageFile;

	/** @var array<string, mixed> the carriers read from the source, keyed by carrier name. */
	private array $_carriers = [];

	/** @var bool whether a carrier was edited since loading. */
	private bool $_changed = false;

	/** @var string[] the kept carriers, from {@see TAssetImageMetaDataMode}. */
	private array $_preserve = [TAssetImageMetaDataMode::All];

	/** @var int the {@see TPrivacyCategory} bits scrubbed on writing. */
	private int $_scrub = 0;

	/** @var int the JFIF header mode for JPEGs, from {@see TJFIFFormat}. */
	private int $_jfifMode = TJFIFFormat::JFIF;

	/** @var string the JFIF/JFXX thumbnail size. */
	private string $_jfifSize = '160x120';

	/** @var bool whether the JFIF/JFXX thumbnail crops to fill its size. */
	private bool $_jfifCropFill = true;

	/** @var int the JFXX JPEG thumbnail quality. */
	private int $_jfxxJpegQuality = 80;

	/** @var bool whether an EXIF thumbnail is generated even when the source had none. */
	private bool $_exifThumbnail = false;

	/** @var string the EXIF thumbnail size. */
	private string $_exifThumbnailSize = '160x120';

	/** @var bool whether the EXIF thumbnail crops to fill its size. */
	private bool $_exifThumbnailCropFill = true;

	/** @var int the EXIF thumbnail JPEG quality. */
	private int $_exifThumbnailJpegQuality = 80;

	/** @var int the GD interpolation mode for scaling thumbnails. */
	private int $_metaInterpolationMode = TInterpolationImagerMode::BilinearFixed;

	/** @var string the IPTC character set on publishing, or "Preserve", or "None". */
	private string $_iptcPublishEncoding = 'UTF-8';

	/**
	 * @return int the GD interpolation mode for scaling thumbnails, default BilinearFixed.
	 */
	public function getMetaInterpolationMode(): int
	{
		return $this->_metaInterpolationMode;
	}

	/**
	 * @param int|string $value the interpolation mode, a {@see TInterpolationImagerMode}
	 *   name or value.
	 * @throws TInvalidDataValueException when the mode is not a TInterpolationImagerMode.
	 */
	public function setMetaInterpolationMode($value): void
	{
		$modes = array_change_key_case((new \ReflectionClass(TInterpolationImagerMode::class))->getConstants());
		if (is_numeric($value)) {
			if (!in_array((int) $value, $modes, true)) {
				throw new TInvalidDataValueException('metadata_bad_mode', $value);
			}
			$value = (int) $value;
		} else {
			$key = strtolower(trim((string) $value));
			if (!array_key_exists($key, $modes)) {
				throw new TInvalidDataValueException('metadata_bad_mode', $value);
			}
			$value = $modes[$key];
		}
		$this->_metaInterpolationMode = $value;
	}

	/**
	 * @return string[] the kept carriers, {@see TAssetImageMetaDataMode} values; default ['All'].
	 */
	public function getMetaDataPreserve(): array
	{
		return $this->_preserve;
	}

	/**
	 * @param array|string $value the kept carriers, as an array or a comma-separated list
	 *   of {@see TAssetImageMetaDataMode} values.
	 */
	public function setMetaDataPreserve($value): void
	{
		$modes = is_array($value) ? $value : explode(',', (string) $value);
		$preserve = [];
		foreach ($modes as $mode) {
			if (($mode = trim((string) $mode)) !== '') {
				$preserve[] = TPropertyValue::ensureEnum($mode, TAssetImageMetaDataMode::class);
			}
		}
		$this->_preserve = $preserve ?: [TAssetImageMetaDataMode::None];
	}

	/**
	 * @param string $mode a {@see TAssetImageMetaDataMode} carrier.
	 * @return bool whether the carrier is kept on writing.
	 */
	public function getIsPreserved(string $mode): bool
	{
		if (in_array(TAssetImageMetaDataMode::None, $this->_preserve, true)) {
			return false;
		}
		return in_array(TAssetImageMetaDataMode::All, $this->_preserve, true) || in_array($mode, $this->_preserve, true);
	}

	/**
	 * @return int the {@see TPrivacyCategory} bits removed on writing, default 0.
	 */
	public function getScrub(): int
	{
		return $this->_scrub;
	}

	/**
	 * @param int|string $value the privacy categories to remove on writing: an integer
	 *   of {@see TPrivacyCategory} bits, or a comma-separated list of its names
	 *   (e.g. "Location, Author", "Identity", "All"); "" or "None" removes nothing.
	 * @throws TInvalidDataValueException when a name is not a TPrivacyCategory.
	 */
	public function setScrub($value): void
	{
		if (is_numeric($value)) {
			$this->_scrub = (int) $value;
			return;
		}
		$categories = array_change_key_case((new \ReflectionClass(TPrivacyCategory::class))->getConstants());
		$scrub = 0;
		foreach (explode(',', (string) $value) as $name) {
			$key = strtolower(trim($name));
			if ($key === '' || $key === 'none') {
				continue;
			}
			if (!array_key_exists($key, $categories)) {
				throw new TInvalidDataValueException('metadata_bad_scrub', $name);
			}
			$scrub |= $categories[$key];
		}
		$this->_scrub = $scrub;
	}

	/**
	 * @return int the JFIF header mode for published JPEGs, a {@see TJFIFFormat} value,
	 *   default JFIF (the header without a thumbnail).
	 */
	public function getJFIFMode(): int
	{
		return $this->_jfifMode;
	}

	/**
	 * @param int|string $value the JFIF header mode, a {@see TJFIFFormat} name or value:
	 *   None, JFIF, Thumbnail, JFXXJPEG, JFXXPalette, JFXXColor, or JFXXEfficiency.
	 * @throws TInvalidDataValueException when the mode is not a TJFIFFormat.
	 */
	public function setJFIFMode($value): void
	{
		$formats = (new \ReflectionClass(TJFIFFormat::class))->getConstants();
		if (is_int($value) || (is_string($value) && ctype_digit($value))) {
			if (!in_array((int) $value, $formats, true)) {
				throw new TInvalidDataValueException('metadata_bad_jfif_mode', $value);
			}
			$this->_jfifMode = (int) $value;
			return;
		}
		foreach ($formats as $name => $format) {
			if (strcasecmp($name, trim((string) $value)) === 0) {
				$this->_jfifMode = $format;
				return;
			}
		}
		throw new TInvalidDataValueException('metadata_bad_jfif_mode', $value);
	}

	/**
	 * @return string the JFIF/JFXX thumbnail size, "width" or "width x height"; default "160x120".
	 */
	public function getJFIFSize(): string
	{
		return $this->_jfifSize;
	}

	/**
	 * @param string $value the JFIF/JFXX thumbnail size, "width" or "width x height",
	 *   each at most 255.
	 * @throws TInvalidDataValueException when the size is malformed or too large.
	 */
	public function setJFIFSize($value): void
	{
		[$width, $height] = static::parseSize($value, 'metadata_bad_jfif_size');
		if ($width > 255 || $height > 255) {
			throw new TInvalidDataValueException('metadata_jfif_size_too_large', $width, $height);
		}
		$this->_jfifSize = TPropertyValue::ensureString($value);
	}

	/**
	 * @return bool whether the JFIF/JFXX thumbnail crops to fill its size, default true.
	 */
	public function getJFIFCropFill(): bool
	{
		return $this->_jfifCropFill;
	}

	/**
	 * @param bool|string $value whether the JFIF/JFXX thumbnail crops to fill its size.
	 */
	public function setJFIFCropFill($value): void
	{
		$this->_jfifCropFill = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int the JFXX JPEG thumbnail quality, default 80.
	 */
	public function getJFXXJpegQuality(): int
	{
		return $this->_jfxxJpegQuality;
	}

	/**
	 * @param int|string $value the JFXX JPEG thumbnail quality, 0..100.
	 */
	public function setJFXXJpegQuality($value): void
	{
		$this->_jfxxJpegQuality = min(100, max(0, TPropertyValue::ensureInteger($value)));
	}

	/**
	 * @return bool whether an EXIF thumbnail is generated even when the source had none,
	 *   default false. A source thumbnail is always regenerated when the image changes.
	 */
	public function getExifThumbnail(): bool
	{
		return $this->_exifThumbnail;
	}

	/**
	 * @param bool|string $value whether an EXIF thumbnail is generated even when the
	 *   source had none.
	 */
	public function setExifThumbnail($value): void
	{
		$this->_exifThumbnail = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return string the EXIF thumbnail size, default "160x120".
	 */
	public function getExifThumbnailSize(): string
	{
		return $this->_exifThumbnailSize;
	}

	/**
	 * @param string $value the EXIF thumbnail size, "width" or "width x height".
	 * @throws TInvalidDataValueException when the size is malformed.
	 */
	public function setExifThumbnailSize($value): void
	{
		static::parseSize($value, 'metadata_bad_exif_thumbnail_size');
		$this->_exifThumbnailSize = TPropertyValue::ensureString($value);
	}

	/**
	 * @return bool whether the EXIF thumbnail crops to fill its size, default true.
	 */
	public function getExifThumbnailCropFill(): bool
	{
		return $this->_exifThumbnailCropFill;
	}

	/**
	 * @param bool|string $value whether the EXIF thumbnail crops to fill its size.
	 */
	public function setExifThumbnailCropFill($value): void
	{
		$this->_exifThumbnailCropFill = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int the EXIF thumbnail JPEG quality, default 80.
	 */
	public function getExifThumbnailJpegQuality(): int
	{
		return $this->_exifThumbnailJpegQuality;
	}

	/**
	 * @param int|string $value the EXIF thumbnail JPEG quality, 0..100.
	 */
	public function setExifThumbnailJpegQuality($value): void
	{
		$this->_exifThumbnailJpegQuality = min(100, max(0, TPropertyValue::ensureInteger($value)));
	}

	/**
	 * @return string the IPTC character set written on publishing, default "UTF-8".
	 *   "Preserve" keeps the source's, and "None" removes the declaration.
	 */
	public function getIPTCPublishEncoding(): string
	{
		return $this->_iptcPublishEncoding;
	}

	/**
	 * @param string $value the IPTC character set written on publishing, "Preserve", or "None".
	 */
	public function setIPTCPublishEncoding($value): void
	{
		$this->_iptcPublishEncoding = TPropertyValue::ensureString($value);
	}

	/**
	 * @return ?string the image file the metadata is read from.
	 */
	public function getMetaDataSource(): ?string
	{
		return $this->_filePath;
	}

	/**
	 * Sets the image file the metadata is read from, discarding any loaded metadata.
	 * @param ?string $filePath the image file.
	 */
	public function setMetaDataSource($filePath): void
	{
		$this->_filePath = $filePath === null ? null : TPropertyValue::ensureString($filePath);
		$this->_info = null;
		$this->_imageFile = null;
		$this->_carriers = [];
		$this->_changed = false;
	}

	/**
	 * @return false|int the IMAGETYPE_* constant of the source image, false when it is not an image.
	 */
	public function getImageType()
	{
		if ($this->_info === null) {
			$this->_info = ($this->_filePath !== null && is_file($this->_filePath)) ? @getimagesize($this->_filePath) : false;
		}
		return is_array($this->_info) ? $this->_info[2] : false;
	}

	/**
	 * @return ?TImageFile the prado-image container of the source, or null when the
	 *   source is missing or in a format prado-image does not read.
	 */
	public function getImageFile(): ?TImageFile
	{
		if ($this->_imageFile === null) {
			$this->_imageFile = false;
			if ($this->_filePath !== null && is_file($this->_filePath)) {
				try {
					$this->_imageFile = TImageFile::fromFile($this->_filePath);
				} catch (\Exception $e) {
					$this->_imageFile = false;
				}
			}
		}
		return $this->_imageFile ?: null;
	}

	/**
	 * Reads a carrier from the source container once.
	 * @param string $name the carrier: EXIF, XMP, IPTC, ICC, JFIF, or JFXX.
	 * @return mixed the carrier, or null.
	 */
	protected function carrier(string $name)
	{
		if (!array_key_exists($name, $this->_carriers)) {
			$value = null;
			if ($file = $this->getImageFile()) {
				try {
					$value = match ($name) {
						'EXIF' => $file->getEXIF(),
						'XMP' => $file->getXMP(),
						'IPTC' => $file->getIPTC(),
						'ICC' => $file->getICCProfile(),
						'JFIF' => $file instanceof TJPEG ? $file->getJFIF() : null,
						'JFXX' => $file instanceof TJPEG ? $file->getJFXX() : null,
					};
				} catch (\Exception $e) {
					$value = null;
				}
			}
			$this->_carriers[$name] = $value;
		}
		return $this->_carriers[$name];
	}

	/**
	 * Reads every carrier from the source now. The imager calls this before it re-encodes
	 * the file the metadata is read from.
	 */
	public function loadMetaData(): void
	{
		foreach (['EXIF', 'XMP', 'IPTC', 'ICC', 'JFIF', 'JFXX'] as $name) {
			$this->carrier($name);
		}
	}

	/**
	 * @return ?TEXIF the EXIF metadata, edited in place.
	 */
	public function getEXIF(): ?TEXIF
	{
		return $this->carrier('EXIF');
	}

	/**
	 * @param ?TEXIF $value the EXIF metadata, or null to remove it.
	 */
	public function setEXIF(?TEXIF $value): void
	{
		$this->_carriers['EXIF'] = $value;
		$this->_changed = true;
	}

	/**
	 * @return ?TXMP the XMP metadata, edited in place.
	 */
	public function getXMP(): ?TXMP
	{
		return $this->carrier('XMP');
	}

	/**
	 * @param ?TXMP $value the XMP metadata, or null to remove it.
	 */
	public function setXMP(?TXMP $value): void
	{
		$this->_carriers['XMP'] = $value;
		$this->_changed = true;
	}

	/**
	 * @return ?TIPTC the IPTC metadata, edited in place.
	 */
	public function getIPTC(): ?TIPTC
	{
		return $this->carrier('IPTC');
	}

	/**
	 * @param ?TIPTC $value the IPTC metadata, or null to remove it.
	 */
	public function setIPTC(?TIPTC $value): void
	{
		$this->_carriers['IPTC'] = $value;
		$this->_changed = true;
	}

	/**
	 * @return ?string the ICC profile bytes.
	 */
	public function getICCProfile(): ?string
	{
		return $this->carrier('ICC');
	}

	/**
	 * @param ?string $value the ICC profile bytes, or null to remove it.
	 */
	public function setICCProfile(?string $value): void
	{
		$this->_carriers['ICC'] = $value;
		$this->_changed = true;
	}

	/**
	 * @return ?TIPTC the IPTC metadata, created when the source has none.
	 */
	public function ensureIPTC(): TIPTC
	{
		if (!($iptc = $this->getIPTC())) {
			$this->setIPTC($iptc = new TIPTC());
		}
		return $iptc;
	}

	/**
	 * @return TEXIF the EXIF metadata, created when the source has none.
	 */
	public function ensureEXIF(): TEXIF
	{
		if (!($exif = $this->getEXIF())) {
			$this->setEXIF($exif = new TEXIF());
		}
		return $exif;
	}

	/**
	 * @return ?TXMP the XMP metadata, created when the source has none.
	 */
	public function ensureXMP(): TXMP
	{
		if (!($xmp = $this->getXMP())) {
			$this->setXMP($xmp = TXMP::blank());
		}
		return $xmp;
	}

	/**
	 * @return bool whether the source has any EXIF, XMP, IPTC, or ICC metadata.
	 */
	public function hasMetaData(): bool
	{
		return $this->getEXIF() !== null || $this->getXMP() !== null || $this->getIPTC() !== null || $this->getICCProfile() !== null;
	}

	/**
	 * @return bool whether a carrier was edited since the source was loaded.
	 */
	public function getChanged(): bool
	{
		return $this->_changed;
	}

	/**
	 * @param bool $value whether a carrier was edited.
	 */
	public function setChanged(bool $value): void
	{
		$this->_changed = $value;
	}

	/**
	 * @return bool whether writing the metadata would change a file whose pixels did not
	 *   change: a carrier was edited, a carrier is dropped, privacy is scrubbed, or the
	 *   IPTC character set is rewritten.
	 */
	public function getNeedsWrite(): bool
	{
		if ($this->_changed || $this->_scrub !== 0) {
			return true;
		}
		foreach ([TAssetImageMetaDataMode::EXIF => 'EXIF', TAssetImageMetaDataMode::XMP => 'XMP', TAssetImageMetaDataMode::IPTC => 'IPTC', TAssetImageMetaDataMode::Color => 'ICC'] as $mode => $carrier) {
			if (!$this->getIsPreserved($mode) && $this->carrier($carrier) !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The orientation of the image, from the EXIF Orientation tag, or else from the
	 * IPTC image rotation.
	 * @return int the EXIF orientation 1..8, or 9..10 for IPTC-only flips; default 1.
	 */
	public function getOrientation(): int
	{
		if (($exif = $this->getEXIF()) && ($orientation = $exif->getIfd0()->getTagValue(self::ORIENTATION_TAG)) !== null) {
			$orientation = is_array($orientation) ? reset($orientation) : $orientation;
			if ((int) $orientation >= 1 && (int) $orientation <= 8) {
				return (int) $orientation;
			}
		}
		if (($iptc = $this->getIPTC()) && $iptc->contains(TIPTCTags::IPTCImageRotation)) {
			// IPTC rotation: 0 none, 1 90° CCW, 2 180°, 3 90° CW.
			return match ((int) $iptc[TIPTCTags::IPTCImageRotation]) {
				1 => 6,
				2 => 3,
				3 => 8,
				default => 1,
			};
		}
		return 1;
	}

	/**
	 * Records that the image now has the given orientation: the IPTC rotation is removed
	 * and, when there is EXIF metadata, its Orientation tag is set.
	 * @param ?int $exifOrientation the EXIF orientation to record, or null to remove the tag.
	 */
	public function resetOrientation(?int $exifOrientation = 1): void
	{
		if (($iptc = $this->getIPTC()) && $iptc->contains(TIPTCTags::IPTCImageRotation)) {
			$iptc->remove(TIPTCTags::IPTCImageRotation);
			$this->_changed = true;
		}
		if ($exif = $this->getEXIF()) {
			if ($exifOrientation === null) {
				$exif->getIfd0()->removeTag(self::ORIENTATION_TAG);
			} else {
				$exif->getIfd0()->setTagValues(self::ORIENTATION_TAG, TTIFFDataType::UShort, [$exifOrientation]);
			}
			$this->_changed = true;
		}
	}

	/**
	 * Resolves a metadata field name into its carrier and key. A name may be prefixed
	 * with its carrier ("IPTC:Keywords", "EXIF:Artist", "XMP:dc:title"). Without a
	 * prefix, an IPTC dataset name or "record#dataset" id is tried first, then an EXIF
	 * tag name.
	 * @param string $field the field name.
	 * @return ?array{0:string, 1:string} the carrier (IPTC, EXIF, or XMP) and key, or
	 *   null when the name is not a known field.
	 */
	public static function resolveField(string $field): ?array
	{
		$field = trim($field);
		if (preg_match('/^(IPTC|EXIF|XMP):(.+)$/i', $field, $match)) {
			$carrier = strtoupper($match[1]);
			$name = trim($match[2]);
			return match ($carrier) {
				'IPTC' => ($id = TIPTC::mapToIPTCTagId($name)) !== null ? ['IPTC', $id] : null,
				'EXIF' => TEXIFTags::findByName($name) !== null ? ['EXIF', $name] : null,
				'XMP' => str_contains($name, ':') ? ['XMP', $name] : null,
			};
		}
		if (($id = TIPTC::mapToIPTCTagId($field)) !== null) {
			return ['IPTC', $id];
		}
		if (TEXIFTags::findByName($field) !== null) {
			return ['EXIF', $field];
		}
		return null;
	}

	/**
	 * Reads a metadata field.
	 * @param string $field the field name; see {@see resolveField}.
	 * @return mixed the field value, or null when absent or unknown.
	 */
	public function getMetaData(string $field)
	{
		if (!($resolved = static::resolveField($field))) {
			return null;
		}
		[$carrier, $key] = $resolved;
		try {
			return match ($carrier) {
				'IPTC' => $this->getIPTC()?->itemAt($key),
				'EXIF' => $this->getEXIF()?->getValueByName($key),
				'XMP' => ($xmp = $this->getXMP()) ? $xmp->getProperty($xmp->namespaceFor(strstr($key, ':', true)) ?? '', substr(strstr($key, ':'), 1)) : null,
			};
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Writes a metadata field, creating its carrier when needed.
	 * @param string $field the field name; see {@see resolveField}.
	 * @param mixed $value the value, or null to remove the field.
	 * @param bool $append whether to add the value to a repeatable IPTC dataset or an XMP
	 *   array rather than replace it.
	 * @throws TInvalidDataValueException when the field is unknown.
	 */
	public function setMetaData(string $field, $value, bool $append = false): void
	{
		if (!($resolved = static::resolveField($field))) {
			throw new TInvalidDataValueException('metadata_bad_field', $field);
		}
		[$carrier, $key] = $resolved;
		if ($carrier === 'IPTC') {
			$iptc = $this->ensureIPTC();
			if ($value === null) {
				$iptc->remove($key);
			} elseif ($append && $iptc->contains($key)) {
				$iptc[$key] = array_merge((array) $iptc[$key], (array) $value);
			} else {
				$iptc[$key] = $value;
			}
		} elseif ($carrier === 'EXIF') {
			$this->ensureEXIF()->setValueByName($key, is_array($value) ? implode(', ', $value) : $value);
		} else {
			[$prefix, $name] = explode(':', $key, 2);
			if (($namespace = ($this->getXMP() ?? TXMP::blank())->namespaceFor($prefix)) === null) {
				throw new TInvalidDataValueException('metadata_bad_field', $field);
			}
			$xmp = $this->ensureXMP();
			if ($value === null) {
				$xmp->removeProperty($namespace, $name);
			} elseif ($append) {
				foreach ((array) $value as $item) {
					$xmp->addArrayItem($namespace, $name, $item);
				}
			} else {
				$xmp->setProperty($namespace, $name, $value);
			}
		}
		$this->_changed = true;
	}

	/**
	 * Matches the metadata against an expression "Field", "Field=value", or
	 * "Field<op>value". The operators are `=` and `!=` (equal, or the value as a
	 * case-insensitive regular expression), `==` and `!==` (equal), and `<`, `<=`, `>`,
	 * `>=` (natural order). A bare field name matches when the field has a value. An
	 * unquoted field name may use `*` to match any IPTC dataset name; the match
	 * succeeds when any matched field, or any value of a repeatable field, matches.
	 * @param string $expression the match expression.
	 * @return bool whether the metadata matches.
	 */
	public function match(string $expression): bool
	{
		$match = static::parseMatchString($expression);
		foreach ($match['fields'] as $field) {
			$value = $this->getMetaData($field);
			if ($match['operator'] === '+') {
				if ($value !== null && $value !== [] && $value !== '') {
					return true;
				}
				continue;
			}
			$values = is_array($value) ? $value : [$value];
			$result = in_array($match['operator'], ['!=', '!=='], true);
			foreach ($values as $item) {
				if (is_array($item)) {
					$item = implode('/', $item);
				}
				$item = (string) $item;
				$expected = (string) $match['value'];
				$hit = match ($match['operator']) {
					'=' => $item === $expected || static::regexMatch($expected, $item),
					'!=' => $item !== $expected && !static::regexMatch($expected, $item),
					'==' => $item === $expected,
					'!==' => $item !== $expected,
					'<' => strnatcasecmp($item, $expected) < 0,
					'<=' => strnatcasecmp($item, $expected) <= 0,
					'>' => strnatcasecmp($item, $expected) > 0,
					'>=' => strnatcasecmp($item, $expected) >= 0,
				};
				if ($result) {
					if (!$hit) {
						$result = false;
						break;
					}
				} elseif ($hit) {
					return true;
				}
			}
			if ($result) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses a match expression; see {@see match}.
	 * @param string $expression the match expression.
	 * @return array{operator:string, value:?string, fields:string[]} the operator ("+"
	 *   for existence), the value, and the field names matched.
	 */
	public static function parseMatchString(string $expression): array
	{
		preg_match('/^(.*?)(?:(!==|<=|>=|==|!=|>|<|=)(.*))?$/s', $expression, $result);
		$rawKey = trim($result[1]);
		$key = trim($rawKey, '\'"');
		$fields = [];
		if ($key === $rawKey && str_contains($key, '*')) {
			$search = '/^' . str_replace('\*', '.*', preg_quote(strtolower($key), '/')) . '$/';
			foreach (TIPTC::getIPTCTagKeys() as $name => $id) {
				if (preg_match($search, $name)) {
					$fields[] = 'IPTC:' . $id;
				}
			}
			$fields = array_values(array_unique($fields));
		} elseif (static::resolveField($key) !== null) {
			$fields[] = $key;
		}
		return [
			'operator' => ($result[2] ?? '') !== '' ? $result[2] : '+',
			'value' => isset($result[3]) ? trim(trim($result[3]), '\'"') : null,
			'fields' => $fields,
		];
	}

	/**
	 * @param string $pattern the regular expression body, without delimiters.
	 * @param string $subject the subject.
	 * @return bool whether the pattern matches, case-insensitively; false when invalid.
	 */
	protected static function regexMatch(string $pattern, string $subject): bool
	{
		return $pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/i', $subject) === 1;
	}

	/**
	 * Whether the metadata can be written into an image of a type, which is whether
	 * prado-image has a container for it. BMP, WBMP, and XBM publish without metadata.
	 * @param int $imageType the IMAGETYPE_* constant of the published image.
	 * @return bool whether the type carries metadata.
	 */
	public static function carriesMetaData(int $imageType): bool
	{
		return in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true);
	}

	/**
	 * Writes the metadata into an encoded image, so the published file is written once
	 * rather than written by the imager and rewritten here. The kept carriers are written
	 * into the bytes as {@see writeMetaData} writes them into a file; bytes of a format
	 * prado-image does not read are returned unchanged.
	 * @param string $bytes the encoded image.
	 * @param null|\GdImage|\Imagick $image the published image, for its dimensions and thumbnails.
	 * @param bool $pixelsChanged whether the pixels differ from the source.
	 * @return string the encoded image with the metadata written into it.
	 */
	public function writeImageBytes(string $bytes, $image = null, bool $pixelsChanged = true): string
	{
		try {
			$target = TImageFile::fromString($bytes);
			$this->applyTo($target, $image, $pixelsChanged);
			return $target->toBinary();
		} catch (\Exception $e) {
			return $bytes; // The format carries no metadata, or it cannot be written.
		}
	}

	/**
	 * Writes the metadata into a published image file, for an image whose pixels are
	 * unchanged: the file is opened with prado-image; the kept carriers are written, the
	 * dimensions and thumbnails follow the image when its pixels changed, the JFIF header
	 * of a JPEG follows {@see getJFIFMode JFIFMode}, privacy is scrubbed, and the file is
	 * saved. A re-encoded image takes {@see writeImageBytes} instead.
	 * @param string $filePath the published image file.
	 * @param null|\GdImage|\Imagick $image the published image, for its dimensions and thumbnails.
	 * @param bool $pixelsChanged whether the pixels differ from the source.
	 * @return bool whether the metadata was written; false when prado-image cannot read the file.
	 */
	public function writeMetaData(string $filePath, $image = null, bool $pixelsChanged = true): bool
	{
		try {
			$target = TImageFile::fromFile($filePath);
		} catch (\Exception $e) {
			return false;
		}
		$this->applyTo($target, $image, $pixelsChanged);
		$target->save($filePath);
		return true;
	}

	/**
	 * Writes the metadata into an image container; see {@see writeMetaData}.
	 * @param TImageFile $target the published image container.
	 * @param null|\GdImage|\Imagick $image the published image.
	 * @param bool $pixelsChanged whether the pixels differ from the source.
	 */
	public function applyTo(TImageFile $target, $image = null, bool $pixelsChanged = true): void
	{
		[$width, $height] = $image ? TImageGraphics::getSize($image) : [$target->getWidth(), $target->getHeight()];

		$exif = $this->getIsPreserved(TAssetImageMetaDataMode::EXIF) ? $this->getEXIF() : null;
		if ($exif && $pixelsChanged) {
			$this->updateExifImage($exif, $image, $width, $height);
		}
		static::setCarrier(fn () => $target->setEXIF($exif));

		$xmp = $this->getIsPreserved(TAssetImageMetaDataMode::XMP) ? $this->getXMP() : null;
		static::setCarrier(fn () => $target->setXMP($xmp));

		$iptc = $this->getIsPreserved(TAssetImageMetaDataMode::IPTC) ? $this->getIPTC() : null;
		if ($iptc) {
			$this->updateIptc($iptc, $width, $height, $pixelsChanged);
		}
		static::setCarrier(fn () => $target->setIPTC($iptc));

		$icc = $this->getIsPreserved(TAssetImageMetaDataMode::Color) ? $this->getICCProfile() : null;
		static::setCarrier(fn () => $target->setICCProfile($icc));

		if ($target instanceof TJPEG) {
			$this->applyJFIF($target, $image, $pixelsChanged);
		}
		if ($this->_scrub !== 0) {
			$target->clearPrivateData($this->_scrub);
		}
	}

	/**
	 * Sets a carrier on a container that may have no place for it.
	 * @param callable $setter the setter call.
	 */
	protected static function setCarrier(callable $setter): void
	{
		try {
			$setter();
		} catch (TIOException $e) {
			// The format has no carrier for this metadata; it is dropped.
		}
	}

	/**
	 * Updates EXIF for changed pixels: the pixel dimensions, and the IFD1 thumbnail.
	 * @param TEXIF $exif the EXIF metadata.
	 * @param null|\GdImage|\Imagick $image the published image.
	 * @param ?int $width the published width.
	 * @param ?int $height the published height.
	 */
	protected function updateExifImage(TEXIF $exif, $image, ?int $width, ?int $height): void
	{
		if ($width !== null && $height !== null && ($exifIfd = $exif->getExifIfd())) {
			foreach ([40962 => $width, 40963 => $height] as $tag => $size) {
				if ($exifIfd->hasTag($tag)) {
					$exifIfd->setTagValues($tag, TTIFFDataType::ULong, [$size]);
				}
			}
		}
		$hadThumbnail = $exif->getThumbnail() !== null;
		$exif->setThumbnail(null);
		if ($image && ($hadThumbnail || $this->getExifThumbnail())) {
			[$tw, $th] = static::parseSize($this->getExifThumbnailSize(), 'metadata_bad_exif_thumbnail_size');
			$thumbnail = $this->createThumbnail($image, $tw, $th, $this->getExifThumbnailCropFill());
			$exif->setThumbnail(TImageGraphics::encodeJpeg($thumbnail, $this->getExifThumbnailJpegQuality()));
		}
	}

	/**
	 * Updates IPTC: the image dimensions and the character set.
	 * @param TIPTC $iptc the IPTC metadata.
	 * @param ?int $width the published width.
	 * @param ?int $height the published height.
	 * @param bool $pixelsChanged whether the pixels differ from the source.
	 */
	protected function updateIptc(TIPTC $iptc, ?int $width, ?int $height, bool $pixelsChanged): void
	{
		if ($width !== null && ($pixelsChanged || $iptc->contains(TIPTCTags::IPTCImageWidth))) {
			$iptc[TIPTCTags::IPTCImageWidth] = min(0xFFFF, $width);
		}
		if ($height !== null && ($pixelsChanged || $iptc->contains(TIPTCTags::IPTCImageHeight))) {
			$iptc[TIPTCTags::IPTCImageHeight] = min(0xFFFF, $height);
		}
		$encoding = $this->getIPTCPublishEncoding();
		if (strcasecmp($encoding, 'Preserve') === 0) {
			return;
		}
		if (strcasecmp($encoding, 'None') === 0) {
			$iptc->remove(TIPTCTags::CodedCharacterSet);
		} else {
			$iptc[TIPTCTags::CodedCharacterSet] = $encoding;
		}
	}

	/**
	 * Applies the {@see getJFIFMode JFIFMode} to a published JPEG. A thumbnail is created
	 * when the pixels changed or the source had none.
	 * @param TJPEG $jpeg the published JPEG.
	 * @param null|\GdImage|\Imagick $image the published image.
	 * @param bool $pixelsChanged whether the pixels differ from the source.
	 */
	protected function applyJFIF(TJPEG $jpeg, $image, bool $pixelsChanged): void
	{
		$mode = $this->getJFIFMode();
		if ($mode === TJFIFFormat::None) {
			$jpeg->setJFXX(null);
			$jpeg->setJFIF(null);
			return;
		}
		$jfif = $this->carrier('JFIF') ?? $jpeg->getJFIF() ?? new TJFIF();
		$jfxx = $this->carrier('JFXX') ?? $jpeg->getJFXX();
		if ($mode === TJFIFFormat::JFIF) {
			$jfif->clearThumbnail();
			$jpeg->setJFIF($jfif);
			$jpeg->setJFXX(null);
			return;
		}
		[$tw, $th] = static::parseSize($this->getJFIFSize(), 'metadata_bad_jfif_size');
		if ($mode === TJFIFFormat::Thumbnail) {
			if ($image && ($pixelsChanged || !$jfif->hasImage())) {
				$jfif->setImage($this->createThumbnail($image, $tw, $th, $this->getJFIFCropFill()));
			}
			$jpeg->setJFIF($jfif);
			$jpeg->setJFXX(null);
			return;
		}
		$format = match ($mode) {
			TJFIFFormat::JFXXJPEG => TJFXX::JPEG_THUMB,
			TJFIFFormat::JFXXPalette => TJFXX::PALETTE_THUMB,
			TJFIFFormat::JFXXColor => TJFXX::COLOR_THUMB,
			default => TJFXX::EFFICIENCY_THUMB,
		};
		$jfif->clearThumbnail();
		$jpeg->setJFIF($jfif);
		if ($image && ($pixelsChanged || !$jfxx || !$jfxx->hasImage())) {
			$jfxx = new TJFXX();
			$jfxx->setImage($this->createThumbnail($image, $tw, $th, $this->getJFIFCropFill()), $format, $this->getJFXXJpegQuality());
		}
		$jpeg->setJFXX($jfxx);
	}

	/**
	 * Scales an image into a thumbnail.
	 * @param \GdImage|\Imagick $image the image, converted to GD when it is not one.
	 * @param int $width the thumbnail width.
	 * @param int $height the thumbnail height.
	 * @param bool $cropFill whether to crop the image to fill the size; otherwise the
	 *   image fits within the size, keeping its aspect ratio.
	 * @return \GdImage the thumbnail.
	 */
	public function createThumbnail($image, int $width, int $height, bool $cropFill): \GdImage
	{
		if (!($image instanceof \GdImage)) {
			// The thumbnails are scaled with GD; an image of another library is converted.
			$image = TAssetImagerBase::convertImage($image, TImageGraphicsMode::GD);
		}
		$sx = imagesx($image);
		$sy = imagesy($image);
		$ox = $oy = 0;
		$cx = $sx;
		$cy = $sy;
		$widerThanHigh = $sx * $height > $width * $sy;
		if ($cropFill) {
			if ($widerThanHigh) {
				$cx = (int) round($sy * $width / $height);
				$ox = intdiv($sx - $cx, 2);
			} else {
				$cy = (int) round($sx * $height / $width);
				$oy = intdiv($sy - $cy, 2);
			}
			$tw = $width;
			$th = $height;
		} else {
			$tw = $widerThanHigh ? $width : max(1, (int) round($height * $sx / $sy));
			$th = $widerThanHigh ? max(1, (int) round($width * $sy / $sx)) : $height;
		}
		$source = ($cx !== $sx || $cy !== $sy) ? imagecrop($image, ['x' => $ox, 'y' => $oy, 'width' => $cx, 'height' => $cy]) : $image;
		return TBaseImagerFilter::imageScaleWithMode($source, $tw, $th, $this->getMetaInterpolationMode());
	}

	/**
	 * Parses a size "width" or "width x height" (or "width*height").
	 * @param mixed $value the size.
	 * @param string $errorKey the exception message key when the size is malformed.
	 * @throws TInvalidDataValueException when the size is malformed or not positive.
	 * @return array{0:int, 1:int} the width and height.
	 */
	public static function parseSize($value, string $errorKey): array
	{
		$value = TPropertyValue::ensureString($value);
		if (!preg_match('/^\s*(\d+)\s*(?:[x*]\s*(\d+)\s*)?$/i', $value, $matches) || (int) $matches[1] < 1 || (isset($matches[2]) && (int) $matches[2] < 1)) {
			throw new TInvalidDataValueException($errorKey, $value);
		}
		return [(int) $matches[1], (int) ($matches[2] ?? $matches[1])];
	}
}
