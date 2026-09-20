<?php

/**
 * TAssetDiscovery class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;

/**
 * TAssetDiscovery
 *
 * The TAssetDiscovery enables alternate IAssets to be instanced based upon
 * file path.  This attaches to {@see \Prado\Web\TPublishingManager} and handles
 * its {@see \Prado\Web\TPublishingManager::onDiscoverClass onDiscoverClass} event,
 * rather than attaching to the TAsset class (as most Asset Behaviors do).
 * A TTemplate can publish alternate assets by reference using this class.
 * Any file path that matches MatchFiles will optionally be replaced with
 * {@see getMatchReplacement MatchReplacement} and then instanced
 * with the specified {@see getRoutedClass RoutedClass} rather than the
 * {@see \Prado\Web\TPublishingManager::getDefaultAssetClass DefaultAssetClass}.
 *
 * eg. If you have a TDbAsset for publishing files from your database,
 * the files can be referenced as a file to publish in a TTemplate
 * without special classes.
 *
 * ```xml
 * <behavior name="dbPublisher" Class="Prado\Web\Assets\Behaviors\TAssetDiscovery"
 *     AttachToClass="Prado\Web\TPublishingManager" RoutedClass="\MyAppNS\TDbAsset"
 *     MatchFiles="/^\/dbFolderPath\//i" />
 * <behavior name="dbAlter" Class="Prado\Web\Assets\Behaviors\TAssetDiscovery"
 *     AttachToClass="Prado\Web\TPublishingManager" RoutedClass="\MyAppNS\TVirtualDbAsset"
 *     MatchFiles="/^\/dbFiles\//i" MatchReplacement="/db/file/structure/" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\TPublishingManager
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 *   for information on replacement backreferences for replacement.
 * @see https://www.regular-expressions.info for info on Regex
 */
class TAssetDiscovery extends TBehavior implements IAssetReplacement
{
	/** @var null|string the IAsset class matching assets */
	private $_routedClass;

	/**
	 * @var null|string The regex that matches file paths for re-routing
	 *   their IAssetClass.
	 */
	private $_matchFiles;

	/** @var null|string the replacement value for MatchFiles, optional  */
	private $_matchReplacement;

	/**
	 * Events to attach.  {@see \Prado\Web\TPublishingManager::onDiscoverClass
	 * onDiscoverClass} is handled by {@see discoverClass}.
	 *
	 * @return array events (array keys) and the corresponding event handler
	 *   methods (array values).
	 */
	public function events()
	{
		return ['onDiscoverClass' => 'discoverClass'];
	}

	/**
	 * @return ?string The new IAsset class for matching files, default null.
	 */
	public function getRoutedClass()
	{
		return $this->_routedClass;
	}

	/**
	 * @param string $value The new IAsset class for matching files.
	 */
	public function setRoutedClass($value)
	{
		$this->_routedClass = TPropertyValue::ensureString($value);
	}

	/**
	 * The regular expression to match the files to process.
	 *
	 * @return null|string The regex for Matching file paths to reroute
	 *   asset class.
	 */
	public function getMatchFiles()
	{
		return $this->_matchFiles;
	}

	/**
	 * @param string $value the regex for Matching file paths to reroute
	 *   asset class.
	 */
	public function setMatchFiles($value)
	{
		$this->_matchFiles = TPropertyValue::ensureString($value);
	}

	/**
	 * This may contain backreferences ${1}, ${2} to replace the matching text.
	 * If this is null or blank, there is no replacement of the file path.
	 * @return ?string The regex replacement text.
	 */
	public function getMatchReplacement()
	{
		return $this->_matchReplacement;
	}

	/**
	 * Optional, This may contain backreferences ${1}, ${2} to replace the matching text.
	 * If this is null or blank, there is no replacement of the file path.
	 * @param string $value The regex replacement text.
	 */
	public function setMatchReplacement($value)
	{
		$this->_matchReplacement = TPropertyValue::ensureString($value);
	}

	/**
	 * Any file paths that match the regex in {@see getMatchFiles MatchFiles}
	 * are optionally replaced with {@see getMatchReplacement MatchReplacement} and the
	 * parameter's {@see \Prado\Web\Assets\TAssetDiscoverClassEventParameter::setClass
	 * Class} is set to the {@see getRoutedClass RoutedClass}.
	 *
	 * @param \Prado\Web\TPublishingManager $sender
	 * @param \Prado\Web\Assets\TAssetDiscoverClassEventParameter $param
	 * @throws \Prado\Exceptions\TConfigurationException when MatchFiles matches but no
	 *   RoutedClass is configured.
	 */
	public function discoverClass($sender, $param)
	{
		$filePath = $param->getFilePath();
		if ($this->getEnabled() && $filePath && ($match = $this->getMatchFiles()) && preg_match($match, $filePath)) {
			if (!($class = $this->getRoutedClass())) {
				throw new TConfigurationException('assetdiscovery_requires_class');
			}
			if ($replacement = $this->getMatchReplacement()) {
				$param->setFilePath(preg_replace($match, $replacement, $filePath));
			}
			$param->setClass($class);
		}
	}
}
