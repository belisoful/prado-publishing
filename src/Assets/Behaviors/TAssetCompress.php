<?php

/**
 * TAssetCompress class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Compression\ICompressor;
use Prado\IO\Compression\TCompression;
use Prado\TPropertyValue;
use Prado\Util\TBehavior;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TAssetCompress class
 *
 * Publishes matching assets compressed, under their file name with the format's extension
 * appended (".gz", ".br", ".zst", ".zz", ".bz2", ".xz", ".deflate"). The compression uses the Prado compression
 * codecs: {@see setMethod Method} names an HTTP content coding of
 * {@see \Prado\IO\Compression\TCompression} (`gzip`, `br`, `zstd`, `deflate`), or
 * {@see setCompressor Compressor} names any {@see \Prado\IO\Compression\ICompressor}
 * class, such as the command-backed codecs of belisoful/prado-compression when a PHP
 * extension is missing. When the codec is not available, the asset publishes
 * uncompressed under its own name.
 *
 * Compression is the last step of publishing: it runs as an asset finalizer after the
 * other processing, such as image filters, has written the file. A file already ending in
 * the extension, or already in the format (gzip, zstd, bzip2, and xz are detected), is
 * left as it is.
 *
 * The web server must serve the compressed files with the matching `Content-Encoding`
 * and the original content type.
 *
 * ```xml
 * <behavior name="brotli" Class="Prado\Web\Assets\Behaviors\TAssetCompress"
 *     AttachToClass="Prado\Web\Assets\TAsset" MatchFiles="/\.(js|css|svg)$/i" Method="br" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetCompress extends TBehavior implements IAssetMatching, IAssetFinalizer
{
	/** The finalizer priority; compression runs after the other finalizers. */
	public const FINALIZER_PRIORITY = 100;

	/** The compressed format of each TCompression content-coding token. */
	public const METHOD_FORMATS = ['gzip' => 'gzip', 'br' => 'br', 'zstd' => 'zstd', 'deflate' => 'zlib'];

	/** The file extension of each compressed format. */
	public const EXTENSIONS = ['gzip' => '.gz', 'br' => '.br', 'zstd' => '.zst', 'zlib' => '.zz', 'bzip2' => '.bz2', 'xz' => '.xz', 'rawdeflate' => '.deflate'];

	/** The highest compression level of each compressed format. */
	public const MAX_LEVELS = ['gzip' => 9, 'br' => 11, 'zstd' => 22, 'zlib' => 9, 'bzip2' => 9, 'xz' => 9, 'rawdeflate' => 9];

	/** The leading magic bytes of the formats that are detectable. */
	public const MAGIC = ['gzip' => "\x1f\x8b", 'zstd' => "\x28\xb5\x2f\xfd", 'bzip2' => 'BZh', 'xz' => "\xfd7zXZ\x00"];

	/** @var ?string the regular expression matching the file paths to compress. */
	private ?string $_matchFiles = null;

	/** @var bool whether the asset file path matched. */
	private bool $_match = false;

	/** @var string the content-coding token. */
	private string $_method = 'gzip';

	/** @var ?string the ICompressor class overriding the method's codec. */
	private ?string $_compressor = null;

	/** @var ?string the compressed format of the Compressor, null when unknown. */
	private ?string $_compressorFormat = null;

	/** @var ?string the file extension, null for the coding's. */
	private ?string $_extension = null;

	/** @var int the compression level, -1 for the codec default. */
	private int $_compressionLevel = -1;

	/**
	 * @return array the events and handlers to automatically attach and detach
	 */
	public function events()
	{
		return ['onProcessAsset' => 'addFinalizer'];
	}

	/**
	 * @return ?string the regular expression matching the file paths to compress.
	 */
	public function getMatchFiles()
	{
		return $this->_matchFiles;
	}

	/**
	 * @param ?string $value the regular expression matching the file paths to compress.
	 */
	public function setMatchFiles($value)
	{
		$value = TPropertyValue::ensureString($value);
		$this->_matchFiles = $value === '' ? null : $value;
		$this->resetOwnerFilePath();
	}

	/**
	 * @return string the content-coding token, default "gzip".
	 */
	public function getMethod(): string
	{
		return $this->_method;
	}

	/**
	 * @param string $value the content-coding token: gzip, br, zstd, or deflate.
	 * @throws TInvalidDataValueException when the token is not a TCompression coding.
	 */
	public function setMethod($value): void
	{
		$method = strtolower(trim(TPropertyValue::ensureString($value)));
		if (TCompression::getCodec($method) === null) {
			throw new TInvalidDataValueException('assetcompress_method_invalid', $value);
		}
		$this->_method = $method;
		$this->resetOwnerFilePath();
	}

	/**
	 * @return ?string the ICompressor class overriding the method's codec, or null.
	 */
	public function getCompressor(): ?string
	{
		return $this->_compressor;
	}

	/**
	 * @param ?string $value an {@see ICompressor} class, or null for the method's codec.
	 *   Its format, from its class or its `NAME` constant, selects the extension, level
	 *   range, and format detection; a codec of an unknown format needs an
	 *   {@see setExtension Extension}.
	 * @throws TInvalidDataValueException when the class is not an ICompressor.
	 */
	public function setCompressor($value): void
	{
		if ($value === null || $value === '') {
			$this->_compressor = null;
		} else {
			$class = ltrim(TPropertyValue::ensureString($value), '\\');
			if (!is_a($class, ICompressor::class, true)) {
				throw new TInvalidDataValueException('assetcompress_compressor_invalid', $value);
			}
			$this->_compressor = $class;
			$this->_compressorFormat = static::compressorFormat($class);
		}
		$this->resetOwnerFilePath();
	}

	/**
	 * The compressed format: the Compressor's when set, otherwise the Method's.
	 * @return ?string a key of {@see EXTENSIONS}, or null for a Compressor of unknown format.
	 */
	public function getFormat(): ?string
	{
		return $this->_compressor !== null ? $this->_compressorFormat : static::METHOD_FORMATS[$this->_method];
	}

	/**
	 * The compressed format of a codec class: raw DEFLATE for a
	 * {@see \Prado\IO\Compression\TDeflateCompressor}, otherwise its `NAME` constant when
	 * that is a known format ("deflate" being zlib, as in TCompression).
	 * @param string $class the codec class.
	 * @return ?string a key of {@see EXTENSIONS}, or null when unknown.
	 */
	public static function compressorFormat(string $class): ?string
	{
		if (is_a($class, \Prado\IO\Compression\TDeflateCompressor::class, true)) {
			return 'rawdeflate';
		}
		$name = defined($class . '::NAME') ? strtolower((string) constant($class . '::NAME')) : '';
		$name = static::METHOD_FORMATS[$name] ?? $name;
		return isset(static::EXTENSIONS[$name]) ? $name : null;
	}

	/**
	 * @throws TInvalidDataValueException when the format is unknown and no Extension is set.
	 * @return string the file extension appended to compressed files, by default the
	 *   format's (see {@see EXTENSIONS}).
	 */
	public function getExtension(): string
	{
		if ($this->_extension !== null) {
			return $this->_extension;
		}
		if (($format = $this->getFormat()) === null) {
			throw new TInvalidDataValueException('assetcompress_extension_required', $this->_compressor);
		}
		return static::EXTENSIONS[$format];
	}

	/**
	 * @param ?string $value the file extension, with or without the leading dot; null or
	 *   '' for the coding's.
	 */
	public function setExtension($value): void
	{
		$value = $value === null ? '' : trim(TPropertyValue::ensureString($value));
		$this->_extension = $value === '' ? null : '.' . ltrim($value, '.');
		$this->resetOwnerFilePath();
	}

	/**
	 * @return int the compression level, at most the coding's highest (see
	 *   {@see MAX_LEVELS}), or -1 for the codec default.
	 */
	public function getCompressionLevel(): int
	{
		$format = $this->getFormat();
		return $format === null ? $this->_compressionLevel : min($this->_compressionLevel, static::MAX_LEVELS[$format]);
	}

	/**
	 * @param int|string $value the compression level, or -1 for the codec default. The
	 *   range depends on the codec (gzip 0..9, brotli 0..11, zstd 1..22).
	 */
	public function setCompressionLevel($value): void
	{
		$this->_compressionLevel = max(-1, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return class-string<ICompressor> the codec class compressing the files.
	 */
	public function getCodec(): string
	{
		return $this->_compressor ?? TCompression::getCodec($this->_method);
	}

	/**
	 * @return bool whether the codec can compress here.
	 */
	public function getIsAvailable(): bool
	{
		$codec = $this->getCodec();
		return !method_exists($codec, 'isAvailable') || $codec::isAvailable();
	}

	/**
	 * Matches the asset file path.
	 * @param string $filePath the file path.
	 * @param \Prado\Util\TCallChain $callchain the dynamic event chain.
	 * @return string the file path.
	 */
	public function dySetAssetFilePathPre($filePath, $callchain)
	{
		$filePath = $callchain->dySetAssetFilePathPre($filePath);
		$this->_match = !empty($filePath) && ($match = $this->getMatchFiles()) !== null && preg_match($match, $filePath) === 1;
		return $filePath;
	}

	/**
	 * Appends the extension to the published file name of a matching asset, when the
	 * codec is available and the name does not already end with it.
	 * @param string $filePath the published file path.
	 * @param ?\Prado\Util\TCallChain $callchain the dynamic event chain.
	 * @return string the published file path.
	 */
	public function dyAlterAssetFilePath($filePath, $callchain = null)
	{
		if ($this->getAppliesTo($filePath)) {
			$filePath .= $this->getExtension();
		}
		return $callchain ? $callchain->dyAlterAssetFilePath($filePath) : $filePath;
	}

	/**
	 * @param mixed $filePath the published file path.
	 * @return bool whether a file path is compressed: the behavior is enabled, the path
	 *   matched, is a file not already ending with the extension, and the codec is available.
	 */
	protected function getAppliesTo($filePath): bool
	{
		return $this->getEnabled() && $this->_match && is_string($filePath) && $filePath !== ''
			&& substr($filePath, -1) !== DIRECTORY_SEPARATOR
			&& strcasecmp(substr($filePath, -strlen($this->getExtension())), $this->getExtension()) !== 0
			&& $this->getIsAvailable();
	}

	/**
	 * Adds this behavior as the last finalizer of a matching asset.
	 * @param object $sender the asset.
	 * @param TAssetEventParameter $param the event parameter.
	 */
	public function addFinalizer($sender, $param)
	{
		if ($param instanceof TAssetEventParameter && $sender !== null && method_exists($sender, 'getAssetFilePath') && $this->getAppliesTo($sender->getAssetFilePath())) {
			$param->addFinalizer($this, static::FINALIZER_PRIORITY);
		}
	}

	/**
	 * Compresses the written file in place. Data already in the coding's format is left
	 * as it is.
	 * @param string $dstFile the written file.
	 * @param TAssetEventParameter $param the event parameter.
	 * @throws TIOException when the file cannot be read or written, or the codec fails.
	 */
	public function finalize(string $dstFile, TAssetEventParameter $param)
	{
		if (!$this->getEnabled() || !$this->_match || $dstFile === '' || !is_file($dstFile)) {
			return;
		}
		if (($data = @file_get_contents($dstFile)) === false) {
			throw new TIOException('assetcompress_read_failed', $dstFile);
		}
		$magic = static::MAGIC[$this->getFormat() ?? ''] ?? null;
		if ($magic !== null && strncmp($data, $magic, strlen($magic)) === 0) {
			return;
		}
		$codec = $this->getCodec();
		$level = $this->getCompressionLevel();
		$compressed = $level >= 0 ? $codec::compress($data, $level) : $codec::compress($data);
		if (@file_put_contents($dstFile, $compressed) !== strlen($compressed)) {
			throw new TIOException('assetcompress_write_failed', $dstFile);
		}
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
}
