<?php

/**
 * TPublishingManager class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplicationMode;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Web\Assets\IAsset;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetDiscoverClassEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Assets\TTarAsset;

/**
 * TPublishingManager class
 *
 * TPublishingManager is a {@see TAssetManager} that publishes every file through an
 * asset object. Where TAssetManager copies a file verbatim, TPublishingManager
 * instances the file as an {@see IAsset}, lets the behaviors attached to the asset
 * rename, virtualize, block, and process it, and writes the result. Everything else
 * is TAssetManager: the base path and URL, the hashed publishing directories, the
 * publishing options (`only`, `except`, `caseSensitive`, `beforeCopy`, `afterCopy`,
 * `forceCopy`, `atomic`), atomic writes, directory completion markers, symlinked
 * assets, timestamps, and the asset map.
 *
 * ```xml
 * <modules>
 *   <module id="asset" class="Prado\Web\TPublishingManager"
 *       BasePath="Application.assets" BaseUrl="/assets" />
 * </modules>
 * ```
 *
 * Every route publishes through {@see routeAsset}: a string path, instanced as an
 * asset by {@see ensureAsset}; an {@see IAsset} object; each file of a published
 * directory; and a tar archive from {@see publishTarFile}, instanced as the
 * {@see getDefaultTarAssetClass DefaultTarAssetClass}. A bare {@see IPublishable}
 * that is not an IAsset takes the TAssetManager virtual path unchanged.
 *
 * A string path is instanced as the {@see getDefaultAssetClass DefaultAssetClass},
 * or the {@see getDefaultImageAssetClass DefaultImageAssetClass} for an image, after
 * {@see onDiscoverClass} gives handlers the chance to route it to another class.
 *
 * The published location is computed from the asset's publish path
 * ({@see \Prado\Web\Assets\TAsset::getAssetPublishFilePath}), so a behavior that converts
 * an image to a new format or fingerprints its name changes the published file name and URL.
 *
 * With {@see setLinkAssets LinkAssets}, a file asset is symlinked to its source only
 * when publishing it would not change it: its published file name is its source file
 * name and it has no `onProcessAsset` handlers. Any other asset is written, because a
 * link would serve the unprocessed source.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TPublishingManager extends TAssetManager
{
	/** The file extensions that instance as the {@see getDefaultImageAssetClass DefaultImageAssetClass}. */
	public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'gif', 'png', 'bmp', 'wbmp', 'xbm', 'webp', 'tif', 'tiff'];

	/** @var string the default class for file assets. */
	private string $_defaultAssetClass = TFileAsset::class;

	/** @var string the default class for image file assets. */
	private string $_defaultImageAssetClass = TImageAsset::class;

	/** @var string the default class for tar archive assets. */
	private string $_defaultTarAssetClass = TTarAsset::class;

	/** @var array<string, IAsset> the published assets, keyed by source path. */
	private array $_publishedAssets = [];

	/** @var array<string, string> the published URLs of assets, keyed by asset class, path, and options. */
	private array $_assetUrls = [];

	/** @var array<string, true> the published list keys last written by an asset object, which a string path does not take. */
	private array $_assetListed = [];

	/** @var bool whether a directory file renamed by its behaviors is also published under its own name. */
	private bool $_publishOriginalNames = false;

	/** @var bool whether the assets this manager instances cache their publish file path. */
	private bool $_cacheAssetPublishFilePath = false;

	/**
	 * @return string the default asset class for instancing file assets.
	 */
	public function getDefaultAssetClass(): string
	{
		return $this->_defaultAssetClass;
	}

	/**
	 * @param string $value the default asset class for instancing file assets.
	 * @throws \Prado\Exceptions\TInvalidOperationException if the module is initialized already.
	 */
	public function setDefaultAssetClass($value): void
	{
		$this->assertUninitialized('DefaultAssetClass');
		$this->_defaultAssetClass = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the default asset class for instancing image file assets.
	 */
	public function getDefaultImageAssetClass(): string
	{
		return $this->_defaultImageAssetClass;
	}

	/**
	 * @param string $value the default asset class for instancing image file assets.
	 * @throws \Prado\Exceptions\TInvalidOperationException if the module is initialized already.
	 */
	public function setDefaultImageAssetClass($value): void
	{
		$this->assertUninitialized('DefaultImageAssetClass');
		$this->_defaultImageAssetClass = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the asset class {@see publishTarFile} instances, default TTarAsset.
	 */
	public function getDefaultTarAssetClass(): string
	{
		return $this->_defaultTarAssetClass;
	}

	/**
	 * @param string $value the asset class {@see publishTarFile} instances; a TTarAsset.
	 * @throws \Prado\Exceptions\TInvalidOperationException if the module is initialized already.
	 */
	public function setDefaultTarAssetClass($value): void
	{
		$this->assertUninitialized('DefaultTarAssetClass');
		$this->_defaultTarAssetClass = TPropertyValue::ensureString($value);
	}

	/**
	 * Whether a file of a published directory that its behaviors rename (a format
	 * conversion, a fingerprint, a compression extension) is also published unprocessed
	 * under its own name. Code that builds a file URL from a directory URL, such as the
	 * framework's client script packages, then still finds the file. Default false: the
	 * original is the unprocessed source, so it is published without the processing, e.g.
	 * with the image metadata a conversion scrubs. A file substituted or virtualized onto
	 * another source is never published under its own name.
	 * @return bool whether renamed directory files keep their original names too.
	 */
	public function getPublishOriginalNames(): bool
	{
		return $this->_publishOriginalNames;
	}

	/**
	 * @param bool|string $value whether renamed directory files keep their original names too.
	 */
	public function setPublishOriginalNames($value): void
	{
		$this->_publishOriginalNames = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Whether the assets this manager instances cache their publish path
	 * ({@see \Prado\Web\Assets\TAsset::setCachePublishFilePath CachePublishFilePath}), so
	 * the `dyAlterAssetFilePath` renaming runs once per asset instead of on every read of
	 * the path. Default false, as a behavior that renames a file from data it changes
	 * itself, without resetting the file path cache, would keep its first name. It applies
	 * to the assets {@see ensureAsset} instances, from a published path; an asset object
	 * given to {@see publish} carries its own setting.
	 * @return bool whether instanced assets cache their publish path, default false.
	 */
	public function getCacheAssetPublishFilePath(): bool
	{
		return $this->_cacheAssetPublishFilePath;
	}

	/**
	 * @param bool|string $value whether instanced assets cache their publish path.
	 */
	public function setCacheAssetPublishFilePath($value): void
	{
		$this->_cacheAssetPublishFilePath = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return array<string, IAsset> the assets published by this manager, keyed by the
	 *   string path they were published from, or by the asset file path.
	 */
	public function getPublishedAssets(): array
	{
		return $this->_publishedAssets;
	}

	/**
	 * Publishes a file, a directory, or an asset, and returns its URL. A string path is
	 * instanced as an asset by {@see ensureAsset}; it and an {@see IAsset} publish through
	 * {@see routeAsset}. A bare {@see IPublishable} publishes as in
	 * {@see TAssetManager::publish}. The options are those of {@see TAssetManager::publish}.
	 * @param IPublishable|string $path the path to publish, or an asset.
	 * @param array|bool $checkTimestamp the modification-time flag, or an options array.
	 * @throws TInvalidDataValueException if the file path to be published is invalid.
	 * @return string the absolute URL to the published file, directory, or asset; ''
	 *   when publishing is cancelled.
	 */
	public function publish($path, $checkTimestamp = false)
	{
		if (!is_string($path) && !($path instanceof IAsset)) {
			return parent::publish($path, $checkTimestamp);
		}
		[$checkTimestamp, $options] = static::publishingArguments($checkTimestamp);
		if ($path instanceof IAsset) {
			return $this->routeAsset($path, $checkTimestamp, $options, null, null, true);
		}
		$cacheKey = $this->publishCacheKey($path, $options);
		$published = $this->getPublishedDirect();
		if (isset($published[$cacheKey]) && !isset($this->_assetListed[$cacheKey])) {
			return $published[$cacheKey];
		}
		if ($path === '') {
			throw new TInvalidDataValueException('assetmanager_filepath_invalid', $path);
		}
		$asset = $this->ensureAsset($path);
		$url = $this->routeAsset($asset, $checkTimestamp, $options);
		$this->_publishedAssets[$path] = $asset;
		unset($this->_assetListed[$cacheKey]);
		return $this->cachePublished($cacheKey, $url);
	}

	/**
	 * Publishes a tar archive by extracting it, through a
	 * {@see getDefaultTarAssetClass DefaultTarAssetClass} asset and {@see routeAsset}.
	 * The URL and the options are those of {@see TAssetManager::publishTarFile}; the
	 * `only` and `except` patterns do not apply to an archive.
	 * @param string $tarfile the tar archive.
	 * @param ?string $md5sum the checksum file, or null for none.
	 * @param array|bool $checkTimestamp the modification-time flag, or an options array
	 *   with the publishing and extractor options (atomic, strict, conflictMode, dirMode,
	 *   fileMode).
	 * @throws TInvalidDataValueException when the checksum file does not exist.
	 * @throws TInvalidDataTypeException when the DefaultTarAssetClass is not a TTarAsset.
	 * @throws \Prado\Exceptions\TIOException when the archive does not exist.
	 * @return string the URL of the directory the archive was extracted to.
	 */
	public function publishTarFile($tarfile, $md5sum = null, $checkTimestamp = false)
	{
		[$checkTimestamp, $options] = static::publishingArguments($checkTimestamp);
		// The tar key is namespaced, as in TAssetManager, so that publishing the checksum
		// file itself as a plain file does not share the entry of the extracted directory.
		$cacheKey = $this->publishCacheKey('tar:' . (($md5sum === null || $md5sum === '') ? $tarfile : $md5sum), $options);
		$published = $this->getPublishedDirect();
		if (isset($published[$cacheKey])) {
			return $published[$cacheKey];
		}
		$class = $this->getDefaultTarAssetClass();
		if (!is_a($class, TTarAsset::class, true)) {
			throw new TInvalidDataTypeException('publishingmanager_invalid_tar_class', $class);
		}
		$asset = new $class($tarfile, $md5sum);
		$url = $this->routeAsset($asset, $checkTimestamp, ['only' => null, 'except' => null] + $options);
		return $this->cachePublished($cacheKey, $url);
	}

	/**
	 * The common publishing route of every asset. The asset's target is resolved, the
	 * published URL is returned from the cache when present, and otherwise the asset is
	 * published by kind: a tar archive is extracted ({@see publishTarAsset}); a directory
	 * with a source is copied file by file ({@see publishAssetDirectory}); a generated
	 * directory populates itself ({@see publishGeneratedDirectory}); and a file is written
	 * ({@see publishAssetFile}). The asset is tracked, and an {@see IPublishedCapture}
	 * receives its path and URL. A {@see \Prado\Web\Assets\TAsset} is given this manager
	 * as its {@see \Prado\Web\Assets\TAsset::setAssetManager AssetManager}, so a directory
	 * asset copies its files with the manager publishing it.
	 *
	 * The asset cache is keyed by the asset class and path, separately from the published
	 * list of {@see getPublished}, so a path published as a string and as an asset object
	 * of another class do not share an entry.
	 *
	 * With a $destination, the asset publishes to that file path rather than its hashed
	 * location, as a file of a published directory does: a rename by its behaviors is
	 * applied to the destination file name, and it is not cached.
	 * @param IAsset $asset the asset to publish.
	 * @param bool $checkTimestamp true to check the modification time even in performance mode.
	 * @param array $options the publishing options.
	 * @param ?string $destination a predetermined destination file path, or null.
	 * @param ?string $sourcePath the unresolved source path passed to the copy callbacks,
	 *   or null for the asset's.
	 * @param bool $checkModification whether a published file older than the asset is
	 *   republished even in performance mode, as for an asset object.
	 * @throws TInvalidDataValueException when the asset file path is empty or invalid.
	 * @return string the published URL, or '' when publishing is cancelled.
	 */
	public function routeAsset(IAsset $asset, $checkTimestamp = false, array $options = [], ?string $destination = null, ?string $sourcePath = null, bool $checkModification = false): string
	{
		if ($asset instanceof TAsset) {
			// A directory asset copies its files with the manager publishing it.
			$asset->setAssetManager($this);
		}
		$target = $destination === null ? $this->virtualAssetTarget($asset) : $this->destinationTarget($asset, $destination, $sourcePath);
		if ($target === null) {
			return '';
		}
		$target['callbackSource'] = $sourcePath;
		$cacheKey = $destination === null ? $this->publishCacheKey(get_class($asset) . ':' . $target['key'], $options) : null;
		if ($cacheKey !== null && isset($this->_assetUrls[$cacheKey])) {
			$url = $this->_assetUrls[$cacheKey];
		} else {
			$src = $this->assetSourcePath($asset);
			if ($asset instanceof TTarAsset) {
				$url = $this->publishTarAsset($asset, $target, $checkTimestamp, $options);
			} elseif ($target['isDir'] && $src !== null && is_dir($src)) {
				$url = $this->publishAssetDirectory($asset, $src, $target, $checkTimestamp, $options);
			} elseif ($target['isDir']) {
				$url = $this->publishGeneratedDirectory($asset, $target, $checkTimestamp, $options);
			} else {
				$url = $this->publishAssetFile($asset, $src, $target, $checkTimestamp || ($checkModification && @filemtime($target['dst']) < (int) $asset->getAssetModificationDate()), $options, $destination === null);
				if ($destination !== null && $this->getPublishOriginalNames() && $sourcePath !== null && $target['fileName'] !== basename($destination)
					&& $src !== null && realpath($sourcePath) === $src) {
					$this->copyFile($sourcePath, dirname($destination), ['only' => null, 'except' => null] + $options);
				}
			}
			$this->_publishedAssets[$target['source']] = $asset;
			if ($cacheKey !== null) {
				$this->_assetUrls[$cacheKey] = $url;
				if ($checkModification) {
					// An asset object is listed in the published list as TAssetManager lists a virtual asset,
					// without taking the entry of a string path, which instances its own asset class.
					$listKey = $this->publishCacheKey($target['key'], $options);
					if (!isset($this->getPublishedDirect()[$listKey]) || isset($this->_assetListed[$listKey])) {
						$this->_assetListed[$listKey] = true;
						$this->cachePublished($listKey, $url);
					}
				}
			}
		}
		if ($asset instanceof IPublishedCapture) {
			$asset->setPublishedPath($target['dst']);
			$asset->setPublishedUrl($url);
		}
		return $url;
	}

	/**
	 * Instances a string path as an asset. The path is made absolute, checked not to be
	 * the BasePath or one of its parents, and routed through {@see onDiscoverClass}.
	 * @param IAsset|string $asset the path, or an asset returned as-is.
	 * @throws TInvalidDataValueException when the path is empty, or is the BasePath or
	 *   one of its parents.
	 * @throws TInvalidDataTypeException when the discovered class is not an IAsset, or
	 *   the argument is neither a string nor an IAsset.
	 * @return IAsset the asset.
	 */
	public function ensureAsset($asset): IAsset
	{
		if ($asset instanceof IAsset) {
			return $asset;
		}
		if (!is_string($asset)) {
			throw new TInvalidDataTypeException('publishingmanager_invalid_asset', get_debug_type($asset));
		}
		if ($asset === '') {
			throw new TInvalidDataValueException('publishingmanager_filepath_invalid', $asset);
		}
		$path = ($real = realpath($asset)) !== false ? $real : TAsset::virtualpath($asset);
		$this->assertNotBasePath($path, $asset);
		$discovered = $path;
		$class = $this->onDiscoverClass($this->getDefaultAssetClass(), $discovered);
		if ($discovered !== $path) {
			$this->assertNotBasePath($discovered, $asset);
		}
		if (!class_exists($class) || !is_a($class, IAsset::class, true) || !(new \ReflectionClass($class))->isInstantiable()) {
			throw new TInvalidDataTypeException('publishingmanager_invalid_class', $class);
		}
		$instance = new $class($discovered);
		if ($this->_cacheAssetPublishFilePath && $instance instanceof TAsset) {
			$instance->setCachePublishFilePath(true);
		}
		return $instance;
	}

	/**
	 * Rejects a path that is the BasePath or one of its parents, which would publish the
	 * assets directory into itself. Both paths are compared resolved when they exist.
	 * @param string $path the absolute path.
	 * @param string $original the path as given, for the exception.
	 * @throws TInvalidDataValueException when the path is the BasePath or a parent of it.
	 */
	protected function assertNotBasePath(string $path, string $original): void
	{
		if (($basePath = $this->getBasePathDirect()) === null) {
			return;
		}
		$basePath = realpath($basePath) ?: $basePath;
		$path = realpath($path) ?: $path;
		if ($basePath === $path || str_starts_with($basePath, rtrim($path, '/\\') . DIRECTORY_SEPARATOR)) {
			throw new TInvalidDataValueException('publishingmanager_filepath_invalid', $original);
		}
	}

	/**
	 * Raised to discover the asset class of a file path. Handlers inspect the
	 * {@see TAssetDiscoverClassEventParameter::getFilePath FilePath} and, when they
	 * recognize it, set the {@see TAssetDiscoverClassEventParameter::setClass Class} and
	 * optionally rewrite the FilePath. When no handler sets the class, an image file
	 * extension ({@see IMAGE_EXTENSIONS}) selects the
	 * {@see getDefaultImageAssetClass DefaultImageAssetClass}.
	 * @param string $class the default class for the file path.
	 * @param string $filepath the file path, rewritten when a handler changes it.
	 * @throws TInvalidDataTypeException when a handler clears the class.
	 * @return string the asset class for the file path.
	 * @see \Prado\Web\Assets\Behaviors\TAssetDiscovery
	 */
	public function onDiscoverClass(string $class, string &$filepath): string
	{
		$param = new TAssetDiscoverClassEventParameter($class, $filepath);
		$this->raiseEvent('onDiscoverClass', $this, $param);
		$filepath = $param->getFilePath();
		if ($param->getIsClassSet()) {
			if ($param->getClass() === '') {
				throw new TInvalidDataTypeException('publishingmanager_requires_class');
			}
			return $param->getClass();
		}
		if (in_array(strtolower(pathinfo($filepath, PATHINFO_EXTENSION)), static::IMAGE_EXTENSIONS, true)) {
			return $this->getDefaultImageAssetClass();
		}
		return $class;
	}

	/**
	 * Returns where a path or asset publishes, without publishing it. A string path is
	 * resolved as an asset, so a behavior that renames it is reflected; a path that
	 * cannot be instanced as an asset resolves as in {@see TAssetManager::getPublishedPath}.
	 * @param IPublishable|string $path the directory or file path, or an asset.
	 * @return string the published file or directory path; '' when the asset cancels.
	 */
	public function getPublishedPath($path)
	{
		if (is_string($path) && ($target = $this->stringAssetTarget($path)) !== false) {
			return $target === null ? '' : $target['dst'];
		}
		return parent::getPublishedPath($path);
	}

	/**
	 * Returns the URL a path or asset publishes to, without publishing it. A string path
	 * is resolved as in {@see getPublishedPath}.
	 * @param IPublishable|string $path the directory or file path, or an asset.
	 * @return string the published URL; '' when the asset cancels.
	 */
	public function getPublishedUrl($path)
	{
		if (is_string($path) && ($target = $this->stringAssetTarget($path)) !== false) {
			return $target === null ? '' : $target['url'];
		}
		return parent::getPublishedUrl($path);
	}

	/**
	 * Splits the second publishing argument into the timestamp flag and the options.
	 * @param array|bool $checkTimestamp the modification-time flag, or an options array
	 *   whose forceCopy key is the flag.
	 * @return array{0:bool, 1:array} the flag and the options.
	 */
	protected static function publishingArguments($checkTimestamp): array
	{
		if (is_array($checkTimestamp)) {
			return [(bool) ($checkTimestamp['forceCopy'] ?? false), $checkTimestamp];
		}
		return [(bool) $checkTimestamp, []];
	}

	/**
	 * Stores a published URL in the published cache.
	 * @param string $cacheKey the cache key.
	 * @param string $url the published URL.
	 * @return string the URL.
	 */
	protected function cachePublished(string $cacheKey, string $url): string
	{
		$published = $this->getPublishedDirect();
		$published[$cacheKey] = $url;
		$this->setPublishedDirect($published);
		return $url;
	}

	/**
	 * Resolves the published target of a string path through its asset.
	 * @param string $path the file or directory path.
	 * @return null|array|false the target from {@see virtualAssetTarget}, null when the
	 *   asset cancels, or false when the path cannot be instanced as a valid asset or its
	 *   target cannot be resolved.
	 */
	protected function stringAssetTarget(string $path)
	{
		try {
			return $this->virtualAssetTarget($this->ensureAsset($path));
		} catch (\Prado\Exceptions\TException $e) {
			return false;
		}
	}

	/**
	 * The file path and the publish path of an asset. The publish path is
	 * {@see \Prado\Web\Assets\TAsset::getAssetPublishFilePath} for a TAsset, the file path
	 * filtered by `dyAlterAssetFilePath` for another component, or else the file path.
	 * @param IAsset $asset the asset.
	 * @throws TInvalidDataValueException when the asset or publish path is empty or invalid.
	 * @return ?array{0:string, 1:string} the asset file path and the publish path, or null
	 *   when publishing is cancelled.
	 */
	protected function assetPaths(IAsset $asset): ?array
	{
		$source = $asset->getAssetFilePath();
		if ($source === null) {
			return null;
		}
		if (empty($source)) {
			throw new TInvalidDataValueException('assetmanager_filepath_invalid', $source);
		}
		if ($asset instanceof TAsset) {
			$path = $asset->getAssetPublishFilePath();
		} elseif ($asset instanceof TComponent) {
			$path = $asset->dyAlterAssetFilePath($source);
		} else {
			$path = $source;
		}
		if ($path === null) {
			return null;
		}
		if (empty($path)) {
			throw new TInvalidDataValueException('assetmanager_filepath_invalid', $path);
		}
		return [$source, $path];
	}

	/**
	 * Resolves the published target of an asset. For an {@see IAsset}, the location is
	 * computed from its publish path ({@see assetPaths}); a bare {@see IPublishable}
	 * resolves as in TAssetManager.
	 * @param IPublishable $asset the asset to resolve.
	 * @throws TInvalidDataValueException when the asset file path is empty or invalid.
	 * @return ?array null when publishing is cancelled; otherwise the keys vpath, isDir,
	 *   fileName, source (the asset file path), key (the cache key path), and those from
	 *   {@see publishedLocation}.
	 */
	protected function virtualAssetTarget($asset)
	{
		$target = parent::virtualAssetTarget($asset);
		if ($target === null || !($asset instanceof IAsset) || ($paths = $this->assetPaths($asset)) === null) {
			return $target;
		}
		$source = rtrim($paths[0], '/\\');
		$target['source'] = $source;
		$target['key'] = $source === $target['vpath'] ? $target['vpath'] : $source . '>' . $target['vpath'];
		return $target;
	}

	/**
	 * The path an asset publishes under: the asset's publish path ({@see assetPaths}); a
	 * bare {@see IPublishable} resolves as in TAssetManager. {@see virtualAssetTarget}
	 * turns it into the published location.
	 *
	 * An asset is asked for its publish path twice per target, once here and once for the
	 * source of the target; {@see setCacheAssetPublishFilePath CacheAssetPublishFilePath}
	 * computes it once per asset instead.
	 * @param IPublishable $asset the asset to resolve.
	 * @throws TInvalidDataValueException when the asset file path is empty or invalid.
	 * @return ?string the publish path, or null when publishing is cancelled.
	 */
	protected function virtualAssetPath(IPublishable $asset): ?string
	{
		if (!($asset instanceof IAsset)) {
			return parent::virtualAssetPath($asset);
		}
		$paths = $this->assetPaths($asset);
		return $paths === null ? null : $paths[1];
	}

	/**
	 * Resolves the target of an asset published to a predetermined destination: the
	 * destination's directory, with the file name of the asset's publish path.
	 * When the directory entry ($sourcePath) is a symbolic link to the asset's source, the
	 * asset's rename is applied to the entry's name ({@see renamedFileName}); otherwise,
	 * as for a substituted or virtualized source, the file takes its publish name.
	 * @param IAsset $asset the asset.
	 * @param string $destination the destination file path.
	 * @param ?string $sourcePath the directory entry the asset was instanced from, or null.
	 * @throws TInvalidDataValueException when the asset file path is empty or invalid.
	 * @return ?array the target, as {@see virtualAssetTarget}; null when cancelled.
	 */
	protected function destinationTarget(IAsset $asset, string $destination, ?string $sourcePath = null): ?array
	{
		if (($paths = $this->assetPaths($asset)) === null) {
			return null;
		}
		[$source, $path] = $paths;
		$isDir = in_array(substr($path, -1), ['/', '\\'], true);
		$publishName = basename(rtrim($path, '/\\'));
		$sourceName = basename(rtrim($source, '/\\'));
		$fileName = ($sourcePath !== null && basename($sourcePath) !== $sourceName && realpath($sourcePath) === rtrim($source, '/\\'))
			? static::renamedFileName(basename($sourcePath), $sourceName, $publishName)
			: $publishName;
		$dstDir = dirname($destination);
		$dst = $dstDir . DIRECTORY_SEPARATOR . $fileName;
		$basePath = $this->getBasePathDirect();
		$url = str_starts_with($dst, $basePath . DIRECTORY_SEPARATOR) ? $this->getBaseUrlDirect() . str_replace(DIRECTORY_SEPARATOR, '/', substr($dst, strlen($basePath))) : '';
		return ['vpath' => rtrim($path, '/\\'), 'isDir' => $isDir, 'fileName' => $fileName, 'source' => rtrim($source, '/\\'), 'key' => $dst, 'dir' => basename($dstDir), 'dstDir' => $dstDir, 'dst' => $dst, 'url' => $url];
	}

	/**
	 * Applies an asset's rename to a destination file name. A directory file is published
	 * under its name in the directory, which differs from its asset's source name when it
	 * is a symbolic link. The rename from the source name to the publish name (e.g.
	 * "main.css" to "main.1a2b.css" or "main.css.gz") is applied to the destination name
	 * by the part after their common prefix.
	 * @param string $destinationName the file name in the directory.
	 * @param string $sourceName the asset's source file name.
	 * @param string $publishName the asset's publish file name.
	 * @return string the destination file name with the rename applied.
	 */
	public static function renamedFileName(string $destinationName, string $sourceName, string $publishName): string
	{
		if ($publishName === $sourceName) {
			return $destinationName;
		}
		if ($destinationName === $sourceName) {
			return $publishName;
		}
		$common = strspn($sourceName ^ $publishName, "\0");
		$sourceTail = substr($sourceName, $common);
		if ($sourceTail !== '' && !str_ends_with($destinationName, $sourceTail)) {
			return $publishName;
		}
		return substr($destinationName, 0, strlen($destinationName) - strlen($sourceTail)) . substr($publishName, $common);
	}

	/**
	 * Publishes an asset. An {@see IAsset} publishes through {@see routeAsset}; a bare
	 * {@see IPublishable} publishes as in TAssetManager.
	 * @param IPublishable $asset the asset to publish.
	 * @param bool $checkTimestamp true to check the modification time even in performance mode.
	 * @param array $options the publishing options.
	 * @return string the absolute URL to the published asset, or '' when cancelled.
	 */
	protected function publishVirtual($asset, $checkTimestamp = false, array $options = [])
	{
		if ($asset instanceof IAsset) {
			return $this->routeAsset($asset, $checkTimestamp, $options, null, null, true);
		}
		return parent::publishVirtual($asset, $checkTimestamp, $options);
	}

	/**
	 * Publishes a tar archive asset as TAssetManager publishes a tar file: the archive is
	 * extracted when the published checksum file (or completion marker) is missing or
	 * older than the archive's checksum, when forced, or when the timestamp is checked or
	 * outside performance mode and the checksum is newer. The extraction settings the
	 * asset leaves null come from the options, then the manager's Atomic, DirMode, and
	 * FileMode.
	 * @param TTarAsset $asset the archive asset.
	 * @param array $target the target from {@see virtualAssetTarget}.
	 * @param bool $checkTimestamp whether to check the modification time.
	 * @param array $options the publishing and extractor options.
	 * @return string the published directory URL.
	 */
	protected function publishTarAsset(TTarAsset $asset, array $target, $checkTimestamp, array $options): string
	{
		$dst = $target['dst'];
		$forceCopy = $options['forceCopy'] ?? $this->getForceCopy();
		$sentinel = $dst . DIRECTORY_SEPARATOR . $this->directoryCompleteMarker($asset->getExtractedMarkerSource());
		if (!is_file($sentinel) || $checkTimestamp || $forceCopy || $this->getApplication()->getMode() !== TApplicationMode::Performance) {
			if ($forceCopy || !is_file($sentinel) || @filemtime($sentinel) < (int) $asset->getAssetModificationDate()) {
				Prado::trace("Publishing tar archive {$target['source']} to $dst", TPublishingManager::class);
				$asset->applyPublishingOptions($options + ['atomic' => $this->getAtomic(), 'dirMode' => $this->getDirMode(), 'fileMode' => $this->getFileMode()]);
				$asset->publish($dst);
				if (is_dir($dst)) {
					@touch($sentinel);
				}
			}
		}
		return $target['url'];
	}

	/**
	 * Publishes a directory asset as TAssetManager publishes a directory: the directory
	 * is copied by {@see copyDirectory} when it is not yet published, when the timestamp
	 * is checked, when forced, or outside performance mode, and an atomic publish writes
	 * the per-source completion marker. The asset's `onProcessAsset` is then raised on
	 * the published directory.
	 * @param IAsset $asset the directory asset.
	 * @param string $src the source directory.
	 * @param array $target the target from {@see virtualAssetTarget}.
	 * @param bool $checkTimestamp whether to check the modification time.
	 * @param array $options the publishing options.
	 * @return string the published directory URL.
	 */
	protected function publishAssetDirectory(IAsset $asset, string $src, array $target, $checkTimestamp, array $options): string
	{
		$dst = $target['dst'];
		$forceCopy = $options['forceCopy'] ?? $this->getForceCopy();
		$atomic = $options['atomic'] ?? $this->getAtomic();
		$marker = $dst . DIRECTORY_SEPARATOR . $this->directoryCompleteMarker($src);
		$published = $atomic ? is_file($marker) : is_dir($dst);
		if (!$published || $checkTimestamp || $forceCopy || $this->getApplication()->getMode() !== TApplicationMode::Performance) {
			Prado::trace("Publishing directory $src", TPublishingManager::class);
			$this->copyDirectory($src, $dst, $options);
			if ($atomic && is_dir($dst)) {
				@touch($marker);
			}
			if (is_dir($dst) && method_exists($asset, 'onProcessAsset')) {
				$asset->onProcessAsset($dst);
			}
		}
		return $target['url'];
	}

	/**
	 * Publishes a generated directory asset, which populates the directory itself, as
	 * TAssetManager publishes a virtual directory: it is published when not yet complete
	 * (its completion marker, when atomic), when the timestamp is checked, when forced,
	 * when the directory is older than the asset, or outside performance mode.
	 * @param IAsset $asset the directory asset.
	 * @param array $target the target from {@see virtualAssetTarget}.
	 * @param bool $checkTimestamp whether to check the modification time.
	 * @param array $options the publishing options.
	 * @return string the published directory URL.
	 */
	protected function publishGeneratedDirectory(IAsset $asset, array $target, $checkTimestamp, array $options): string
	{
		$dst = $target['dst'];
		$atomic = $options['atomic'] ?? $this->getAtomic();
		$forceCopy = $options['forceCopy'] ?? $this->getForceCopy();
		$marker = $atomic ? $dst . DIRECTORY_SEPARATOR . $this->directoryCompleteMarker($target['vpath']) : null;
		$exists = $marker !== null ? is_file($marker) : is_dir($dst);
		if (!$exists || $checkTimestamp || $forceCopy || @filemtime($dst) < (int) $asset->getAssetModificationDate() || $this->getApplication()->getMode() !== TApplicationMode::Performance) {
			if (!is_dir($dst)) {
				$dirMode = $this->getDirMode();
				@mkdir($dst, $dirMode, true);
				@chmod($dst, $dirMode);
			}
			Prado::trace("Publishing generated directory {$target['vpath']} to $dst", TPublishingManager::class);
			$asset->publish($dst);
			if ($marker !== null && is_dir($dst)) {
				@touch($marker);
			}
		}
		return $target['url'];
	}

	/**
	 * Publishes a file asset: the `only`/`except` patterns are matched against the
	 * source file name, and the asset is produced when it is not yet published, when the
	 * timestamp is checked, when forced, or outside performance mode.
	 * @param IAsset $asset the file asset.
	 * @param ?string $src the source file path, or null for a generated asset.
	 * @param array $target the target from {@see virtualAssetTarget}.
	 * @param bool $checkTimestamp whether to check the modification time.
	 * @param array $options the publishing options.
	 * @param bool $appendTimestamp whether the URL may carry the timestamp query.
	 * @return string the published file URL, with the timestamp query when
	 *   {@see getAppendTimestamp AppendTimestamp} is enabled.
	 */
	protected function publishAssetFile(IAsset $asset, ?string $src, array $target, $checkTimestamp, array $options, bool $appendTimestamp = true): string
	{
		$dst = $target['dst'];
		$only = array_key_exists('only', $options) ? $options['only'] : $this->getOnly();
		$except = array_key_exists('except', $options) ? $options['except'] : $this->getExcept();
		$caseSensitive = $options['caseSensitive'] ?? $this->getCaseSensitive();
		$forceCopy = $options['forceCopy'] ?? $this->getForceCopy();
		if ($this->matchFilePattern(basename($src ?? $target['vpath']), $only, $except, $caseSensitive)
			&& (!is_file($dst) || $checkTimestamp || $forceCopy || $this->getApplication()->getMode() !== TApplicationMode::Performance)) {
			$this->produceAsset($asset, $src, $dst, $options, $target['callbackSource'] ?? null);
		}
		$url = $target['url'];
		if ($appendTimestamp && $this->getAppendTimestamp() && ($timestamp = @filemtime($dst)) > 0) {
			$url .= '?' . $this->getTimestampVar() . '=' . $timestamp;
		}
		return $url;
	}

	/**
	 * Writes a file asset to its destination. The `beforeCopy` callback may cancel it.
	 * A linkable asset (see {@see isAssetLinkable}) is symlinked to its source when
	 * {@see getLinkAssets LinkAssets} is on. Otherwise the asset is written by
	 * {@see IPublishable::publish} when forced, when the destination is missing, or when
	 * the destination is older than the asset, through {@see writeAtomic} when atomic.
	 * The `afterCopy` callback runs last, and the {@see getFileMode FileMode} is applied.
	 * @param IAsset $asset the asset to write.
	 * @param ?string $src the source file path, or null for a generated asset.
	 * @param string $dst the destination file path.
	 * @param array $options the publishing options.
	 * @param ?string $callbackSource the source path given to the callbacks, default the
	 *   asset's source path.
	 */
	protected function produceAsset(IAsset $asset, ?string $src, string $dst, array $options = [], ?string $callbackSource = null): void
	{
		$callbackSrc = $callbackSource ?? $src ?? (string) $asset->getAssetFilePath();
		$beforeCopy = $options['beforeCopy'] ?? $this->getBeforeCopy();
		if ($beforeCopy !== null && !call_user_func($beforeCopy, $callbackSrc, $dst)) {
			return;
		}
		$dir = dirname($dst);
		if (!is_dir($dir)) {
			$dirMode = $this->getDirMode();
			@mkdir($dir, $dirMode, true);
			@chmod($dir, $dirMode);	// override umask on mkdir dirMode
		}
		$forceCopy = $options['forceCopy'] ?? $this->getForceCopy();
		if ($this->getLinkAssets() && $src !== null && $this->isAssetLinkable($asset, $src, $dst)) {
			if ($forceCopy && (is_file($dst) || is_link($dst))) {
				@unlink($dst);
			}
			if (!is_file($dst) && !is_link($dst)) {
				try {
					$this->symlink($this->relativeSymlinkTarget($src, $dst), $dst);
				} catch (\Throwable $e) {
					if (!is_file($dst) && !is_link($dst)) {
						throw $e;
					}
				}
			}
		} elseif ($forceCopy || !is_file($dst) || @filemtime($dst) < (int) $asset->getAssetModificationDate()) {
			Prado::trace("Publishing asset $callbackSrc to $dst", TPublishingManager::class);
			if ($options['atomic'] ?? $this->getAtomic()) {
				$this->writeAtomic($dst, fn ($tmp) => $asset->publish($tmp));
			} else {
				try {
					$asset->publish($dst);
				} catch (\Throwable $e) {
					@unlink($dst);	// a partially processed file must not look published
					throw $e;
				}
			}
			$fileMode = $this->getFileMode();
			if ($fileMode !== null && is_file($dst)) {
				@chmod($dst, $fileMode);
			}
		}
		$afterCopy = $options['afterCopy'] ?? $this->getAfterCopy();
		if ($afterCopy !== null) {
			call_user_func($afterCopy, $callbackSrc, $dst);
		}
	}

	/**
	 * Whether an asset may be published as a symlink to its source: its published file
	 * name is its source file name, it writes its source unchanged
	 * ({@see \Prado\Web\Assets\TAsset::getIsPassThrough}), and nothing processes it on
	 * publishing. A link to a converted or processed asset would serve the unprocessed source.
	 * @param IAsset $asset the asset.
	 * @param string $src the source file path.
	 * @param string $dst the destination file path.
	 * @return bool whether the asset can be linked.
	 */
	protected function isAssetLinkable(IAsset $asset, string $src, string $dst): bool
	{
		if (basename($src) !== basename($dst) || ($asset instanceof TAsset && !$asset->getIsPassThrough())) {
			return false;
		}
		return !($asset instanceof TComponent) || !$asset->hasEventHandler('onProcessAsset');
	}

	/**
	 * The real source path of a file-backed asset.
	 * @param IAsset $asset the asset.
	 * @return ?string the existing source file or directory path, without a trailing
	 *   separator, or null for a generated asset.
	 */
	protected function assetSourcePath(IAsset $asset): ?string
	{
		if ($asset instanceof TAsset && $asset->getIsVirtual()) {
			return null;
		}
		$src = $asset->getAssetOriginalFilePath();
		if (!is_string($src) || $src === '') {
			return null;
		}
		$src = rtrim($src, '/\\');
		return file_exists($src) ? $src : null;
	}

	/**
	 * Publishes one file of a directory copy as an asset, in place of the
	 * {@see TAssetManager::copyDirectoryFile} copy. The `only`/`except` patterns match the
	 * path relative to the published root, as they do for a copy; the file is then
	 * instanced by {@see ensureAsset} and published by {@see routeAsset} to its place in
	 * the directory, under its name with its behaviors' rename applied, and written
	 * atomically when {@see getAtomic Atomic}. A renamed file is also published unprocessed
	 * under its own name when {@see getPublishOriginalNames PublishOriginalNames}. The copy
	 * callbacks receive the file's path in the source directory.
	 *
	 * The directory walk itself is {@see TAssetManager::copyDirectory}: the
	 * {@see TAssetManager::PATH_COPY_EXCEPTIONS} and the completion markers are skipped,
	 * `except` prunes sub-directories, a sub-directory whose contents are all filtered out
	 * is removed, and a symbolic link cycle is broken.
	 * @param string $srcPath the source file path.
	 * @param string $dstPath the destination file path.
	 * @param string $relativePath the source path relative to the published root, with
	 *   "/" separators, which the patterns match against.
	 * @param array $options the options resolved by {@see TAssetManager::copyDirectory}.
	 */
	protected function copyDirectoryFile(string $srcPath, string $dstPath, string $relativePath, array $options): void
	{
		$only = array_key_exists('only', $options) ? $options['only'] : $this->getOnly();
		$except = array_key_exists('except', $options) ? $options['except'] : $this->getExcept();
		$caseSensitive = $options['caseSensitive'] ?? $this->getCaseSensitive();
		if (!$this->matchFilePattern($relativePath, $only, $except, $caseSensitive)) {
			return;
		}
		// The asset applies its own naming; the directory patterns are already matched.
		$this->routeAsset($this->ensureAsset($srcPath), true, ['only' => null, 'except' => null] + $options, $dstPath, $srcPath);
	}
}
