<?php

/**
 * TAssetGZCompressTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TIOException;
use Prado\TEventParameter;
use Prado\Util\TCallChain;
use Prado\Web\Assets\Behaviors\IAssetMatching;
use Prado\Web\Assets\Behaviors\TAssetCacheBuster;
use Prado\Web\Assets\Behaviors\TAssetGZCompress;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Tests\Fixtures\TCountingFileAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TMarkFinalizerBehavior;
use Prado\Web\Tests\Fixtures\TUppercaseAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetGZCompressTest class.
 *
 * Tests the gzip compressor: its configuration, matching and renaming published files
 * to ".gz", registering as the last finalizer, compressing the written file in place,
 * its read and write failures, and publishing compressed files and directories end to
 * end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetGZCompressTest extends PublishingTestCase
{
	/** Compressible sample content. */
	public const CONTENT = "function app() { return 'Lorem ipsum dolor sit amet'; }\n";

	protected function setUp(): void
	{
		if (!function_exists('gzencode')) {
			self::markTestSkipped('The zlib extension is required.');
		}
		parent::setUp();
		TUppercaseAssetBehavior::$processed = [];
	}

	/**
	 * @param string $path the asset file path.
	 * @param array $properties the compressor properties.
	 * @param string $class the asset class.
	 * @return TAsset the asset with a compressor attached as "gzip".
	 */
	protected function gzipAsset(string $path, array $properties = ['MatchFiles' => '/\.js$/'], string $class = TFileAsset::class): TAsset
	{
		$asset = new $class($path);
		$asset->attachBehavior('gzip', ['class' => TAssetGZCompress::class] + $properties);
		return $asset;
	}

	/**
	 * Raises onProcessAsset on an asset for a destination file, collecting the parameter.
	 * @param TAsset $asset the asset.
	 * @param string $dst the destination file.
	 * @return TAssetEventParameter the raised parameter, after finalizing.
	 */
	protected static function process(TAsset $asset, string $dst): TAssetEventParameter
	{
		$captured = null;
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$captured) {
			$captured = $param;
		});
		$asset->onProcessAsset($dst);
		return $captured;
	}

	/**
	 * Skips the test when file permissions are not enforced, eg for root.
	 * @param string $path a file whose permissions were restricted.
	 * @param callable $check returns true when the restriction is enforced.
	 */
	protected static function requirePermissions(string $path, callable $check): void
	{
		clearstatcache();
		if (!$check($path)) {
			chmod($path, 0o644);
			self::markTestSkipped('File permissions are not enforced for this user.');
		}
	}

	public function testInterfacesAndConstants(): void
	{
		$gzip = new TAssetGZCompress();

		self::assertInstanceOf(IAssetMatching::class, $gzip);
		self::assertInstanceOf(IAssetFinalizer::class, $gzip);
		self::assertSame("\x1f\x8b", TAssetGZCompress::GZIP_MAGIC);
		self::assertSame(100, TAssetGZCompress::FINALIZER_PRIORITY);
		self::assertSame(['onProcessAsset' => 'addFinalizer'], $gzip->events());
	}

	public function testDefaults(): void
	{
		$gzip = new TAssetGZCompress();

		self::assertNull($gzip->getMatchFiles());
		self::assertSame(-1, $gzip->getCompressionLevel());
	}

	public function testMatchFiles(): void
	{
		$gzip = new TAssetGZCompress();

		$gzip->setMatchFiles('/\.(js|css)$/');
		self::assertSame('/\.(js|css)$/', $gzip->getMatchFiles());

		$gzip->setMatchFiles('');
		self::assertNull($gzip->getMatchFiles());

		$gzip->setMatchFiles(null);
		self::assertNull($gzip->getMatchFiles());
	}

	public function testCompressionLevelIsClamped(): void
	{
		$gzip = new TAssetGZCompress();

		$gzip->setCompressionLevel('6');
		self::assertSame(6, $gzip->getCompressionLevel());
		$gzip->setCompressionLevel(0);
		self::assertSame(0, $gzip->getCompressionLevel());
		$gzip->setCompressionLevel(12);
		self::assertSame(9, $gzip->getCompressionLevel());
		$gzip->setCompressionLevel(-5);
		self::assertSame(-1, $gzip->getCompressionLevel());
	}

	public function testSettingMatchFilesResetsTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.js'));
		$asset->attachBehavior('gzip', TAssetGZCompress::class);
		$gzip = $asset->asa('gzip');

		$resets = $asset->resets;
		$gzip->setMatchFiles('/\.js$/');
		self::assertSame($resets + 1, $asset->resets);

		$gzip->setEnabled(false);
		$gzip->setMatchFiles('/\.css$/');
		self::assertSame($resets + 1, $asset->resets, 'A disabled behavior does not reset the cache.');

		$resets = $asset->resets;
		$gzip->setCompressionLevel(9);
		self::assertSame($resets, $asset->resets, 'The compression level does not affect the file path.');
	}

	public function testChangingMatchFilesRecomputesTheMatch(): void
	{
		$source = $this->writeSource('a.js');
		$asset = $this->gzipAsset($source, ['MatchFiles' => '/\.css$/']);
		self::assertSame($source, $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));

		$asset->asa('gzip')->setMatchFiles('/\.js$/');

		self::assertSame($source . '.gz', $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
	}

	public function testAppendsGzToAMatchingFile(): void
	{
		$source = $this->writeSource('app.js');
		$asset = $this->gzipAsset($source);

		self::assertSame($source, $asset->getAssetFilePath(), 'The asset file path itself is not changed.');
		self::assertSame($source . '.gz', $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
	}

	public function testDoesNotRenameANonMatchingFile(): void
	{
		$source = $this->writeSource('site.css');
		$asset = $this->gzipAsset($source);

		self::assertSame($source, $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
	}

	public function testWithoutMatchFilesNothingIsCompressed(): void
	{
		$source = $this->writeSource('app.js', self::CONTENT);
		$asset = $this->gzipAsset($source, []);
		$dst = $this->assetDir . '/app.js';
		copy($source, $dst);

		self::assertSame($source, $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
		self::assertCount(0, static::process($asset, $dst)->getFinalizers());
		self::assertSame(self::CONTENT, file_get_contents($dst));
	}

	public function testDoesNotRenameADirectory(): void
	{
		$this->writeSource('bundle.js/a.txt');
		$asset = $this->gzipAsset($this->srcDir . '/bundle.js', ['MatchFiles' => '/\.js$/']);

		self::assertSame($this->srcDir . '/bundle.js/', $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
	}

	public function testMatchesTheFilePathBeforeValidation(): void
	{
		$asset = $this->gzipAsset('/virtual/data.json', ['MatchFiles' => '/^\/virtual\//'], TGeneratedAsset::class);

		self::assertSame('/virtual/data.json.gz', $asset->dyAlterAssetFilePath($asset->getAssetFilePath()));
	}

	public function testEmptyAndDisabledPathsAreNotRenamed(): void
	{
		$source = $this->writeSource('app.js');
		$asset = $this->gzipAsset($source);
		$asset->getAssetFilePath();
		$gzip = $asset->asa('gzip');

		self::assertSame('', $gzip->dyAlterAssetFilePath(''));
		self::assertSame($source . '.gz', $gzip->dyAlterAssetFilePath($source));

		$gzip->setEnabled(false);
		self::assertSame($source, $gzip->dyAlterAssetFilePath($source));
	}

	public function testMatchingIsRecomputedOnEachPreValidationFilter(): void
	{
		$gzip = new TAssetGZCompress();
		$gzip->setMatchFiles('/\.js$/');

		self::assertSame('/a.js', $gzip->dySetAssetFilePathPre('/a.js', new TCallChain('dySetAssetFilePathPre')));
		self::assertSame('/a.js.gz', $gzip->dyAlterAssetFilePath('/a.js'));

		self::assertSame('', $gzip->dySetAssetFilePathPre('', new TCallChain('dySetAssetFilePathPre')));
		self::assertSame('/a.js', $gzip->dyAlterAssetFilePath('/a.js'), 'An empty path does not match.');

		self::assertNull($gzip->dySetAssetFilePathPre(null, new TCallChain('dySetAssetFilePathPre')));
		self::assertSame('/a.js', $gzip->dyAlterAssetFilePath('/a.js'), 'A cancelled path does not match.');

		$gzip->dySetAssetFilePathPre('/b.css', new TCallChain('dySetAssetFilePathPre'));
		self::assertSame('/a.js', $gzip->dyAlterAssetFilePath('/a.js'), 'A non-matching path clears the match.');
	}

	public function testRegistersAsTheLastFinalizer(): void
	{
		$source = $this->writeSource('app.js', self::CONTENT);
		$asset = $this->gzipAsset($source);
		$asset->getAssetFilePath();
		$dst = $this->assetDir . '/app.js.gz';
		copy($source, $dst);

		$param = static::process($asset, $dst);

		self::assertSame([$asset->asa('gzip')], $param->getFinalizers()->toArray());
		self::assertSame(100, $param->getFinalizers()->priorityOf($asset->asa('gzip')));
		self::assertSame(self::CONTENT, gzdecode(file_get_contents($dst)));
	}

	public function testDoesNotRegisterForANonMatchingDisabledOrForeignParameter(): void
	{
		$css = $this->gzipAsset($this->writeSource('site.css'));
		$css->getAssetFilePath();
		$param = new TAssetEventParameter('onProcessAsset', '/x', $css);
		$css->asa('gzip')->addFinalizer($css, $param);
		self::assertCount(0, $param->getFinalizers(), 'A non-matching asset is not finalized.');

		$js = $this->gzipAsset($this->writeSource('app.js'));
		$js->getAssetFilePath();
		$gzip = $js->asa('gzip');
		$gzip->addFinalizer($js, new TEventParameter());
		$param = new TAssetEventParameter('onProcessAsset', '/x', $js);
		$gzip->setEnabled(false);
		$gzip->addFinalizer($js, $param);
		self::assertCount(0, $param->getFinalizers(), 'A disabled behavior is not a finalizer.');

		$gzip->setEnabled(true);
		$gzip->addFinalizer($js, $param);
		self::assertCount(1, $param->getFinalizers());
	}

	public function testFinalizeCompressesInPlace(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$asset->getAssetFilePath();
		file_put_contents($dst = $this->assetDir . '/out.gz', self::CONTENT);
		$gzip = $asset->asa('gzip');

		$gzip->finalize($dst, new TAssetEventParameter());

		$data = file_get_contents($dst);
		self::assertStringStartsWith(TAssetGZCompress::GZIP_MAGIC, $data);
		self::assertSame(self::CONTENT, gzdecode($data));
	}

	public function testFinalizeLeavesGzippedDataAsIs(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$asset->getAssetFilePath();
		$gz = gzencode(self::CONTENT, 1);
		file_put_contents($dst = $this->assetDir . '/out.gz', $gz);

		$asset->asa('gzip')->finalize($dst, new TAssetEventParameter());

		self::assertSame($gz, file_get_contents($dst), 'Already gzip-compressed data is not compressed again.');
	}

	public function testFinalizeCompressesEmptyAndBinaryFiles(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$asset->getAssetFilePath();
		$gzip = $asset->asa('gzip');

		file_put_contents($empty = $this->assetDir . '/empty.gz', '');
		$gzip->finalize($empty, new TAssetEventParameter());
		self::assertSame('', gzdecode(file_get_contents($empty)));

		file_put_contents($single = $this->assetDir . '/single.gz', "\x1f");
		$gzip->finalize($single, new TAssetEventParameter());
		self::assertSame("\x1f", gzdecode(file_get_contents($single)), 'A lone first magic byte is not gzip data.');
	}

	public function testCompressionLevel(): void
	{
		$content = str_repeat(self::CONTENT . random_bytes(8), 200);
		$sizes = [];
		foreach ([0, 9] as $level) {
			$asset = $this->gzipAsset($this->writeSource('app.js'), ['MatchFiles' => '/\.js$/', 'CompressionLevel' => $level]);
			$asset->getAssetFilePath();
			file_put_contents($dst = $this->assetDir . "/level$level.gz", $content);
			$asset->asa('gzip')->finalize($dst, new TAssetEventParameter());
			$data = file_get_contents($dst);
			$sizes[$level] = strlen($data);
			self::assertSame($content, gzdecode($data));
		}

		self::assertGreaterThan(strlen($content), $sizes[0], 'Level 0 stores the data.');
		self::assertLessThan($sizes[0], $sizes[9]);
	}

	public function testFinalizeSkipsWhenNotApplicable(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$gzip = $asset->asa('gzip');
		file_put_contents($dst = $this->assetDir . '/out.gz', self::CONTENT);

		$gzip->finalize($dst, new TAssetEventParameter());
		self::assertSame(self::CONTENT, file_get_contents($dst), 'An unmatched asset is not compressed.');

		$asset->getAssetFilePath();
		$gzip->finalize('', new TAssetEventParameter());
		$gzip->finalize($this->assetDir . '/missing.gz', new TAssetEventParameter());
		$gzip->finalize($this->assetDir, new TAssetEventParameter());
		self::assertFalse(is_file($this->assetDir . '/missing.gz'));

		$gzip->setEnabled(false);
		$gzip->finalize($dst, new TAssetEventParameter());
		self::assertSame(self::CONTENT, file_get_contents($dst), 'A disabled behavior does not compress.');
	}

	public function testFinalizeThrowsWhenTheFileCannotBeRead(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$asset->getAssetFilePath();
		file_put_contents($dst = $this->assetDir . '/out.gz', self::CONTENT);
		chmod($dst, 0);
		static::requirePermissions($dst, fn ($path) => !is_readable($path));

		try {
			$asset->asa('gzip')->finalize($dst, new TAssetEventParameter());
			self::fail('An unreadable file throws.');
		} catch (TIOException $e) {
			self::assertSame('assetcompress_read_failed', $e->getErrorCode());
		} finally {
			chmod($dst, 0o644);
		}
	}

	public function testFinalizeThrowsWhenTheFileCannotBeWritten(): void
	{
		$asset = $this->gzipAsset($this->writeSource('app.js'));
		$asset->getAssetFilePath();
		file_put_contents($dst = $this->assetDir . '/out.gz', self::CONTENT);
		chmod($dst, 0o444);
		static::requirePermissions($dst, fn ($path) => !is_writable($path));

		try {
			$asset->asa('gzip')->finalize($dst, new TAssetEventParameter());
			self::fail('An unwritable file throws.');
		} catch (TIOException $e) {
			self::assertSame('assetcompress_write_failed', $e->getErrorCode());
		} finally {
			chmod($dst, 0o644);
		}
		self::assertSame(self::CONTENT, file_get_contents($dst));
	}

	public function testPublishCompressesMatchingFiles(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.js$/'], TAsset::class);
		$js = $this->writeSource('js/app.js', self::CONTENT);
		$css = $this->writeSource('js/site.css', 'body{}');
		$manager = $this->newManager();

		$url = $manager->publish($js);
		$cssUrl = $manager->publish($css);

		self::assertStringEndsWith('/app.js.gz', $url);
		$data = file_get_contents($this->urlToPath($url));
		self::assertStringStartsWith(TAssetGZCompress::GZIP_MAGIC, $data);
		self::assertSame(self::CONTENT, gzdecode($data));
		self::assertSame($url, $manager->getPublishedUrl($js));
		self::assertSame($this->urlToPath($url), $manager->getPublishedPath($js));
		self::assertFalse(is_file(dirname($this->urlToPath($url)) . '/app.js'));
		self::assertSame([], glob(dirname($this->urlToPath($url)) . '/tmp-*'), 'No temporary file is left.');
		self::assertSame(self::CONTENT, file_get_contents($js), 'The source is not modified.');

		self::assertStringEndsWith('/site.css', $cssUrl);
		self::assertSame('body{}', file_get_contents($this->urlToPath($cssUrl)));
	}

	public function testPublishNonAtomic(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.js$/', 'CompressionLevel' => 9], TAsset::class);
		$js = $this->writeSource('app.js', self::CONTENT);

		$url = $this->newManager(['Atomic' => false])->publish($js);

		self::assertSame(self::CONTENT, gzdecode(file_get_contents($this->urlToPath($url))));
	}

	public function testPublishAlreadyCompressedSource(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.svgz$/'], TAsset::class);
		$gz = gzencode('<svg/>');
		$source = $this->writeSource('icon.svgz', $gz);

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/icon.svgz.gz', $url);
		self::assertSame($gz, file_get_contents($this->urlToPath($url)));
	}

	public function testCompressionFollowsProcessing(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.txt$/'], TAsset::class);
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$this->attachClassBehavior('mark', TMarkFinalizerBehavior::class, TAsset::class);
		$source = $this->writeSource('readme.txt', 'hello');

		$url = $this->newManager()->publish($source);

		self::assertCount(1, TUppercaseAssetBehavior::$processed);
		self::assertSame('HELLO|marked', gzdecode(file_get_contents($this->urlToPath($url))), 'Processing and default-priority finalizers run before compression.');
	}

	public function testPublishWithTheCacheBuster(): void
	{
		$this->attachClassBehavior('buster', TAssetCacheBuster::class, TAsset::class, 5);
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.js$/'], TAsset::class, 20);
		$source = $this->writeSource('app.js', self::CONTENT);

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/app.' . hash('crc32b', self::CONTENT) . '.js.gz', $url);
		self::assertSame(self::CONTENT, gzdecode(file_get_contents($this->urlToPath($url))));
	}

	public function testPublishDirectoryCompressesEachMatchingFile(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\/bundle\//'], TAsset::class);
		$this->writeSource('bundle/a.txt', 'alpha');
		$this->writeSource('bundle/sub/b.txt', 'beta');

		$url = $this->newManager()->publish($this->srcDir . '/bundle');
		$dir = $this->urlToPath($url);

		self::assertTrue(is_dir($dir), 'The matching directory keeps its name.');
		self::assertSame('alpha', gzdecode(file_get_contents($dir . '/a.txt.gz')));
		self::assertSame('beta', gzdecode(file_get_contents($dir . '/sub/b.txt.gz')));
		self::assertFalse(is_file($dir . '/a.txt'));
	}

	public function testDisabledBehaviorOnPublish(): void
	{
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.js$/'], TAsset::class);
		$source = $this->writeSource('app.js', self::CONTENT);
		$manager = $this->newManager();
		$asset = $manager->ensureAsset($source);
		$asset->disableBehavior('gzip');

		$url = $manager->publish($asset);

		self::assertStringEndsWith('/app.js', $url);
		self::assertSame(self::CONTENT, file_get_contents($this->urlToPath($url)));
	}
}
