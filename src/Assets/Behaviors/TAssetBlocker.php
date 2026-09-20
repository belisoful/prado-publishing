<?php

/**
 * TAssetBlocker class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;

/**
 * TAssetBlocker
 *
 * This blocks publishing of certain {@see \Prado\Web\Assets\TAsset}s.
 * When the file path matches the {@see getBlockedFiles BlockedFiles}
 * regular expression, then the file is blocked from publishing.
 * By default, BlockedFiles are hidden files and files in hidden directories (any
 * path segment starting with a period), '.php' files, cron and cache files, and all
 * config.xml and application.xml files.  The whole absolute path is tested, so assets
 * under a hidden directory anywhere above them (e.g. "~/.composer/...") are blocked by
 * the default; set BlockedFiles for such an installation.  The path is tested both as
 * given and resolved, so a symbolic link to a blocked file is blocked too.
 *
 * Attach this class to {@see \Prado\Web\Assets\TAsset} class with the
 * following {@see \Prado\Util\TBehaviorsModule} configuration, for example:
 * ```xml
 * <behavior name="blockerHidden" Class="Prado\Web\Assets\Behaviors\TAssetBlocker"
 *     AttachToClass="Prado\Web\Assets\TAsset" BlockedFiles="/\/\./i" />
 * <behavior name="blockerJPGPDF" Class="Prado\Web\Assets\Behaviors\TAssetBlocker"
 *     AttachToClass="Prado\Web\Assets\TAsset" BlockedFiles="/(\.pdf$)|(.jpg$)/i"
 *     NoAssetFile="noImage.jpg" />
 * <behavior name="blockerMovies" Class="Prado\Web\Assets\Behaviors\TAssetBlocker"
 *     AttachToClass="Prado\Web\Assets\TAsset" BlockedFiles="/(\.mov$)|(\.avi$)/i"
 *     NoAssetPath="Application.Pages.images" NoAssetFile="noImage.svg" />
 * ```
 *
 * Multiple TAssetBlocker can be used, eg one for images with
 * replacement images when blocked, and another instance could block
 * certain PDFs from being published and provide a replacement PDF.
 *
 * The NoAsset asset is published, with its URL, instead of the original.
 *
 * When a blocker matches its own the NoAssetFile (with Path NameSpace),
 * it has no effect on the publishing of the NoAsset asset.  Other
 * TAssetBlocker[s] could still block the publishing of the NoAsset.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.regular-expressions.info
 */
class TAssetBlocker extends TBehavior
{
	/** @var string regular expression that blocks publishing of files that match */
	private $_blockedFiles;

	/** @var string the name space of the No Asset replacement file */
	private $_noAssetPath = 'Application.Pages';

	/** @var string the name of the No Asset replacement file, could be any file type */
	private $_noAssetFile;

	/**
	 * Sets the blockedFiles with proper Directory Separator.
	 */
	public function __construct()
	{
		$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
		$this->_blockedFiles = '/(' . $sep . '\.|\.php$|cron\.jobs$|\.cache$|protected.application\.xml$|config\.xml$)/i';
		parent::__construct();
	}

	/**
	 * The regular expression for matching files to block publishing.
	 *
	 * @return string Regex of files to block publishing
	 */
	public function getBlockedFiles(): string
	{
		return $this->_blockedFiles;
	}

	/**
	 * The regular expression for matching files to block publishing
	 *
	 * @param string $value Regex of files to block publishing
	 */
	public function setBlockedFiles($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_blockedFiles = TPropertyValue::ensureString($value);
	}

	/**
	 * The path is in namespace format.
	 * @return string The Namespace of the NoAssetFile
	 */
	public function getNoAssetPath(): string
	{
		return $this->_noAssetPath;
	}

	/**
	 * The path must be in namespace format.
	 *
	 * @param string $value The Path Namespace of the NoAssetFile
	 */
	public function setNoAssetPath($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_noAssetPath = TPropertyValue::ensureString($value);
	}

	/**
	 * @return null|string The name of the replacement file (in the
	 *   Path Namespace) when the file is blocked from publishing.
	 */
	public function getNoAssetFile()
	{
		return $this->_noAssetFile;
	}

	/**
	 * @param string $value The name of the replacement file (in the
	 *   Path Namespace) when the file is blocked from publishing.
	 */
	public function setNoAssetFile($value)
	{
		if ($this->getEnabled() && ($owner = $this->getOwner())) {
			$owner->resetFilePathCache();
		}
		$this->_noAssetFile = TPropertyValue::ensureString($value);
	}

	/**
	 * This cancels the publishing of the asset by setting the AssetFilePath
	 * to the NoAsset asset (before file validation) so the asset is blocked
	 * from publish.  NoAsset is validated and published in the place of
	 * the Owner/TAsset.
	 * This event must call the chain first, rather than last, to purposefully
	 * reverse the call chain.
	 * @param string $filepath
	 * @param \Prado\Util\TCallChain $callchain
	 * @return null|string the new file path
	 */
	public function dySetAssetFilePathPre($filepath, $callchain)
	{
		$filepath = $callchain->dySetAssetFilePathPre($filepath);
		if ($filepath && !empty($path = $this->getNoAssetPath()) && !empty($nofile = $this->getNoAssetFile()) &&
			preg_match($this->getBlockedFiles(), $filepath)) {
			$filepath = Prado::getPathOfNamespace($path) . DIRECTORY_SEPARATOR . $nofile;
		}
		return $filepath;
	}

	/**
	 * This cancels the publishing of the asset by setting the AssetFilePath
	 * to null (after file validation) so the asset is blocked from publish.
	 *
	 * @param string $filepath
	 * @param \Prado\Util\TCallChain $callchain
	 * @return null|false|string the new file path
	 */
	public function dySetAssetFilePathPost($filepath, $callchain)
	{
		if (is_string($filepath) && $filepath !== '' && preg_match($this->getBlockedFiles(), $filepath)) {
			$placeholder = null;
			if (!empty($path = $this->getNoAssetPath()) && !empty($nofile = $this->getNoAssetFile())) {
				$placeholder = realpath(Prado::getPathOfNamespace($path) . DIRECTORY_SEPARATOR . $nofile) ?: null;
			}
			// The placeholder itself is published, even when it matches the blocked files.
			if (rtrim($filepath, '/\\') !== $placeholder) {
				$filepath = $placeholder;
			}
		}
		return $callchain->dySetAssetFilePathPost($filepath);
	}
}
