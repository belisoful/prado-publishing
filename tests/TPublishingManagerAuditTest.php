<?php

/**
 * TPublishingManagerAuditTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplicationMode;
use Prado\Web\Assets\Behaviors\TAssetBlocker;
use Prado\Web\Assets\Behaviors\TAssetJPEGize;
use Prado\Web\Assets\Behaviors\TAssetPNGize;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TGDFAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Assets\TTarAsset;
use Prado\Web\TAssetManager;
use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TPartialFileAsset;
use Prado\Web\Tests\Fixtures\TRecordingAtomicManager;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\Fixtures\TThrowingAssetBehavior;
use Prado\Web\Tests\Fixtures\TUppercaseAssetBehavior;
use Prado\Web\TPublishingManager;
use Prado\Test\Unit\Harness\IO\TarTestHelper;

/**
 * TPublishingManagerAuditTest class.
 *
 * Regression tests of the TPublishingManager audit fixes: the asset URL cache keyed by
 * asset class apart from the published list, modification checks of asset objects in
 * performance mode, the tar archive cache key, resolved paths in class discovery and the
 * BasePath guard, the TAssetManager fallback of the published path and URL, symbolic
 * links and renames in published directories, the copy callback source, cleanup of a
 * failed write, atomic directory files, PublishOriginalNames, linking pass-through
 * assets only, renamed files colliding with another file, replacing a stale link, failed
 * publishes, completion markers of cancelled publishes, the asset object cache, the
 * BasePath guard of asset objects, and processed assets written through a temporary file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPublishingManagerAuditTest extends PublishingTestCase
{
	/** The alias of the per-test NoAsset directory. */
	public const NOASSET_ALIAS = 'PublishingAuditNoAsset';

	/** @var string the application mode before the test. */
	private string $_mode;

	protected function setUp(): void
	{
		parent::setUp();
		$this->_mode = static::application()->getMode();
		static::application()->setMode(TApplicationMode::Debug);
		TUppercaseAssetBehavior::$processed = [];
		TThrowingAssetBehavior::$failOn = null;
		TThrowingAssetBehavior::$processed = [];
		TAssetPathFilterBehavior::$journal = [];
	}

	protected function tearDown(): void
	{
		static::application()->setMode($this->_mode);
		TThrowingAssetBehavior::$failOn = null;
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
		]), time() - 100);
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

	/**
	 * @param string $dir the directory.
	 * @return string[] the sorted file names of the directory, without the completion markers.
	 */
	protected static function publishedNames(string $dir): array
	{
		$names = array_values(array_filter(scandir($dir), fn ($name) => $name !== '.' && $name !== '..' && !str_starts_with($name, TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX)));
		sort($names);
		return $names;
	}

	/**
	 * Attaches a PDF blocker, with a NoAsset placeholder when a file name is given.
	 * @param ?string $noAssetFile the placeholder file name, written with 'unavailable'.
	 */
	protected function attachPdfBlocker(?string $noAssetFile = null): void
	{
		$config = ['class' => TAssetBlocker::class, 'BlockedFiles' => '/\.pdf$/'];
		if ($noAssetFile !== null) {
			mkdir($noAssetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'noasset');
			file_put_contents($noAssetDir . DIRECTORY_SEPARATOR . $noAssetFile, 'unavailable');
			Prado::setPathOfAlias(static::NOASSET_ALIAS, $noAssetDir);
			$config += ['NoAssetPath' => static::NOASSET_ALIAS, 'NoAssetFile' => $noAssetFile];
		}
		$this->attachClassBehavior('blocker', $config, TAsset::class);
	}

	// ---------------------------------------------------------------------------
	// The asset URL cache and the published list
	// ---------------------------------------------------------------------------

	public function testAnAssetObjectDoesNotTakeTheCacheOfAStringPathOfAnotherClass(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.image'], TImageAsset::class);
		$png = $this->writeImage('images/logo.png');
		$manager = $this->newManager();

		$objectUrl = $manager->publish(new TFileAsset($png));
		$stringUrl = $manager->publish($png);

		self::assertStringEndsWith('/logo.png', $objectUrl, 'The TFileAsset object is not renamed.');
		self::assertStringEndsWith('/logo.png.image', $stringUrl, 'The string path instances a TImageAsset and publishes separately.');
		self::assertInstanceOf(TImageAsset::class, $manager->getPublishedAssets()[$png]);
		self::assertTrue(is_file($this->urlToPath($objectUrl)));
		self::assertTrue(is_file($this->urlToPath($stringUrl)));
		self::assertSame($stringUrl, $manager->publish($png), 'The string path is then cached.');
		self::assertSame($objectUrl, $manager->publish(new TFileAsset($png)), 'The asset object is cached by its class.');
		self::assertSame([$png => $stringUrl], $manager->getPublished(), 'The string path owns the published list entry.');
	}

	public function testAStringPathKeepsItsCacheWhenAnAssetObjectOfAnotherClassPublishes(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.image'], TImageAsset::class);
		$png = $this->writeImage('images/logo.png');
		$manager = $this->newManager();

		$stringUrl = $manager->publish($png);
		$objectUrl = $manager->publish(new TFileAsset($png));

		self::assertStringEndsWith('/logo.png.image', $stringUrl);
		self::assertStringEndsWith('/logo.png', $objectUrl);
		self::assertSame($stringUrl, $manager->publish($png), 'The string path is not taken by the asset object.');
		self::assertSame([$png => $stringUrl], $manager->getPublished());
	}

	public function testASeededPublishedEntryIsKeptAndReturned(): void
	{
		$source = $this->writeSource('seeded.js', 'js');
		$manager = $this->initManager(new class () extends TPublishingManager {
			public function seed(array $values): void
			{
				$this->setPublished($values);
			}
		});
		$manager->seed([$source => '/assets/seeded/seeded.js']);

		$objectUrl = $manager->publish(new TFileAsset($source));

		self::assertNotSame('/assets/seeded/seeded.js', $objectUrl);
		self::assertSame([$source => '/assets/seeded/seeded.js'], $manager->getPublished(), 'An asset object does not replace a seeded entry.');
		self::assertSame('/assets/seeded/seeded.js', $manager->publish($source), 'The seeded entry is returned for the string path.');
	}

	public function testAnAssetObjectIsListedInThePublishedList(): void
	{
		$asset = new TGeneratedAsset('/virtual/listed/data.json');
		$renamed = new TGeneratedAsset('/virtual/listed/renamed.json');
		$renamed->attachBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v2']);
		$manager = $this->newManager();

		$url = $manager->publish($asset);
		$renamedUrl = $manager->publish($renamed);

		self::assertSame([
			'/virtual/listed/data.json' => $url,
			'/virtual/listed/renamed.json>/virtual/listed/renamed.json.v2' => $renamedUrl,
		], $manager->getPublished(), 'An asset object is listed under its key.');
		self::assertSame($url, $manager->publish(new TGeneratedAsset('/virtual/listed/data.json')));
		self::assertCount(2, $manager->getPublished());
	}

	public function testGetPublishedOfAStringPathHasOnlyThePath(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.min'], TAsset::class);
		$source = $this->writeSource('app.js', 'js');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertStringEndsWith('/app.js.min', $url);
		self::assertSame([$source => $url], $manager->getPublished());
	}

	// ---------------------------------------------------------------------------
	// Modification checks in performance mode
	// ---------------------------------------------------------------------------

	public function testPerformanceModeRepublishesAnAssetObjectNewerThanItsPublishedFile(): void
	{
		$source = $this->writeSource('perf/app.js', 'one', time() - 100);
		$dst = $this->urlToPath($this->newManager()->publish($source));
		static::application()->setMode(TApplicationMode::Performance);
		file_put_contents($source, 'two');
		touch($source, time() + 100);

		$this->newManager()->publish($source);
		self::assertSame('one', file_get_contents($dst), 'A string path is not checked in performance mode, as in TAssetManager.');

		$this->newManager()->publish(new TFileAsset($source));
		self::assertSame('two', file_get_contents($dst), 'An asset object newer than its published file is republished.');
	}

	public function testPerformanceModeKeepsAnAssetObjectNotNewerThanItsPublishedFile(): void
	{
		$asset = new TGeneratedAsset('/virtual/perf/data.txt');
		$asset->modified = time() - 100;
		$dst = $this->urlToPath($this->newManager()->publish($asset));
		static::application()->setMode(TApplicationMode::Performance);
		file_put_contents($dst, 'published');

		$this->newManager()->publish($asset);

		self::assertSame('published', file_get_contents($dst));
		self::assertCount(1, $asset->writtenTo);
	}

	public function testPublishVirtualChecksTheModificationOfAnAssetObject(): void
	{
		$asset = new TGeneratedAsset('/virtual/perf/virtual.txt');
		$asset->modified = time() - 100;
		$dst = $this->urlToPath($this->newManager()->publish($asset));
		static::application()->setMode(TApplicationMode::Performance);
		$asset->content = 'regenerated';
		$asset->modified = time() + 100;
		$manager = $this->newManager();

		$url = (new \ReflectionMethod($manager, 'publishVirtual'))->invoke($manager, $asset);

		self::assertSame($dst, $this->urlToPath($url));
		self::assertSame('regenerated', file_get_contents($dst));
	}

	// ---------------------------------------------------------------------------
	// publishTarFile
	// ---------------------------------------------------------------------------

	public function testPublishAndPublishTarFileOfAnArchiveDoNotCollide(): void
	{
		$tar = $this->writeTar();
		$manager = $this->newManager();

		$fileUrl = $manager->publish($tar);
		$dirUrl = $manager->publishTarFile($tar);

		self::assertStringEndsWith('/bundle.tar', $fileUrl, 'The archive is published as a file.');
		self::assertNotSame($fileUrl, $dirUrl);
		self::assertSame('body{color:red}', file_get_contents($this->urlToPath($dirUrl) . '/style.css'), 'The archive is extracted.');
		self::assertSame([$tar => $fileUrl, 'tar:' . $tar => $dirUrl], $manager->getPublished());

		$reverse = $this->newManager();
		self::assertSame($dirUrl, $reverse->publishTarFile($tar));
		self::assertSame($fileUrl, $reverse->publish($tar), 'The archive file is not taken by the extraction.');
	}

	// ---------------------------------------------------------------------------
	// ensureAsset and the BasePath guard
	// ---------------------------------------------------------------------------

	public function testEnsureAssetDiscoversTheResolvedPathOfAnExistingFile(): void
	{
		$real = $this->writeSource('real.txt', 'real');
		$link = $this->createSymlink($real, $this->srcDir . '/alias.txt');
		$manager = $this->newManager();
		$discovered = [];
		$manager->attachEventHandler('onDiscoverClass', function ($sender, $param) use (&$discovered) {
			$discovered[] = $param->getFilePath();
		});

		$manager->ensureAsset($link);
		$manager->ensureAsset($this->srcDir . '/missing/../absent.txt');

		self::assertSame([$real, $this->srcDir . '/absent.txt'], $discovered, 'An existing path is resolved; a missing path is normalized.');
	}

	public function testEnsureAssetComparesTheResolvedBasePath(): void
	{
		$linkedRoot = $this->createSymlink($this->tempDir, $this->srcDir . '/linked-root');
		$manager = $this->initManager(new class () extends TPublishingManager {
			public function useBasePath(string $path): void
			{
				$this->setBasePathDirect($path);
			}
		});
		$manager->useBasePath($linkedRoot . '/assets');

		foreach ([$this->assetDir, $linkedRoot . '/assets', $this->tempDir, $linkedRoot] as $path) {
			try {
				$manager->ensureAsset($path);
				self::fail("$path is the BasePath or a parent of it once resolved.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('publishingmanager_filepath_invalid', $e->getErrorCode());
			}
		}
		self::assertInstanceOf(TFileAsset::class, $manager->ensureAsset($this->writeSource('allowed.txt')));
	}

	public function testEnsureAssetRechecksThePathRewrittenByDiscovery(): void
	{
		$manager = $this->newManager();
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setFilePath($this->assetDir));

		try {
			$manager->ensureAsset($this->writeSource('innocent.txt'));
			self::fail('A path rewritten to the BasePath is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('publishingmanager_filepath_invalid', $e->getErrorCode());
		}
	}

	public function testEnsureAssetWithoutABasePathDoesNotGuard(): void
	{
		$manager = new TPublishingManager();
		$source = $this->writeSource('unguarded.txt');

		self::assertSame($source, $manager->ensureAsset($source)->getAssetFilePath());
	}

	public function testEnsureAssetRejectsAnAbstractAssetClass(): void
	{
		$manager = $this->newManager();
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setClass(TAsset::class));

		try {
			$manager->ensureAsset($this->writeSource('abstract.txt'));
			self::fail('An abstract asset class cannot be instanced.');
		} catch (TInvalidDataTypeException $e) {
			self::assertSame('publishingmanager_invalid_class', $e->getErrorCode());
		}
	}

	public function testPublishedPathAndUrlFallBackToTAssetManagerForAnyAssetException(): void
	{
		$source = $this->writeSource('fallback/data.txt');
		$plain = new TAssetManager();
		$plain->setBasePath(static::ASSETS_ALIAS);
		$plain->setBaseUrl(static::BASE_URL);
		$plain->init(null);
		$manager = $this->newManager();
		$handler = fn ($sender, $param) => $param->setClass(\stdClass::class);
		$manager->attachEventHandler('onDiscoverClass', $handler);

		self::assertSame($plain->getPublishedPath($source), $manager->getPublishedPath($source), 'A non-asset class falls back to TAssetManager.');
		self::assertSame($plain->getPublishedUrl($source), $manager->getPublishedUrl($source));

		$manager->detachEventHandler('onDiscoverClass', $handler);
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setClass(''));
		self::assertSame($plain->getPublishedUrl($source), $manager->getPublishedUrl($source), 'A cleared class falls back to TAssetManager.');
	}

	// ---------------------------------------------------------------------------
	// Directory files: symbolic links, renames, and callbacks
	// ---------------------------------------------------------------------------

	public function testASymbolicLinkInADirectoryIsPublishedUnderItsNameWithTheRename(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.suffix'], TAsset::class);
		$main = $this->writeSource('site/main.css', 'main');
		$this->createSymlink($main, $this->srcDir . '/site/link.css');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/site'));

		self::assertSame(['link.css.suffix', 'main.css.suffix'], static::publishedNames($dir));
		self::assertSame('main', file_get_contents($dir . '/link.css.suffix'));
		self::assertFalse(is_link($dir . '/link.css.suffix'), 'The link is published as a file.');
	}

	public function testASymbolicLinkInADirectoryIsConvertedUnderItsName(): void
	{
		$this->attachClassBehavior('jpegize', TAssetJPEGize::class, TImageAsset::class);
		$png = $this->writeImage('pictures/photo.png');
		$this->createSymlink($png, $this->srcDir . '/pictures/link.png');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/pictures'));

		self::assertSame(['link.jpg', 'photo.jpg'], static::publishedNames($dir));
		self::assertSame(IMAGETYPE_JPEG, getimagesize($dir . '/link.jpg')[2]);
	}

	public function testASubstitutedSourceInADirectoryTakesThePublishName(): void
	{
		$this->attachPdfBlocker('unavailable.txt');
		$report = $this->writeSource('docs/report.pdf', 'secret');
		$this->createSymlink($report, $this->srcDir . '/docs/alias.txt');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/docs'));

		self::assertSame(['unavailable.txt'], static::publishedNames($dir), 'The blocked file and the link to it publish the placeholder.');
		self::assertSame('unavailable', file_get_contents($dir . '/unavailable.txt'));
	}

	public function testRenamedFileName(): void
	{
		self::assertSame('link.css', TPublishingManager::renamedFileName('link.css', 'main.css', 'main.css'), 'An unrenamed asset keeps the destination name.');
		self::assertSame('main.1a2b.css', TPublishingManager::renamedFileName('main.css', 'main.css', 'main.1a2b.css'), 'The destination named as the source takes the publish name.');
		self::assertSame('link.css.gz', TPublishingManager::renamedFileName('link.css', 'main.css', 'main.css.gz'), 'An appended extension is appended.');
		self::assertSame('link.1a2b.css', TPublishingManager::renamedFileName('link.css', 'main.css', 'main.1a2b.css'), 'A fingerprint is inserted.');
		self::assertSame('link.jpg', TPublishingManager::renamedFileName('link.png', 'photo.png', 'photo.jpg'), 'A converted extension is replaced.');
		self::assertSame('main.1a2b.css', TPublishingManager::renamedFileName('link.txt', 'main.css', 'main.1a2b.css'), 'A destination not ending with the renamed tail takes the publish name.');
	}

	public function testCopyCallbacksOfADirectoryFileReceiveTheDirectoryEntry(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.x'], TAsset::class);
		$main = $this->writeSource('entries/main.css', 'main');
		$link = $this->createSymlink($main, $this->srcDir . '/entries/link.css');
		$calls = [];
		$record = function ($src, $dst) use (&$calls) {
			$calls[basename($dst)][] = $src;
			return true;
		};

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/entries', ['beforeCopy' => $record, 'afterCopy' => $record]));

		ksort($calls);
		self::assertSame(['link.css.x' => [$link, $link], 'main.css.x' => [$main, $main]], $calls, 'The link, not its target, is given to the callbacks.');
		self::assertTrue(is_file($dir . '/link.css.x'));
	}

	// ---------------------------------------------------------------------------
	// Failed writes and atomic directory files
	// ---------------------------------------------------------------------------

	public function testANonAtomicWriteThatThrowsLeavesNoFile(): void
	{
		TThrowingAssetBehavior::$failOn = '/./';
		$asset = new TFileAsset($this->writeSource('failing.txt', 'content'));
		$asset->attachBehavior('fail', TThrowingAssetBehavior::class);
		$manager = $this->newManager(['Atomic' => false]);
		$dst = $manager->getPublishedPath($asset);

		try {
			$manager->publish($asset);
			self::fail('The processing exception is rethrown.');
		} catch (\RuntimeException $e) {
			self::assertSame('Processing failed.', $e->getMessage());
		}
		self::assertCount(1, TThrowingAssetBehavior::$processed);
		self::assertStringStartsWith('tmp-', basename(TThrowingAssetBehavior::$processed[0]), 'A processed file is written to a temporary file.');
		self::assertFalse(is_file($dst), 'The partially processed file is not published.');
		self::assertSame([], glob(dirname($dst) . '/tmp-*'), 'No temporary file is left.');
	}

	public function testDirectoryFilesAreWrittenAtomically(): void
	{
		$this->attachClassBehavior('fail', TThrowingAssetBehavior::class, TAsset::class);
		TThrowingAssetBehavior::$failOn = '/bad\.txt$/';
		$this->writeSource('atomic/bad.txt', 'bad');
		$manager = $this->newManager();
		$dir = $manager->getPublishedPath($this->srcDir . '/atomic');

		try {
			$manager->publish($this->srcDir . '/atomic');
			self::fail('The processing exception is rethrown.');
		} catch (\RuntimeException $e) {
			self::assertSame('Processing failed.', $e->getMessage());
		}
		self::assertCount(1, TThrowingAssetBehavior::$processed);
		self::assertStringStartsWith('tmp-', basename(TThrowingAssetBehavior::$processed[0]), 'The directory file is processed in a temporary file.');
		self::assertFalse(is_file($dir . '/bad.txt'), 'No partial file is published.');
		self::assertSame([], glob($dir . '/tmp-*'), 'No temporary file is left.');

		TThrowingAssetBehavior::$failOn = null;
		self::assertSame($manager->getPublishedUrl($this->srcDir . '/atomic'), $this->newManager()->publish($this->srcDir . '/atomic'));
		self::assertSame('bad', file_get_contents($dir . '/bad.txt'), 'A republish writes the file.');
	}

	public function testNonAtomicProcessedDirectoryFilesAreWrittenThroughATemporaryFile(): void
	{
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$this->writeSource('inplace/a.txt', 'a');

		$dir = $this->urlToPath($this->newManager(['Atomic' => false])->publish($this->srcDir . '/inplace'));

		$files = array_values(array_filter(TUppercaseAssetBehavior::$processed, 'is_string'));
		self::assertNotContains($dir . DIRECTORY_SEPARATOR . 'a.txt', $files, 'The file is not processed in place.');
		self::assertCount(1, preg_grep('/' . preg_quote(DIRECTORY_SEPARATOR, '/') . 'tmp-[^' . preg_quote(DIRECTORY_SEPARATOR, '/') . ']*a\.txt$/', $files));
		self::assertSame('A', file_get_contents($dir . '/a.txt'));
	}

	public function testNonAtomicPassThroughFilesAreCopiedInPlace(): void
	{
		$this->writeSource('inplace/a.txt', 'a');
		$manager = $this->initManager(new TRecordingAtomicManager(), ['Atomic' => false]);

		$dir = $this->urlToPath($manager->publish($this->srcDir . '/inplace'));
		$url = $manager->publish($this->writeSource('single.txt', 'single'));

		self::assertSame([], $manager->atomicWrites);
		self::assertSame('a', file_get_contents($dir . '/a.txt'));
		self::assertSame('single', file_get_contents($this->urlToPath($url)));
	}

	public function testTheAtomicOptionOfADirectoryPublishReachesItsFiles(): void
	{
		$this->writeSource('off/a.txt', 'a');
		$this->writeSource('on/b.txt', 'b');
		$atomicManager = $this->initManager(new TRecordingAtomicManager(), ['Atomic' => true]);
		$plainManager = $this->initManager(new TRecordingAtomicManager(), ['Atomic' => false]);

		$atomicManager->publish($this->srcDir . '/off', ['atomic' => false]);
		$plainManager->publish($this->srcDir . '/on', ['atomic' => true]);

		self::assertSame([], $atomicManager->atomicWrites, 'The atomic option off copies the files in place.');
		self::assertSame(['b.txt'], $plainManager->atomicWrites, 'The atomic option on writes the files atomically.');
	}

	// ---------------------------------------------------------------------------
	// PublishOriginalNames
	// ---------------------------------------------------------------------------

	public function testPublishOriginalNamesProperty(): void
	{
		$manager = new TPublishingManager();
		self::assertFalse($manager->getPublishOriginalNames());

		$manager->setPublishOriginalNames('true');
		self::assertTrue($manager->getPublishOriginalNames());
		$manager->setPublishOriginalNames(false);
		self::assertFalse($manager->getPublishOriginalNames());
	}

	public function testPublishOriginalNamesPublishesARenamedFileUnprocessedUnderItsName(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v2'], TAsset::class);
		$this->attachClassBehavior('upper', TUppercaseAssetBehavior::class, TAsset::class);
		$main = $this->writeSource('originals/app.js', 'code');
		$this->createSymlink($main, $this->srcDir . '/originals/link.js');
		$copied = [];
		$afterCopy = function ($src, $dst) use (&$copied) {
			$copied[] = basename($dst);
		};

		$dir = $this->urlToPath($this->newManager(['PublishOriginalNames' => true])->publish($this->srcDir . '/originals', ['afterCopy' => $afterCopy]));

		self::assertSame(['app.js', 'app.js.v2', 'link.js', 'link.js.v2'], static::publishedNames($dir));
		self::assertSame('CODE', file_get_contents($dir . '/app.js.v2'));
		self::assertSame('code', file_get_contents($dir . '/app.js'), 'The original name is the unprocessed source.');
		self::assertSame('code', file_get_contents($dir . '/link.js'), 'A link to the source keeps its name too.');
		sort($copied);
		self::assertSame(['app.js', 'app.js.v2', 'link.js', 'link.js.v2'], $copied);
	}

	public function testWithoutPublishOriginalNamesOnlyTheRenamedFileIsPublished(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v2'], TAsset::class);
		$this->writeSource('renamed/app.js', 'code');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/renamed'));

		self::assertSame(['app.js.v2'], static::publishedNames($dir));
	}

	public function testPublishOriginalNamesDoesNotCopyAnUnrenamedFileTwice(): void
	{
		$this->writeSource('plain/app.js', 'code');
		$copied = [];
		$afterCopy = function ($src, $dst) use (&$copied) {
			$copied[] = basename($dst);
		};

		$dir = $this->urlToPath($this->newManager(['PublishOriginalNames' => true])->publish($this->srcDir . '/plain', ['afterCopy' => $afterCopy]));

		self::assertSame(['app.js'], static::publishedNames($dir));
		self::assertSame(['app.js'], $copied);
	}

	public function testPublishOriginalNamesSkipsASubstitutedSource(): void
	{
		$this->attachPdfBlocker('unavailable.txt');
		$this->writeSource('substituted/report.pdf', 'secret');

		$dir = $this->urlToPath($this->newManager(['PublishOriginalNames' => true])->publish($this->srcDir . '/substituted'));

		self::assertSame(['unavailable.txt'], static::publishedNames($dir), 'The blocked source is not published under its own name.');
	}

	public function testPublishOriginalNamesSkipsABlockedFile(): void
	{
		$this->attachPdfBlocker();
		$this->writeSource('blocked/report.pdf', 'secret');
		$this->writeSource('blocked/style.css', 'css');

		$dir = $this->urlToPath($this->newManager(['PublishOriginalNames' => true])->publish($this->srcDir . '/blocked'));

		self::assertSame(['style.css'], static::publishedNames($dir));
	}

	// ---------------------------------------------------------------------------
	// Linking pass-through assets
	// ---------------------------------------------------------------------------

	public function testLinkAssetsDoesNotLinkAnAssetConvertingItsSource(): void
	{
		$this->createSymlink($this->writeSource('probe-target'), $this->srcDir . '/probe-link');
		$font = $this->writeSource('fonts/teletext.gdf', file_get_contents(static::dataDir() . '/Teletext_6x10_BE.gdf'));

		$url = $this->newManager(['LinkAssets' => true])->publish(new TGDFAsset($font));

		self::assertTrue(is_file($this->urlToPath($url)));
		self::assertFalse(is_link($this->urlToPath($url)), 'A GDF font is converted, so it is written.');
	}

	public function testLinkAssetsDoesNotLinkAnAssetWrittenByABehavior(): void
	{
		$this->createSymlink($this->writeSource('probe-target'), $this->srcDir . '/probe-link');
		$written = new TFileAsset($this->writeSource('written.txt', 'written'));
		$written->attachBehavior('writer', TAssetPathFilterBehavior::class);
		$linked = new TFileAsset($this->writeSource('linked.txt', 'linked'));
		$linked->attachBehavior('writer', TAssetPathFilterBehavior::class);
		$linked->disableBehavior('writer');
		$manager = $this->newManager(['LinkAssets' => true]);

		$writtenUrl = $manager->publish($written);
		$linkedUrl = $manager->publish($linked);

		self::assertFalse(is_link($this->urlToPath($writtenUrl)), 'An enabled dyWriteAsset behavior may change the file.');
		self::assertSame('written', file_get_contents($this->urlToPath($writtenUrl)));
		self::assertTrue(is_link($this->urlToPath($linkedUrl)), 'A disabled dyWriteAsset behavior does not prevent linking.');
		self::assertSame('linked', file_get_contents($this->urlToPath($linkedUrl)));
	}

	// ---------------------------------------------------------------------------
	// Renamed files colliding with another file
	// ---------------------------------------------------------------------------

	public function testARenamedFileCollidingWithAnotherFileTakesItsOwnNameAndTheNewExtension(): void
	{
		$this->attachClassBehavior('png', ['class' => TAssetPNGize::class], TImageAsset::class);
		$jpg = $this->writeImage('collide/logo.jpg', 40, 20);
		$png = $this->writeImage('collide/logo.png', 99, 77);
		$manager = $this->newManager();

		$pngUrl = $manager->publish($png);
		$jpgUrl = $manager->publish($jpg);

		self::assertSame('logo.png', basename($pngUrl));
		self::assertSame('logo.jpg.png', basename($jpgUrl));
		self::assertSame($jpgUrl, $manager->getPublishedUrl($jpg));
		self::assertSame([99, 77], array_slice(getimagesize($this->urlToPath($pngUrl)), 0, 2), 'The real file keeps its name and content.');
		self::assertSame([40, 20], array_slice(getimagesize($this->urlToPath($jpgUrl)), 0, 2));
	}

	public function testARenamedDirectoryFileCollidingWithAnotherFileTakesItsOwnNameAndTheNewExtension(): void
	{
		$this->attachClassBehavior('png', ['class' => TAssetPNGize::class], TImageAsset::class);
		$this->writeImage('collidedir/logo.png', 99, 77);
		$this->writeImage('collidedir/logo.jpg', 40, 20);
		$this->writeImage('collidedir/icon.jpg', 10, 10);

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/collidedir'));

		self::assertSame(['icon.png', 'logo.jpg.png', 'logo.png'], static::publishedNames($dir), 'A rename without a collision keeps its name.');
		self::assertSame([99, 77], array_slice(getimagesize($dir . '/logo.png'), 0, 2));
		self::assertSame([40, 20], array_slice(getimagesize($dir . '/logo.jpg.png'), 0, 2));
	}

	public function testCollisionFreeName(): void
	{
		$this->writeSource('names/logo.png');
		$jpg = $this->writeSource('names/logo.jpg');
		$dir = $this->srcDir . DIRECTORY_SEPARATOR . 'names';

		self::assertSame('logo.jpg.png', TPublishingManager::collisionFreeName($dir, 'logo.png', 'logo.jpg', $jpg));
		self::assertSame('logo.webp', TPublishingManager::collisionFreeName($dir, 'logo.webp', 'logo.jpg', $jpg), 'No other file has the name.');
		self::assertSame('logo.png', TPublishingManager::collisionFreeName($dir, 'logo.png', 'logo.png'), 'The file is not renamed.');
		self::assertSame('logo.jpg', TPublishingManager::collisionFreeName($dir, 'logo.jpg', 'other.jpg', $jpg), 'The other file is the source itself.');
	}

	// ---------------------------------------------------------------------------
	// Stale links, failed publishes, and processed writes
	// ---------------------------------------------------------------------------

	public function testALinkIsReplacedWhenTheAssetGainsProcessing(): void
	{
		$this->createSymlink($this->writeSource('probe-target'), $this->srcDir . '/probe-link');
		$source = $this->writeSource('stale.txt', 'raw');
		$dst = $this->urlToPath($this->newManager(['LinkAssets' => true])->publish($source));
		self::assertTrue(is_link($dst));
		$process = fn ($sender, $param) => file_put_contents($param->getFilePath(), 'PROCESSED');

		foreach (['atomic' => [true, true], 'non-atomic forced' => [false, ['forceCopy' => true]]] as $case => [$atomic, $flag]) {
			$asset = new TFileAsset($source);
			$asset->attachEventHandler('onProcessAsset', $process);
			$this->newManager(['LinkAssets' => true, 'Atomic' => $atomic])->publish($asset, $flag);

			self::assertFalse(is_link($dst), "The link is replaced, $case.");
			self::assertSame('PROCESSED', file_get_contents($dst), "The processed file is published, $case.");
			self::assertSame('raw', file_get_contents($source), "The source is not written through the link, $case.");
			unlink($dst);
			symlink($source, $dst);
		}
	}

	public function testAFailedPublishPublishesNothing(): void
	{
		$source = $this->writeSource('partial.txt', 'full content');
		foreach ([true, false] as $atomic) {
			$manager = $this->newManager(['Atomic' => $atomic]);
			$dst = $manager->getPublishedPath(new TPartialFileAsset($source));

			$manager->publish(new TPartialFileAsset($source));

			self::assertFalse(is_file($dst), 'No partial file is published, Atomic ' . var_export($atomic, true) . '.');
			self::assertSame([], glob(dirname($dst) . '/tmp-*'), 'No temporary file is left.');
		}
	}

	public function testAPublishReturningFalseIsNotMovedIntoPlace(): void
	{
		$asset = new TFileAsset($this->writeSource('vetoed.txt', 'content'));
		$veto = new TAssetPathFilterBehavior();
		$veto->writeFilter = function ($return, $dst) {
			file_put_contents($dst, 'UNPROCESSED');
			return false;
		};
		$asset->attachBehavior('veto', $veto);
		$manager = $this->newManager(['Atomic' => false]);

		$url = $manager->publish($asset);

		self::assertFalse(is_file($this->urlToPath($url)));
		self::assertSame([], glob(dirname($this->urlToPath($url)) . '/tmp-*'));
	}

	// ---------------------------------------------------------------------------
	// Completion markers of cancelled publishes
	// ---------------------------------------------------------------------------

	public function testACancelledTarPublishWritesNoMarker(): void
	{
		$tar = $this->writeTar('cancelled/bundle.tar');
		$asset = new TTarAsset($tar);
		$veto = new TAssetPathFilterBehavior();
		$veto->writeFilter = fn ($return, $dst) => false;
		$asset->attachBehavior('veto', $veto);
		$dir = $this->urlToPath($this->newManager()->publish($asset));
		mkdir($dir);

		$this->newManager()->publish($asset);

		self::assertSame([], static::publishedNames($dir));
		self::assertSame([], glob($dir . DIRECTORY_SEPARATOR . TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX . '*'), 'A cancelled extraction is not marked.');

		static::application()->setMode(TApplicationMode::Performance);
		$this->newManager()->publishTarFile($tar);
		self::assertTrue(is_file($dir . '/style.css'), 'A later publish extracts the archive.');
	}

	public function testACancelledGeneratedDirectoryWritesNoMarker(): void
	{
		$asset = new class ('/virtual/cancelled/site') extends TGeneratedAsset {
			protected function isDirectory(string $path): bool
			{
				return true;
			}

			public function publish(string $dst): ?bool
			{
				$this->writtenTo[] = $dst;
				return false;
			}
		};

		$dir = $this->urlToPath($this->newManager()->publish($asset));

		self::assertSame([$dir], $asset->writtenTo);
		self::assertFalse(is_file($dir . DIRECTORY_SEPARATOR . TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX . sha1('/virtual/cancelled/site')));
	}

	// ---------------------------------------------------------------------------
	// The asset object cache and the BasePath guard
	// ---------------------------------------------------------------------------

	public function testAnotherAssetObjectOfTheSameClassAndPathIsPublishedItself(): void
	{
		$source = $this->writeSource('objects.txt', 'raw');
		$manager = $this->newManager(['ForceCopy' => true]);
		$manager->publish(new TFileAsset($source));
		$processed = new TFileAsset($source);
		$processed->attachEventHandler('onProcessAsset', fn ($sender, $param) => file_put_contents($param->getFilePath(), 'PROCESSED'));

		$url = $manager->publish($processed);

		self::assertSame('PROCESSED', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $manager->publish($processed), 'The same object is cached.');
	}

	public function testAnAssetObjectOfTheBasePathOrAParentIsRejected(): void
	{
		$manager = $this->newManager();
		foreach ([$this->tempDir, $this->assetDir] as $path) {
			try {
				$manager->publish(new TFileAsset($path));
				self::fail('The BasePath or a parent is rejected.');
			} catch (TInvalidDataValueException $e) {
				self::assertStringContainsString('publishingmanager_filepath_invalid', $e->getErrorCode());
			}
		}
		self::assertSame([], glob($this->assetDir . '/*'), 'Nothing is published.');
	}

	public function testAPathRewrittenToTheBasePathParentIsRejected(): void
	{
		$this->writeSource('rewritten/a.txt', 'a');
		$asset = new TFileAsset($this->srcDir . '/rewritten');
		$rewrite = new TAssetPathFilterBehavior();
		$rewrite->preFilter = fn ($path) => $this->tempDir;
		$asset->attachBehavior('rewrite', $rewrite);

		$this->expectException(TInvalidDataValueException::class);
		$this->newManager()->publish($asset);
	}
}
