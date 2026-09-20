<?php

/**
 * TAssetVirtualize class
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
 * TAssetVirtualize
 *
 * Allows publishing of virtual files that map to real files.  The
 * original file names are then rewritten into the virtualized path of
 * the asset.  There are two steps: mapping the virtual file to the real
 * file, then mapping the real file to the virtualized path.  The
 * re-virtualization does not need to conform to the virtual file map,
 * but usually does.
 *
 * The re-virtualization of the file path cannot be the the original path.
 * Put another way, there must be a mapping from the real file path to the
 * virtual file path.  Virtual files cannot publish as their original path.
 *
 * This can be used with non-virtual assets, aka real files, to map
 * matching published file paths into different virtual paths by using
 * the MapToVirtual property.
 *
 * The File paths that match the regular expression {@see getVirtualFiles
 * VirtualFiles} are virtualized.  The VirtualFiles regex matches are
 * replaced with {@see getMapFromVirtual MapFromVirtual}.  The resulting
 * filepath are validated (unless captured by another Asset Behavior).
 *
 * After the real filepath is validated, TAssetVirtualize remaps the real
 * filepath to a virtual filepath.  The real file path is matched to the
 * regular expression {@see getOriginalFiles OriginalFiles} and replaced
 * with {@see getMapToVirtual MapToVirtual} to make the virtual filepath
 * that is then "published."
 *
 * By default, VirtualFiles and MapToVirtual must be specified. MapFromVirtual
 * and Original Files are preset to filter files rather than folders.
 *
 * MapFromVirtual has the default "${1}.${2}"; for regular expressions like
 * in the example "virtualizeThumb" below.
 * OriginalFiles has the default Linux value: '/(?<=\/)([^\/]*)\.([^\/\.]*)$/i'
 * and default Windows value: '/(?<=\\)([^\\]*)\.([^\\\.]*)$/i'
 * This is to make the MapToVirtual easy, like in the example "virtualizeThumb"
 * below.
 *
 * ```xml
 * <behavior name="virtualizeThumb" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset"
 *     VirtualFiles="/(?<=\/)([^\/]*?)\.thumb\.([^\/\.]*)$/i"
 *     MapToVirtual="${1}.thumb.${2}" />
 * ```
 *
 * ```xml
 * <behavior name="iconResizerBad" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\/realpath\//i">
 *   <filter type="resize" FixedWidth="80" FixedHeight="80" CropFill="true" />
 * </behavior>
 * <behavior name="virtualizeFolder" Class="Prado\Web\Assets\Behaviors\TAssetVirtualize"
 *     AttachToClass="Prado\Web\Assets\TAsset" VirtualFiles="/\/userIcons\//i"
 *     MapFromVirtual="/realpath/" OriginalFiles="/\/realpath\//i" MapToVirtual="/icons/" />
 * <behavior name="iconResizerGood" Class="Prado\Web\Assets\Behaviors\TAssetJPEGize"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\/icons\//i">
 *   <filter type="resize" FixedWidth="80" FixedHeight="80" CropFill="true" />
 * </behavior>
 * ```
 *
 * This is an example of a Thumbnail virtualizer with "virtualizeThumb".  In the second
 * example, the "iconResizerBad" is prioritized before the virtualizeFolder and applies
 * to all files in "/realpath/" rather than the virtual path "/icons/", like "iconResizerGood".
 * "iconResizerGood" is prioritized after the virtualizer in order/priority.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 * @see \Prado\Web\Assets\Behaviors\IAssetVirtualize
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 *   for information on replacement backreferences.
 * @see https://www.regular-expressions.info for information on
 *   regular expressions.
 */
class TAssetVirtualize extends TBehavior implements IAssetVirtualize
{
	/**
	 * @var null|string The Regular Expression matching the virtual files.
	 * In the MapFromVirtual property, the first capture group is the file
	 * name, and the second capture group is the file extension.
	 * Default: null
	 */
	private $_virtualFiles;

	/**
	 * @var string The replacement string for the virtual file matches.
	 * This maps the virtual file into it real file path.
	 * Default: '${1}.${2}'
	 */
	private $_mapFromVirtual = '${1}.${2}'; //eg. '${1}.${2}' or '/thumbnails/'

	/**
	 * @var string The Regular Expression matching the real files.
	 * The first capture group is the file name, and the second capture group
	 * is the file extension.
	 *   Linux Default: '/(?<=\/)([^\/]*?)\.([^\/\.]*)$/i'
	 * Windows Default: '/(?<=\\)([^\\]*?)\.([^\\\.]*)$/i'
	 */
	private $_originalFiles;

	/**
	 * @var string The replacement string for the OriginalFiles.
	 * Given the default OriginalFiles regex, the first capture group is
	 * the file name, and the second capture group is the file extension.
	 * This maps the real file into its virtual file path.
	 * Default: null
	 */
	private $_mapToVirtual;

	/** @var null|bool Was the file name matched on dySetAssetFilePathPre */
	private $_match;

	/**
	 * Sets the OriginalFiles with proper Directory Separator.
	 */
	public function __construct()
	{
		$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
		$this->_originalFiles = '/(?<=' . $sep . ')([^' . $sep . ']*?)(?:\.([^' . $sep . '\.]*))?$/i';
		parent::__construct();
	}

	/**
	 * The regular expression to match the virtual filepaths.  This must be
	 * written for use with {@see getMapFromVirtual MapFromVirtual} in mind.
	 * The FilePath is matched and replaced in dySetAssetFilePathPre with
	 * VirtualFiles as the Matching Regex, and MapFromVirtual being the
	 * replacement string.
	 *
	 * @return ?string the regex for matching virtual files, default null.
	 */
	public function getVirtualFiles()
	{
		return $this->_virtualFiles;
	}

	/**
	 * Sets the Virtual filepaths matching regular expression.
	 *
	 * @param string $value the regex for matching virtual filepaths
	 */
	public function setVirtualFiles($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_virtualFiles = TPropertyValue::ensureString($value);
	}

	/**
	 * The replacement string for matching virtual filepaths.  This must be
	 * written for use with {@see getVirtualFiles VirtualFiles} in mind.
	 * The FilePath is matched and replaced in dySetAssetFilePathPre with
	 * VirtualFiles as the Matching Regex, and MapFromVirtual being the
	 * replacement string.
	 *
	 * @return string the replacement string for matching virtual filepaths
	 * default '${1}.${2}'
	 */
	public function getMapFromVirtual()
	{
		return $this->_mapFromVirtual;
	}

	/**
	 * Sets the Virtual filepaths regular expression matching replacement string.
	 *
	 * @param string $value the replacement string for matching virtual filepaths
	 */
	public function setMapFromVirtual($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_mapFromVirtual = TPropertyValue::ensureString($value);
	}

	/**
	 * The regular expression to match the real filepaths.  This must be
	 * written for use with {@see getMapToVirtual MapToVirtual} in mind.
	 * The FilePath is matched and replaced in dyRewriteFilePath with
	 * OriginalFiles as the Matching Regex, and MapToVirtual being the
	 * replacement string.
	 *
	 * @return string the regex for matching real filepaths
	 *   Linux Default: '/(?<=\/)([^\/]*?)\.([^\/]*)$/i'
	 * Windows Default: '/(?<=\\)([^\\]*?)\.([^\\]*)$/i'
	 */
	public function getOriginalFiles()
	{
		return $this->_originalFiles;
	}

	/**
	 * Sets the real filepaths matching regular expression.
	 *
	 * @param string $value the regex for matching real filepaths
	 */
	public function setOriginalFiles($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_originalFiles = TPropertyValue::ensureString($value);
	}

	/**
	 * The replacement string for matching real filepaths.  This must be
	 * written for use with {@see getOriginalFiles OriginalFiles} in mind.
	 * The FilePath is matched and replaced in dyRewriteFilePath with
	 * OriginalFiles as the Matching Regex, and MapToVirtual being the
	 * replacement string.
	 *
	 * @return ?string the replacement string for matching real filepaths,
	 * default null.
	 */
	public function getMapToVirtual()
	{
		return $this->_mapToVirtual;
	}

	/**
	 * Sets the real filepaths regular expression matching replacement string.
	 *
	 * @param string $value the replacement string for matching virtual filepaths
	 */
	public function setMapToVirtual($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_mapToVirtual = TPropertyValue::ensureString($value);
	}

	/**
	 * This is raised when setting the AssetFilePath before validation.
	 * It matches the filePath against the regular expression from
	 * getVirtualFiles.  If there is a match, the Asset is virtualized.
	 * When virtualizing, this matches and replaces filePath against
	 * {@see getVirtualFiles VirtualFiles} with replacement values from
	 * {@see getMapFromVirtual MapFromVirtual}.
	 *
	 * This event must call the chain first, rather than last, to purposefully
	 * reverse the call chain.
	 * @param string $filepath the file path of the asset before validation.
	 * @param \Prado\Util\TCallChain $callchain
	 * @throws \Prado\Exceptions\TConfigurationException when virtualizing itself.
	 * @return null|string the new file path
	 */
	public function dySetAssetFilePathPre($filepath, $callchain)
	{
		$filepath = $callchain->dySetAssetFilePathPre($filepath);
		$this->_match = false;
		if (!empty($filepath) && !$this->getOwner()->validatePath($filepath) && ($match = $this->getVirtualFiles()) && preg_match($match, $filepath)) {
			$filepath = preg_replace($match, $replace = $this->getMapFromVirtual(), $inValue = $filepath);
			if ($filepath == $inValue) {
				throw new TConfigurationException('assetvirtualize_no_alias_self', $match, $replace, $filepath);
			}
			$this->_match = true;
		}
		return $filepath;
	}

	/**
	 * This is raised when converting the validated asset into its
	 * virtualized publishing filePath.  When virtualizing an asset,
	 * the filePath is rewritten by matching and replacing with the
	 * regular expression from {@see getOriginalFiles OriginalFiles} and the
	 * replacement values from {@see getMapToVirtual MapToVirtual}
	 *
	 * @param string $filepath
	 * @param \Prado\Util\TCallChain $callchain
	 * @throws \Prado\Exceptions\TConfigurationException when not re-virtualizing.
	 * @return null|string the new file path
	 */
	public function dyRewriteFilePath($filepath, $callchain)
	{
		if ($this->_match && !empty($filepath)) {
			$match = $this->getOriginalFiles();
			$replace = $this->getMapToVirtual();
			$inValue = $filepath;
			if ($replace !== null && $replace !== '') {
				$filepath = preg_replace($match, $replace, $filepath);
			}
			if ($filepath == $inValue || $replace === null || $replace === '') {
				throw new TConfigurationException('assetvirtualize_no_publish_as_original', $match, $replace, $filepath);
			}
		}
		return $callchain->dyRewriteFilePath($filepath);
	}
}
