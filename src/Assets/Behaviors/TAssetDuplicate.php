<?php

/**
 * TAssetDuplicate class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\TPublishingManager;

/**
 * TAssetDuplicate
 *
 * This duplicates a published asset into another published asset
 * under a different name.
 *
 * When processing an Asset, if the asset
 * {@see \Prado\Web\Assets\TAsset::getAssetFilePath AssetFilePath} matches the
 * {@see getMatchFiles MatchFiles} regex, then the asset's
 * {@see \Prado\Web\Assets\TAsset::getAssetOriginalFilePath AssetOriginalFilePath}
 * is republished under a second designated file path with the designated class, or the
 * same class if no {@see getDuplicateClass DuplicateClass} is provided.
 *
 * The first match of the {@see getMatchFiles MatchFiles} regex in the file path
 * is replaced with {@see getMatchReplacement MatchReplacement} to generate the new
 * asset's file path.  Anchor MatchFiles, with "^" or "$", so it matches the
 * intended part of the path.
 *
 * The duplicate is published by the asset's {@see \Prado\Web\Assets\TAsset::getAssetManager
 * AssetManager}, or the application's asset manager for an asset without one, which
 * must be a {@see \Prado\Web\TPublishingManager}.
 *
 * Be careful: The duplicated asset file path cannot match the MatchFiles
 * regex because an infinite recursive loop of duplicated files would result.
 * This is identified and an exception is thrown when recursive Duplication
 * occurs: when a duplicator is asked to duplicate a file path it is already
 * duplicating, or already has {@see MAX_NESTING} of its own duplications in
 * progress.  Distinct duplicators may chain without limit.  The
 * class can be different from the original and prevent catastrophic duplication
 * even when the file path is not changed.
 *
 * ```xml
 * <behavior name="fileDoubler" Class="Prado\Web\Assets\Behaviors\TAssetDuplicate"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/^(.*\/[^\/.]+)(?<!-copy)(\.[^\/]*)?$/i"
 *     MatchReplacement="${1}-copy${2}" />
 * <behavior name="originalize" Class="Prado\Web\Assets\Behaviors\TAssetDuplicate"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/(?<=\/)copy-([^\/]+)$/i"
 *     MatchReplacement="${1}" />
 * ```
 *
 * "fileDoubler" duplicates "app.js" as "app-copy.js" and "app.min.js" as
 * "app-copy.min.js"; directories and "-copy" files are not matched.  "originalize"
 * duplicates "copy-app.js" as "app.js".
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 *   for information on replacement backreferences for replacement.
 * @see https://www.regular-expressions.info
 */
class TAssetDuplicate extends TBehavior implements IAssetReplacement
{
	/** The most duplications of one duplicator that may be in progress at once. */
	public const MAX_NESTING = 3;

	/** @var array<int, array{0:string, 1:string}> the duplicators and file paths being duplicated, outermost first. */
	private static array $_duplicating = [];

	/** @var null|string the IAsset class matching assets */
	private $_duplicateClass;

	/**
	 * @var string regular expression to match the files to duplicate
	 * eg. '/((?<=\/)[^\/]*)(?<!\.thumb)\.([^\/]{3,4})$/i', or '/\/userImages\//i'
	 * for file name with extension after '/'
	 */
	private $_matchFiles;

	/**
	 * This is the replacement string for the matches that duplicate.
	 * @see https://www.php.net/manual/en/function.preg-replace.php
	 */
	private $_matchReplacement; //eg. '${1}.thumb.${2}' or '/thumbnails/'

	/**
	 * @return array the events and handlers to automatically attach and detach
	 */
	public function events()
	{
		return ['onProcessAsset' => 'duplicateAsset'];
	}

	/**
	 * @return ?string The duplicate IAsset class for matching files, default null.
	 */
	public function getDuplicateClass()
	{
		return $this->_duplicateClass;
	}

	/**
	 * @param string $value The duplicate IAsset class for matching files.
	 */
	public function setDuplicateClass($value)
	{
		$this->_duplicateClass = TPropertyValue::ensureString($value);
	}

	/**
	 * The regular expression matching the asset file paths to duplicate.
	 * @return ?string the regular expression, default null (nothing is duplicated).
	 */
	public function getMatchFiles()
	{
		return $this->_matchFiles;
	}

	/**
	 * @param string $value the regular expression matching the asset file paths
	 *   to duplicate.
	 */
	public function setMatchFiles($value)
	{
		$this->_matchFiles = TPropertyValue::ensureString($value);
	}

	/**
	 * The expression for replacing the matching files.
	 * Matching groups can be the replacement values.
	 * @return ?string the replacement expression, default null.
	 */
	public function getMatchReplacement()
	{
		return $this->_matchReplacement;
	}

	/**
	 * This may contain backreferences ${1}, ${2}
	 * to refer to matching text
	 * @param string $value the replacement expression
	 */
	public function setMatchReplacement($value)
	{
		$this->_matchReplacement = TPropertyValue::ensureString($value);
	}

	/**
	 * Republishes the asset's original file under the file path made by replacing the
	 * first {@see getMatchFiles MatchFiles} match with {@see getMatchReplacement
	 * MatchReplacement}, as the {@see getDuplicateClass DuplicateClass} or the asset's
	 * own class.  Nothing is duplicated when MatchFiles does not match.  The duplicate is
	 * published by the asset's manager, or the application's for an asset without one.
	 * @param \Prado\Web\Assets\TAsset $asset
	 * @param \Prado\Web\Assets\TAssetEventParameter $param
	 * @throws TConfigurationException when the publishing manager is not a
	 *   TPublishingManager, or the duplication recurses.
	 */
	public function duplicateAsset($asset, $param)
	{
		if (!$this->getEnabled() || !$param || !($param instanceof TAssetEventParameter)) {
			return null;
		}

		$match = $this->getMatchFiles();
		$assetPath = $asset->getAssetFilePath();

		if ($match && is_string($assetPath) && preg_match($match, $assetPath)) {
			$assetManager = (method_exists($asset, 'getAssetManager') ? $asset->getAssetManager() : null) ?? Prado::getApplication()->getAssetManager();
			if (!($assetManager instanceof TPublishingManager)) {
				throw new TConfigurationException('assetduplicate_requires_publishing_manager', $assetPath);
			}
			$key = $this->duplicatorKey();
			$nesting = 0;
			foreach (self::$_duplicating as [$duplicator, $path]) {
				if ($duplicator === $key && ($path === $assetPath || ++$nesting >= static::MAX_NESTING)) {
					throw new TConfigurationException('assetduplicate_recursive', $match, $this->getMatchReplacement(), $assetPath);
				}
			}
			self::$_duplicating[] = [$key, $assetPath];
			try {
				$assetClass = $this->getDuplicateClass() ?: get_class($asset);
				$path = $assetPath;
				if ($replace = $this->getMatchReplacement()) {
					$assetPath = preg_replace($match, $replace, $assetPath, 1);
				}
				$virtualPath = $assetPath == $path ? null : $assetPath;
				if ($asset instanceof \Prado\Web\Assets\TTarAsset && is_a($assetClass, \Prado\Web\Assets\TTarAsset::class, true)) {
					$newAsset = new $assetClass($asset->getAssetOriginalFilePath(), $asset->getMd5FilePath(), $virtualPath);
				} else {
					$newAsset = new $assetClass($asset->getAssetOriginalFilePath(), $virtualPath);
				}
				$assetManager->publish($newAsset);
			} finally {
				array_pop(self::$_duplicating);
			}
		}
	}

	/**
	 * Identifies the duplicator across the copies of a class behavior, which each asset
	 * gets its own of: duplicators of the same class and properties are the same.
	 * @return string the duplicator key.
	 */
	protected function duplicatorKey(): string
	{
		return implode("\0", [static::class, (string) $this->getMatchFiles(), (string) $this->getMatchReplacement(), (string) $this->getDuplicateClass()]);
	}
}
