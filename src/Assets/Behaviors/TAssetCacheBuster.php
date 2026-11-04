<?php

/**
 * TAssetCacheBuster class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
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
 * asset file path is matched before other behaviors rename it, and the token goes before
 * the extension of the asset file name, so the order of the behaviors does not matter:
 * with {@see TAssetCompress} appending ".gz", "app.js" publishes as "app.<token>.js.gz"
 * whichever runs first.
 *
 * The token is computed when the publish path is computed, which is on every request
 * that publishes or looks up the asset. A content hash reads the whole source file, so
 * each token is kept in an in-process map keyed by the source's real path, modification
 * time, size, and algorithm, and a source is hashed again only when it changes. A source
 * modified within the last two seconds is always hashed again, because a rewrite of the
 * same size within the same second keeps its modification time. The map lasts for the
 * PHP process; {@see clearTokenCache} empties it. The "mtime" algorithm reads only the
 * modification time and is the cheapest, at the cost of a new URL whenever the file is
 * touched without changing.
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

	/** The most tokens kept in the in-process token map. */
	public const TOKEN_CACHE_SIZE = 1024;

	/** @var null|string the regular expression limiting which file paths are fingerprinted. Default null (all). */
	private $_matchFiles;

	/** @var array<string, string> the content tokens by real path, modification time, size, and algorithm. */
	private static array $_tokens = [];

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
		$this->resetOwnerFilePath();
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
		$this->resetOwnerFilePath();
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
		$this->resetOwnerFilePath();
	}

	/**
	 * Empties the in-process map of content tokens, so every source is hashed again.
	 */
	public static function clearTokenCache(): void
	{
		self::$_tokens = [];
	}

	/**
	 * Inserts a content fingerprint into the published file name before the extension of
	 * the asset file name. "app.js" becomes "app.<token>.js", and "app.js.gz", renamed from
	 * "app.js" by an earlier behavior, becomes "app.<token>.js.gz"; an extension-less "app"
	 * becomes "app.<token>". A name not derived from the asset file name gets the token
	 * before its last extension.
	 * @param null|string $filePath the asset file path being published
	 * @param null|\Prado\Util\TCallChain $callchain the dynamic event chain
	 * @return string the fingerprinted file path
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		if ($this->getEnabled() && !empty($filePath) && ($token = $this->bustToken($filePath)) !== null) {
			$filePath = $this->insertToken($filePath, $token);
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * Inserts the token into the file name of a path. When the file name is the stem of
	 * the asset file name, or starts with the stem and a dot, the token follows the stem;
	 * otherwise it goes before the last extension.
	 * @param string $filePath the file path being published.
	 * @param string $token the token.
	 * @return string the file path with the token.
	 */
	protected function insertToken(string $filePath, string $token): string
	{
		$sep = (DIRECTORY_SEPARATOR === '/') ? '\\/' : '\\\\';
		$nameRegex = '/(?<=' . $sep . ')([^' . $sep . ']*?)(\.[^' . $sep . '\.]*)?$/';
		$pos = strrpos($filePath, DIRECTORY_SEPARATOR);
		$dir = $pos === false ? '' : substr($filePath, 0, $pos + 1);
		$name = substr($filePath, strlen($dir));
		$owner = $this->getOwner();
		$assetPath = ($owner !== null && method_exists($owner, 'getAssetFilePath')) ? $owner->getAssetFilePath() : null;
		if (is_string($assetPath) && preg_match($nameRegex, $assetPath, $m) && ($stem = $m[1]) !== ''
			&& ($name === $stem || strncmp($name, $stem . '.', strlen($stem) + 1) === 0)) {
			return $dir . $stem . '.' . $token . substr($name, strlen($stem));
		}
		return preg_replace($nameRegex, '${1}.' . $token . '${2}', $filePath, 1);
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
		$token = $this->sourceToken($src);
		if ($token === '') {
			return null;
		}
		$len = $this->getHashLength();
		return $len > 0 ? substr($token, 0, $len) : $token;
	}

	/**
	 * The full token of a source file: its modification time for the "mtime" algorithm,
	 * otherwise its content hash. A hash is kept in the in-process map under the real
	 * path, modification time, size, and algorithm, except for a source modified within
	 * the last two seconds.
	 * @param string $src the source file path.
	 * @return string the token, or '' when it cannot be computed.
	 */
	protected function sourceToken(string $src): string
	{
		clearstatcache(true, $src);
		if (($stat = @stat($src)) === false) {
			return '';
		}
		if (strcasecmp($this->_algorithm, self::MTIME_ALGORITHM) === 0) {
			return (string) $stat['mtime'];
		}
		$algorithm = strtolower($this->_algorithm);
		if (!in_array($algorithm, hash_algos(), true)) {
			return '';
		}
		$key = (realpath($src) ?: $src) . "\0" . $stat['mtime'] . "\0" . $stat['size'] . "\0" . $algorithm;
		if (isset(self::$_tokens[$key])) {
			return self::$_tokens[$key];
		}
		$token = @hash_file($algorithm, $src) ?: '';
		if ($token !== '' && $stat['mtime'] < time() - 1) {
			if (count(self::$_tokens) >= static::TOKEN_CACHE_SIZE) {
				array_shift(self::$_tokens);
			}
			self::$_tokens[$key] = $token;
		}
		return $token;
	}

	/**
	 * Resets the owner's file path cache, so the published name is recomputed.
	 */
	protected function resetOwnerFilePath(): void
	{
		if ($this->getEnabled() && ($owner = $this->getOwner()) && method_exists($owner, 'resetFilePathCache')) {
			$owner->resetFilePathCache();
		}
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
