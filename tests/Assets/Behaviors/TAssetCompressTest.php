<?php

/**
 * TAssetCompressTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Compression\TBrotliCompressor;
use Prado\IO\Compression\TBuiltinCompressor;
use Prado\IO\Compression\TBzip2Compressor;
use Prado\IO\Compression\TDeflateCompressor;
use Prado\IO\Compression\TGzipCompressor;
use Prado\IO\Compression\TXzCompressor;
use Prado\IO\Compression\TZlibCompressor;
use Prado\IO\Compression\TZstdCompressor;
use Prado\TComponent;
use Prado\Util\TCallChain;
use Prado\Web\Assets\Behaviors\TAssetCompress;
use Prado\Web\Assets\Behaviors\TAssetGZCompress;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Tests\Fixtures\TCountingFileAsset;
use Prado\Web\Tests\Fixtures\TRecordingZlibCompressor;
use Prado\Web\Tests\Fixtures\TReverseCompressor;
use Prado\Web\Tests\Fixtures\TReverseZstdCompressor;
use Prado\Web\Tests\Fixtures\TUnavailableCompressor;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetCompressTest class.
 *
 * Tests the general compressor beyond its gzip preset (see TAssetGZCompressTest): the
 * content-coding methods and their formats, the compressed format of ICompressor classes
 * (by class and NAME, known and unknown) with their extensions and level limits, the
 * Extension override and its requirement for an unknown format, codec availability, the
 * format detection, and publishing deflate, unavailable brotli and zstd, and custom
 * codecs end to end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetCompressTest extends PublishingTestCase
{
	/** Compressible sample content. */
	public const CONTENT = "function app() { return 'Lorem ipsum dolor sit amet'; }\n";

	protected function setUp(): void
	{
		if (!function_exists('gzcompress')) {
			self::markTestSkipped('The zlib extension is required.');
		}
		parent::setUp();
		TRecordingZlibCompressor::$calls = [];
		TReverseCompressor::$calls = [];
	}

	/**
	 * @param string $path the asset file path.
	 * @param array $properties the compressor properties.
	 * @return TAsset the asset with a compressor attached as "compress", its path matched.
	 */
	protected function compressAsset(string $path, array $properties = []): TAsset
	{
		$asset = new TFileAsset($path);
		$asset->attachBehavior('compress', ['class' => TAssetCompress::class] + $properties + ['MatchFiles' => '/\.js$/']);
		$asset->getAssetFilePath();
		return $asset;
	}

	/**
	 * Skips the test when a PHP extension is loaded.
	 * @param string $extension the extension.
	 */
	protected static function requireMissingExtension(string $extension): void
	{
		if (extension_loaded($extension)) {
			self::markTestSkipped("The $extension extension is loaded.");
		}
	}

	public function testConstants(): void
	{
		self::assertSame(['gzip' => 'gzip', 'br' => 'br', 'zstd' => 'zstd', 'deflate' => 'zlib'], TAssetCompress::METHOD_FORMATS);
		self::assertSame(['gzip' => '.gz', 'br' => '.br', 'zstd' => '.zst', 'zlib' => '.zz', 'bzip2' => '.bz2', 'xz' => '.xz', 'rawdeflate' => '.deflate'], TAssetCompress::EXTENSIONS);
		self::assertSame(['gzip' => 9, 'br' => 11, 'zstd' => 22, 'zlib' => 9, 'bzip2' => 9, 'xz' => 9, 'rawdeflate' => 9], TAssetCompress::MAX_LEVELS);
		self::assertSame(['gzip' => "\x1f\x8b", 'zstd' => "\x28\xb5\x2f\xfd", 'bzip2' => 'BZh', 'xz' => "\xfd7zXZ\x00"], TAssetCompress::MAGIC);
		self::assertSame(array_keys(TAssetCompress::EXTENSIONS), array_keys(TAssetCompress::MAX_LEVELS), 'Every format has a level range.');
		self::assertSame([], array_diff(TAssetCompress::METHOD_FORMATS, array_keys(TAssetCompress::EXTENSIONS)), 'Every method format has an extension.');
		self::assertInstanceOf(TAssetCompress::class, new TAssetGZCompress());
	}

	public function testDefaults(): void
	{
		$compress = new TAssetCompress();

		self::assertSame('gzip', $compress->getMethod());
		self::assertNull($compress->getCompressor());
		self::assertSame('.gz', $compress->getExtension());
		self::assertSame(-1, $compress->getCompressionLevel());
		self::assertSame(TGzipCompressor::class, $compress->getCodec());
		self::assertTrue($compress->getIsAvailable());
	}

	public function testMethodSelectsTheCodecAndExtension(): void
	{
		$compress = new TAssetCompress();
		$expected = [
			'gzip' => [TGzipCompressor::class, '.gz'],
			'br' => [TBrotliCompressor::class, '.br'],
			'zstd' => [TZstdCompressor::class, '.zst'],
			'deflate' => [TZlibCompressor::class, '.zz'],
		];
		foreach ($expected as $method => [$codec, $extension]) {
			$compress->setMethod($method);
			self::assertSame($method, $compress->getMethod());
			self::assertSame($codec, $compress->getCodec());
			self::assertSame($extension, $compress->getExtension());
		}

		$compress->setMethod(' DEFLATE ');
		self::assertSame('deflate', $compress->getMethod(), 'The method is trimmed and lower-cased.');
	}

	public function testInvalidMethod(): void
	{
		$compress = new TAssetCompress();
		foreach (['compress', '', 'zlib'] as $method) {
			try {
				$compress->setMethod($method);
				self::fail("'$method' is not a content coding.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('assetcompress_method_invalid', $e->getErrorCode());
			}
		}
		self::assertSame('gzip', $compress->getMethod(), 'An invalid method is not set.');
	}

	public function testAvailabilityOfTheCodings(): void
	{
		$compress = new TAssetCompress();

		$compress->setMethod('deflate');
		self::assertTrue($compress->getIsAvailable());
		$compress->setMethod('br');
		self::assertSame(extension_loaded('brotli'), $compress->getIsAvailable());
		$compress->setMethod('zstd');
		self::assertSame(extension_loaded('zstd'), $compress->getIsAvailable());
	}

	public function testCompressionLevelIsClampedPerMethod(): void
	{
		$compress = new TAssetCompress();
		$compress->setCompressionLevel(100);

		foreach (TAssetCompress::METHOD_FORMATS as $method => $format) {
			$compress->setMethod($method);
			self::assertSame($format, $compress->getFormat());
			self::assertSame(TAssetCompress::MAX_LEVELS[$format], $compress->getCompressionLevel(), "The $method level is at most the $format maximum.");
		}

		$compress->setCompressionLevel('10');
		$compress->setMethod('deflate');
		self::assertSame(9, $compress->getCompressionLevel());
		$compress->setMethod('br');
		self::assertSame(10, $compress->getCompressionLevel());
		$compress->setMethod('zstd');
		self::assertSame(10, $compress->getCompressionLevel());

		$compress->setCompressionLevel(-20);
		self::assertSame(-1, $compress->getCompressionLevel());
	}

	public function testCompressionLevelIsClampedPerCompressorFormat(): void
	{
		$compress = new TAssetCompress();
		$compress->setCompressionLevel(100);

		$compress->setCompressor(TBzip2Compressor::class);
		self::assertSame(9, $compress->getCompressionLevel(), 'The bzip2 level is at most 9.');
		$compress->setCompressor(TXzCompressor::class);
		self::assertSame(9, $compress->getCompressionLevel(), 'The xz level is at most 9.');
		$compress->setCompressor(TDeflateCompressor::class);
		self::assertSame(9, $compress->getCompressionLevel(), 'The raw deflate level is at most 9.');
		$compress->setCompressor(TReverseZstdCompressor::class);
		self::assertSame(22, $compress->getCompressionLevel());

		$compress->setCompressor(TReverseCompressor::class);
		self::assertNull($compress->getFormat());
		self::assertSame(100, $compress->getCompressionLevel(), 'The level of a codec of unknown format is not clamped.');
		$compress->setCompressionLevel(-1);
		self::assertSame(-1, $compress->getCompressionLevel());
	}

	public function testExtension(): void
	{
		$compress = new TAssetCompress();

		$compress->setExtension('gzip');
		self::assertSame('.gzip', $compress->getExtension(), 'A leading dot is added.');
		$compress->setExtension('..deflated');
		self::assertSame('.deflated', $compress->getExtension(), 'Leading dots are normalized.');
		$compress->setExtension(' .z ');
		self::assertSame('.z', $compress->getExtension());

		$compress->setMethod('br');
		self::assertSame('.z', $compress->getExtension(), 'An explicit extension is kept across methods.');

		$compress->setExtension('');
		self::assertSame('.br', $compress->getExtension());
		$compress->setExtension('x');
		$compress->setExtension(null);
		self::assertSame('.br', $compress->getExtension(), 'null restores the coding extension.');
		$compress->setExtension('  ');
		self::assertSame('.br', $compress->getExtension());
	}

	public function testCompressorFormat(): void
	{
		$expected = [
			TGzipCompressor::class => 'gzip',
			TBrotliCompressor::class => 'br',
			TZstdCompressor::class => 'zstd',
			TZlibCompressor::class => 'zlib',
			TBzip2Compressor::class => 'bzip2',
			TXzCompressor::class => 'xz',
			TDeflateCompressor::class => 'rawdeflate',
			TRecordingZlibCompressor::class => 'zlib',
			TUnavailableCompressor::class => 'br',
			TReverseZstdCompressor::class => 'zstd',
		];
		foreach ($expected as $class => $format) {
			self::assertSame($format, TAssetCompress::compressorFormat($class), "The $class format.");
		}
		self::assertNull(TAssetCompress::compressorFormat(TReverseCompressor::class), 'A codec without NAME has no known format.');
		self::assertNull(TAssetCompress::compressorFormat(TBuiltinCompressor::class), 'An empty NAME is not a format.');
		self::assertNull(TAssetCompress::compressorFormat(\stdClass::class));
	}

	public function testCompressorWithACodingName(): void
	{
		$compress = new TAssetCompress();

		$compress->setCompressor('\\' . TRecordingZlibCompressor::class);

		self::assertSame(TRecordingZlibCompressor::class, $compress->getCompressor(), 'A leading backslash is removed.');
		self::assertSame(TRecordingZlibCompressor::class, $compress->getCodec());
		self::assertSame('gzip', $compress->getMethod(), 'The compressor does not change the method.');
		self::assertSame('zlib', $compress->getFormat(), 'The "deflate" NAME is the zlib format.');
		self::assertSame('.zz', $compress->getExtension());
		self::assertTrue($compress->getIsAvailable());

		$compress->setCompressor(TGzipCompressor::class);
		self::assertSame('gzip', $compress->getFormat());
		self::assertSame('.gz', $compress->getExtension());
		self::assertSame(TGzipCompressor::class, $compress->getCodec());

		$compress->setCompressor(TDeflateCompressor::class);
		self::assertSame('rawdeflate', $compress->getFormat(), 'TDeflateCompressor writes raw DEFLATE, not zlib.');
		self::assertSame('.deflate', $compress->getExtension());

		foreach ([TBzip2Compressor::class => '.bz2', TXzCompressor::class => '.xz', TZlibCompressor::class => '.zz', TBrotliCompressor::class => '.br', TZstdCompressor::class => '.zst'] as $class => $extension) {
			$compress->setCompressor($class);
			self::assertSame($extension, $compress->getExtension(), "The $class extension.");
		}
		self::assertSame('gzip', $compress->getMethod());
	}

	public function testCompressorWithoutACodingName(): void
	{
		$compress = new TAssetCompress();
		$compress->setMethod('zstd');

		$compress->setCompressor(TZlibCompressor::class);
		self::assertSame('zstd', $compress->getMethod(), 'The compressor keeps the method.');
		self::assertSame('zlib', $compress->getFormat(), 'The compressor format, not the method format, applies.');
		self::assertSame('.zz', $compress->getExtension());
		self::assertSame(TZlibCompressor::class, $compress->getCodec());
		self::assertTrue($compress->getIsAvailable(), 'The compressor availability, not the method codec, applies.');

		$compress->setCompressor(TReverseCompressor::class);
		self::assertSame('zstd', $compress->getMethod(), 'A compressor without NAME keeps the method.');
		self::assertNull($compress->getFormat(), 'A compressor without NAME has an unknown format.');
		self::assertSame(TReverseCompressor::class, $compress->getCodec());
		self::assertTrue($compress->getIsAvailable(), 'A codec without isAvailable is available.');
		try {
			$compress->getExtension();
			self::fail('A codec of unknown format needs an Extension.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetcompress_extension_required', $e->getErrorCode());
		}

		$compress->setExtension('rev');
		self::assertSame('.rev', $compress->getExtension());
	}

	public function testUnavailableCompressor(): void
	{
		$compress = new TAssetCompress();

		$compress->setCompressor(TUnavailableCompressor::class);

		self::assertSame('gzip', $compress->getMethod());
		self::assertSame('br', $compress->getFormat());
		self::assertSame('.br', $compress->getExtension());
		self::assertFalse($compress->getIsAvailable());
	}

	public function testClearingTheCompressorRestoresTheMethodCodec(): void
	{
		$compress = new TAssetCompress();
		$compress->setCompressor(TRecordingZlibCompressor::class);

		$compress->setCompressor('');
		self::assertNull($compress->getCompressor());
		self::assertSame('gzip', $compress->getMethod(), 'The method was never changed by the compressor.');
		self::assertSame('gzip', $compress->getFormat());
		self::assertSame('.gz', $compress->getExtension());
		self::assertSame(TGzipCompressor::class, $compress->getCodec());

		$compress->setCompressor(TReverseCompressor::class);
		$compress->setCompressor(null);
		self::assertNull($compress->getCompressor());
		self::assertSame('.gz', $compress->getExtension(), 'The method format applies again.');
	}

	public function testInvalidCompressor(): void
	{
		$compress = new TAssetCompress();
		$compress->setCompressor(TReverseCompressor::class);
		foreach ([\stdClass::class, TAssetCompress::class, 'gzip'] as $class) {
			try {
				$compress->setCompressor($class);
				self::fail("'$class' is not an ICompressor.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('assetcompress_compressor_invalid', $e->getErrorCode());
			}
		}
		self::assertSame(TReverseCompressor::class, $compress->getCompressor(), 'An invalid compressor is not set.');
	}

	public function testPropertiesResetTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.js'));
		$asset->attachBehavior('compress', TAssetCompress::class);
		$compress = $asset->asa('compress');

		$changes = [
			'Method' => 'deflate',
			'Compressor' => TReverseCompressor::class,
			'Extension' => 'z',
			'MatchFiles' => '/\.js$/',
		];
		foreach ($changes as $property => $value) {
			$resets = $asset->resets;
			$compress->{'set' . $property}($value);
			self::assertSame($resets + 1, $asset->resets, "Setting $property resets the file path cache.");
		}
		$resets = $asset->resets;
		$compress->setCompressor(null);
		self::assertSame($resets + 1, $asset->resets, 'Clearing the compressor resets the file path cache.');

		$resets = $asset->resets;
		$compress->setCompressionLevel(5);
		self::assertSame($resets, $asset->resets, 'The compression level does not affect the file path.');

		$compress->setEnabled(false);
		$resets = $asset->resets;
		foreach ($changes as $property => $value) {
			$compress->{'set' . $property}($value);
		}
		self::assertSame($resets, $asset->resets, 'A disabled behavior does not reset the cache.');
	}

	public function testPropertiesWithoutAnOwner(): void
	{
		$compress = new TAssetCompress();

		$compress->setMethod('deflate');
		$compress->setExtension('z');
		$compress->setCompressor(TReverseCompressor::class);

		self::assertNull($compress->getOwner());
		self::assertSame('deflate', $compress->getMethod());
		self::assertSame('.z', $compress->getExtension());
	}

	public function testChangingPropertiesRecomputesThePublishPath(): void
	{
		$source = $this->writeSource('app.js');
		$asset = $this->compressAsset($source);
		self::assertSame($source . '.gz', $asset->getAssetPublishFilePath());

		$asset->asa('compress')->setMethod('deflate');
		self::assertSame($source . '.zz', $asset->getAssetPublishFilePath());

		$asset->asa('compress')->setExtension('.deflate');
		self::assertSame($source . '.deflate', $asset->getAssetPublishFilePath());

		$asset->asa('compress')->setCompressor(TUnavailableCompressor::class);
		self::assertSame($source, $asset->getAssetPublishFilePath(), 'An unavailable codec does not rename.');
	}

	public function testAlterSkipsANameEndingWithTheExtension(): void
	{
		$compress = new TAssetCompress();
		$compress->setMethod('deflate');
		$compress->setMatchFiles('/\.zz$/i');
		$compress->dySetAssetFilePathPre('/a/app.js.ZZ', new TCallChain('dySetAssetFilePathPre'));

		self::assertSame('/a/app.js.ZZ', $compress->dyAlterAssetFilePath('/a/app.js.ZZ'), 'The extension compares case-insensitively.');
		self::assertSame('/a/app.js.zz', $compress->dyAlterAssetFilePath('/a/app.js.zz'));
		self::assertSame('/a/other.css.zz', $compress->dyAlterAssetFilePath('/a/other.css'));
		self::assertSame('/a/dir' . DIRECTORY_SEPARATOR, $compress->dyAlterAssetFilePath('/a/dir' . DIRECTORY_SEPARATOR), 'A directory is not renamed.');
		self::assertSame('', $compress->dyAlterAssetFilePath(''));
		self::assertNull($compress->dyAlterAssetFilePath(null));

		$compress->setEnabled(false);
		self::assertSame('/a/other.css', $compress->dyAlterAssetFilePath('/a/other.css'), 'A disabled behavior does not rename.');
	}

	public function testAlterSkipsAnUnmatchedPath(): void
	{
		$compress = new TAssetCompress();
		$compress->setMatchFiles('/\.js$/');
		$compress->dySetAssetFilePathPre('/a/site.css', new TCallChain('dySetAssetFilePathPre'));

		self::assertSame('/a/app.js', $compress->dyAlterAssetFilePath('/a/app.js'), 'Only the matched path state applies.');
	}

	public function testAddFinalizer(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Method' => 'deflate']);
		$compress = $asset->asa('compress');

		$param = new TAssetEventParameter('onProcessAsset', '/x', $asset);
		$compress->addFinalizer(null, $param);
		$compress->addFinalizer(new TComponent(), $param);
		self::assertCount(0, $param->getFinalizers(), 'A sender without an asset file path is not finalized.');

		$compress->addFinalizer($asset, $param);
		self::assertSame([$compress], $param->getFinalizers()->toArray());
		self::assertSame(TAssetCompress::FINALIZER_PRIORITY, $param->getFinalizers()->priorityOf($compress));
	}

	public function testAddFinalizerSkipsAnUnavailableCodec(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Compressor' => TUnavailableCompressor::class]);
		$param = new TAssetEventParameter('onProcessAsset', '/x', $asset);

		$asset->asa('compress')->addFinalizer($asset, $param);

		self::assertCount(0, $param->getFinalizers());
	}

	public function testFinalizeDeflate(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Method' => 'deflate']);
		file_put_contents($dst = $this->assetDir . '/out.zz', self::CONTENT);

		$asset->asa('compress')->finalize($dst, new TAssetEventParameter());

		$data = file_get_contents($dst);
		self::assertSame(self::CONTENT, gzuncompress($data));
		self::assertSame(self::CONTENT, zlib_decode($data));
		self::assertSame(0x78, ord($data[0]), 'The zlib stream header.');
	}

	public function testFinalizeDeflateDoesNotDetectItsFormat(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Method' => 'deflate']);
		$zz = gzcompress(self::CONTENT);
		file_put_contents($dst = $this->assetDir . '/out.zz', $zz);

		$asset->asa('compress')->finalize($dst, new TAssetEventParameter());

		self::assertSame($zz, gzuncompress(file_get_contents($dst)), 'Deflate has no magic; the data is compressed again.');
	}

	public function testFinalizeLeavesZstdDataAsIs(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Compressor' => TReverseZstdCompressor::class]);
		$compress = $asset->asa('compress');
		$zstd = TAssetCompress::MAGIC['zstd'] . 'frame';
		file_put_contents($framed = $this->assetDir . '/framed.zst', $zstd);
		file_put_contents($plain = $this->assetDir . '/plain.zst', 'plain');

		$compress->finalize($framed, new TAssetEventParameter());
		$compress->finalize($plain, new TAssetEventParameter());

		self::assertSame($zstd, file_get_contents($framed), 'Zstandard frames are not compressed again.');
		self::assertSame('nialp', file_get_contents($plain));
		self::assertCount(1, TReverseCompressor::$calls);
	}

	public function testFinalizeLeavesBzip2AndXzDataAsIs(): void
	{
		foreach (['bzip2' => TBzip2Compressor::class, 'xz' => TXzCompressor::class] as $format => $class) {
			$asset = $this->compressAsset($this->writeSource("$format.js"), ['Compressor' => $class]);
			$data = TAssetCompress::MAGIC[$format] . 'stream';
			file_put_contents($dst = $this->assetDir . "/out.$format", $data);

			$asset->asa('compress')->finalize($dst, new TAssetEventParameter());

			self::assertSame($data, file_get_contents($dst), "Data already in the $format format is not compressed again.");
		}
	}

	public function testFinalizeWithoutMagicForBrotli(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Compressor' => TReverseCompressor::class, 'Method' => 'br', 'Extension' => 'br']);
		file_put_contents($dst = $this->assetDir . '/out.br', "\x1f\x8bdata");

		$asset->asa('compress')->finalize($dst, new TAssetEventParameter());

		self::assertSame(strrev("\x1f\x8bdata"), file_get_contents($dst), 'A codec of unknown format detects no format, even with the br method.');
	}

	public function testFinalizePassesTheClampedLevel(): void
	{
		$asset = $this->compressAsset($this->writeSource('app.js'), ['Compressor' => TRecordingZlibCompressor::class]);
		$compress = $asset->asa('compress');
		file_put_contents($dst = $this->assetDir . '/out.zz', self::CONTENT);

		$compress->finalize($dst, new TAssetEventParameter());
		self::assertSame([[self::CONTENT]], TRecordingZlibCompressor::$calls, 'The codec default level passes no level.');
		self::assertSame(self::CONTENT, gzuncompress(file_get_contents($dst)));

		TRecordingZlibCompressor::$calls = [];
		$compress->setCompressionLevel(15);
		file_put_contents($dst, self::CONTENT);
		$compress->finalize($dst, new TAssetEventParameter());
		self::assertSame([[self::CONTENT, 9]], TRecordingZlibCompressor::$calls, 'The level is clamped to the zlib maximum.');
		self::assertSame(self::CONTENT, gzuncompress(file_get_contents($dst)));

		$zstd = $this->compressAsset($this->writeSource('b.js'), ['Compressor' => TReverseZstdCompressor::class, 'CompressionLevel' => 30]);
		file_put_contents($dst, 'abc');
		$zstd->asa('compress')->finalize($dst, new TAssetEventParameter());
		self::assertSame([['abc', 22]], TReverseCompressor::$calls, 'The level is clamped to the zstd maximum.');
		self::assertSame('cba', file_get_contents($dst));

		TReverseCompressor::$calls = [];
		$reverse = $this->compressAsset($this->writeSource('c.js'), ['Compressor' => TReverseCompressor::class, 'Extension' => 'rev', 'CompressionLevel' => 30]);
		file_put_contents($dst, 'xyz');
		$reverse->asa('compress')->finalize($dst, new TAssetEventParameter());
		self::assertSame([['xyz', 30]], TReverseCompressor::$calls, 'The level of a codec of unknown format is passed as set.');
	}

	public function testFinalizeWithAnUnavailableCodecThrows(): void
	{
		static::requireMissingExtension('brotli');
		$compress = new TAssetCompress();
		$compress->setMethod('br');
		$compress->setMatchFiles('/\.js$/');
		$compress->dySetAssetFilePathPre('/a/app.js', new TCallChain('dySetAssetFilePathPre'));
		file_put_contents($dst = $this->assetDir . '/out.br', self::CONTENT);

		try {
			$compress->finalize($dst, new TAssetEventParameter());
			self::fail('An unavailable codec cannot compress.');
		} catch (TIOException $e) {
			self::assertSame('builtincompressor_extension_required', $e->getErrorCode());
		}
		self::assertSame(self::CONTENT, file_get_contents($dst), 'The file is left as it was.');
	}

	public function testPublishDeflate(): void
	{
		$this->attachClassBehavior('deflate', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.js$/', 'Method' => 'deflate', 'CompressionLevel' => 9], TAsset::class);
		$js = $this->writeSource('js/app.js', self::CONTENT);
		$css = $this->writeSource('js/site.css', 'body{}');
		$manager = $this->newManager();

		$url = $manager->publish($js);
		$cssUrl = $manager->publish($css);

		self::assertStringEndsWith('/app.js.zz', $url);
		$data = file_get_contents($this->urlToPath($url));
		self::assertSame(self::CONTENT, gzuncompress($data));
		self::assertSame(self::CONTENT, zlib_decode($data));
		self::assertSame($this->urlToPath($url), $manager->getPublishedPath($js));
		self::assertFalse(is_file(dirname($this->urlToPath($url)) . '/app.js'));
		self::assertSame([], glob(dirname($this->urlToPath($url)) . '/tmp-*'), 'No temporary file is left.');
		self::assertStringEndsWith('/site.css', $cssUrl);
		self::assertSame('body{}', file_get_contents($this->urlToPath($cssUrl)));
	}

	public function testPublishDeflateDirectory(): void
	{
		$this->attachClassBehavior('deflate', ['class' => TAssetCompress::class, 'MatchFiles' => '/\/bundle\//', 'Method' => 'deflate', 'Extension' => 'deflate'], TAsset::class);
		$this->writeSource('bundle/a.txt', 'alpha');
		$this->writeSource('bundle/sub/b.txt', 'beta');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/bundle'));

		self::assertTrue(is_dir($dir));
		self::assertSame('alpha', gzuncompress(file_get_contents($dir . '/a.txt.deflate')));
		self::assertSame('beta', zlib_decode(file_get_contents($dir . '/sub/b.txt.deflate')));
		self::assertFalse(is_file($dir . '/a.txt'));
	}

	public function testPublishWithAnUnavailableCodingIsUncompressed(): void
	{
		foreach (['br' => 'brotli', 'zstd' => 'zstd'] as $method => $extension) {
			static::requireMissingExtension($extension);
		}
		foreach (['br', 'zstd'] as $method) {
			$this->attachClassBehavior($method, ['class' => TAssetCompress::class, 'MatchFiles' => "/\\/$method\\.js$/", 'Method' => $method], TAsset::class);
			$source = $this->writeSource("$method.js", self::CONTENT);
			$manager = $this->newManager();
			$asset = $manager->ensureAsset($source);

			self::assertFalse($asset->asa($method)->getIsAvailable());
			$url = $manager->publish($asset);

			self::assertStringEndsWith("/$method.js", $url, "An unavailable $method codec does not rename.");
			self::assertSame(self::CONTENT, file_get_contents($this->urlToPath($url)), "An unavailable $method codec does not compress.");
			self::assertFalse(is_file($this->urlToPath($url) . TAssetCompress::EXTENSIONS[$method]));
		}
	}

	public function testPublishWithAnUnavailableCompressorIsUncompressed(): void
	{
		$this->attachClassBehavior('compress', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.js$/', 'Compressor' => TUnavailableCompressor::class], TAsset::class);
		$source = $this->writeSource('app.js', self::CONTENT);

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/app.js', $url);
		self::assertSame(self::CONTENT, file_get_contents($this->urlToPath($url)));
	}

	public function testPublishWithACustomCompressor(): void
	{
		$this->attachClassBehavior('zlib', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.js$/', 'Compressor' => TRecordingZlibCompressor::class], TAsset::class);
		$this->attachClassBehavior('reverse', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.txt$/', 'Compressor' => TReverseCompressor::class, 'Extension' => 'rev'], TAsset::class);
		$manager = $this->newManager();

		$jsUrl = $manager->publish($this->writeSource('app.js', self::CONTENT));
		$txtUrl = $manager->publish($this->writeSource('notes.txt', 'hello'));

		self::assertStringEndsWith('/app.js.zz', $jsUrl);
		self::assertSame(self::CONTENT, gzuncompress(file_get_contents($this->urlToPath($jsUrl))));
		self::assertCount(1, TRecordingZlibCompressor::$calls);
		self::assertStringEndsWith('/notes.txt.rev', $txtUrl);
		self::assertSame('olleh', file_get_contents($this->urlToPath($txtUrl)));
		self::assertCount(1, TReverseCompressor::$calls);
	}

	public function testPublishWithAGzipCompressorLeavesGzipDataAsIs(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.svgz$/', 'Compressor' => TGzipCompressor::class], TAsset::class);
		$gz = gzencode('<svg/>');
		$source = $this->writeSource('icon.svgz', $gz);

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/icon.svgz.gz', $url);
		self::assertSame($gz, file_get_contents($this->urlToPath($url)), 'gzip data is not compressed again.');
	}

	public function testPublishWithACompressorOfUnknownFormat(): void
	{
		$this->attachClassBehavior('reverse', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.svgz$/', 'Compressor' => TReverseCompressor::class], TAsset::class);
		$gz = gzencode('<svg/>');
		$source = $this->writeSource('icon.svgz', $gz);
		$manager = $this->newManager();

		try {
			$manager->publish($source);
			self::fail('A matching asset needs an Extension for a codec of unknown format.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetcompress_extension_required', $e->getErrorCode());
		}
		self::assertCount(0, TReverseCompressor::$calls);

		$asset = $manager->ensureAsset($source);
		$asset->asa('reverse')->setExtension('rev');
		$url = $manager->publish($asset);

		self::assertStringEndsWith('/icon.svgz.rev', $url);
		self::assertSame(strrev($gz), file_get_contents($this->urlToPath($url)), 'A codec of unknown format detects no format.');
		self::assertCount(1, TReverseCompressor::$calls);
	}
}
