# Changelog

All notable changes to `belisoful/prado-publishing` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The publishing code, moved out of the Prado framework tree into its own package and rebuilt on
the current `TAssetManager` and on `belisoful/prado-image`.

### Added

- `Prado\Web\TPublishingManager` — a `TAssetManager` that publishes each file through an
  `IAsset` and its behaviors. It reuses the `TAssetManager` publishing machinery rather than
  a parallel implementation: the publishing options, `writeAtomic()`, the directory completion
  markers, `publishedLocation()`, `LinkAssets`, `FileMode`/`DirMode`, `AppendTimestamp`, and
  `IPublishedCapture`. A file with no behaviors publishes byte for byte to the `TAssetManager`
  URL. The `TAssetManager` extension points carry it: `virtualAssetPath()` gives an asset's
  publish path, `copyDirectoryFile()` publishes each file of a published directory (so the
  directory walk itself is shared), and `copyFile()` publishes the original names of renamed
  directory files. `deployTarFile()` is not used: tar extraction is customized on `TTarAsset`
  (`createExtractor()`, `configureExtractor()`).
- `TAsset::CachePublishFilePath` and `TPublishingManager::CacheAssetPublishFilePath` — the
  publish path of an asset is resolved through `dyAlterAssetFilePath` on every read; with the
  cache on, it is resolved once per asset until the file path cache is reset. Off by default,
  as a behavior that renames a file from data it changes itself would keep its first name. The
  manager property applies to the assets it instances.
- `TPublishingManager::PublishOriginalNames` — a directory file renamed by its behaviors is also
  published unprocessed under its own name, so URLs built from a directory URL still resolve.
  Off by default, as the original is unprocessed.
- `TAsset::getIsPassThrough()` — whether an asset writes its source unchanged, so `LinkAssets`
  does not link a converting asset such as `TGDFAsset`.
- `TAsset::AssetManager` — the asset manager publishing the asset, which
  `TPublishingManager::routeAsset()` sets, so a directory asset copies its files with the
  manager publishing it rather than with the application's.
- `Prado\Web\Assets` — the asset classes (`TAsset`, `TFileAsset`, `TImageAsset`, `TGDFAsset`),
  the asset behaviors, and the image filters.
- `TAssetImageMetaData`, rebuilt on prado-image: EXIF, XMP, IPTC, and ICC profiles for JPEG,
  PNG, WebP, and GIF; carrier preservation (`MetaDataPreserve`); privacy scrubbing by
  `TPrivacyCategory` (`Scrub`); EXIF/IPTC dimensions and EXIF thumbnails that follow a resized
  image; JFIF/JFXX headers and thumbnails (`JFIFMode`, a `TJFIFFormat`); field access and
  editing by name (`getMetaData()`/`setMetaData()`); and `match()` for `MetaMatch`. When no
  filter changes the pixels, only the metadata is rewritten.
- `TMetaDataImagerFilter` writes metadata fields from `<meta>` elements, with `${param}` and
  `${date:format}` placeholders.
- Images are read and written through prado-image, not only their metadata: the imager reads a
  matching image with the graphics library and, for a format it cannot decode, through the
  prado-image container the metadata was read from (`openImage()`), so TIFF is published like
  any other image. The published image is encoded (`encodeImage()`) and the metadata is written
  into those bytes (`TAssetImageMetaData::writeImageBytes()`), so the file is written once
  instead of being written by GD and rewritten with its metadata. Without a graphics library a
  matching asset publishes unprocessed instead of being dropped.
- The image filters are implemented in Imagick as well as GD: `Orient`, `GammaCorrect`, `Blur`,
  `Convolution`, `Rectangle`, `CircleMask`, `Image`, `Resize`, and the `Filter` effects. A filter
  implements `filterGdImage()`, `filterImagickImage()`, or both, and `TBaseImagerFilter` runs the
  one that matches the image. `AutoCrop`, `Text`, and `BoxBlur` stay GD (no equivalent for
  `imagecropauto()`, `.gdf` bitmap fonts, or our moving average), as do `Colorize` with an alpha
  offset and `Scatter` with a color list, which return null so the imager runs the GD path. The
  libraries do not resample or blend identically; each class documents what differs.
- The graphics library is negotiated, not assumed. A filter declares the library it works in
  (`IBaseImagerFilter::getGraphicsMode()`: `GD` for this package's filters, `Imagick`, or null
  for a filter that does not touch the pixels), the imager converts the image into that library
  through a lossless PNG (`TAssetImagerBase::convertImage()`, its interchange format the
  overridable `encodePortableImage()`) and leaves it there for the filters that follow, and a
  filter whose library is not installed is skipped. The image is read by GD, then Imagick, then
  the prado-image container, and written by GD, by the containers (GIF, TIFF), or by the
  graphics library (JPEG, PNG, WebP) when GD is missing. `TAssetImageMetaData` takes an image of
  either library.
- TIFF: `TAssetTIFFize` converts matching images to TIFF, which GD cannot write, with
  `TAssetImageFilter::TiffCompression` (None, CcittRle, Group3, Group4, Lzw, PackBits); TIFF
  sources are processed; and `.tif`/`.tiff` are image extensions
  (`TPublishingManager::IMAGE_EXTENSIONS`, the imager's default `MatchFiles`).
- `TPublishingManager::routeAsset()` — the common route of every asset: string paths, `IAsset`
  objects, the files of a published directory, and tar archives.
- `TTarAsset` wraps `TTarFileExtractor` as an asset: plain, gzip, bzip2, and xz archives; the
  extractor's `Atomic`, `Strict`, `ConflictMode`, `DirMode`, and `FileMode` as asset properties
  (unset ones take the publishing options, then the manager's); `VerifyChecksum`; archives with
  no checksum file; virtual archives; and the extractor's manifest through `getExtractor()`.
  `publishTarFile()` publishes through it (`DefaultTarAssetClass`), under the namespaced
  `tar:` cache key of `TAssetManager`, and the extraction is gated by the completion marker of
  the checksum file (or of the archive without one), written once the contents are in place.
- Image filters are plain objects, not behaviors. `Filters\TImagerFilterFactory` holds the filter
  type registry (`registerType()`, replacing the imager's `dyGetFilterClass` dynamic event and
  `FILTER_CLASSES`), parses an imager configuration once into specifications shared by every
  asset (an XML configuration is parsed once per element), and creates the filters. An imager
  creates its filters only when it first processes a matching image (in Debug mode, when it is
  attached), runs them in configuration order (`applyFilters()`, replacing the
  `onFilterImage` event), and edits them with `addFilter()`, `removeFilter()`, `getFilter()`,
  and `getFilters()`. `IBaseImagerFilter` declares `getEnabled()`, `getGraphicsMode()`, and
  `filterImage(&$image, ?TAssetEventParameter $param)`; `TBaseImagerFilter` is a `TComponent`
  whose subclasses write `filterGdImage()`, `filterImagickImage()`, or both.
  A filter file's filters follow the imager's, and its `<metadata>` overrides the imager's.
- `TAssetImageMetaData` is a component, not a behavior of the asset: the imager holds it
  (`getImageMetaData()`, a `MetaClass` that is not a `TAssetImageMetaData` is rejected) and
  shares it with the filters and other imagers as `TAssetEventParameter::getImageMetaData()`.
  `TAssetImagerBase::METADATA_BEHAVIOR` is removed.
- `TMetaDataImagerFilter::setMeta()` sets the fields of an array configuration.
- `TAssetCompress` compresses with the Prado codecs: a `TCompression` coding (`Method`: `gzip`,
  `br`, `zstd`, `deflate`) or any `ICompressor` (`Compressor`), with `Extension` and a
  per-coding `CompressionLevel`. `TAssetGZCompress` is its gzip preset.
- `TAsset::getAssetPublishFilePath()` — the path an asset publishes under.
- `TAssetDiscoverClassEventParameter::getIsClassSet()` — a handler routing a path to the default
  class is honored.
- `config/classMap.json` and `config/errorMessages.txt`, with a message for every key the
  package raises that the framework does not define.
- Tests: the framework's `TAssetManagerTest` runs against `TPublishingManager` with nothing
  skipped; and the package's own tests for the manager,
  the asset classes, the behaviors, the filters, and the image metadata on real image files.

### Removed (superseded by TAssetManager or prado-image)
- `IAssetPublishedCapture` — assets implement `Prado\Web\IPublishedCapture` directly.
- `TAssetFinalizeEventParameter` — unused; asset finalizers use `TAssetEventParameter`.
- `Behaviors\Meta\TICCProfileMetaHelper`, `TXMPMetaHelper`, and the EXIF tag generator script —
  prado-image's `TImageFile`, `TXMP`, and `TEXIFTags`.
- `TAssetImageMetaData::IPTCDefaultEncoding` and `JFXXPaletteDither` — prado-image reads IPTC in
  the application charset, and dithers JFXX palette thumbnails itself.
- `TAssetImagerBase::ORIGINAL_METADATA_BEHAVIOR` and `METADATA_BEHAVIOR` — the imager holds the
  metadata.
- `TBaseImagerFilter::filterImageHandler()`, `IBaseImager::onFilterImage()`, and
  `TAssetImagerBase::parseConfig()`/`instanceFilters()` — filters are not behaviors.
- The dead `IAsset::dyAlterModifationFilePath` and `TAsset::getFolderFiles()`.

### Fixed

- Filters never flagged the image to be saved, so a filtered image was published unchanged.
- A filter `type` in any case other than lower case threw (`FILTER_CLASSES[$type]`), and an
  array filter configuration lost its names; filter elements lost their child configuration.
- `TAssetImagerBase` reset the file path cache when attaching its metadata behavior (inverted
  check), and an unopenable image threw a `TypeError` instead of being dropped.
- `TAssetVirtualize` called the protected `TAsset::validatePath()` (now public), and kept a
  stale match across path changes.
- `TAssetDuplicate` left its recursion counter raised after an exception.
- `TAssetGZCompress` never registered its finalizer, and compressed only already-compressed data.
- `TAsset::publish()` skipped the rename when `chmod()` failed; writing is now the manager's
  atomic write.
- `TAssetImageFilter`: `TWebColors` is `TWebColor`; float-to-int deprecations; a missing
  transparent color crashed palettizing.
- The image filters: 22 defects, including crashes on numeric modes, wrong corners in
  `AutoCrop` Sides, box blur memory, shift, and bounds, off-center and aliased circle masks,
  watermark alignment and interpolation, zero-size resizes, and float coordinate deprecations.
- `TAsset`: the default `writeAsset()` reported an empty file as a failure; an invalid path was
  stored before it was rejected; `dySetAssetFilePathPre` returning null or false did not cancel
  or reject; a virtual path set later lost a directory's trailing separator.
- `TAssetEventParameter::finalize()` failed with an `Error` on a non-`IAssetFinalizer`; it now
  throws `TInvalidDataTypeException`.
- `TGDFAsset` warned on a font file shorter than its header.
- `TAssetImagerBase`: `MetaMatch` was not applied to virtual assets (the delayed match was cast
  to `true`); `init()` required an application.
- `TAssetImageFilter`: `PaletteColorIsTransparent` and `PaletteAlphaThreshold` had no effect on
  published images (alpha blending was on); black-and-white palettizing turned a transparent
  color black; `PaletteAlphaColor` could not be set to -1.
- `TAssetImageMetaData` thumbnails now honor `MetaInterpolationMode`.
- `TAssetVirtualize` without `MapToVirtual` raised a deprecation instead of its configuration
  error; `TAssetCacheBuster` with an unknown `Algorithm` threw a `ValueError`.
- `TTextImagerFilter` and `TImageImagerFilter` warned on missing or unreadable fonts and images.
- `TTextImagerFilter` failed with a `.gdf` font on PHP 8.1, whose `imagefontwidth()` and
  `imagefontheight()` reject a loaded `GdFont`; the font size is read from the font header
  (`gdFontSize()`).
- A format conversion renamed the published file even when the image could not be converted (no
  graphics library, or a format it cannot write), publishing the original bytes under the new
  name, e.g. a JPEG as "photo.png"; the rename now asks `TAssetImageFilter::canEncode()`.
- From the audit: `TAsset::virtualpath()` treated Windows drive and UNC paths as relative;
  a failed processing step in a non-atomic write left the partial file looking published; a
  symbolic link in a published directory published under its target's name; string paths and
  asset objects, and `publish($tar)` and `publishTarFile($tar)`, shared cache entries; asset
  objects were not republished in performance mode when their source changed; the BasePath
  guard compared unresolved paths and ran only before discovery rewrote them; published
  path/URL queries could throw; `TAssetBlocker` let a symbolic link to a blocked file through;
  `TAssetCompress` named a
  bzip2, xz, or zlib codec's output ".gz"; `TAssetCacheBuster` missed files another behavior
  had already renamed; metadata was read from a removed temporary file when an asset was
  republished; `TTarAsset` leaked the temporary archive when writing it threw, verified the
  checksum of a virtual archive before writing it, rejected `ConflictMode=""`, and a virtual
  archive without a checksum was never extracted; `TAssetDuplicate` passed the virtual path as
  a tar asset's checksum; and `imagedestroy()` calls are removed (deprecated in PHP 8.5).
- From the second audit: `TAssetImageFilter::saveImage()` threw a `TypeError` on an Imagick
  image, as a TIFF source published as a BMP, WBMP, or XBM is; `TAssetImagerBase::finalize()`
  discarded a failed encode or write and published the bytes written before the processing as
  the processed image, the unscrubbed source under the name of a conversion that did not
  happen, and now throws, while only an image type that no graphics library writes
  (`canEncode()`, now part of the imager contract) publishes unprocessed; a filter that
  overrides `filterImage()` itself, implementing neither `filterGdImage()` nor
  `filterImagickImage()`, was declared a filter of GD, which converted the image for it and
  skipped it where GD is missing; an invalid (false) publish file path was recomputed on every
  read with `CachePublishFilePath`; and a `TXmlElement` parsed by `TImagerFilterFactory` under
  a second name prefix took the filter names of the first.
- The test suite errored without the Imagick extension, which is only suggested, and the
  bootstrap now says how to install the framework's tests when the package does not carry them.
- Misspelled and outdated namespaces in the documentation (`\Prade\`, `Prado\Web\Asset\`).

[Unreleased]: https://github.com/belisoful/prado-publishing/commits/main
