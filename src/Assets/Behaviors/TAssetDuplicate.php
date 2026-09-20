<?php

/**
 * TAssetDuplicate class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;
use Prado\Web\Assets\TAssetEventParameter;

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
 * The file path is matched from the {@see getMatchFiles MatchFiles}
 * regex and replaced with values in {@see getMatchReplacement MatchReplacement}
 * to generate the new asset's file path.
 *
 * Be careful: The duplicated asset file path cannot match the MatchFiles
 * regex because an infinite recursive loop of duplicated files would result.
 * This is identified and an exception is thrown when recursive Duplication
 * occurs.  The duplicated asset cannot [re-]re-duplicate itself with a given
 * MatchFiles regex.  The class can be different from the original and prevent
 * catastrophic duplication even when the file path is not changed.
 *
 * ```xml
 * <behavior name="fileDoubler" Class="Prado\Web\Assets\Behaviors\TAssetDuplicate"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/(?<!\-copy)(\.[^\/]*)?$/i"
 *     MatchReplacement="-copy${1}" />
 * <behavior name="originalize" Class="Prado\Web\Assets\Behaviors\TAssetDuplicate"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/(?<=\/)(?=copy-)([^\/]+)$/i"
 *     MatchReplacement="${1}" />
 * ```
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
	 * {@see getMatchFiles MatchFiles} match with {@see getMatchReplacement
	 * MatchReplacement}, as the {@see getDuplicateClass DuplicateClass} or the asset's
	 * own class.  Nothing is duplicated when MatchFiles does not match.
	 * @param \Prado\Web\Assets\TAsset $asset
	 * @param \Prado\Web\Assets\TAssetEventParameter $param
	 */
	public function duplicateAsset($asset, $param)
	{
		if (!$this->getEnabled() || !$param || !($param instanceof TAssetEventParameter)) {
			return null;
		}

		$dst = $param->getFilePath();
		/** @var \Prado\Web\TPublishingManager $assetManager the asset model requires the publishing manager */
		$assetManager = Prado::getApplication()->getAssetManager();

		$match = $this->getMatchFiles();
		$assetPath = $asset->getAssetFilePath();

		if ($assetManager && $match && preg_match($match, $assetPath, $matches)) {
			static $depth = 0;
			if ($depth++ >= 3) { //3 iterations
				$depth = 0;
				throw new TConfigurationException('assetduplicate_recursive', $match, $this->getMatchReplacement(), $assetPath);
			}
			try {
				$assetClass = $this->getDuplicateClass() ?: get_class($asset);
				$path = $assetPath;
				if ($replace = $this->getMatchReplacement()) {
					$assetPath = preg_replace($match, $replace, $assetPath);
				}
				$virtualPath = $assetPath == $path ? null : $assetPath;
				if ($asset instanceof \Prado\Web\Assets\TTarAsset && is_a($assetClass, \Prado\Web\Assets\TTarAsset::class, true)) {
					$newAsset = new $assetClass($asset->getAssetOriginalFilePath(), $asset->getMd5FilePath(), $virtualPath);
				} else {
					$newAsset = new $assetClass($asset->getAssetOriginalFilePath(), $virtualPath);
				}
				$assetManager->publish($newAsset);
			} finally {
				$depth = max(0, $depth - 1);
			}
		}
	}
}
