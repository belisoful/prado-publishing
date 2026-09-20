<?php

/**
 * TPublishingManagerRoutingTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\IO\TTarFileExtractor;
use Prado\TApplicationMode;
use Prado\TComponent;
use Prado\Web\Assets\IAsset;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Assets\TTarAsset;
use Prado\Web\TAssetManager;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TProbeTarAsset;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\TPublishingManager;
use Prado\Test\Unit\Harness\IO\TarTestHelper;

/**
 * TPublishingManagerRoutingTest class.
 *
 * Tests the common publishing route of TPublishingManager: tar archives published as
 * assets (the DefaultTarAssetClass, the checksum or completion marker gating a
 * republish, performance mode, and the manager defaults reaching the extraction),
 * generated directories, the publish paths of non-TAsset assets, predetermined
 * destinations, IAssets reaching the TAssetManager virtual route, and class discovery.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPublishingManagerRoutingTest extends PublishingTestCase
{
	/** @var string the application mode before the test. */
	private string $_mode;

	protected function setUp(): void
	{
		parent::setUp();
		$this->_mode = static::application()->getMode();
		static::application()->setMode(TApplicationMode::Debug);
	}

	protected function tearDown(): void
	{
		static::application()->setMode($this->_mode);
		parent::tearDown();
	}

	/**
	 * Writes a tar archive into the source directory, dated in the past.
	 * @param string $relative the archive path relative to the source directory.
	 * @return string the archive path.
	 */
	protected function writeTar(string $relative = 'bundle/bundle.tar'): string
	{
		return $this->writeSource($relative, TarTestHelper::archive([
			TarTestHelper::entry('style.css', 'body{color:red}'),
			TarTestHelper::entry('sub/', '', '5'),
			TarTestHelper::entry('sub/nested.txt', 'nested'),
		]), time() - 100);
	}

	/**
	 * Writes the md5sum checksum file of an archive, dated in the past.
	 * @param string $tar the archive path.
	 * @param string $relative the checksum path relative to the source directory.
	 * @return string the checksum file path.
	 */
	protected function writeChecksum(string $tar, string $relative = 'bundle/bundle.md5'): string
	{
		return $this->writeSource($relative, md5_file($tar) . '  ' . basename($tar), time() - 100);
	}

	/**
	 * @param string $source the source path.
	 * @return string the completion marker name of the source.
	 */
	protected static function marker(string $source): string
	{
		return TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX . sha1($source);
	}

	/**
	 * @param TPublishingManager $manager the uninitialized manager, e.g. of an anonymous class.
	 * @param array $properties properties set before initialization.
	 * @return TPublishingManager the initialized manager.
	 */
	protected function initManager(TPublishingManager $manager, array $properties = []): TPublishingManager
	{
		$manager->setBasePath(static::ASSETS_ALIAS);
		$manager->setBaseUrl(static::BASE_URL);
		foreach ($properties as $name => $value) {
			$manager->{'set' . $name}($value);
		}
		$manager->init(null);
		return $manager;
	}

	// ---------------------------------------------------------------------------
	// DefaultTarAssetClass and publishTarFile
	// ---------------------------------------------------------------------------

	public function testDefaultTarAssetClass(): void
	{
		$manager = new TPublishingManager();
		self::assertSame(TTarAsset::class, $manager->getDefaultTarAssetClass());

		$manager->setDefaultTarAssetClass(TProbeTarAsset::class);
		self::assertSame(TProbeTarAsset::class, $manager->getDefaultTarAssetClass());
	}

	public function testDefaultTarAssetClassIsUnchangeableAfterInit(): void
	{
		$manager = $this->newManager();
		$this->expectException(TInvalidOperationException::class);
		$manager->setDefaultTarAssetClass(TProbeTarAsset::class);
	}

	public function testPublishTarFileInstancesTheDefaultTarAssetClass(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		$manager = $this->newManager(['DefaultTarAssetClass' => TProbeTarAsset::class]);

		$url = $manager->publishTarFile($tar, $md5);
		$dir = $this->urlToPath($url);

		self::assertSame($manager->getPublishedUrl($this->srcDir . '/bundle'), $url, 'The archive publishes as the checksum directory.');
		self::assertSame('nested', file_get_contents($dir . '/sub/nested.txt'));
		self::assertSame(file_get_contents($md5), file_get_contents($dir . '/bundle.md5'));
		$asset = $manager->getPublishedAssets()[$tar];
		self::assertInstanceOf(TProbeTarAsset::class, $asset);
		self::assertSame($md5, $asset->getMd5FilePath());
		self::assertSame([[$tar, true]], $asset->extractedFrom);
		self::assertSame($dir, $asset->getPublishedPath());
		self::assertSame($url, $asset->getPublishedUrl());
		self::assertSame($url, $manager->getPublished()['tar:' . $md5], 'The URL is cached by the namespaced checksum file.');
	}

	public function testPublishTarFileRejectsANonTarAssetClass(): void
	{
		$tar = $this->writeTar();
		$manager = $this->newManager(['DefaultTarAssetClass' => TFileAsset::class]);

		try {
			$manager->publishTarFile($tar, $this->writeChecksum($tar));
			self::fail('A DefaultTarAssetClass that is not a TTarAsset is rejected.');
		} catch (TInvalidDataTypeException $e) {
			self::assertSame('publishingmanager_invalid_tar_class', $e->getErrorCode());
		}
		self::assertSame([], $manager->getPublishedAssets());
		self::assertSame(['.', '..'], scandir($this->assetDir));
	}

	public function testPublishTarFileWithoutAChecksumIsMarkedAndCachedByTheArchive(): void
	{
		$tar = $this->writeTar();
		$manager = $this->newManager();

		$url = $manager->publishTarFile($tar);
		$dir = $this->urlToPath($url);

		self::assertSame($manager->getPublishedUrl($this->srcDir . '/bundle'), $url, 'The archive publishes as its directory.');
		self::assertSame(['.', '..', static::marker($tar), 'style.css', 'sub'], scandir($dir), 'The completion marker marks the extraction.');
		self::assertSame($url, $manager->getPublished()['tar:' . $tar], 'The URL is cached by the archive, under a tar key.');
		self::assertArrayNotHasKey($tar, $manager->getPublished(), 'The archive does not take the cache entry of its path.');

		static::removeTree($dir);
		self::assertSame($url, $manager->publishTarFile($tar));
		self::assertFalse(is_dir($dir), 'A cached archive is not extracted again.');
	}

	public function testPublishTarFileWithAnEmptyChecksumIsCachedByTheArchive(): void
	{
		$first = $this->writeTar('first/bundle.tar');
		$second = $this->writeTar('second/bundle.tar');
		$manager = $this->newManager();

		$firstUrl = $manager->publishTarFile($first, '');
		$secondUrl = $manager->publishTarFile($second, '');

		self::assertNotSame($firstUrl, $secondUrl, 'Archives without a checksum file do not share a cache entry.');
		self::assertTrue(is_file($this->urlToPath($secondUrl) . '/style.css'));
	}

	public function testPublishTarFileOptionsFillTheArchiveSettings(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		$url = $this->newManager()->publishTarFile($tar, $md5);
		$dir = $this->urlToPath($url);
		file_put_contents($dir . '/style.css', 'modified');

		$manager = $this->newManager();
		$manager->publishTarFile($tar, $md5, ['forceCopy' => true, 'conflictMode' => 'Skip', 'strict' => 'false', 'atomic' => false]);
		$asset = $manager->getPublishedAssets()[$tar];

		self::assertSame('modified', file_get_contents($dir . '/style.css'), 'The named conflict mode skips the existing file.');
		self::assertSame(TTarFileExtractor::CONFLICT_SKIP, $asset->getExtractor()->getConflictMode());
		self::assertFalse($asset->getExtractor()->getStrict());
		self::assertFalse($asset->getExtractor()->getAtomic());

		$this->newManager()->publishTarFile($tar, $md5, ['forceCopy' => true, 'conflictMode' => TTarFileExtractor::CONFLICT_OVERWRITE]);
		self::assertSame('body{color:red}', file_get_contents($dir . '/style.css'));
	}

	// ---------------------------------------------------------------------------
	// Tar republishing
	// ---------------------------------------------------------------------------

	public function testTarRepublishIsGatedByTheChecksumMarker(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		$dir = $this->urlToPath($this->newManager()->publishTarFile($tar, $md5));
		$marker = $dir . DIRECTORY_SEPARATOR . static::marker(realpath($md5));
		self::assertTrue(is_file($marker), 'The marker is keyed by the checksum file.');
		touch($marker, time());
		unlink($dir . '/style.css');

		$this->newManager()->publishTarFile($tar, $md5);
		self::assertFalse(is_file($dir . '/style.css'), 'A marker newer than the checksum is not extracted again.');

		$this->newManager()->publishTarFile($tar, $md5, true);
		self::assertFalse(is_file($dir . '/style.css'), 'Checking the timestamp finds the marker current.');

		touch($md5, time() + 100);
		clearstatcache();
		$this->newManager()->publishTarFile($tar, $md5);
		self::assertSame('body{color:red}', file_get_contents($dir . '/style.css'), 'A newer source checksum extracts again.');

		unlink($dir . '/style.css');
		$this->newManager()->publishTarFile($tar, $md5, ['forceCopy' => true]);
		self::assertTrue(is_file($dir . '/style.css'), 'forceCopy extracts again.');

		unlink($dir . '/style.css');
		$this->newManager(['ForceCopy' => true])->publishTarFile($tar, $md5);
		self::assertTrue(is_file($dir . '/style.css'), 'The manager ForceCopy extracts again.');

		unlink($dir . '/style.css');
		unlink($dir . '/bundle.md5');
		touch($marker, time() + 200); // Current again, as after an undisturbed publish.
		clearstatcache();
		$this->newManager()->publishTarFile($tar, $md5);
		self::assertFalse(is_file($dir . '/style.css'), 'The published checksum does not gate the extraction; the marker does.');

		unlink($marker);
		$this->newManager()->publishTarFile($tar, $md5);
		self::assertTrue(is_file($dir . '/style.css'), 'A missing marker extracts again.');
		self::assertTrue(is_file($dir . '/bundle.md5'), 'The checksum is published with the contents.');
	}

	public function testTarRepublishWithoutAChecksumIsGatedByTheMarker(): void
	{
		$tar = $this->writeTar();
		$dir = $this->urlToPath($this->newManager()->publishTarFile($tar));
		$marker = $dir . DIRECTORY_SEPARATOR . static::marker($tar);
		unlink($dir . '/style.css');

		$this->newManager()->publishTarFile($tar);
		self::assertFalse(is_file($dir . '/style.css'), 'A marker newer than the archive is not extracted again.');

		touch($marker, time() - 200);
		clearstatcache();
		$this->newManager()->publishTarFile($tar);
		self::assertTrue(is_file($dir . '/style.css'), 'A marker older than the archive extracts again.');
		clearstatcache();
		self::assertGreaterThan(time() - 200, filemtime($marker), 'The marker is renewed.');

		unlink($dir . '/style.css');
		unlink($marker);
		$this->newManager()->publishTarFile($tar);
		self::assertTrue(is_file($dir . '/style.css'), 'A missing marker extracts again.');
		self::assertTrue(is_file($marker));
	}

	public function testPerformanceModeSkipsAPublishedArchive(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		$dir = $this->urlToPath($this->newManager()->publishTarFile($tar, $md5));
		static::application()->setMode(TApplicationMode::Performance);
		unlink($dir . '/style.css');
		touch($md5, time() + 100);
		clearstatcache();

		$this->newManager()->publishTarFile($tar, $md5);
		self::assertFalse(is_file($dir . '/style.css'), 'In performance mode a published archive is not checked.');

		$this->newManager()->publishTarFile($tar, $md5, true);
		self::assertTrue(is_file($dir . '/style.css'), 'Checking the timestamp finds the newer checksum.');

		unlink($dir . '/style.css');
		unlink($dir . '/bundle.md5');
		$this->newManager()->publishTarFile($tar, $md5);
		self::assertFalse(is_file($dir . '/style.css'), 'The published checksum does not gate the extraction; the marker does.');

		unlink($marker = $dir . DIRECTORY_SEPARATOR . static::marker(realpath($md5)));
		$this->newManager()->publishTarFile($tar, $md5);
		self::assertTrue(is_file($dir . '/style.css'), 'A missing marker extracts again in performance mode.');
		self::assertTrue(is_file($marker));
	}

	public function testManagerDefaultsReachTheArchive(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('File permission modes are not honored on Windows.');
		}
		$tar = $this->writeTar();
		$asset = new TTarAsset($tar, $this->writeChecksum($tar));
		$manager = $this->newManager(['FileMode' => 0o640, 'DirMode' => 0o750, 'Atomic' => false]);

		$url = $manager->publish($asset);
		$dir = $this->urlToPath($url);

		clearstatcache();
		self::assertSame(0o640, fileperms($dir . '/style.css') & 0o777);
		self::assertSame(0o640, fileperms($dir . '/bundle.md5') & 0o777);
		self::assertSame(0o750, fileperms($dir . '/sub') & 0o777);
		self::assertFalse($asset->getAtomic());
		self::assertFalse($asset->getExtractor()->getAtomic());
		self::assertSame($dir, $asset->getPublishedPath());
		self::assertSame($url, $asset->getPublishedUrl());
		self::assertSame($asset, $manager->getPublishedAssets()[$tar]);
	}

	public function testArchiveSettingsPrecedeTheOptionsAndTheOptionsPrecedeTheManager(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('File permission modes are not honored on Windows.');
		}
		$tar = $this->writeTar('first/bundle.tar');
		$asset = new TTarAsset($tar);
		$asset->setFileMode(0o600);
		$dir = $this->urlToPath($this->newManager(['FileMode' => 0o640])->publish($asset, ['fileMode' => 0o644]));
		clearstatcache();
		self::assertSame(0o600, fileperms($dir . '/style.css') & 0o777, 'The archive setting wins.');

		$tar = $this->writeTar('second/bundle.tar');
		$dir = $this->urlToPath($this->newManager(['FileMode' => 0o640])->publishTarFile($tar, null, ['fileMode' => 0o644]));
		clearstatcache();
		self::assertSame(0o644, fileperms($dir . '/style.css') & 0o777, 'The option precedes the manager FileMode.');
	}

	public function testVirtualArchivePublishesThroughTheManager(): void
	{
		$archive = TarTestHelper::archive([TarTestHelper::entry('generated.txt', 'generated')]);
		mkdir($this->srcDir . '/unchecked');
		foreach (['with a checksum' => true, 'without a checksum' => false] as $case => $withChecksum) {
			$asset = new TProbeTarAsset(null, $withChecksum ? $this->writeSource('checked/bundle.md5', md5($archive)) : null);
			$asset->virtual = true;
			$asset->archive = $archive;
			$asset->setAssetFilePath($this->srcDir . ($withChecksum ? '/checked' : '/unchecked') . '/bundle.tar');
			$manager = $this->newManager();

			$url = $manager->publish($asset);

			self::assertSame($manager->getPublishedUrl($this->srcDir . ($withChecksum ? '/checked' : '/unchecked')), $url);
			self::assertCount(1, $asset->writtenTo, "The virtual archive $case is generated.");
			self::assertTrue(is_file($this->urlToPath($url) . '/generated.txt'), "The virtual archive $case is extracted.");
			self::assertSame([], glob($this->assetDir . '/tmp-*'), 'The temporary archive is removed.');
		}
	}

	// ---------------------------------------------------------------------------
	// Generated directories, asset paths, and destinations
	// ---------------------------------------------------------------------------

	public function testNonAtomicGeneratedDirectoryRepublishesWhenTheAssetIsNewer(): void
	{
		$make = function (int $modified) {
			$asset = new class ('/virtual/generated/site') extends TGeneratedAsset {
				protected function isDirectory(string $path): bool
				{
					return true;
				}

				public function publish(string $dst): ?bool
				{
					$this->writtenTo[] = $dst;
					return file_put_contents($dst . '/index.txt', $this->content) !== false;
				}
			};
			$asset->modified = $modified;
			return $asset;
		};
		static::application()->setMode(TApplicationMode::Performance);

		$first = $make(0);
		$dir = $this->urlToPath($this->newManager(['Atomic' => false])->publish($first));
		self::assertSame([$dir], $first->writtenTo, 'A missing directory is created and populated.');
		self::assertSame(['.', '..', 'index.txt'], scandir($dir), 'A non-atomic directory has no completion marker.');
		touch($dir, time() - 50);
		clearstatcache();

		$current = $make(time() - 100);
		$this->newManager(['Atomic' => false])->publish($current);
		self::assertSame([], $current->writtenTo, 'An existing directory newer than the asset is kept.');

		$newer = $make(time());
		$this->newManager(['Atomic' => false])->publish($newer);
		self::assertSame([$dir], $newer->writtenTo, 'An asset newer than the directory populates it again.');
	}

	public function testComponentAssetPublishPathIsAlteredByBehaviors(): void
	{
		$asset = new class () extends TComponent implements IAsset {
			public function getAssetFilePath()
			{
				return '/virtual/component/data.txt';
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
				return file_put_contents($dst, 'component') !== false;
			}
		};
		$asset->attachBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v1']);
		$manager = $this->newManager();

		$url = $manager->publish($asset);

		self::assertStringEndsWith('/data.txt.v1', $url);
		self::assertSame('component', file_get_contents($this->urlToPath($url)));
		self::assertSame($asset, $manager->getPublishedAssets()['/virtual/component/data.txt']);
		self::assertSame($url, $manager->getPublishedUrl($asset));

		$plain = new class () extends TComponent implements IAsset {
			public function getAssetFilePath()
			{
				return '/virtual/component/data.txt';
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
		self::assertStringEndsWith('/data.txt', $manager->getPublishedUrl($plain), 'Without behaviors, the file path is the publish path.');
	}

	public function testPlainIAssetDirectoryIsPublishedFileByFile(): void
	{
		$this->writeSource('plain/a.txt', 'alpha');
		$this->writeSource('plain/sub/b.txt', 'beta');
		$source = $this->srcDir . DIRECTORY_SEPARATOR . 'plain' . DIRECTORY_SEPARATOR;
		$asset = new class ($source) implements IAsset {
			public array $published = [];

			public function __construct(private string $path)
			{
			}

			public function getAssetFilePath()
			{
				return $this->path;
			}

			public function getAssetOriginalFilePath()
			{
				return $this->path;
			}

			public function getAssetModificationDate()
			{
				return filemtime($this->path);
			}

			public function publish(string $dst): ?bool
			{
				$this->published[] = $dst;
				return true;
			}
		};
		$manager = $this->newManager();

		$url = $manager->publish($asset);
		$dir = $this->urlToPath($url);

		self::assertSame($manager->getPublishedUrl($this->srcDir . '/plain'), $url);
		self::assertSame('alpha', file_get_contents($dir . '/a.txt'));
		self::assertSame('beta', file_get_contents($dir . '/sub/b.txt'));
		self::assertTrue(is_file($dir . DIRECTORY_SEPARATOR . static::marker(rtrim($source, DIRECTORY_SEPARATOR))));
		self::assertSame([], $asset->published, 'A source directory is copied, not published by the asset.');
		self::assertSame($asset, $manager->getPublishedAssets()[rtrim($source, DIRECTORY_SEPARATOR)]);
		self::assertInstanceOf(TFileAsset::class, $manager->getPublishedAssets()[$this->srcDir . DIRECTORY_SEPARATOR . 'plain' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt']);
	}

	public function testDestinationRouteUsesTheRenamedFileNameAndIsNotCached(): void
	{
		$asset = new TGeneratedAsset('/virtual/dest/data.txt');
		$asset->content = 'destined';
		$asset->attachBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.out']);
		$manager = $this->newManager();
		$destination = $this->assetDir . DIRECTORY_SEPARATOR . 'fixed' . DIRECTORY_SEPARATOR . 'ignored.txt';

		$url = $manager->routeAsset($asset, false, [], $destination);

		$dst = $this->assetDir . DIRECTORY_SEPARATOR . 'fixed' . DIRECTORY_SEPARATOR . 'data.txt.out';
		self::assertSame(static::BASE_URL . '/fixed/data.txt.out', $url);
		self::assertSame('destined', file_get_contents($dst));
		self::assertFalse(is_file($destination), 'The destination name is replaced by the publish name.');
		self::assertSame($dst, $asset->getPublishedPath());
		self::assertSame($url, $asset->getPublishedUrl());
		self::assertSame([], $manager->getPublished(), 'A destination route is not cached.');
		self::assertSame($asset, $manager->getPublishedAssets()['/virtual/dest/data.txt']);
	}

	public function testDestinationRouteOutsideTheBasePathHasNoUrl(): void
	{
		$asset = new TGeneratedAsset('/virtual/dest/outside.txt');
		$asset->content = 'outside';
		$manager = $this->newManager(['AppendTimestamp' => true]);
		$destination = $this->tempDir . DIRECTORY_SEPARATOR . 'elsewhere' . DIRECTORY_SEPARATOR . 'outside.txt';

		$url = $manager->routeAsset($asset, false, [], $destination);

		self::assertSame('', $url, 'A destination outside the BasePath has no URL, and no timestamp.');
		self::assertSame('outside', file_get_contents($destination));
		self::assertSame($destination, $asset->getPublishedPath());
		self::assertSame('', $asset->getPublishedUrl());
	}

	public function testTAssetManagerPublishRoutesAnIAsset(): void
	{
		$manager = $this->initManager(new class () extends TPublishingManager {
			public function publishAsTAssetManager($asset)
			{
				return TAssetManager::publish($asset);
			}
		});
		$asset = new TGeneratedAsset('/virtual/parent/data.txt');
		$asset->content = 'routed';
		$asset->attachBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.min']);

		$url = $manager->publishAsTAssetManager($asset);

		self::assertStringEndsWith('/data.txt.min', $url, 'The IAsset takes the asset route, which renames it.');
		self::assertSame('routed', file_get_contents($this->urlToPath($url)));
		self::assertSame($asset, $manager->getPublishedAssets()['/virtual/parent/data.txt']);
		self::assertSame($url, $asset->getPublishedUrl());
	}

	// ---------------------------------------------------------------------------
	// Class discovery
	// ---------------------------------------------------------------------------

	public function testOnDiscoverClassHandlerChoosingTheDefaultClassOverridesTheImageClass(): void
	{
		$manager = $this->newManager();
		$image = $this->writeImage('picture.png');
		$classes = [];
		$manager->attachEventHandler('onDiscoverClass', function ($sender, $param) use (&$classes) {
			$classes[] = $param->getClass();
			$param->setClass($param->getClass());
		});

		$asset = $manager->ensureAsset($image);

		self::assertSame([TFileAsset::class], $classes);
		self::assertNotInstanceOf(TImageAsset::class, $asset, 'A class set by a handler is used even when it is the default.');
		self::assertInstanceOf(TFileAsset::class, $asset);

		$filepath = $image;
		self::assertSame(TAsset::class, $manager->onDiscoverClass(TAsset::class, $filepath));
		self::assertSame($image, $filepath);
	}

	public function testOnDiscoverClassWithoutAClassSetUsesTheDefaults(): void
	{
		$manager = $this->newManager(['DefaultImageAssetClass' => TGeneratedAsset::class]);
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setFilePath(strtolower($param->getFilePath())));

		$png = '/Virtual/Picture.PNG';
		self::assertSame(TGeneratedAsset::class, $manager->onDiscoverClass(TFileAsset::class, $png), 'An image extension selects the DefaultImageAssetClass.');
		self::assertSame('/virtual/picture.png', $png, 'The rewritten file path is returned.');

		$txt = '/virtual/readme.txt';
		self::assertSame(TAsset::class, $manager->onDiscoverClass(TAsset::class, $txt), 'Another file keeps the given class.');
	}
}
