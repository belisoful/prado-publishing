# prado-publishing

`TPublishingManager` for [Prado](https://github.com/pradosoft/prado): a `TAssetManager` that
publishes files through asset objects, so behaviors can rename, virtualize, block, compress,
and process them, including image resizing, format conversion, and image metadata preservation
and scrubbing.

`TPublishingManager` extends `TAssetManager` and keeps all of its features: the base path and
URL, the hashed publishing directories, the publishing options (`only`, `except`,
`caseSensitive`, `beforeCopy`, `afterCopy`, `forceCopy`, `atomic`), atomic writes, directory
completion markers, symlinked assets, timestamps, the asset map, and tar publishing options. What
it replaces is how a file gets written. `TAssetManager` copies it. `TPublishingManager` instances
it as an `IAsset`, lets the asset's behaviors act on it, and writes the result through the same
atomic, marker, and permission handling. A file with no behaviors publishes byte for byte, to
the same URL `TAssetManager` would give it, and the framework's `TAssetManagerTest` passes
against `TPublishingManager`.

## Usage

Replace the application's asset manager module:

```xml
<modules>
  <module id="asset" class="Prado\Web\TPublishingManager"
      BasePath="Application.assets" BaseUrl="/assets" />
</modules>
```

Then attach behaviors to the asset classes. This fingerprints scripts and stylesheets for
cache busting, and serves them gzip-compressed:

```xml
<behavior name="cacheBuster" Class="Prado\Web\Assets\Behaviors\TAssetCacheBuster"
    AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.(js|css)$/i" HashLength="8" />
<behavior name="gzip" Class="Prado\Web\Assets\Behaviors\TAssetCompress"
    AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.(js|css)$/i" Method="gzip" />
```

This publishes photos upright, at most 1920×1080, as JPEGs, keeping their metadata but
removing the location and camera serial number:

```xml
<behavior name="photos" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
    AttachToClass="Prado\Web\Assets\TImageAsset" ImageQuality="82">
  <metadata Scrub="Location, SerialNumber" />
  <filter type="Orient" />
  <filter type="Resize" MaximumWidth="1920" MaximumHeight="1080" />
</behavior>
```

## How a file publishes

Every route goes through one asset router, `TPublishingManager::routeAsset()`: a string path,
an `IAsset` object, each file of a published directory, and a tar archive from
`publishTarFile()`.

1. **Discovery.** A string path is instanced as `TFileAsset`, or as `TImageAsset` for an image
   extension. `onDiscoverClass` handlers (such as `TAssetDiscovery`) can route it to another
   class first.
2. **Path.** The asset resolves its file path through the `dySetAssetFilePathPre`,
   `dySetAssetFilePathPost`, and `dyRewriteFilePath` dynamic events. Behaviors virtualize a path
   onto a real file (`TAssetVirtualize`), or cancel publishing (`TAssetBlocker`). Then
   `dyAlterAssetFilePath` gives the published file name: format conversions change the
   extension, `TAssetCacheBuster` adds a fingerprint, and `TAssetCompress` appends `.gz`. The
   published location is the `TAssetManager` hashed directory of that path.
3. **Write.** The asset writes itself, to a temporary file when publishing atomically, and
   raises `onProcessAsset` on it. Image behaviors process the file there, and finalizers (image
   saving, then gzip) run in priority order. The file then moves into place.

A directory publishes with the `TAssetManager` directory walk and completion marker (its
`copyDirectoryFile()` hook), but each file in it publishes as its own asset, under its name with
its behaviors' rename applied.

The published location comes from the `TAssetManager` hooks: `virtualAssetPath()` gives the
asset's publish path and `copyDirectoryFile()` publishes each file of a directory. An asset
resolves that path through its behaviors on every read, so `CacheAssetPublishFilePath="true"`
on the manager has the assets it instances resolve it once instead
(`TAsset::CachePublishFilePath`). It is off by default: a behavior that renames a file from data
it changes itself, without resetting the asset's file path cache, would keep its first name.

> **Renaming behaviors and directories.** A behavior that renames files (`TAssetCompress`,
> `TAssetCacheBuster`, the format conversions) renames the files of every published directory it
> matches, including the framework's own script and style packages, whose URLs are built from the
> directory URL. Scope such behaviors with `MatchFiles`, or attach them to `TImageAsset` for
> images. `PublishOriginalNames="true"` also publishes each renamed file unprocessed under its own
> name, so those URLs resolve; the original is the unprocessed source, so leave it off when the
> processing matters, such as scrubbing image metadata.

A tar archive publishes as a `TTarAsset`, extracted by `TTarFileExtractor` into the directory,
with the extractor's options on the asset. A published `IPublishedCapture` asset receives its
path and URL.

With `LinkAssets`, a file is symlinked only when its asset writes the source unchanged
(`TAsset::getIsPassThrough()`: not a converting asset such as `TGDFAsset`, and no behavior
taking over the write), keeps its name, and has no `onProcessAsset` handlers. Any other file is
written, because a link would serve the unprocessed source. An asset class with a processing
behavior attached, even one that does not match the file, is therefore copied rather than
linked.

## Contents

The classes keep their framework namespaces, `Prado\Web` and `Prado\Web\Assets`.

### Manager and assets

| Class | Purpose |
|-------|---------|
| `Prado\Web\TPublishingManager` | The asset manager module |
| `Assets\IAsset` | An `IPublishable` with an original source path |
| `Assets\TAsset` | The base asset: the path dynamic events, `onProcessAsset`, and finalizers |
| `Assets\TFileAsset` | A file or directory on disk |
| `Assets\TImageAsset` | An image file; image behaviors attach here |
| `Assets\TGDFAsset` | A GD font (`.gdf`), converted to the machine's byte order |
| `Assets\TTarAsset` | A tar archive (plain, gzip, bzip2, xz) extracted by `TTarFileExtractor`: `Atomic`, `Strict`, `ConflictMode`, `DirMode`, `FileMode`, `VerifyChecksum` |
| `Assets\TAssetEventParameter`, `Assets\TAssetDiscoverClassEventParameter` | Event parameters |
| `Assets\IAssetFinalizer`, `Assets\IAssetImageParameter` | Finalizer and image parameter contracts |

### Behaviors (`Prado\Web\Assets\Behaviors`)

| Class | Purpose |
|-------|---------|
| `TAssetDiscovery` | Attaches to the manager; routes matching paths to another asset class |
| `TAssetVirtualize` | Maps virtual file paths onto real files, and back to virtual published names |
| `TAssetBlocker` | Blocks matching files, or substitutes a placeholder |
| `TAssetDuplicate` | Publishes a matching asset a second time under another path or class |
| `TAssetCacheBuster` | Fingerprints published file names with a content hash or mtime |
| `TAssetCompress` | Publishes matching files compressed with a Prado codec: `Method` (`gzip`, `br`, `zstd`, `deflate`) or any `ICompressor` `Compressor`, as `name.ext.gz` (`.br`, `.zst`, `.zz`) |
| `TAssetGZCompress` | The gzip preset of `TAssetCompress` |
| `TAssetImagerBase` | The base imager: `MatchFiles`, `MetaMatch`, the filter configuration, and the finalizer that saves the image |
| `TAssetImageFilter` | The general imager: runs the image filters and saves the image in its format, with the saving properties |
| `TAssetJPEGize`, `TAssetPNGize`, `TAssetWebPize`, `TAssetGIFize`, `TAssetBMPize`, `TAssetWBMPize`, `TAssetXBMize`, `TAssetTIFFize` | Convert matching images to a format; `TAssetWebPize` follows the browser's `Accept` header, and `TAssetTIFFize` encodes with prado-image (`TiffCompression`) |
| `TAssetImageMetaData` | The image metadata, configured by `<metadata>` and shared with the filters through the publishing parameter |
| `IBaseImager` | The imager contract: the filters and running them |
| `IAssetMatching`, `IAssetReplacement`, `IAssetVirtualize` | The `MatchFiles`, replacement, and virtualization contracts of the behaviors |
| `TAssetImageMetaDataMode`, `TAssetPNGColorMode`, `TAssetWebPFallbackMode` | The values of `MetaDataPreserve`, `PngColorMode`, and `WebPFallback` |

### Image filters (`<filter type="...">`)

`AutoCrop`, `Blur`, `BoxBlur`, `CircleMask`, `Convolution`, `GammaCorrect`, `Image` (watermark),
`MetaData`, `Orient`, `Rectangle`, `Resize`, `Text` (watermark), and the GD `imagefilter`
effects by name (`Grayscale`, `Negate`, `Brightness`, `Contrast`, `Colorize`, `EdgeDetect`,
`Emboss`, `GaussianBlur`, `SelectiveBlur`, `MeanRemoval`, `Smooth`, `Pixelate`, `Scatter`).
A filter can also be given by `class`, and filters can be kept in a separate file with the
imager's `FilterFilePath` (its filters follow the imager's, and its `<metadata>` attributes
override the imager's).

Filters are not behaviors. An imager's configuration is parsed once into filter
specifications (`Filters\TImagerFilterFactory`) shared by every asset the imager attaches to,
and the filter objects are created only when the imager first processes a matching image (in
Debug mode, when it attaches, so configuration errors show early). They run in configuration
order. A filter implements `Filters\IBaseImagerFilter` (`getEnabled()`, `getGraphicsMode()`, and
`filterImage(&$image, $param)`), usually by extending `Filters\TBaseImagerFilter` and writing
`filterGdImage()`, `filterImagickImage()`, or both; `$param` is
the publishing `TAssetEventParameter`, which holds the asset, the image types, and the image
metadata (`getImageMetaData()`). Register a type name for your own filter:

```php
use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TImagerFilterFactory;
use Prado\Web\Assets\TAssetEventParameter;

class TSepiaFilter extends TBaseImagerFilter
{
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		return imagefilter($image, IMG_FILTER_GRAYSCALE) && imagefilter($image, IMG_FILTER_COLORIZE, 90, 60, 30);
	}
}

TImagerFilterFactory::registerType('Sepia', TSepiaFilter::class); // <filter type="Sepia" />
```

Register types before the configuration that uses them is parsed; a registered type takes
precedence over a built-in type of the same name. In code, `addFilter()`, `removeFilter()`,
`getFilter()`, and `getFilters()` on an imager edit and read its filters.

### Graphics libraries

An imager reads the image with GD, then Imagick, and falls back to the prado-image container for
a format neither decodes (TIFF). A filter implements `filterGdImage()`, `filterImagickImage()`,
or both, and `getGraphicsMode()` follows from that: a filter of one library gets the image in
that library, and a filter of both runs in whichever library the image is already in. The imager
converts between them through a lossless PNG, which keeps the alpha channel, and leaves the
image there for the filters that follow, so a chain can mix both libraries freely. A filter
whose library is not installed is skipped.

| | Filters |
|---|---|
| Both libraries | `Orient`, `GammaCorrect`, `Blur`, `Convolution`, `Rectangle`, `CircleMask`, `Image`, `Resize`, `Filter` (its GD effects, except `Colorize` with an alpha offset and `Scatter` with a color list, which fall back to GD) |
| GD only | `AutoCrop` (`imagecropauto()` has no equivalent), `Text` (Imagick cannot read `.gdf` bitmap fonts), `BoxBlur` (its moving average is our own) |
| Either | `MetaData`, which does not touch the pixels |

The two libraries do not produce identical pixels. `Orient`, `GammaCorrect`, `Rectangle`,
`Negate`, `Brightness`, `MeanRemoval`, and `Smooth` match GD exactly or to within a unit of
rounding; `Convolution` is within one unit per channel; the rest are equivalent rather than
identical, and each class documents what differs — the resampling kernels of `Resize`, the
anti-aliased edge of `CircleMask`, the blur radii, and the effects whose arguments mean
different things in the two libraries (`Contrast`, `Colorize`, `EdgeDetect`, `Emboss`).

The image is written back with GD where it can be (and with the saving properties, which are GD
work), by the prado-image containers for GIF and TIFF, and by the graphics library for JPEG,
PNG, and WebP when GD is missing. A format conversion only renames the published file when the
image can be written in that format (`canEncode()`), so a file is never published under a name
whose format it is not.

## Image metadata

Image metadata is read and written with
[belisoful/prado-image](https://github.com/belisoful/prado-image) for JPEG, PNG, WebP, GIF, and
TIFF. When an imager processes an image, the metadata is read before the image is re-encoded,
and it is written into the encoded image, so the published file is written once:

- **Preserved** carriers are chosen with `MetaDataPreserve`: any of `EXIF`, `IPTC`, `XMP`,
  `Color` (the ICC profile), or `All` (the default) or `None`. A carrier the published format
  cannot hold is dropped: GIF has no EXIF, and GIF and WebP have no IPTC. A TIFF's EXIF is the
  image structure itself, so it is not transplanted from the source. BMP, WBMP, and XBM have no
  prado-image container and publish without metadata.
- **Scrubbed** identifying information is chosen with `Scrub`, by `TPrivacyCategory`: e.g.
  `Location`, `Author`, `SerialNumber`, `Timestamp`, `Identity`, `Provenance`, `All`.
- **Updated:** the EXIF and IPTC dimensions follow a resized image, an EXIF thumbnail is
  regenerated (`ExifThumbnail*`), JPEGs get the JFIF header and optional thumbnail selected by
  `JFIFMode` (`None`, `JFIF`, `Thumbnail`, `JFXXJPEG`, `JFXXPalette`, `JFXXColor`,
  `JFXXEfficiency`), and `IPTCPublishEncoding` sets the IPTC character set.
- **Edited:** the `Orient` filter rotates the image upright and records the upright
  orientation, and the `MetaData` filter writes fields:

  ```xml
  <filter type="MetaData">
    <meta name="Copyright" content="© ${date:Y} ${SiteOwner}" />
    <meta name="Keywords" content="web, published" separator="," append="true" />
    <meta name="EXIF:Artist" content="${SiteOwner}" />
  </filter>
  ```

  When no filter changes the pixels, only the metadata is rewritten; the encoded image data is
  kept as it is.
- **Matched:** an imager's `MetaMatch` limits it to images whose metadata matches, e.g.
  `MetaMatch="Keywords=portfolio"` or `MetaMatch="EXIF:Model==X100V"`.

Field names are IPTC dataset names or ids (`Keywords`, `2#025`), EXIF tag names (`Artist`), or
carrier-prefixed names (`IPTC:Copyright`, `EXIF:Copyright`, `XMP:dc:title`).

## Prado integration

The `config/` directory holds the package's declarative configuration:

| File | Purpose |
|------|---------|
| `config/classMap.json` | Prado3-style short name to fully qualified name, for `Prado::registerClassMap()` |
| `config/errorMessages.txt` | Package error messages, for `TException::addMessageFile()` |

Neither is auto-discovered. An application (or a plugin module) wires them once at start-up:

```php
use Prado\Exceptions\TException;
use Prado\Prado;

$config = __DIR__ . '/vendor/belisoful/prado-publishing/config';
Prado::registerClassMap(json_decode(file_get_contents($config . '/classMap.json'), true));
TException::addMessageFile($config . '/errorMessages.txt');
```

The class map lets configuration use short names such as `Class="TAssetJPEGize"`. Unregistered
messages still carry their key, so registration affects readability, not behavior.

## Requirements

- PHP 8.1+
- `pradosoft/prado` 4.4 (`^4.4@dev` until 4.4.0 is released)
- `belisoful/prado-image`
- `ext-gd` for images, and `ext-imagick` for the formats and filters GD cannot do; `ext-dom` for
  XMP; `ext-zlib` for gzip and deflate; `ext-brotli` or `ext-zstd` for those codings, or the
  command-backed codecs of `belisoful/prado-compression`

## Install

```bash
composer require belisoful/prado-publishing
```

## Test

```bash
composer install
composer test
```

`TPublishingManager` must stay a drop-in replacement for `TAssetManager`, so the suite runs the
framework's own `TAssetManagerTest` against it, loaded from the installed `pradosoft/prado`
package, with no test skipped. The package's own tests cover the manager's asset publishing,
the asset classes, every behavior and filter, and the image metadata, on real image files.
