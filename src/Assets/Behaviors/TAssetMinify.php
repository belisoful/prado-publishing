<?php

/**
 * TAssetMinify class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TIOException;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\Javascripts\TStyleSheetHelper;

/**
 * TAssetMinify class
 *
 * Publishes matching JavaScript and CSS assets minified, under their own name. JavaScript
 * (".js", ".mjs", ".cjs") is minified with {@see \Prado\Web\Javascripts\TJavaScript::minify()}
 * and CSS (".css") with {@see \Prado\Web\Javascripts\TStyleSheetHelper::minify()}, so each
 * uses the {@see \Prado\Web\Javascripts\IMinifier} set with their `setMinifier()`. A matching
 * file of another extension is left as it is.
 *
 * A file already named "name.min.ext" is left as it is, whatever {@see setMatchFiles
 * MatchFiles} matches.
 *
 * The published name does not change. Minified code means the same as its source, so the
 * URLs that pages, scripts, and stylesheets build from a published directory still resolve,
 * such as the framework's packages, ES module imports, and CSS `@import`.
 *
 * Minifying runs as an asset finalizer, after the other processing and before
 * {@see TAssetCompress}, so a compressed file holds the minified code.
 *
 * ```xml
 * <behavior name="minify" Class="Prado\Web\Assets\Behaviors\TAssetMinify"
 *     AttachToClass="Prado\Web\Assets\TAsset" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetMinify extends TBehavior implements IAssetMatching, IAssetFinalizer
{
	/** The finalizer priority; minifying runs before {@see TAssetCompress}. */
	public const FINALIZER_PRIORITY = 90;

	/** The default regular expression matching the file paths to minify. */
	public const DEFAULT_MATCH_FILES = '/\.(js|css)$/i';

	/** The regular expression matching the file paths already minified. */
	public const MINIFIED_FILES = '/\.min\.[^.\/\\\\]+$/i';

	/** The language of each minified file extension. */
	public const LANGUAGES = ['js' => 'js', 'mjs' => 'js', 'cjs' => 'js', 'css' => 'css'];

	/** @var ?string the regular expression matching the file paths to minify. */
	private ?string $_matchFiles = self::DEFAULT_MATCH_FILES;

	/**
	 * @return array the events and handlers to automatically attach and detach
	 */
	public function events()
	{
		return ['onProcessAsset' => 'addFinalizer'];
	}

	/**
	 * @return ?string the regular expression matching the file paths to minify, default
	 *   {@see DEFAULT_MATCH_FILES}.
	 */
	public function getMatchFiles()
	{
		return $this->_matchFiles;
	}

	/**
	 * @param ?string $value the regular expression matching the file paths to minify; null
	 *   or '' matches none.
	 */
	public function setMatchFiles($value)
	{
		$value = TPropertyValue::ensureString($value);
		$this->_matchFiles = $value === '' ? null : $value;
	}

	/**
	 * The language of a file path, from its extension.
	 * @param mixed $filePath the file path.
	 * @return ?string "js", "css", or null for another extension.
	 */
	public static function getLanguage($filePath): ?string
	{
		if (!is_string($filePath) || $filePath === '') {
			return null;
		}
		return static::LANGUAGES[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))] ?? null;
	}

	/**
	 * Minifies code of a language.
	 * @param string $code the code.
	 * @param string $language "js" or "css".
	 * @return string the minified code.
	 */
	public static function minify(string $code, string $language): string
	{
		return $language === 'css' ? TStyleSheetHelper::minify($code) : TJavaScript::minify($code);
	}

	/**
	 * The path matched and minified: the owner's {@see \Prado\Web\Assets\TAsset::getAssetFilePath
	 * AssetFilePath}, the virtual path being published; without an owner asset file path, the
	 * given path.
	 * @param mixed $filePath the path used when the owner has no asset file path.
	 * @return mixed the asset file path.
	 */
	protected function getMatchedPath($filePath = null)
	{
		$owner = $this->getOwner();
		if ($owner !== null && method_exists($owner, 'getAssetFilePath')) {
			$filePath = $owner->getAssetFilePath();
		}
		return $filePath;
	}

	/**
	 * @param mixed $filePath the asset file path.
	 * @return bool whether a file path is minified: the behavior is enabled, the path matches
	 *   {@see getMatchFiles MatchFiles}, is not already minified (see {@see MINIFIED_FILES}),
	 *   and has a language (see {@see LANGUAGES}).
	 */
	protected function getAppliesTo($filePath): bool
	{
		return $this->getEnabled() && ($match = $this->getMatchFiles()) !== null
			&& is_string($filePath) && $filePath !== '' && preg_match($match, $filePath) === 1
			&& preg_match(static::MINIFIED_FILES, $filePath) !== 1
			&& static::getLanguage($filePath) !== null;
	}

	/**
	 * Adds this behavior as a finalizer of a matching asset, before {@see TAssetCompress}.
	 * @param object $sender the asset.
	 * @param TAssetEventParameter $param the event parameter.
	 */
	public function addFinalizer($sender, $param)
	{
		if ($param instanceof TAssetEventParameter && $sender !== null && method_exists($sender, 'getAssetFilePath') && $this->getAppliesTo($this->getMatchedPath($sender->getAssetFilePath()))) {
			$param->addFinalizer($this, static::FINALIZER_PRIORITY);
		}
	}

	/**
	 * Minifies the written file in place. The asset file path is matched and gives the
	 * language: the owner's, else the event parameter asset's, else the written file's.
	 * @param string $dstFile the written file.
	 * @param TAssetEventParameter $param the event parameter.
	 * @throws TIOException when the file cannot be read or written.
	 */
	public function finalize(string $dstFile, TAssetEventParameter $param)
	{
		$asset = $param->getAsset();
		$filePath = $this->getMatchedPath($asset !== null && method_exists($asset, 'getAssetFilePath') ? $asset->getAssetFilePath() : $dstFile);
		if ($dstFile === '' || !is_file($dstFile) || !$this->getAppliesTo($filePath)) {
			return;
		}
		if (($code = @file_get_contents($dstFile)) === false) {
			throw new TIOException('assetminify_read_failed', $dstFile);
		}
		$minified = static::minify($code, static::getLanguage($filePath));
		if ($minified === $code) {
			return;
		}
		if (@file_put_contents($dstFile, $minified) !== strlen($minified)) {
			throw new TIOException('assetminify_write_failed', $dstFile);
		}
	}
}
