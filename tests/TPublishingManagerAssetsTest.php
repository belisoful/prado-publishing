<?php

/**
 * TPublishingManagerAssetsTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\Web\Assets\Behaviors\TAssetBlocker;
use Prado\Web\Assets\IAsset;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\TAssetManager;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\Fixtures\TUppercaseAssetBehavior;
use Prado\Web\TPublishingManager;

/**
 * TPublishingManagerAssetsTest class.
 *
 * Tests what TPublishingManager adds to TAssetManager: publishing through asset
 * objects, class discovery, behavior renaming and processing, directories published
 * file by file, and the asset-aware link, capture, and cache rules.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPublishingManagerAssetsTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TUppercaseAssetBehavior::$processed = [];
	}

	public function testIsATAssetManager(): void
	{
		self::assertInstanceOf(TAssetManager::class, $this->newManager());
	}

	public function testDefaultAssetClasses(): void
	{
		$manager = new TPublishingManager();
		self::assertSame(TFileAsset::class, $manager->getDefaultAssetClass());
		self::assertSame(TImageAsset::class, $manager->getDefaultImageAssetClass());

		$manager->setDefaultAssetClass(TGeneratedAsset::class);
		$manager->setDefaultImageAssetClass(TFileAsset::class);
		self::assertSame(TGeneratedAsset::class, $manager->getDefaultAssetClass());
		self::assertSame(TFileAsset::class, $manager->getDefaultImageAssetClass());
	}

	public function testDefaultAssetClassIsUnchangeableAfterInit(): void
	{
		$manager = $this->newManager();
		$this->expectException(TInvalidOperationException::class);
		$manager->setDefaultAssetClass(TFileAsset::class);
	}

	public function testDefaultImageAssetClassIsUnchangeableAfterInit(): void
	{
		$manager = $this->newManager();
		$this->expectException(TInvalidOperationException::class);
		$manager->setDefaultImageAssetClass(TImageAsset::class);
	}

	public function testPublishFileThroughAnAsset(): void
	{
		$source = $this->writeSource('js/app.js', 'let a = 1;');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertSame('let a = 1;', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $manager->getPublishedUrl($source));
		self::assertSame($this->urlToPath($url), $manager->getPublishedPath($source));
		$assets = $manager->getPublishedAssets();
		self::assertArrayHasKey($source, $assets);
		self::assertInstanceOf(TFileAsset::class, $assets[$source]);
		self::assertSame($url, $assets[$source]->getPublishedUrl());
		self::assertSame($this->urlToPath($url), $assets[$source]->getPublishedPath());
	}

	public function testPublishedUrlMatchesTAssetManager(): void
	{
		$source = $this->writeSource('css/site.css', 'body{}');
		$plain = new TAssetManager();
		$plain->setBasePath(static::ASSETS_ALIAS);
		$plain->setBaseUrl(static::BASE_URL);
		$plain->init(null);
		$expected = $plain->getPublishedUrl($source);

		self::assertSame($expected, $this->newManager()->publish($source), 'An unaltered file publishes where TAssetManager publishes it.');
	}

	public function testGetPublishedHoldsUrlStrings(): void
	{
		$source = $this->writeSource('a.css', 'x');
		$manager = $this->newManager();
		$url = $manager->publish($source);

		self::assertSame([$source => $url], $manager->getPublished());
	}

	public function testImageFilesInstanceAsImageAssets(): void
	{
		$manager = $this->newManager();
		foreach (TPublishingManager::IMAGE_EXTENSIONS as $extension) {
			$path = $this->writeSource('images/picture.' . strtoupper($extension), 'x');
			self::assertInstanceOf(TImageAsset::class, $manager->ensureAsset($path), "A .$extension file is an image asset.");
		}
		self::assertNotInstanceOf(TImageAsset::class, $manager->ensureAsset($this->writeSource('doc.txt')));
	}

	public function testOnDiscoverClassRoutesAndRewrites(): void
	{
		$manager = $this->newManager();
		$real = $this->writeSource('real.txt', 'real');
		$manager->attachEventHandler('onDiscoverClass', function ($sender, $param) use ($real) {
			if (str_ends_with($param->getFilePath(), 'alias.txt')) {
				$param->setFilePath($real);
				$param->setClass(TGeneratedAsset::class);
			}
		});

		$asset = $manager->ensureAsset($this->srcDir . '/alias.txt');

		self::assertInstanceOf(TGeneratedAsset::class, $asset);
		self::assertSame($real, $asset->getAssetOriginalFilePath());
	}

	public function testOnDiscoverClassHandlerCannotClearTheClass(): void
	{
		$manager = $this->newManager();
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setClass(''));

		$this->expectException(TInvalidDataTypeException::class);
		$manager->ensureAsset($this->writeSource('a.txt'));
	}

	public function testEnsureAssetRejectsANonAssetClass(): void
	{
		$manager = $this->newManager();
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setClass(\stdClass::class));

		$this->expectException(TInvalidDataTypeException::class);
		$manager->ensureAsset($this->writeSource('a.txt'));
	}

	public function testEnsureAssetRejectsANonStringNonAsset(): void
	{
		$this->expectException(TInvalidDataTypeException::class);
		$this->newManager()->ensureAsset(new \stdClass());
	}

	public function testEnsureAssetReturnsAnAssetAsIs(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt'));
		self::assertSame($asset, $this->newManager()->ensureAsset($asset));
	}

	public function testEnsureAssetRejectsTheBasePathAndItsParents(): void
	{
		$manager = $this->newManager();
		foreach ([$this->assetDir, dirname($this->assetDir), '/'] as $path) {
			try {
				$manager->ensureAsset($path);
				self::fail("$path is rejected.");
			} catch (TInvalidDataValueException $e) {
				self::assertStringContainsString('publishingmanager_filepath_invalid', $e->getErrorCode());
			}
		}
	}

	public function testEnsureAssetRejectsAnEmptyPath(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->newManager()->ensureAsset('');
	}

	public function testAssetWithAnEmptyFilePathIsInvalid(): void
	{
		$asset = new class () implements IAsset {
			public function getAssetFilePath()
			{
				return '';
			}

			public function getAssetOriginalFilePath()
			{
				return '';
			}

			public function getAssetModificationDate()
			{
				return 0;
			}

			public function publish(string $dst): ?bool
			{
				return true;
			}
		};

		$this->expectException(TInvalidDataValueException::class);
		$this->newManager()->publish($asset);
	}

	public function testPlainIAssetWithoutASourcePublishesByItsPath(): void
	{
		$asset = new class () implements IAsset {
			public function getAssetFilePath()
			{
				return '/virtual/plain/data.txt';
			}

			public function getAssetOriginalFilePath()
			{
				return '';
			}

			public function getAssetModificationDate()
			{
				return 0;
			}

			public function publish(string $dst): ?bool
			{
				return file_put_contents($dst, 'plain') !== false;
			}
		};

		$url = $this->newManager()->publish($asset);

		self::assertStringEndsWith('/data.txt', $url);
		self::assertSame('plain', file_get_contents($this->urlToPath($url)));
	}

	public function testAlteredFilePathCanCancelOrBeInvalid(): void
	{
		$asset = new TGeneratedAsset('/virtual/cancel.txt');
		$asset->attachBehavior('cancel', new class () extends \Prado\Util\TBehavior {
			public $result;

			public function dyAlterAssetFilePath($filePath, $callchain)
			{
				return $this->result;
			}
		});
		$manager = $this->newManager();

		self::assertSame('', $manager->publish($asset), 'A null altered path cancels.');
		self::assertSame('', $manager->getPublishedUrl($asset));

		$asset->asa('cancel')->result = '';
		$this->expectException(TInvalidDataValueException::class);
		$manager->publish($asset);
	}

	public function testLinkFailureRethrowsWhenNothingWasPublished(): void
	{
		$source = $this->writeSource('nolink.txt', 'x');
		$manager = new class () extends TPublishingManager {
			protected function symlink($target, $link)
			{
				throw new \RuntimeException('symlink failed');
			}
		};
		$manager->setBasePath(static::ASSETS_ALIAS);
		$manager->setBaseUrl(static::BASE_URL);
		$manager->setLinkAssets(true);
		$manager->init(null);

		$this->expectException(\RuntimeException::class);
		$manager->publish($source);
	}

	public function testLinkFailureIsIgnoredWhenTheLinkAppeared(): void
	{
		$source = $this->writeSource('racelink.txt', 'x');
		$manager = new class () extends TPublishingManager {
			protected function symlink($target, $link)
			{
				file_put_contents($link, 'raced');
				throw new \RuntimeException('symlink failed after another process published');
			}
		};
		$manager->setBasePath(static::ASSETS_ALIAS);
		$manager->setBaseUrl(static::BASE_URL);
		$manager->setLinkAssets(true);
		$manager->init(null);

		$url = $manager->publish($source);

		self::assertSame('raced', file_get_contents($this->urlToPath($url)));
	}

	public function testEmptyPathIsInvalid(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->newManager()->publish('');
	}

	public function testBehaviorRenamesThePublishedFile(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v2'], TAsset::class);
		$source = $this->writeSource('lib/app.js', 'code');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertStringEndsWith('/app.js.v2', $url);
		self::assertSame('code', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $manager->getPublishedUrl($source), 'getPublishedUrl reflects the rename.');
		self::assertSame($this->urlToPath($url), $manager->getPublishedPath($source), 'getPublishedPath reflects the rename.');
		self::assertFalse(is_file(dirname($this->urlToPath($url)) . '/app.js'), 'The unrenamed name is not published.');
	}

	public function testBehaviorProcessesThePublishedFile(): void
	{
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$source = $this->writeSource('readme.txt', 'hello');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertSame('HELLO', file_get_contents($this->urlToPath($url)));
		self::assertSame('hello', file_get_contents($source), 'The source is not modified.');
		self::assertCount(1, TUppercaseAssetBehavior::$processed);
		self::assertStringContainsString('tmp-', basename(TUppercaseAssetBehavior::$processed[0]), 'Processing happens on the atomic temporary file.');
		self::assertEmpty(glob(dirname($this->urlToPath($url)) . '/tmp-*'), 'No temporary file is left.');
	}

	public function testNonAtomicProcessingWritesInPlace(): void
	{
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$source = $this->writeSource('readme.txt', 'hello');
		$manager = $this->newManager(['Atomic' => false]);

		$url = $manager->publish($source);

		self::assertSame([$this->urlToPath($url)], TUppercaseAssetBehavior::$processed);
		self::assertSame('HELLO', file_get_contents($this->urlToPath($url)));
	}

	public function testBlockedAssetCancelsPublishing(): void
	{
		$this->attachClassBehavior('blocker', ['class' => TAssetBlocker::class, 'BlockedFiles' => '/secret/'], TAsset::class);
		$source = $this->writeSource('secret.txt', 'x');
		$manager = $this->newManager();

		self::assertSame('', $manager->publish($source));
		self::assertSame('', $manager->getPublishedUrl($source));
		self::assertSame('', $manager->getPublishedPath($source));
		self::assertSame([], glob($this->assetDir . '/*/secret.txt'));
	}

	public function testGetPublishedPathOfAMissingFileFallsBackToTAssetManager(): void
	{
		$manager = $this->newManager();
		$missing = $this->srcDir . '/missing.txt';

		// TAssetManager reports a missing path rather than resolving it to a location.
		foreach (['getPublishedPath', 'getPublishedUrl'] as $method) {
			try {
				$manager->$method($missing);
				self::fail("$method of a missing file is rejected.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
			}
		}
	}

	public function testPublishAnAssetObject(): void
	{
		$asset = new TGeneratedAsset('/virtual/generated/data.json');
		$asset->content = '{"a":1}';
		$manager = $this->newManager();

		$url = $manager->publish($asset);

		self::assertStringEndsWith('/data.json', $url);
		self::assertSame('{"a":1}', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $asset->getPublishedUrl());
		self::assertSame($this->urlToPath($url), $asset->getPublishedPath());
		self::assertArrayHasKey('/virtual/generated/data.json', $manager->getPublishedAssets());
		self::assertSame($url, $manager->getPublishedUrl($asset));
	}

	public function testPublishedAssetIsCachedAndCapturedOnTheCachedCall(): void
	{
		$manager = $this->newManager();
		$first = new TGeneratedAsset('/virtual/cached.txt');
		$url = $manager->publish($first);

		$second = new TGeneratedAsset('/virtual/cached.txt');
		self::assertSame($url, $manager->publish($second));
		self::assertSame([], $second->writtenTo, 'The cached asset is not written again.');
		self::assertSame($url, $second->getPublishedUrl(), 'A cached publish still captures the URL.');
	}

	public function testUpToDateAssetIsNotRewritten(): void
	{
		$source = $this->writeSource('stable.txt', 'one', time() - 100);
		$url = $this->newManager()->publish($source);
		$dst = $this->urlToPath($url);
		file_put_contents($dst, 'published');
		touch($dst, time());

		$this->newManager()->publish($source);
		self::assertSame('published', file_get_contents($dst), 'A destination newer than the source is kept.');

		$this->newManager()->publish($source, ['forceCopy' => true]);
		self::assertSame('one', file_get_contents($dst), 'forceCopy rewrites it.');

		touch($source, time() + 100);
		file_put_contents($dst, 'published');
		touch($dst, time());
		$this->newManager()->publish($source);
		self::assertSame('one', file_get_contents($dst), 'A source newer than the destination is republished.');
	}

	public function testOnlyAndExceptMatchTheSourceName(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.min'], TAsset::class);
		$js = $this->writeSource('a.js', 'js');
		$css = $this->writeSource('a.css', 'css');
		$manager = $this->newManager();

		$jsUrl = $manager->publish($js, ['only' => ['*.js']]);
		$cssUrl = $manager->publish($css, ['only' => ['*.js']]);

		self::assertTrue(is_file($this->urlToPath($jsUrl)), 'The pattern matches the source name, not the renamed one.');
		self::assertFalse(is_file($this->urlToPath($cssUrl)));
	}

	public function testCopyCallbacksReceiveTheSourceAndRenamedDestination(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.x'], TAsset::class);
		$source = $this->writeSource('cb.txt', 'x');
		$calls = [];
		$url = $this->newManager()->publish($source, [
			'beforeCopy' => function ($src, $dst) use (&$calls) {
				$calls[] = ['before', $src, $dst];
				return true;
			},
			'afterCopy' => function ($src, $dst) use (&$calls) {
				$calls[] = ['after', $src, $dst];
			},
		]);

		$dst = $this->urlToPath($url);
		self::assertSame([['before', $source, $dst], ['after', $source, $dst]], $calls);
	}

	public function testLinkAssetsLinksAnUnprocessedFile(): void
	{
		$this->requireSymlinks();
		$source = $this->writeSource('link.txt', 'linked');
		$url = $this->newManager(['LinkAssets' => true])->publish($source);

		self::assertTrue(is_link($this->urlToPath($url)));
		self::assertSame('linked', file_get_contents($this->urlToPath($url)));
	}

	public function testLinkAssetsWritesAProcessedFile(): void
	{
		$this->requireSymlinks();
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$source = $this->writeSource('link.txt', 'linked');
		$url = $this->newManager(['LinkAssets' => true])->publish($source);

		self::assertFalse(is_link($this->urlToPath($url)), 'A processed asset is not linked to its unprocessed source.');
		self::assertSame('LINKED', file_get_contents($this->urlToPath($url)));
	}

	public function testLinkAssetsWritesARenamedFile(): void
	{
		$this->requireSymlinks();
		$this->attachClassBehavior('suffix', TSuffixAssetBehavior::class, TAsset::class);
		$source = $this->writeSource('link.txt', 'linked');
		$url = $this->newManager(['LinkAssets' => true])->publish($source);

		self::assertFalse(is_link($this->urlToPath($url)));
		self::assertSame('linked', file_get_contents($this->urlToPath($url)));
	}

	public function testDirectoryPublishesEachFileAsAnAsset(): void
	{
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.out'], TAsset::class);
		$this->writeSource('bundle/a.txt', 'alpha');
		$this->writeSource('bundle/sub/b.txt', 'beta');
		$manager = $this->newManager();

		$url = $manager->publish($this->srcDir . '/bundle');
		$dir = $this->urlToPath($url);

		self::assertSame('ALPHA', file_get_contents($dir . '/a.txt.out'));
		self::assertSame('BETA', file_get_contents($dir . '/sub/b.txt.out'));
		self::assertFalse(is_file($dir . '/a.txt'));
		self::assertTrue(is_file($dir . DIRECTORY_SEPARATOR . TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX . sha1($this->srcDir . DIRECTORY_SEPARATOR . 'bundle')));

		$nested = $manager->getPublishedAssets()[$this->srcDir . DIRECTORY_SEPARATOR . 'bundle' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt'];
		self::assertSame($url . '/sub/b.txt.out', $nested->getPublishedUrl(), 'A nested asset captures its URL.');
		self::assertSame($dir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt.out', $nested->getPublishedPath());
	}

	public function testDirectorySkipsBlockedFiles(): void
	{
		$this->attachClassBehavior('blocker', ['class' => TAssetBlocker::class, 'BlockedFiles' => '/\.php$/'], TAsset::class);
		$this->writeSource('site/index.php', '<?php');
		$this->writeSource('site/style.css', 'body{}');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/site'));

		self::assertTrue(is_file($dir . '/style.css'));
		self::assertFalse(is_file($dir . '/index.php'));
	}

	public function testDirectoryAssetRaisesOnProcessAssetOnTheDirectory(): void
	{
		$this->writeSource('dir/a.txt', 'a');
		$seen = [];
		$manager = $this->newManager();
		$asset = new TFileAsset($this->srcDir . '/dir');
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$seen) {
			$seen[] = $param->getFilePath();
		});

		$url = $manager->publish($asset);

		self::assertSame([$this->urlToPath($url)], $seen);
	}

	public function testGeneratedDirectoryAssetPopulatesItself(): void
	{
		$asset = new class ('/virtual/generated/bundle') extends TGeneratedAsset {
			protected function isDirectory(string $path): bool
			{
				return true;
			}

			public function publish(string $dst): ?bool
			{
				$this->writtenTo[] = $dst;
				@mkdir($dst, 0o777, true);
				return file_put_contents($dst . '/index.txt', 'generated') !== false;
			}
		};
		$manager = $this->newManager();

		$url = $manager->publish($asset);
		$dir = $this->urlToPath($url);

		self::assertSame([$dir], $asset->writtenTo);
		self::assertSame('generated', file_get_contents($dir . '/index.txt'));
		self::assertTrue(is_file($dir . DIRECTORY_SEPARATOR . TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX . sha1('/virtual/generated/bundle')));
		self::assertSame($url, $asset->getPublishedUrl());
		self::assertSame($asset, $manager->getPublishedAssets()['/virtual/generated/bundle']);
	}

	public function testAppendTimestampOnAnAsset(): void
	{
		$source = $this->writeSource('stamp.txt', 'x');
		$manager = $this->newManager(['AppendTimestamp' => true]);

		$url = $manager->publish($source);

		self::assertMatchesRegularExpression('/\/stamp\.txt\?v=\d+$/', $url);
		self::assertSame($url, $manager->getPublishedAssets()[$source]->getPublishedUrl());
	}

	public function testFileModeOnAnAsset(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('File permission modes are not honored on Windows.');
		}
		$source = $this->writeSource('mode.txt', 'x');
		$url = $this->newManager(['FileMode' => 0o640])->publish($source);

		clearstatcache();
		self::assertSame(0o640, fileperms($this->urlToPath($url)) & 0o777);
	}

	public function testBareIPublishableUsesTheTAssetManagerPath(): void
	{
		$virtual = new class () implements \Prado\Web\IPublishable {
			public function getAssetFilePath()
			{
				return '/virtual/bare.txt';
			}

			public function getAssetModificationDate()
			{
				return 0;
			}

			public function publish(string $dst): ?bool
			{
				return file_put_contents($dst, 'bare') !== false;
			}
		};
		self::assertNotInstanceOf(IAsset::class, $virtual);

		$url = $this->newManager()->publish($virtual);

		self::assertSame('bare', file_get_contents($this->urlToPath($url)));
	}

	public function testCacheAssetPublishFilePathIsOffByDefault(): void
	{
		$this->attachClassBehavior('suffix', TSuffixAssetBehavior::class, TAsset::class);
		$manager = $this->newManager();
		self::assertFalse($manager->getCacheAssetPublishFilePath());

		$url = $manager->publish($source = $this->writeSource('script.js', 'x'));
		$asset = $manager->getPublishedAssets()[$source];

		self::assertStringEndsWith('/script.js.renamed', $url);
		self::assertFalse($asset->getCachePublishFilePath());
		self::assertGreaterThan(1, $asset->asa('suffix')->alterCount, 'The publish path is renamed on each read.');
	}

	public function testCacheAssetPublishFilePathRenamesOnce(): void
	{
		$this->attachClassBehavior('suffix', TSuffixAssetBehavior::class, TAsset::class);
		$manager = $this->newManager(['CacheAssetPublishFilePath' => true]);
		self::assertTrue($manager->getCacheAssetPublishFilePath());

		$url = $manager->publish($source = $this->writeSource('script.js', 'x'));
		$asset = $manager->getPublishedAssets()[$source];

		self::assertStringEndsWith('/script.js.renamed', $url, 'The published name is the same.');
		self::assertSame('x', file_get_contents($this->urlToPath($url)));
		self::assertTrue($asset->getCachePublishFilePath());
		self::assertSame(1, $asset->asa('suffix')->alterCount, 'The publish path is renamed once.');
	}

	public function testCacheAssetPublishFilePathLeavesAssetObjectsAlone(): void
	{
		$manager = $this->newManager(['CacheAssetPublishFilePath' => true]);
		$asset = new TFileAsset($this->writeSource('given.js', 'x'));

		$manager->publish($asset);

		self::assertFalse($asset->getCachePublishFilePath(), 'An asset given to the manager keeps its own setting.');
	}

	protected function requireSymlinks(): void
	{
		$target = $this->tempDir . '/link-target';
		$link = $this->tempDir . '/link-probe';
		file_put_contents($target, 'x');
		if (!@symlink($target, $link)) {
			self::markTestSkipped('Symbolic links are not supported.');
		}
		unlink($link);
		unlink($target);
	}
}
