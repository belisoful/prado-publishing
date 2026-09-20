<?php

/**
 * TAssetCacheBuster class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\TPropertyValue;
use Prado\Util\TBehavior;

/**
 * TAssetCacheBuster
 *
 * Fingerprints published asset file names with a content token so that a changed
 * asset is served under a new URL, busting browser and proxy caches. The behavior
 * attaches to {@see \Prado\Web\Assets\TAsset} and rewrites the published file name
 * from "name.ext" to "name.<token>.ext" through {@see dyAlterAssetFilePath}.
 *
 * The token is derived from the asset's source content with {@see getAlgorithm
 * Algorithm} (any algorithm from hash_algos(), default "crc32b"), or from the
 * source modification time when Algorithm is "mtime". {@see getHashLength
 * HashLength} truncates the token, and {@see getMatchFiles MatchFiles}, when set,
 * limits fingerprinting to assets whose file path matches the regular expression. The
 * asset file path is matched, before other behaviors rename it, so the order of the
 * behaviors does not matter (e.g. with {@see TAssetCompress} appending ".gz").
 *
 * Because the published file is stored under its fingerprinted name, the URL that
 * {@see \Prado\Web\TPublishingManager} returns already carries the token; no query
 * string is appended.
 *
 * ```xml
 * <behavior name="cacheBuster" Class="Prado\Web\Assets\Behaviors\TAssetCacheBuster"
 *     AttachToClass="Prado\Web\Assets\TAsset" Algorithm="crc32b" HashLength="8"
 *     MatchFiles="/\.(js|css)$/i" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see \Prado\Web\Assets\TAsset
 * @see https://www.php.net/manual/en/function.hash-file.php
 * @see https://www.php.net/manual/en/function.preg-match.php
 */
class TAssetCacheBuster extends TBehavior implements IAssetMatching
{
	/** Special {@see getAlgorithm Algorithm} value that fingerprints by source modification time. */
	public const MTIME_ALGORITHM = 'mtime';

	/** @var string the hash algorithm, or self::MTIME_ALGORITHM for the source modification time. Default 'crc32b'. */
	private $_algorithm = 'crc32b';

	/** @var int the number of leading token characters to keep, 0 for the full token. Default 0. */
	private $_hashLength = 0;

	/** @var null|string the regular expression limiting which file paths are fingerprinted. Default null (all). */
	private $_matchFiles;

	/**
	 * @return string the hash algorithm, or {@see MTIME_ALGORITHM} for modification time.
	 */
	public function getAlgorithm(): string
	{
		return $this->_algorithm;
	}

	/**
	 * @param string $value the hash algorithm (any of hash_algos()), or
	 *   {@see MTIME_ALGORITHM} to fingerprint by the source modification time.
	 */
	public function setAlgorithm($value): void
	{
		$this->_algorithm = TPropertyValue::ensureString($value);
	}

	/**
	 * @return int the number of leading token characters to keep, 0 for the full token.
	 */
	public function getHashLength(): int
	{
		return $this->_hashLength;
	}

	/**
	 * @param int $value the number of leading token characters to keep, 0 for full.
	 */
	public function setHashLength($value): void
	{
		$this->_hashLength = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return null|string the regular expression limiting which file paths are
	 *   fingerprinted, null to fingerprint all.
	 */
	public function getMatchFiles()
	{
		return $this->_matchFiles;
	}

	/**
	 * @param string $value the regular expression limiting which file paths are
	 *   fingerprinted. An empty value fingerprints all.
	 */
	public function setMatchFiles($value)
	{
		$value = TPropertyValue::ensureString($value);
		$this->_matchFiles = ($value === '') ? null : $value;
	}

	/**
	 * Inserts a content fingerprint into the published file name before its extension.
	 * "app.js" becomes "app.<token>.js"; an extension-less "app" becomes "app.<token>".
	 * @param null|string $filePath the asset file path being published
	 * @param null|\Prado\Util\TCallChain $callchain the dynamic event chain
	 * @return string the fingerprinted file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		if ($this->getEnabled() && !empty($filePath) && ($token = $this->bustToken($filePath)) !== null) {
			$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
			$filePath = preg_replace(
				'/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/',
				'${1}.' . $token . '${2}',
				$filePath,
				1
			);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Computes the cache-busting token for a file path, or null when it cannot be
	 * fingerprinted: the path does not match {@see getMatchFiles MatchFiles}, there is
	 * no readable source file, or the token is empty.
	 * @param string $filePath the asset file path being published
	 * @return null|string the token, or null
	 */
	protected function bustToken($filePath): ?string
	{
		$match = $this->getMatchFiles();
		$owner = $this->getOwner();
		$matchPath = ($owner !== null && method_exists($owner, 'getAssetFilePath') && is_string($assetPath = $owner->getAssetFilePath())) ? $assetPath : $filePath;
		if ($match !== null && !preg_match($match, $matchPath)) {
			return null;
		}
		$src = $this->getSourceFilePath();
		if ($src === null || !is_file($src)) {
			return null;
		}
		if (strcasecmp($this->_algorithm, self::MTIME_ALGORITHM) === 0) {
			$token = (string) @filemtime($src);
		} else {
			$token = in_array(strtolower($this->_algorithm), hash_algos(), true) ? (@hash_file(strtolower($this->_algorithm), $src) ?: '') : '';
		}
		if ($token === '') {
			return null;
		}
		$len = $this->getHashLength();
		return $len > 0 ? substr($token, 0, $len) : $token;
	}

	/**
	 * @return null|string the source file path of the owning asset, when available
	 */
	protected function getSourceFilePath(): ?string
	{
		$owner = $this->getOwner();
		if ($owner !== null && method_exists($owner, 'getAssetOriginalFilePath')) {
			$src = $owner->getAssetOriginalFilePath();
			return $src === null ? null : (string) $src;
		}
		return null;
	}
}
