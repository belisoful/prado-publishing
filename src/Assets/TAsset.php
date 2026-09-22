<?php

/**
 * TAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Web\IPublishedCapture;
use Prado\Web\TAssetManager;

/**
 * TAsset class
 *
 * This is the base class for publishing Prado Assets. Sub-classes and behaviors
 * can act as File Assets, Asset publishers, compressors, filters, and conversion.
 *
 * On {@see publish}, this writes the asset to the destination by calling
 * {@see writeAsset} and raises 'onProcessAsset' to post-process the written file.
 * {@see \Prado\Web\TPublishingManager} passes a temporary file next to the final
 * destination when publishing atomically, and moves it into place afterward.
 *
 * The process of computing the final {@see getAssetFilePath AssetFilePath} from the
 * First File Path is as follows:
 * - {@see setAssetFilePath} sets the First File Path cache. This could happen during
 *   {@see __construct}.
 * - On getting the {@see getAssetOriginalFilePath AssetOriginalFilePath}, {@see
 *   getAssetVirtualFilePath AssetVirtualFilePath} or {@see getAssetFilePath
 *   AssetFilePath}, {@see ensureOriginalFile} filters the First File Path cache through:
 *		- {@see dySetAssetFilePathPre} to filter the given file name into an existing file
 *		- {@see validateAssetPath} to validate the file and synchronize the virtual file
 *        trailing slash with the file being a directory or not.
 *		- {@see dySetAssetFilePathPost} to filter the validated file name into the
 * 		  AssetOriginalFilePath.  This method is usually used for capture.
 * - The process continues when getting {@see getAssetFilePath AssetFilePath}
 *		- Then {@see rewriteFilePath} is called with the specified instance {@see
 *   	  getAssetVirtualFilePath AssetVirtualFilePath}, and if that is not available then
 * 		  {@see getAssetOriginalFilePath AssetOriginalFilePath} is used.
 *			- rewriteFilePath raises {@see dyRewriteFilePath}.
 * - The resulting value is cached as the AssetFilePath.
 *
 * The behaviors can change, map, or reject file paths and functionality in
 * dySetAssetFilePathPre, dySetAssetFilePathPost, dyRewriteFilePath, and
 * dyWriteAsset.
 *
 * Filtering an asset path through {@see dySetAssetFilePathPre} is the decoding
 * whereas the other dynamic events are an encoding.  As such, all dySetAssetFilePathPre
 * events MUST call the chain _first_ rather than typically last.  This will allow
 * later/final filters to be the first to match the file path and preserve processing
 * order.  eg. If a TAssetGZCompress Behavior is the final filter, it would need to
 * match first on the file path.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @method string dySetAssetFilePathPre($filePath) In {@see ensureOriginalFile},
 *   this filters the $filePath before {@see validateAssetPath validation}.
 * @method string dySetAssetFilePathPost($filePath) In {@see ensureOriginalFile},
 *   this filters the $filePath after {@see validateAssetPath validation}.
 *   $filePath may be false.
 * @method string dyRewriteFilePath($filePath) This maps the {@see getAssetVirtualFilePath
 *   AssetVirtualFilePath} or {@see getAssetOriginalFilePath AssetOriginalFilePath} to the {@see getAssetFilePath AssetFilePath}.
 * @method int dyGetAssetModificationDate($time) Filters the {@see getAssetModificationDate}.
 * @method mixed dyWriteAsset($returnValue, $dst) Returns true if
 *   the writing of the asset is handled by a behavior and thus is cancelled.
 * @method string dyAlterAssetFilePath($filePath) Filters the asset file path into
 *   the path the asset publishes under.  Image format conversions and cache busting
 *   rename the published file here.
 * @method dyResetFilePathCache() Allows behaviors to reset their data when the
 *   data is based upon the file path.
 * @method dyResetFileCacheWithBehavior($return, $name, $behavior) Checks behaviors
 *   if the cache should be reset given the name/behavior.  This is to designate when
 *   specific Behaviors do not affect the File Path.
 */
abstract class TAsset extends TComponent implements IAsset, IPublishedCapture
{
	/** @var ?string the path set before any behaviors modify the file path */
	private $_firstfilepath;

	/** @var ?string the virtual file path which is replaces OriginalFile when computing/filtering
	 * into the AssetFilePath.  This acts like an alias or reference to the original file. */
	private $_virtualFilePath;

	/** @var null|false|string the original validated file asset to publish, default
	 * false for not processed.  null is a blocked file. */
	private $_originalfile = false;

	/** @var null|false|string the virtual file asset to publish, default false
	 * for not processed. null is a blocked file */
	private $_vfile = false;

	/** @var bool whether the publish file path is computed once and reused. */
	private bool $_cachePublishFilePath = false;

	/** @var null|false|string the cached publish file path; see $_publishfileCached. */
	private $_publishfile = false;

	/** @var bool whether $_publishfile is computed, as the publish file path can be false. */
	private bool $_publishfileCached = false;

	/** @var ?TAssetManager the asset manager publishing the asset, null for the application's. */
	private ?TAssetManager $_assetManager = null;

	/** @var ?string the published destination file path of a published asset */
	private $_publishedPath;

	/** @var ?string the published url of a published asset */
	private $_publishedUrl;

	/**
	 * When $filePath is not empty, calls {@see setAssetFilePath} to set the
	 * {@see setAssetFilePath AssetFilePath}.  The $virtualPath acts as an
	 * alias or virtualization of the $filePath.
	 *
	 * @param ?string $filePath the initial asset file path, default null.
	 * @param ?string $virtualPath the virtual file path, default null.
	 */
	public function __construct(?string $filePath = null, ?string $virtualPath = null)
	{
		parent::__construct();
		if ($virtualPath) {
			$this->_virtualFilePath = rtrim($virtualPath, DIRECTORY_SEPARATOR);
		}
		if ($filePath) {// dynamic events are raised in setAssetFilePath, this must be last
			$this->setAssetFilePath($filePath);
		}
	}

	/**
	 * When attaching behaviors, reset the file path cache when allowed.
	 * @param string $name the behavior's name. It should uniquely identify this behavior.
	 * @param array|\Prado\Util\IBaseBehavior|string $behavior The behavior or configuration.
	 * @param null|numeric $priority The priority of the behavior, null for the default.
	 * @return \Prado\Util\IBehavior the behavior object
	 */
	public function attachBehavior($name, $behavior, $priority = null)
	{
		if ($this->dyResetFileCacheWithBehavior(true, $name, $behavior)) {
			$this->resetFilePathCache();
		}
		return parent::attachBehavior($name, $behavior, $priority);
	}

	/**
	 * When detaching behaviors, reset the file path cache when allowed.
	 * @param string $name the behavior's name. It uniquely identifies the behavior.
	 * @param false|numeric $priority the behavior's priority. This defaults to false, aka any priority.
	 * @return null|\Prado\Util\IBehavior the detached behavior. Null if the behavior does not exist.
	 * @method dyResetFileCacheWithBehavior($return, $name, $behavior) checks behaviors if the cache
	 *   should be reset given the name/behavior.
	 */
	public function detachBehavior($name, $priority = false)
	{
		$b = parent::detachBehavior($name, $priority);
		if ($this->dyResetFileCacheWithBehavior(true, $name, $b)) {
			$this->resetFilePathCache();
		}
		return $b;
	}

	/**
	 * When enabling behaviors of the object, reset the file path cache.
	 */
	public function enableBehaviors()
	{
		$this->resetFilePathCache();
		return parent::enableBehaviors();
	}

	/**
	 * When disabling behaviors of the object, reset the file path cache.
	 */
	public function disableBehaviors()
	{
		parent::disableBehaviors();
		$this->resetFilePathCache();
	}

	/**
	 * When enabling a specific behavior of the object, reset the file path cache when allowed.
	 * @param string $name the behavior's name. It uniquely identifies the behavior.
	 */
	public function enableBehavior($name): bool
	{
		if (($b = $this->asa($name)) && $this->dyResetFileCacheWithBehavior(true, $name, $b)) {
			$this->resetFilePathCache();
		}
		return parent::enableBehavior($name);
	}

	/**
	 * When disabling a specific behavior of the object, reset the file path cache, when allowed.
	 * @param string $name the behavior's name. It uniquely identifies the behavior.
	 */
	public function disableBehavior($name): bool
	{
		$return = parent::disableBehavior($name);
		if (($b = $this->asa($name)) && $this->dyResetFileCacheWithBehavior(true, $name, $b)) {
			$this->resetFilePathCache();
		}
		return $return;
	}

	/**
	 * @return ?string The asset's virtual file path.
	 */
	public function getAssetVirtualFilePath(): ?string
	{
		$this->ensureOriginalFile(); // sync virtual directory with trailing slash.
		return $this->_virtualFilePath;
	}

	/**
	 * @param ?string $value The asset's virtual file path.
	 */
	public function setAssetVirtualFilePath(?string $value): void
	{
		$this->_virtualFilePath = $value !== null ? rtrim($value, DIRECTORY_SEPARATOR) : null;
		$this->resetFilePathCache(); // re-validates, synchronizing a directory's trailing separator
	}

	/**
	 * This ensures that there is an original file from the firstfilepath.
	 * @throws TInvalidDataValueException When {@see validateAssetPath} fails and
	 *   returns false (and post-filtered by dySetAssetFilePathPost).
	 */
	protected function ensureOriginalFile(): void
	{
		if ($this->_originalfile === false && $this->_firstfilepath !== null) {
			$this->_originalfile = $this->dySetAssetFilePathPost($this->validateAssetPath($filepath = $this->dySetAssetFilePathPre($this->_firstfilepath)));
			if ($this->_originalfile === false) { // the invalid value from validateAssetPath
				throw new TInvalidDataValueException('assetmanager_filepath_invalid', $filepath);
			}
		}
	}

	/**
	 * Returns the original file path of the asset that was validated.
	 * @throws TInvalidDataValueException When {@see validateAssetPath validation} fails.
	 * @return null|false|string the asset's original file path.  default false
	 *   for original path not set, null is Original File Path is computed but the
	 *   publish was canceled.
	 */
	public function getAssetOriginalFilePath()
	{
		$this->ensureOriginalFile();
		return $this->_originalfile;
	}

	/**
	 * This maps the {@see getAssetVirtualFilePath AssetVirtualFilePath} or {@see
	 * getAssetOriginalFilePath} to the virtual file by filtering it through {@see
	 * rewriteFilePath}.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when {@see validateAssetPath
	 *    validation} fails.
	 */
	protected function ensureFilePath(): void
	{
		$originalFile = $this->getAssetOriginalFilePath();
		if ($this->_vfile === false && $originalFile) {
			$virtualFile = $this->getAssetVirtualFilePath();
			$this->_vfile = $this->rewriteFilePath(($virtualFile !== null) ? $virtualFile : $originalFile);
		}
	}

	/**
	 * The virtual file path being published.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when {@see
	 *   validateAssetPath validation} fails.
	 * @return null|false|string the asset file path, false for invalid null for blocked.
	 */
	public function getAssetFilePath()
	{
		$this->ensureFilePath();
		$originalFile = $this->getAssetOriginalFilePath();
		return $originalFile === null ? null : $this->_vfile;
	}

	/**
	 * Whether publishing writes the source unchanged, so the published file may be a
	 * symbolic link to the source. An asset is not a pass-through by default.
	 * @return bool whether the asset publishes its source unchanged.
	 */
	public function getIsPassThrough(): bool
	{
		return false;
	}

	/**
	 * The path the asset publishes under: the {@see getAssetFilePath AssetFilePath}
	 * filtered through the `dyAlterAssetFilePath` dynamic event, where behaviors rename
	 * the published file (format conversions, cache busting, compression).  A trailing
	 * separator publishes a directory.
	 * @return null|false|string the publish path; null when cancelled, false when invalid.
	 */
	public function getAssetPublishFilePath()
	{
		if ($this->_publishfileCached) {
			return $this->_publishfile;
		}
		$path = $this->getAssetFilePath();
		if (!is_string($path) || $path === '') {
			return $path;
		}
		$path = $this->dyAlterAssetFilePath($path);
		if ($this->_cachePublishFilePath) {
			$this->_publishfile = $path;
			$this->_publishfileCached = true;
		}
		return $path;
	}

	/**
	 * Whether {@see getAssetPublishFilePath AssetPublishFilePath} is computed once and
	 * reused until {@see resetFilePathCache} clears it, rather than raising
	 * `dyAlterAssetFilePath` on every read. Default false, so a behavior that renames the
	 * file from data it changes itself always has its say. Turn it on when the renaming
	 * behaviors are settled, to spare the repeated event; {@see \Prado\Web\TPublishingManager::setCacheAssetPublishFilePath
	 * TPublishingManager.CacheAssetPublishFilePath} turns it on for the assets the manager
	 * instances. Attaching, detaching, enabling, or disabling a behavior resets the cache,
	 * as does any change of the file path.
	 * @return bool whether the publish path is cached, default false.
	 */
	public function getCachePublishFilePath(): bool
	{
		return $this->_cachePublishFilePath;
	}

	/**
	 * @param bool|string $value whether the publish path is cached.
	 */
	public function setCachePublishFilePath($value): void
	{
		$this->_cachePublishFilePath = TPropertyValue::ensureBoolean($value);
		$this->_publishfile = false;
		$this->_publishfileCached = false;
	}

	/**
	 * Sets the file path to compute the result file path on demand.
	 *
	 * @param ?string $filepath the file path.
	 * @method dySetAssetFilePathPre($filePath) in {@see ensureOriginalFile}, filters the
	 *   $filepath before validation.  Returning false throws an invalid file path.
	 *   Returning null cancels the publish.
	 * @method dySetAssetFilePathPost($filePath) in {@see ensureOriginalFile}, filters the
	 *   $filepath after validation.  Returning false throws an invalid file path.
	 *   Returning null cancels the publish.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the $filepath is
	 *   empty or just DIRECTORY_SEPARATOR
	 */
	public function setAssetFilePath($filepath): void
	{
		if (!is_string($filepath) || $filepath === '' || ($path = self::virtualpath($filepath)) === DIRECTORY_SEPARATOR) {
			throw new TInvalidDataValueException('asset_filepath_invalid', $filepath);
		}
		$this->_firstfilepath = $path;
		$this->_publishedPath = null;
		$this->_publishedUrl = null;
		$this->resetFilePathCache();
	}

	/**
	 * Resets the AssetFilePath and OriginalFile so they can be
	 * recomputed through the dynamic events.  This is mainly used by
	 * behaviors when their changes affect the TAsset FilePaths.
	 *
	 * @method dyResetFilePathCache() allows behaviors to reset
	 *   their data when the data is based upon the file path.
	 */
	public function resetFilePathCache(): void
	{
		$this->_originalfile = false;
		$this->_vfile = false;
		$this->_publishfile = false;
		$this->_publishfileCached = false;
		$this->dyResetFilePathCache();
	}

	/**
	 * Internally used to synchronize the "folder" status of the
	 * virtual file path with the AssetFilePath on validation.
	 * When the AssetOriginalFilePath directory gets its trailing
	 * slash (to designate it as a folder), the AssetVirtualFilePath
	 * needs to be synchronized.
	 *
	 * @param bool $isDir
	 */
	protected function syncVirtualFilePath(bool $isDir): void
	{
		if ($this->_virtualFilePath !== null) {
			$vfp = rtrim($this->_virtualFilePath, DIRECTORY_SEPARATOR);
			if ($isDir) {
				$vfp .= DIRECTORY_SEPARATOR;
			}
			$this->_virtualFilePath = $vfp;
		}
	}

	/**
	 * Override this method to provide your own asset filepath validation.
	 * Returning false throws an invalid file path.
	 * Returning null cancels the publish.
	 * Directories should return with appended DIRECTORY_SEPARATOR.
	 *
	 * @param string $filepath The path of the asset to validate
	 * @return null|false|string The base filepath, false if invalid, null if canceled.
	 */
	protected function validateAssetPath($filepath)
	{
		if ($filepath === null || $filepath === false) {
			return $filepath;
		}
		if ($filepath = $this->validatePath((string) $filepath)) {
			if ($isDir = $this->isDirectory($filepath)) {
				$filepath .= DIRECTORY_SEPARATOR;
			}
			$this->syncVirtualFilePath($isDir);
		}
		return $filepath;
	}


	/**
	 * This is where a file name is modified to encode qualities of the asset.
	 * eg. If the subclass is an image filter, it could change the file name
	 * from "/path/myImage.jpg" to "/path/myImage.blur-50px.jpg" for an image
	 * that has a blur of 50px applied and published.
	 * Sub-classes should call parent::rewriteFilePath, as a filter to be
	 * behavior aware.
	 *
	 * @param string $filepath The path of the file
	 * @return string The modified path containing qualities of the TFileAsset
	 * @method dyRewriteFilePath($filepath) The behavior filter for rewriting
	 *   the asset file name.
	 */
	protected function rewriteFilePath($filepath)
	{
		return $this->dyRewriteFilePath($filepath);
	}

	/**
	 * The virtual version of `realpath()`: makes a path absolute (relative to the current
	 * working directory) and resolves its "." and ".." segments, without touching the file
	 * system, so it does not follow symbolic links and the path need not exist.  Both "/"
	 * and "\\" separate segments.  A Windows drive ("C:\\") or UNC ("\\\\server\\share")
	 * root is kept, and ".." does not climb above a root.
	 *
	 * @param string $path the path, possibly relative, with "." and ".." segments.
	 * @param ?string $separator the directory separator, default DIRECTORY_SEPARATOR.
	 * @return string the absolute path.
	 */
	public static function virtualpath($path, ?string $separator = null): string
	{
		$separator ??= DIRECTORY_SEPARATOR;
		$path = str_replace(['/', '\\'], $separator, (string) $path);
		$root = $separator;
		if (preg_match('/^([A-Za-z]:)(?:' . preg_quote($separator, '/') . '|$)/', $path, $drive)) {
			$root = $drive[1] . $separator;
			$path = substr($path, strlen($drive[1]));
		} elseif ($separator === '\\' && str_starts_with($path, '\\\\')) {
			$parts = array_values(array_filter(explode($separator, $path), 'strlen'));
			$root = '\\\\' . implode($separator, array_slice($parts, 0, 2)) . $separator;
			$path = $separator . implode($separator, array_slice($parts, 2));
		} elseif ($path === '' || !str_starts_with($path, $separator)) {
			return static::virtualpath(getcwd() . $separator . $path, $separator);
		}
		$absolutes = [];
		foreach (explode($separator, $path) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}
		return $root . implode($separator, $absolutes);
	}

	/**
	 * Copies a directory from the source to the destination with the
	 * {@see getAssetManager AssetManager} publishing the asset, or with the application's
	 * asset manager when the asset publishes on its own.  A
	 * {@see \Prado\Web\TPublishingManager} publishes each file as an asset; a plain
	 * {@see \Prado\Web\TAssetManager} copies the files.
	 *
	 * @param string $src the source directory to copy.
	 * @param string $dst the destination directory.
	 */
	protected function copyDirectory(string $src, string $dst): void
	{
		($this->_assetManager ?? Prado::getApplication()->getAssetManager())->copyDirectory($src, $dst);
	}

	/**
	 * Override this method to provide your own asset filepath modification
	 * date.
	 * Call $this->dyGetAssetModificationDate($yourAssetModificationDate)
	 * in your override to be behavior aware.
	 *
	 * @return false|int the modification date of the file, false if no modification
	 * date of the file, eg file doesn't exist.
	 * @method dyGetAssetModificationDate($date) the behavior filter for the
	 * asset modification date.
	 */
	public function getAssetModificationDate()
	{
		if (!($path = $this->getAssetOriginalFilePath()) || !$this->getAssetFilePath()) {
			return $this->dyGetAssetModificationDate(null);
		}
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		return $this->dyGetAssetModificationDate($this->modificationTime($path));
	}

	/**
	 * Writes the asset to the destination and raises 'onProcessAsset' with a
	 * {@see \Prado\Web\Assets\TAssetEventParameter} to post-process it.  A directory
	 * asset is copied by {@see copyDirectory}.  Initially, this raises dyWriteAsset for
	 * behaviors to take over the writing.
	 *
	 * @param string $dst the destination file or directory path to write.
	 * @throws TInvalidDataValueException when {@see getAssetFilePath AssetFilePath} is
	 *   invalid, or when the $dst is empty.
	 * @throws \Prado\Exceptions\TInvalidDataTypeException when a finalizer is not an
	 *   instance of IAssetFinalizer, from onProcessAsset.
	 * @return ?bool whether the asset was published; false when it is cancelled or not
	 *   written, or the value a dyWriteAsset handler returns.
	 * @method mixed dyWriteAsset($returnValue, $dst) allows behaviors to take over
	 *   writing the asset.  Returning 0 (pass-through) proceeds with writeAsset; any other
	 *   value is returned from publish.
	 */
	public function publish(string $dst): ?bool
	{
		if (($src = $this->getAssetFilePath()) === false) {
			throw new TInvalidDataValueException('asset_assetfilepath_invalid', $src);
		}
		if ($src === null) {
			return false;
		}
		if (empty($dst)) {
			throw new TInvalidDataValueException('asset_dst_filepath_invalid', $dst);
		}
		if (($return = $this->dyWriteAsset(0, $dst)) !== 0) {
			return $return;
		}
		if (substr($src, -1) === DIRECTORY_SEPARATOR) {
			$this->copyDirectory(rtrim($this->getAssetOriginalFilePath(), DIRECTORY_SEPARATOR), $dst);
			$this->onProcessAsset($dst);
			return true;
		}
		if (!$this->writeAsset($dst)) {
			return false;
		}
		$this->onProcessAsset($dst);
		return is_file($dst);
	}

	/**
	 * Raised after the asset is written.  It passes
	 * {@see TAssetEventParameter} as the parameter.
	 *
	 * @param string $filepath the file location to publish the asset
	 * @throws \Prado\Exceptions\TInvalidDataTypeException when the finalizer is
	 *   not an instance of IAssetFinalizer
	 */
	public function onProcessAsset(string $filepath): void
	{
		$this->raiseEvent('onProcessAsset', $this, $param = new TAssetEventParameter('onProcessAsset', $filepath, $this));
		$param->finalize();
	}

	/**
	 * The asset manager publishing the asset, which {@see copyDirectory} copies a
	 * directory asset with.  {@see \Prado\Web\TPublishingManager::routeAsset} sets it on
	 * the assets it publishes, so an asset of a manager other than the application's
	 * publishes through its own manager.
	 *
	 * @return ?TAssetManager the asset manager publishing the asset, or null for an asset
	 *   published on its own, which takes the application's asset manager.
	 */
	public function getAssetManager(): ?TAssetManager
	{
		return $this->_assetManager;
	}

	/**
	 * @param ?TAssetManager $value the asset manager publishing the asset, or null for
	 *   the application's.
	 */
	public function setAssetManager(?TAssetManager $value): void
	{
		$this->_assetManager = $value;
	}

	/**
	 * This is set by publishing of the Asset.
	 *
	 * @return null|string The published destination path of the asset,
	 *   null before publishing.
	 */
	public function getPublishedPath()
	{
		return $this->_publishedPath;
	}

	/**
	 * @param null|string $publishedPath The published destination path of the asset.
	 */
	public function setPublishedPath($publishedPath): void
	{
		$this->_publishedPath = $publishedPath;
	}

	/**
	 * This is set by publishing of the Asset.
	 *
	 * @return null|string the published URL of the asset, null before publishing.
	 */
	public function getPublishedUrl()
	{
		return $this->_publishedUrl;
	}

	/**
	 * @param null|string $publishedUrl the published URL of the asset.
	 */
	public function setPublishedUrl($publishedUrl): void
	{
		$this->_publishedUrl = $publishedUrl;
	}

	/***  Sub-class implementing methods below.  ***/

	/**
	 * Determine if the asset is a real physical file asset or a virtual asset.
	 * By default all assets are virtual, thus true.  They are only valid
	 * real [system] files at the destination (rather than the source) when
	 * virtual.
	 *
	 * @return bool is the asset a virtual asset rather than a real physical file.
	 *   default true.
	 */
	public function getIsVirtual(): bool
	{
		return true;
	}

	/**
	 * Override this to actually write the asset to the $filepath.
	 * This places a blank file at the destination.
	 *
	 * @param string $filepath the file location to publish the asset.
	 * @return bool was the asset published
	 */
	public function writeAsset(string $filepath): bool
	{
		return @file_put_contents($filepath, '') !== false;
	}

	/**
	 * Validates a path.  Override this method to write your
	 * validation method, eg from database storage.
	 *
	 * @param string $filepath the source file path
	 * @return false|string the validated path or false if invalid
	 */
	public function validatePath(string $filepath)
	{
		return self::virtualpath($filepath);
	}

	/**
	 * As virtual assets, the directories are specified by ending
	 * in the DIRECTORY_SEPARATOR.
	 *
	 * @param string $path the file path to check
	 * @return bool whether or not the $filepath is a directory.
	 */
	protected function isDirectory(string $path): bool
	{
		return substr($path, -1) === DIRECTORY_SEPARATOR;
	}

	/**
	 * Provides the modification time for the virtual file.
	 * When the $filepath is not empty, this returns 0, otherwise
	 * false for empty($filepath).
	 *
	 * @param string $path the file to get the modification time.
	 * @return false|int the modification time, in unix time, of the
	 *   specified $filepath, default 0.
	 */
	protected function modificationTime($path)
	{
		if (empty($path)) {
			return false;
		}
		return 0;
	}
}
