<?php

/**
 * TAssetImagerBase class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Caching\TFileCacheDependency;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\IImageGraphicsLibrary;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\Prado;
use Prado\TApplication;
use Prado\TApplicationMode;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;
use Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TImagerFilterFactory;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Xml\TXmlDocument;

/**
 * TAssetImagerBase
 *
 * This is the base Asset behavior for reading Image files, modifying them,
 * and saving the file [paths] that match the regex {@see getMatchFiles MatchFiles}.
 * The file match is made during the rewriting of the original file path
 * to its virtual published file path.  Set your Asset behaviors accordingly for
 * file path rewriting.
 *
 * This class can configure image filters by application configuration of the
 * behavior or by {@see getFilterFilePath FilterFilePath}.  Changes to the filter
 * configuration will update the asset modification time and trigger a re-publishing
 * of the asset.
 *
 * When an asset is published, the TAsset's 'onProcessAsset' is raised and this
 * class's handler {@see processAsset} is called.
 *
 * This is the main workhorse of the TAssetImagerBase. If the file matches
 * the regex in MatchFiles (and any optional Meta-Data [IPTC] matching), the image is read
 * into a GD Image for processing and saving ({@see openImage}): the graphics library
 * decodes the file, and a format it cannot decode, such as TIFF, is converted by the
 * {@see https://github.com/belisoful/prado-image prado-image} container the metadata was
 * read from.  Without a graphics library the asset publishes unprocessed.
 *
 * The published image is encoded ({@see encodeImage}) and the metadata is written into
 * those bytes ({@see TAssetImageMetaData::writeImageBytes}), so the file is written once.
 *
 * The image is processed by the imager's filters, in configuration order, by
 * {@see applyFilters}. Filters are plain objects ({@see IBaseImagerFilter}), not
 * behaviors: the configuration is parsed once into filter specifications
 * ({@see TImagerFilterFactory::parse}), shared by every asset the imager is attached to,
 * and an imager creates its filters only when it first processes a matching image
 * ({@see getFilters}); in Debug mode they are created when the imager is attached, to
 * report configuration errors early. A filter `type` is resolved by
 * {@see TImagerFilterFactory}, where applications register their own types.
 * There are many filters from Cropping, Blurring, Masking, Convolution,
 * Filtering, Gamma Correction, Resizing, and Image and Text water marks on
 * published image assets.  Filtering includes: Negate, Grayscale, Brightness
 * Contrast, Colorize, EdgeDetect, Emboss, GaussianBlur, SelectiveBlur,
 * MeanRemoval, Smooth, Pixelate, and Scatter.
 *
 * {@see TAssetImageFilter} is a general image filter and subclass of
 * TAssetImagerBase.  Here is how filters are configured:
 *
 * ```xml
 *	 <behavior name="userIconFilter" Class="Prado\Web\Assets\Behaviors\TAssetPNGize"
 *	  AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\/userIcons\//i">
 *		<metadata MetaDataPreserve="Color" Scrub="All" />
 *		<filter type="Orient" />
 *		<filter type="AutoCrop" CropMode="Sides" />
 *		<filter type="Resize" FixedWidth="80" FixedHeight="80" CropFill="true" />
 *		<filter type="Grayscale" />
 *		<filter type="CircleMask" />
 *	 </behavior>
 *   <behavior name="virtualizeThumbnailPart1" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *		AttachToClass="Prado\Web\Assets\TAsset" VirtualFiles="/(?<=\/)([^\/]*?)\.thumb\.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i"
 *		MapToVirtual="${1}.thumb.${2}" />
 *	 <behavior name="VirtualThumbnailFilterPart2" Class="Prado\Web\Assets\Behaviors\TAssetImageFilter"
 *	  AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.thumb\.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp)$/i">
 *		<filter type="Orient" />
 *		<filter type="Resize" FixedWidth="150" FixedHeight="150" CropFill="false" />
 *	 </behavior>
 *	 <behavior name="AppWideMaxImageSizeFilter" Class="Prado\Web\Assets\Behaviors\TAssetImageFilter"
 *	  AttachToClass="Prado\Web\Assets\TAsset">
 *		<metadata Scrub="Location" />
 *		<filter type="Resize" MaximumPixels="1920x1080" MaximumWidth="1920" MaximumHeight="1080" />
 *	 </behavior>
 * ```
 *
 * In the "AppWideMaxImageSizeFilter" example above, by default, all supported image
 * files are processed. When several imagers match the same asset, they share one image:
 * the first to open it saves it, with its own saving properties and `<metadata>`
 * configuration, after every imager's filters have run, and the last to set the image
 * type decides the saved format.
 *
 * The `<metadata>` element configures the {@see TAssetImageMetaData} that
 * carries the image metadata (EXIF, XMP, IPTC, and the ICC profile) into the published
 * image: which carriers are kept ({@see TAssetImageMetaData::setMetaDataPreserve
 * MetaDataPreserve}), which identifying information is scrubbed
 * ({@see TAssetImageMetaData::setScrub Scrub}), and the JFIF header of JPEGs. The filter
 * file's `<metadata>` attributes override the imager configuration's. The filters reach
 * the metadata through {@see TAssetEventParameter::getImageMetaData}.
 *
 * {@see setMetaMatch MetaMatch} restricts processing to images whose metadata matches
 * an expression, as "Field", "Field=regex", or "Field<op>value"; see
 * {@see TAssetImageMetaData::match}.  Field names are IPTC dataset names or ids
 * ("Keywords", "2#025"), EXIF tag names ("Artist"), or carrier-prefixed names
 * ("EXIF:Model", "XMP:dc:title").  For example, `MetaMatch="Keywords=portfolio"`
 * processes only images with the "portfolio" keyword.
 *
 * {@see getMatchFiles MatchFiles} matches the asset file path, which is the virtual path
 * when the asset is virtualized (for example by {@see TAssetVirtualize}, as in the
 * ".thumb" example above). For an asset on disk, a {@see setMetaMatch MetaMatch} is
 * tested when the path is matched, on the source file. For a virtual asset (one whose
 * {@see \Prado\Web\Assets\TAsset::getIsVirtual IsVirtual} is true), which has no file
 * until it is written, the MetaMatch is tested on the written file while publishing;
 * an image that does not match is published unprocessed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 * @see https://www.regular-expressions.info for MatchFiles, VirtualFiles, and
 *  OriginalFiles Regex and their replacement values Map(To|From)Virtual.
 */
abstract class TAssetImagerBase extends TBehavior implements IBaseImager, IAssetFinalizer
{
	/** The prefix of the application cache key of a parsed filter file. */
	public const FILTERS_CACHE = 'prado:imager:filters:';

	/** @var mixed the imager configuration: an XML element or an array. */
	private $_config;

	/** @var ?array the parsed imager configuration. */
	private ?array $_appConfig = null;

	/** @var string the Namespace file of the behavior filter file */
	private $_filterFilePath;

	/** @var ?array the parsed filter file configuration. */
	private ?array $_fileConfig = null;

	/** @var ?array<string, IBaseImagerFilter> the filters, by name, in order. */
	private ?array $_filters = null;

	/** @var string the regular expression of the asset file paths this imager processes. */
	private $_matchFiles;

	/** @var null|string The Meta Field for matching via getMetaMatch regex. default null for no matching */
	private $_metaMatch;

	/** @var bool did the file name match on rewrite */
	protected bool|int|null $_match = null;

	/** @var string The class of the metaData */
	protected string $_metaClass = TAssetImageMetaData::class;

	/** @var ?TAssetImageMetaData the metadata of the asset's image. */
	private ?TAssetImageMetaData $_metaData = null;

	/** @var float the priority of this imager among the finalizers of a publish. */
	private float $_finalizerPriority = 5.0;

	/**
	 * Sets the MatchFiles to all supported types (gif, jpg, jpeg, png, bmp, wbmp, xbm,
	 * webp, tif, tiff),
	 * but not files that end with ".full" or ".original", eg. "myImage.full.jpg" are exempt.
	 *
	 */
	public function __construct()
	{
		$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
		$this->_matchFiles = '/(?<=' . $sep . ')([^' . $sep . ']*)(?<!\.full|\.original)\.(gif|jpg|jpeg|png|bmp|wbmp|xbm|webp|tif|tiff)$/i';
		parent::__construct();
	}

	/**
	 * @return array the events and handlers to automatically attach and detach
	 */
	public function events()
	{
		return ['onProcessAsset' => 'processAsset'];
	}

	/**
	 * Keeps the configuration to parse when it is needed. In Debug mode, the filters are
	 * created now, so configuration errors are reported when the imager is attached.
	 *
	 * @param mixed $config the imager configuration, with `<metadata>` and `<filter>` elements.
	 */
	public function init($config)
	{
		parent::init($config);

		$this->_config = $config;
		$this->_appConfig = null;
		$this->_filters = null;
		if (Prado::getApplication()?->getMode() === TApplicationMode::Debug) {
			$this->getFilters();
		}
	}

	/**
	 * @return array{metadata:?array, filters:array} the parsed imager configuration.
	 */
	protected function getAppConfig(): array
	{
		return $this->_appConfig ??= TImagerFilterFactory::parse($this->_config, 'appFilter');
	}

	/**
	 * @return array{metadata:?array, filters:array} the parsed filter file configuration.
	 */
	protected function getFileConfig(): array
	{
		return $this->_fileConfig ??= $this->loadFilterFile();
	}

	/**
	 * The imager configuration followed by the filter file configuration. A filter file
	 * filter replaces an imager filter of the same name, and the filter file `<metadata>`
	 * attributes override the imager's.
	 * @return array{metadata:?array, filters:array} the configuration.
	 */
	public function getFilterConfig(): array
	{
		$app = $this->getAppConfig();
		$file = $this->getFileConfig();
		$filters = $app['filters'];
		foreach ($file['filters'] as $name => $spec) {
			$filters[$name] = $spec;
		}
		$metaData = ($app['metadata'] === null && $file['metadata'] === null) ? null : ($file['metadata'] ?? []) + ($app['metadata'] ?? []);
		return ['metadata' => $metaData, 'filters' => $filters];
	}

	/**
	 * The filters, created from the configuration the first time they are needed.
	 * @return array<string, IBaseImagerFilter> the filters, by name, in order.
	 */
	public function getFilters(): array
	{
		return $this->_filters ??= TImagerFilterFactory::createFilters($this->getFilterConfig()['filters']);
	}

	/**
	 * Adds a filter after the configured filters, replacing a filter of the same name in
	 * its place.
	 * @param string $name the filter name.
	 * @param IBaseImagerFilter $filter the filter.
	 */
	public function addFilter(string $name, IBaseImagerFilter $filter): void
	{
		$this->getFilters();
		$this->_filters[$name] = $filter;
	}

	/**
	 * @param string $name the filter name.
	 * @return ?IBaseImagerFilter the removed filter, or null when there is none of the name.
	 */
	public function removeFilter(string $name): ?IBaseImagerFilter
	{
		$filter = $this->getFilter($name);
		unset($this->_filters[$name]);
		return $filter;
	}

	/**
	 * @param string $name the filter name.
	 * @return ?IBaseImagerFilter the filter, or null when there is none of the name.
	 */
	public function getFilter(string $name): ?IBaseImagerFilter
	{
		return $this->getFilters()[$name] ?? null;
	}

	/**
	 * Returns the filter file as a file system path.
	 *
	 * @return string The filter file.
	 */
	protected function getFilterFile()
	{
		if (!$this->_filterFilePath) {
			return null;
		}
		return Prado::getPathOfNamespace($this->_filterFilePath, Prado::getApplication()->getConfigurationFileExt());
	}

	/**
	 * The filter configuration file, in Prado Namespace format.  Its extension depends on
	 * {@see \Prado\TApplication::getConfigurationType}.
	 *
	 * @return ?string The path of the filter configuration file, default null.
	 */
	public function getFilterFilePath()
	{
		return $this->_filterFilePath;
	}

	/**
	 * The filter configuration file, in Prado Namespace format.  Its extension depends on
	 * {@see \Prado\TApplication::getConfigurationType}.
	 *
	 * @param string $value The filter configuration file, in Prado Namespace format.
	 */
	public function setFilterFilePath($value)
	{
		if ($this->_filterFilePath !== ($value = TPropertyValue::ensureString($value))) {
			$this->_filterFilePath = $value;
			$this->_fileConfig = null;
			$this->_filters = null;
		}
	}

	/**
	 * The regular expression to match the file paths to process.
	 *
	 * @return string the regex to match to the file path.
	 */
	public function getMatchFiles(): string
	{
		return $this->_matchFiles;
	}

	/**
	 * @param string $value The regex to match to the file path.
	 */
	public function setMatchFiles($value)
	{
		$this->_matchFiles = TPropertyValue::ensureString($value);
		$this->_match = null;
	}

	/**
	 * @return string The regex match for specific Meta field data
	 */
	public function getMetaMatch()
	{
		return $this->_metaMatch;
	}

	/**
	 * @param string $value The regex match for specific Meta field data
	 */
	public function setMetaMatch($value)
	{
		$this->_metaMatch = TPropertyValue::ensureString($value);
		$this->_match = null;
	}

	/**
	 * @return string The class for the meta data.
	 */
	public function getMetaClass(): string
	{
		return $this->_metaClass;
	}

	/**
	 * @param string $value The class for the meta data.
	 * @throws TInvalidDataValueException when the class is not a {@see TAssetImageMetaData}.
	 */
	public function setMetaClass($value)
	{
		if (!is_a($value = TPropertyValue::ensureString($value), TAssetImageMetaData::class, true)) {
			throw new TInvalidDataValueException('assetimagerbase_bad_metaclass', $value);
		}
		$this->_metaClass = $value;
		$this->_metaData = null;
	}

	/**
	 * The priority of this imager among the finalizers of a publish, which decides the
	 * order the image is saved in relative to the other finalizers of the asset, such as
	 * the compression of {@see TAssetCompress}. A lower number runs first.
	 * @return numeric the finalizer priority, default 5.
	 */
	public function getFinalizerPriority()
	{
		return $this->_finalizerPriority;
	}

	/**
	 * @param numeric $value the finalizer priority; a lower number runs first.
	 */
	public function setFinalizerPriority($value): void
	{
		$this->_finalizerPriority = TPropertyValue::ensureFloat($value);
	}

	/**
	 * @return ?TAssetImageMetaData the metadata of the asset's image, once it is read for
	 *   {@see setMetaMatch MetaMatch} or for processing.
	 */
	public function getImageMetaData(): ?TAssetImageMetaData
	{
		return $this->_metaData;
	}

	/**
	 * Creates the {@see getMetaClass MetaClass} metadata, configured by the `<metadata>`
	 * properties.
	 * @return TAssetImageMetaData the metadata.
	 */
	protected function createMetaData(): TAssetImageMetaData
	{
		$class = $this->getMetaClass();
		$metaData = new $class();
		foreach ($this->getFilterConfig()['metadata'] ?? [] as $name => $value) {
			$metaData->setSubProperty($name, $value);
		}
		return $metaData;
	}

	/**
	 * Ensures the {@see TAssetImageMetaData} of the asset's image, configured by the
	 * `<metadata>` element, reading from the image file unless its source is still a file.
	 *
	 * @param string $filepath the image file to read the metadata from.
	 * @return TAssetImageMetaData the metadata.
	 */
	protected function ensureMetaData($filepath)
	{
		$metaData = $this->_metaData ??= $this->createMetaData();
		if (($source = $metaData->getMetaDataSource()) === null || !is_file($source)) {
			$metaData->setMetaDataSource($filepath);
		}
		return $metaData;
	}

	/**
	 * Drops the metadata when the path is reset.
	 *
	 * @param \Prado\Util\TCallChain $callchain
	 */
	public function dyResetFilePathCache($callchain)
	{
		$this->_metaData = null;
		return $callchain->dyResetFilePathCache();
	}

	/**
	 * Resets the match when the file path is rewritten, so {@see hasMatch} is computed
	 * again for the new path.
	 *
	 * @param string $filePath the file path being rewritten.
	 * @param null|\Prado\Util\TCallChain $callchain subclasses can override and
	 *   call this parent without the callchain to prevent the chain from executing.
	 * @return null|string the new file path
	 */
	public function dyRewriteFilePath($filePath, $callchain = null)
	{
		$this->_match = null;
		return $callchain ? $callchain->dyRewriteFilePath($filePath) : $filePath;
	}

	/**
	 * @param null|string $dstFile
	 * @param null|object $asset
	 * @return bool|int does MatchFiles match AssetFilePath and possibly with
	 *   MetaData search.
	 */
	public function hasMatch($dstFile = null, $asset = null)
	{
		$owner = $asset ?? $this->getOwner();
		// getAssetFilePath may reset $_match, must be before the next if-is_bool-_match
		if (!$owner || empty($filePath = $owner->getAssetFilePath())) {
			return false;
		}
		if (is_bool($this->_match) || ($this->_match === 1 && $dstFile === null)) {
			return $this->_match;
		}
		if ($this->_match || preg_match($this->getMatchFiles(), $filePath)) {
			if ($metaMatch = $this->getMetaMatch()) {
				if (!$owner->getIsVirtual() || $dstFile) {
					$this->_match = $this->ensureMetaData($owner->getIsVirtual() ? $dstFile : $owner->getAssetOriginalFilePath())->match($metaMatch);
				} else {
					$this->_match = 1;
				}
			} else {
				$this->_match = true;
			}
		} else {
			$this->_match = false;
		}
		return $this->_match;
	}

	/**
	 * If there is a match (or pre-MetaData Match), up the Modification date
	 * of the asset to include the Application, if there are app config filters,
	 * and by the FilterFile modification date, if there is one specified.
	 *
	 * @param mixed $modificationDate
	 * @param mixed $callchain
	 */
	public function dyGetAssetModificationDate($modificationDate, $callchain)
	{
		if ($this->hasMatch()) {
			$app = $this->getAppConfig();
			if ($app['filters'] || $app['metadata'] !== null) {
				$modificationDate = max((int) $modificationDate, (int) @filemtime(Prado::getPathOfNamespace('Application.application', Prado::getApplication()->getConfigurationFileExt())));
			}
			if ($filterFile = $this->getFilterFile()) {
				$modificationDate = max((int) $modificationDate, (int) @filemtime($filterFile));
			}
		}
		return $callchain->dyGetAssetModificationDate($modificationDate);
	}


	/**
	 * Loads the {@see getFilterFilePath FilterFilePath} configuration, from the application
	 * cache when it is there.
	 * @throws TConfigurationException when the filter file cannot be loaded.
	 * @return array{metadata:?array, filters:array} the parsed filter file configuration.
	 */
	protected function loadFilterFile(): array
	{
		if (!($filterFilePath = $this->getFilterFile())) {
			return TImagerFilterFactory::parse(null);
		}
		$app = Prado::getApplication();
		$cache = $app->getCache();
		if ($cache && is_array($fileConfig = $cache->get(self::FILTERS_CACHE . $filterFilePath))) {
			return $fileConfig;
		}
		if ($app->getConfigurationType() == TApplication::CONFIG_TYPE_PHP) {
			$config = include $filterFilePath;
		} else {
			$config = new TXmlDocument();
			if (!$config->loadFromFile($filterFilePath)) {
				throw new TConfigurationException('assetimagerbase_filterfile_not_load', $filterFilePath);
			}
		}
		$fileConfig = TImagerFilterFactory::parse($config, 'fileFilter');

		if ($cache) {
			$dependency = null;
			if ($app->getMode() !== TApplicationMode::Performance) {
				$dependency = new TFileCacheDependency($filterFilePath);
			}
			$cache->set(self::FILTERS_CACHE . $filterFilePath, $fileConfig, 0, $dependency);
		}
		return $fileConfig;
	}

	/**
	 * This converts a Palette Image to True color while preserving the
	 * transparent color of the image in the palette.  Normally, imagePaletteToTrueColor
	 * makes all pixels of the transparent color index into 0x7F000000,
	 * regardless of if the transparent color is not that color.  This
	 * preserves transparent pixels with the transparent color while making
	 * the image true color.
	 *
	 * @param object $image The image to convert from Palette to True Color.
	 * @return bool Was the image converted to True Color, false if already true color.
	 */
	public static function imagePaletteToTrueColor($image): bool
	{
		if (!$image || imageIsTrueColor($image)) {
			return false;
		}
		$transparentColor = imagecolortransparent($image);
		$sx = imagesx($image);
		$sy = imagesy($image);
		$tmp = null;
		if ($transparentColor !== -1) {
			$tmp = imagecreatetruecolor($sx, $sy);
			imagealphablending($tmp, false);
			$c = imagecolorsforindex($image, $transparentColor);
			imagefilledrectangle($tmp, 0, 0, $sx, $sy, imageColorAllocateAlpha($tmp, $c['red'], $c['green'], $c['blue'], $c['alpha']));
			imagealphablending($tmp, true);
			imagecopy($tmp, $image, 0, 0, 0, 0, $sx, $sy); //Image over TransparentColor
		}
		imagePaletteToTrueColor($image);
		if ($transparentColor !== -1) {
			imagealphablending($image, false);
			imagecopy($image, $tmp, 0, 0, 0, 0, $sx, $sy); //restore with proper transparentColor.
		}
		return true;
	}

	/**
	 * Whether a graphics library (GD or Imagick) is available to read and write the image.
	 * Without one, a matching asset publishes unprocessed, and the format conversions do
	 * not rename it ({@see TAssetImageFilter::canEncode}).
	 * @return bool whether a graphics library is available.
	 */
	protected function hasGraphics(): bool
	{
		return TImageGraphics::getDefaultMode() !== null;
	}

	/**
	 * Converts an image into the graphics library of a mode. The image is encoded as a PNG,
	 * the format both libraries read that keeps the alpha channel, and decoded by the other
	 * library, as a true color image. An image already in the mode is returned as it is.
	 * @param \GdImage|\Imagick $image the image to convert.
	 * @param string $mode the {@see TImageGraphicsMode} to convert the image into.
	 * @since 0.1.0
	 * @return false|object the image in the mode, or false when it cannot be converted.
	 */
	public static function convertImage($image, string $mode)
	{
		if (TImageGraphics::getModeOf($image) === $mode) {
			return $image;
		}
		if (!TImageGraphics::hasMode($mode)) {
			return false;
		}
		if ($image instanceof \GdImage) { // The alpha channel is only encoded when it is saved.
			imageAlphaBlending($image, false);
			imageSaveAlpha($image, true);
		}
		$bytes = static::encodePortableImage($image);
		if ($bytes === false) {
			return false;
		}
		$converted = TImageGraphics::decode($bytes, $mode);
		if ($converted instanceof \GdImage && !imageIsTrueColor($converted)) {
			// A library may write a palette PNG; the filters work on true color images.
			static::imagePaletteToTrueColor($converted);
		}
		return $converted;
	}
	/**
	 * Encodes an image in the interchange format of {@see convertImage}: a PNG, the format
	 * both graphics libraries read that keeps the alpha channel. A subclass overrides this
	 * to interchange images in another format.
	 * @param \GdImage|\Imagick $image the image to encode.
	 * @return false|string the encoded image, or false when it cannot be encoded.
	 */
	protected static function encodePortableImage($image): false|string
	{
		return TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng, 100);
	}


	/**
	 * Reads the image being published, whatever the format and whatever the graphics
	 * library: GD decodes the file when it can, then Imagick, and a format neither decodes,
	 * such as TIFF, is converted by the {@see https://github.com/belisoful/prado-image
	 * prado-image} container the metadata was read from.
	 * @param TAssetImageMetaData $metaData the metadata of the image, already read.
	 * @param string $filePath the image file.
	 * @param int $imageType the IMAGETYPE_* constant of the image.
	 * @return false|object the image, in the library that read it, or false when it cannot
	 *   be read.
	 */
	protected function openImage($metaData, string $filePath, int $imageType)
	{
		if ($imageType === IMAGETYPE_XBM && TImageGraphics::hasGd()) { // The one format imagecreatefromstring does not read.
			return @imagecreatefromxbm($filePath);
		}
		if (($bytes = @file_get_contents($filePath)) !== false) {
			foreach ([TImageGraphicsMode::GD, TImageGraphicsMode::Imagick] as $mode) {
				// GD first: it keeps a palette image's palette, which the saving properties
				// preserve. Imagick reads what GD cannot, such as CMYK JPEG, PSD, and HEIC.
				if (TImageGraphics::hasMode($mode) && ($image = TImageGraphics::decode($bytes, $mode))) {
					return $image;
				}
			}
		}
		try {
			// A format neither library decodes, such as TIFF, is converted by the
			// prado-image container the metadata was read from.
			if (($file = $metaData->getImageFile()) !== null) {
				return $file->getImage();
			}
		} catch (\Exception $e) {
			// The container cannot convert its raster either; the image is not published.
		}
		return false;
	}



	/**
	 * Opens the image and runs the filters on it.  The metadata is read before the image
	 * can be re-encoded.  An image that cannot be opened is not published.
	 * @param object $asset the TAsset being published.
	 * @param TAssetEventParameter $param the publishing parameter, holding the file being
	 *   processed, the asset, and the image shared by the imagers of the asset.
	 */
	public function processAsset($asset, $param)
	{
		if (!$asset || !$param || !($param instanceof TAssetEventParameter)) {
			return;
		}
		$dst = $param->getFilePath();
		if (!$this->getEnabled() || !$dst || !$this->hasMatch($dst, $asset)) {
			return;
		}

		if (!($image = $param->getImage())) {
			if (!$this->hasGraphics()) {
				return true; // Without a graphics library the asset publishes unprocessed.
			}
			$metaData = $this->ensureMetaData($dst);
			if (!($imageType = $metaData->getImageType())) {
				@unlink($dst);
				$param->setFilePath('');
				return true;
			}
			$metaData->loadMetaData();
			$image = $this->openImage($metaData, $dst, $imageType) ?: null;
			if ($image) {
				if ($image instanceof \GdImage) {
					$param->setPaletteColors(imageColorsTotal($image));
					if (!imageIsTrueColor($image)) {
						TAssetImagerBase::imagePaletteToTrueColor($image);
					}
				}
				$param->setOriginalImageType($imageType);
				$param->setImageType($imageType);
				$param->setImage($image);
				$param->setImageMetaData($metaData);
				$param->addFinalizer($this, $this->getFinalizerPriority());
			}
		}
		if ($image) {
			$this->applyFilters($param);
		} else {
			@unlink($dst);
			$param->setFilePath('');
		}
		return true;
	}

	/**
	 * Finalizes the publishing of an image. When a filter changed the image, or the
	 * image type changes, the image is saved by {@see saveImage} and the metadata is
	 * written into it. Otherwise, when the metadata needs writing (an edit, a dropped
	 * carrier, or a privacy scrub), only the metadata is rewritten, leaving the encoded
	 * pixels untouched.
	 *
	 * An image type that no graphics library writes ({@see canEncode}) leaves the file
	 * written before the processing in place, and the asset publishes unprocessed, as the
	 * rename to that image type is skipped as well.  An image type that can be encoded but
	 * fails to encode or to write throws instead of leaving those bytes: they are the
	 * unprocessed source, and publishing them as the processed image would publish the
	 * metadata a scrub removes, under the file name of a conversion that did not happen.
	 * The manager removes the unfinished file.
	 * @param string $dstFile the destination file to finalize
	 * @param \Prado\Web\Assets\TAssetEventParameter $param
	 * @throws TIOException when the image cannot be encoded as its image type, or the
	 *   encoded image cannot be written to the destination.
	 */
	public function finalize($dstFile, $param)
	{
		if (!($image = $param->getImage()) || !$dstFile) {
			return;
		}
		$type = $param->getImageType();
		$metaData = $param->getImageMetaData();
		if ($param->getSaveImage() || $param->getOriginalImageType() !== $type) {
			if (!$this->canEncode($type)) {
				// No graphics library writes the image type, so the file written before the
				// processing stays and the asset publishes unprocessed, as the rename that
				// would have named it that image type is skipped as well.
				Prado::trace("The image of $dstFile cannot be encoded as image type $type; it publishes unprocessed", TAssetImagerBase::class);
			} elseif ($metaData && $metaData->carriesMetaData($type)) {
				// The image is encoded, the metadata is written into those bytes, and the
				// file is written once, rather than saving the image and rewriting the file.
				if (($bytes = $this->encodeImage($image, $type, $param->getPaletteColors())) === false) {
					throw new TIOException('assetimagerbase_encode_failed', $dstFile, $type);
				}
				$bytes = $metaData->writeImageBytes($bytes, $image, true);
				if (@file_put_contents($dstFile, $bytes) !== strlen($bytes)) {
					throw new TIOException('assetimagerbase_write_failed', $dstFile);
				}
			} elseif (!$this->saveImage($image, $type, $param->getPaletteColors(), $dstFile)) {
				throw new TIOException('assetimagerbase_write_failed', $dstFile);
			}
		} elseif ($metaData && $metaData->getNeedsWrite()) {
			$metaData->writeMetaData($dstFile, $image, false);
		}
		$param->setImage(null);
	}

	/**
	 * Runs the enabled filters, in order, on the parameter's image. A filter that changes
	 * the image stores its (possibly new) image and flags the image to be saved.
	 *
	 * The image is converted into the graphics library a filter works in
	 * ({@see \Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter::getGraphicsMode}) when
	 * it is in the other one, and stays there for the filters that follow. A filter whose
	 * library is not installed is skipped.
	 * @param TAssetEventParameter $param the publishing parameter holding the image.
	 * @return bool whether a filter changed the image.
	 */
	public function applyFilters(TAssetEventParameter $param): bool
	{
		$changed = false;
		foreach ($this->getFilters() as $name => $filter) {
			if (!$filter->getEnabled() || !($image = $param->getImage())) {
				continue;
			}
			if (($mode = $filter->getGraphicsMode()) !== null && TImageGraphics::getModeOf($image) !== $mode) {
				if (($converted = static::convertImage($image, $mode)) === false) {
					Prado::trace("Skipping the image filter '$name': the $mode graphics library is not available", TAssetImagerBase::class);
					continue;
				}
				// The converted image is kept, so a run of filters of one library converts once.
				$param->setImage($image = $converted);
			}
			if ($filter->filterImage($image, $param)) {
				$param->setImage($image);
				$param->appendSaveImage(true);
				$changed = true;
			}
		}
		return $changed;
	}

	/**
	 * This saves an image of $type to the $filePath
	 * @param object $image
	 * @param int $type The GD Image type to save the image as.
	 * @param ?int $paletteColors number of colors in the palette, 0 = true color
	 * @param string $filePath The file path to save the image to.
	 * @return ?bool Was the image saved.
	 */
	abstract public function saveImage($image, int $type, ?int $paletteColors, string $filePath): ?bool;

	/**
	 * This encodes an image of $type, as {@see saveImage} writes it, so the metadata can
	 * be written into the encoded image before the file is written.
	 * @param object $image the image to encode.
	 * @param int $type The GD image type to encode the image as.
	 * @param ?int $paletteColors number of colors in the palette, 0 = true color
	 * @return false|string the encoded image, or false when it cannot be encoded.
	 */
	abstract public function encodeImage($image, int $type, ?int $paletteColors): false|string;

	/**
	 * Whether an image can be written as the image type, by {@see saveImage} or
	 * {@see encodeImage}, with the graphics libraries installed.  A format conversion does
	 * not rename a file to an image type it cannot write, and {@see finalize} leaves such
	 * an image unprocessed.
	 * @param int $type The GD image type.
	 * @return bool whether the image type can be written.
	 */
	abstract public function canEncode(int $type): bool;
}
