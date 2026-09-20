<?php

/**
 * TAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Exceptions\TApplicationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\Web\Assets\IAsset;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\IPublishable;
use Prado\Web\IPublishedCapture;
use Prado\Web\TAssetManager;
use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TProbeAsset;
use Prado\Web\Tests\Fixtures\TRecordingAssetFinalizer;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetTest class.
 *
 * Tests the TAsset base class: path normalization, the lazy file path pipeline and its
 * dynamic events, the cache reset on behavior changes, the modification date, the
 * publish flow with dyWriteAsset, directory copying, onProcessAsset with finalizers, and
 * the published capture.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TAssetPathFilterBehavior::$journal = [];
		TRecordingAssetFinalizer::$journal = [];
	}

	/**
	 * @param array<string, mixed> $properties the behavior properties.
	 * @return TAssetPathFilterBehavior the behavior.
	 */
	protected static function filter(array $properties = []): TAssetPathFilterBehavior
	{
		$behavior = new TAssetPathFilterBehavior();
		foreach ($properties as $name => $value) {
			$behavior->$name = $value;
		}
		return $behavior;
	}

	/**
	 * @param string $prefix the journal entry prefix, eg "pre:".
	 * @return array the journal entries starting with the prefix.
	 */
	protected static function journal(string $prefix): array
	{
		return array_values(array_filter(TAssetPathFilterBehavior::$journal, fn ($entry) => str_starts_with($entry[0], $prefix)));
	}

	/**
	 * Asserts that a callable throws a TInvalidDataValueException with an error code.
	 * @param string $errorCode the expected error code.
	 * @param callable $callable the code expected to throw.
	 */
	protected static function assertThrowsInvalidValue(string $errorCode, callable $callable): void
	{
		try {
			$callable();
		} catch (TInvalidDataValueException $e) {
			self::assertSame($errorCode, $e->getErrorCode());
			return;
		}
		self::fail("TInvalidDataValueException '$errorCode' was not thrown.");
	}

	// ---- Identity ----

	public function testImplementsTheAssetInterfaces(): void
	{
		$asset = new TProbeAsset();
		self::assertInstanceOf(TComponent::class, $asset);
		self::assertInstanceOf(IAsset::class, $asset);
		self::assertInstanceOf(IPublishable::class, $asset);
		self::assertInstanceOf(IPublishedCapture::class, $asset);
		self::assertTrue((new \ReflectionClass(TAsset::class))->isAbstract());
	}

	public function testAssetsAreNotPassThroughByDefault(): void
	{
		self::assertFalse((new TProbeAsset())->getIsPassThrough());
		self::assertFalse((new TGeneratedAsset('/virtual/a.txt'))->getIsPassThrough(), 'A generated asset does not write a source unchanged.');
	}

	public function testAssetsAreVirtualByDefault(): void
	{
		self::assertTrue((new TProbeAsset())->getIsVirtual());
	}

	// ---- virtualpath ----

	public static function virtualpathProvider(): array
	{
		$ds = DIRECTORY_SEPARATOR;
		return [
			'plain' => ['/a/b/c.txt', "{$ds}a{$ds}b{$ds}c.txt"],
			'dot segments' => ['/a/./b/../c', "{$ds}a{$ds}c"],
			'duplicate separators' => ['//a///b', "{$ds}a{$ds}b"],
			'trailing separator removed' => ['/a/b/', "{$ds}a{$ds}b"],
			'parent beyond root' => ['/../../a', "{$ds}a"],
			'root' => ['/', $ds],
			'root parent' => ['/..', $ds],
			'backslashes' => ['\\a\\b\\..\\c', "{$ds}a{$ds}c"],
			'mixed separators' => ['/a\\b/c', "{$ds}a{$ds}b{$ds}c"],
		];
	}

	/**
	 * @dataProvider virtualpathProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('virtualpathProvider')]
	public function testVirtualpathNormalizesAbsolutePaths(string $path, string $expected): void
	{
		self::assertSame($expected, TAsset::virtualpath($path));
	}

	public function testVirtualpathResolvesRelativePathsAgainstTheWorkingDirectory(): void
	{
		$cwd = TAsset::virtualpath(getcwd());
		self::assertSame($cwd . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . 'y.txt', TAsset::virtualpath('x/./z/../y.txt'));
		self::assertSame($cwd, TAsset::virtualpath(''));
		self::assertSame(dirname($cwd), TAsset::virtualpath('..'));
	}

	public function testVirtualpathDoesNotTouchTheFileSystem(): void
	{
		$target = $this->writeSource('target.txt');
		$link = $this->srcDir . DIRECTORY_SEPARATOR . 'link.txt';
		if (!@symlink($target, $link)) {
			self::markTestSkipped('Symbolic links are not supported.');
		}
		self::assertSame($link, TAsset::virtualpath($link), 'Symbolic links are not resolved.');
		self::assertSame('/does/not/exist', TAsset::virtualpath('/does/not/exist'));
	}

	// ---- Construction and AssetFilePath ----

	public function testWithoutAFilePathThereAreNoPaths(): void
	{
		$asset = new TProbeAsset();
		self::assertFalse($asset->getAssetOriginalFilePath());
		self::assertFalse($asset->getAssetFilePath());
		self::assertNull($asset->getAssetVirtualFilePath());
		self::assertNull($asset->getAssetModificationDate());
		self::assertNull($asset->getPublishedPath());
		self::assertNull($asset->getPublishedUrl());
	}

	public function testConstructorIgnoresAnEmptyFilePath(): void
	{
		$asset = new TProbeAsset('', '');
		self::assertFalse($asset->getAssetFilePath());
		self::assertNull($asset->getAssetVirtualFilePath());
	}

	public function testConstructorNormalizesTheFilePath(): void
	{
		$asset = new TProbeAsset('/virtual/./dir/../assets//app.js');
		$expected = TAsset::virtualpath('/virtual/assets/app.js');
		self::assertSame($expected, $asset->getAssetOriginalFilePath());
		self::assertSame($expected, $asset->getAssetFilePath());
		self::assertNull($asset->getAssetVirtualFilePath());
	}

	public function testRelativeFilePathIsMadeAbsolute(): void
	{
		$asset = new TProbeAsset('relative/file.txt');
		self::assertSame(TAsset::virtualpath(getcwd() . '/relative/file.txt'), $asset->getAssetFilePath());
	}

	public static function invalidFilePathProvider(): array
	{
		return [
			'empty' => [''],
			'root' => ['/'],
			'backslash root' => ['\\'],
			'dot root' => ['/./'],
			'parent of root' => ['/..'],
		];
	}

	/**
	 * @dataProvider invalidFilePathProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalidFilePathProvider')]
	public function testSetAssetFilePathRejectsAnEmptyOrRootPath(string $path): void
	{
		$asset = new TProbeAsset();
		self::assertThrowsInvalidValue('asset_filepath_invalid', fn () => $asset->setAssetFilePath($path));
	}

	public function testSetAssetFilePathRejectsNull(): void
	{
		$asset = new TProbeAsset();
		self::assertThrowsInvalidValue('asset_filepath_invalid', fn () => $asset->setAssetFilePath(null));
	}

	public function testRejectedFilePathKeepsThePreviousFilePath(): void
	{
		$asset = new TProbeAsset('/virtual/kept.txt');
		self::assertSame(TAsset::virtualpath('/virtual/kept.txt'), $asset->getAssetFilePath());

		self::assertThrowsInvalidValue('asset_filepath_invalid', fn () => $asset->setAssetFilePath('/'));
		$asset->resetFilePathCache();

		self::assertSame(TAsset::virtualpath('/virtual/kept.txt'), $asset->getAssetFilePath(), 'A rejected path does not replace the asset path.');
	}

	public function testSetAssetFilePathChangesThePathAndClearsTheCapture(): void
	{
		$asset = new TProbeAsset('/virtual/one.txt');
		$asset->getAssetFilePath();
		$asset->setPublishedPath('/published/one.txt');
		$asset->setPublishedUrl('/assets/one.txt');

		$asset->setAssetFilePath('/virtual/two.txt');

		self::assertSame(TAsset::virtualpath('/virtual/two.txt'), $asset->getAssetOriginalFilePath());
		self::assertSame(TAsset::virtualpath('/virtual/two.txt'), $asset->getAssetFilePath());
		self::assertNull($asset->getPublishedPath());
		self::assertNull($asset->getPublishedUrl());
	}

	public function testPublishedCaptureRoundTrips(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->setPublishedPath('/dst/a.txt');
		$asset->setPublishedUrl('/assets/a.txt');
		self::assertSame('/dst/a.txt', $asset->getPublishedPath());
		self::assertSame('/assets/a.txt', $asset->getPublishedUrl());

		$asset->setPublishedPath(null);
		$asset->setPublishedUrl(null);
		self::assertNull($asset->getPublishedPath());
		self::assertNull($asset->getPublishedUrl());
	}

	// ---- Virtual file path ----

	public function testVirtualFilePathIsTheAssetFilePath(): void
	{
		$asset = new TProbeAsset('/virtual/real.txt', '/virtual/alias.txt' . DIRECTORY_SEPARATOR);

		self::assertSame('/virtual/alias.txt', $asset->getAssetVirtualFilePath(), 'The trailing separator is trimmed.');
		self::assertSame(TAsset::virtualpath('/virtual/real.txt'), $asset->getAssetOriginalFilePath());
		self::assertSame('/virtual/alias.txt', $asset->getAssetFilePath());
	}

	public function testSetAssetVirtualFilePathRecomputesTheAssetFilePath(): void
	{
		$asset = new TProbeAsset('/virtual/real.txt');
		self::assertSame(TAsset::virtualpath('/virtual/real.txt'), $asset->getAssetFilePath());

		$asset->setAssetVirtualFilePath('/virtual/alias.txt' . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
		self::assertSame('/virtual/alias.txt', $asset->getAssetVirtualFilePath());
		self::assertSame('/virtual/alias.txt', $asset->getAssetFilePath());

		$asset->setAssetVirtualFilePath(null);
		self::assertNull($asset->getAssetVirtualFilePath());
		self::assertSame(TAsset::virtualpath('/virtual/real.txt'), $asset->getAssetFilePath());
	}

	public function testVirtualFilePathIsSynchronizedWithADirectory(): void
	{
		mkdir($dir = $this->srcDir . DIRECTORY_SEPARATOR . 'dir');
		$file = $this->writeSource('file.txt');

		$dirAsset = new TFileAsset($dir, '/virtual/alias');
		self::assertSame('/virtual/alias' . DIRECTORY_SEPARATOR, $dirAsset->getAssetVirtualFilePath());
		self::assertSame('/virtual/alias' . DIRECTORY_SEPARATOR, $dirAsset->getAssetFilePath());
		self::assertSame($dir . DIRECTORY_SEPARATOR, $dirAsset->getAssetOriginalFilePath());

		$fileAsset = new TFileAsset($file, '/virtual/alias' . DIRECTORY_SEPARATOR);
		self::assertSame('/virtual/alias', $fileAsset->getAssetVirtualFilePath());
		self::assertSame('/virtual/alias', $fileAsset->getAssetFilePath());
	}

	public function testVirtualFilePathSetLaterIsSynchronizedWithADirectory(): void
	{
		mkdir($dir = $this->srcDir . DIRECTORY_SEPARATOR . 'dir');
		$asset = new TFileAsset($dir);
		self::assertSame($dir . DIRECTORY_SEPARATOR, $asset->getAssetFilePath());

		$asset->setAssetVirtualFilePath('/virtual/alias');

		self::assertSame('/virtual/alias' . DIRECTORY_SEPARATOR, $asset->getAssetVirtualFilePath(), 'The virtual path of a directory keeps its separator.');
		self::assertSame('/virtual/alias' . DIRECTORY_SEPARATOR, $asset->getAssetFilePath(), 'The asset file path still designates a directory.');
	}

	// ---- The path pipeline ----

	public function testPipelineFiltersPreValidatePostThenRewrite(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter([
			'preFilter' => fn ($path) => str_replace('a.txt', 'b/../b.txt', $path),
			'postFilter' => fn ($path) => $path . '.post',
			'rewriteFilter' => fn ($path) => $path . '.rewrite',
		]));

		self::assertSame('/virtual/b.txt.post', $asset->getAssetOriginalFilePath());
		self::assertSame('/virtual/b.txt.post.rewrite', $asset->getAssetFilePath());
		self::assertSame([
			['pre:filter', '/virtual/a.txt'],
			['post:filter', '/virtual/b.txt'],
			['rewrite:filter', '/virtual/b.txt.post'],
		], array_values(array_filter(TAssetPathFilterBehavior::$journal, fn ($e) => !str_starts_with($e[0], 'vote:'))), 'Pre filters the first path, post filters the validated path, rewrite filters the original.');
	}

	public function testPreFilterDecodesInReverseOfThePostAndRewriteEncoding(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('first', static::filter(['label' => 'first']), 10);
		$asset->attachBehavior('second', static::filter(['label' => 'second']), 20);

		$asset->getAssetFilePath();

		self::assertSame(['pre:second', 'pre:first'], array_column(static::journal('pre:'), 0), 'The last filter decodes first.');
		self::assertSame(['post:first', 'post:second'], array_column(static::journal('post:'), 0));
		self::assertSame(['rewrite:first', 'rewrite:second'], array_column(static::journal('rewrite:'), 0));
	}

	public function testRewriteReceivesTheVirtualFilePathWhenSet(): void
	{
		$asset = new TProbeAsset('/virtual/real.txt', '/virtual/alias.txt');
		$asset->attachBehavior('filter', static::filter(['rewriteFilter' => fn ($path) => strtoupper($path)]));

		self::assertSame('/VIRTUAL/ALIAS.TXT', $asset->getAssetFilePath());
		self::assertSame('/virtual/alias.txt', $asset->getAssetVirtualFilePath(), 'The rewrite does not change the virtual path.');
		self::assertSame(TAsset::virtualpath('/virtual/real.txt'), $asset->getAssetOriginalFilePath());
	}

	public function testRewriteFilePathRaisesDyRewriteFilePath(): void
	{
		$asset = new TProbeAsset();
		self::assertSame('/any/path', $asset->probeRewriteFilePath('/any/path'), 'Without behaviors it passes the path through.');

		$asset->attachBehavior('filter', static::filter(['rewriteFilter' => fn ($path) => $path . '.gz']));
		self::assertSame('/any/path.gz', $asset->probeRewriteFilePath('/any/path'));
	}

	public function testPathsAreComputedOnceAndCached(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter());
		TAssetPathFilterBehavior::$journal = [];

		$asset->getAssetOriginalFilePath();
		$asset->getAssetVirtualFilePath();
		$asset->getAssetFilePath();
		$asset->getAssetFilePath();
		$asset->getAssetModificationDate();

		self::assertCount(1, static::journal('pre:'));
		self::assertCount(1, static::journal('post:'));
		self::assertCount(1, static::journal('rewrite:'));
	}

	public function testResetFilePathCacheRecomputesAndNotifiesBehaviors(): void
	{
		$suffix = '.one';
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', $filter = static::filter(['rewriteFilter' => function ($path) use (&$suffix) {
			return $path . $suffix;
		}]));
		self::assertSame('/virtual/a.txt.one', $asset->getAssetFilePath());
		$resets = $filter->resetCount;

		$suffix = '.two';
		self::assertSame('/virtual/a.txt.one', $asset->getAssetFilePath(), 'The path is cached.');

		$asset->resetFilePathCache();
		self::assertSame($resets + 1, $filter->resetCount, 'dyResetFilePathCache is raised.');
		self::assertSame('/virtual/a.txt.two', $asset->getAssetFilePath());
	}

	public function testInvalidValidatedPathThrowsLazily(): void
	{
		$missing = $this->srcDir . DIRECTORY_SEPARATOR . 'missing.txt';
		$asset = new TFileAsset($missing);

		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetOriginalFilePath());
		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetVirtualFilePath());
		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetFilePath());
		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetModificationDate());
		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->publish($this->tempDir . '/out.txt'));
	}

	public function testInvalidPathExceptionNamesThePreFilteredPath(): void
	{
		$asset = new TFileAsset($this->srcDir . '/missing.txt');
		$asset->attachBehavior('filter', static::filter(['preFilter' => fn ($path) => $path . '.mapped']));

		try {
			$asset->getAssetFilePath();
			self::fail('The invalid path throws.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
			self::assertStringContainsString('missing.txt.mapped', $e->getMessage());
		}
	}

	public function testPreFilterCanMapToAnExistingFile(): void
	{
		$real = $this->writeSource('real.txt', 'real');
		$asset = new TFileAsset($this->srcDir . '/alias.txt');
		$asset->attachBehavior('filter', static::filter(['preFilter' => fn ($path) => $real]));

		self::assertSame($real, $asset->getAssetOriginalFilePath());
		self::assertSame($real, $asset->getAssetFilePath());
	}

	public function testPreFilterReturningNullCancelsTheAsset(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['preFilter' => fn ($path) => null]));

		self::assertNull($asset->getAssetOriginalFilePath());
		self::assertNull($asset->getAssetFilePath());
		self::assertFalse($asset->publish($this->tempDir . '/out.txt'));
	}

	public function testPreFilterReturningFalseIsAnInvalidPath(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt'));
		$asset->attachBehavior('filter', static::filter(['preFilter' => fn ($path) => false]));

		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetFilePath());
	}

	public function testPostFilterReturningNullCancelsTheAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt', '/virtual/alias.txt');
		$asset->modified = 99;
		$asset->attachBehavior('filter', $filter = static::filter(['postFilter' => fn ($path) => null]));
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';

		self::assertNull($asset->getAssetOriginalFilePath());
		self::assertNull($asset->getAssetFilePath());
		self::assertSame('/virtual/alias.txt', $asset->getAssetVirtualFilePath());
		self::assertNull($asset->getAssetModificationDate());
		self::assertCount(0, static::journal('rewrite:'), 'A cancelled asset is not rewritten.');
		self::assertFalse($asset->publish($dst));
		self::assertFalse($asset->publish(''), 'A cancelled asset does not validate the destination.');
		self::assertSame([], $asset->writtenTo);
		self::assertFileDoesNotExist($dst);
	}

	public function testPostFilterReturningFalseThrows(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['postFilter' => fn ($path) => false]));

		self::assertThrowsInvalidValue('assetmanager_filepath_invalid', fn () => $asset->getAssetFilePath());
	}

	public function testPostFilterReceivesFalseAndCanRescueAnInvalidPath(): void
	{
		$real = $this->writeSource('real.txt');
		$asset = new TFileAsset($this->srcDir . '/missing.txt');
		$asset->attachBehavior('filter', static::filter(['postFilter' => fn ($path) => $path === false ? $real : $path]));

		self::assertSame($real, $asset->getAssetFilePath());
		self::assertSame([['post:filter', false]], static::journal('post:'));
	}

	public function testRewriteReturningNullCancelsTheAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['rewriteFilter' => fn ($path) => null]));

		self::assertSame(TAsset::virtualpath('/virtual/a.txt'), $asset->getAssetOriginalFilePath());
		self::assertNull($asset->getAssetFilePath());
		self::assertNull($asset->getAssetModificationDate());
		self::assertFalse($asset->publish($this->tempDir . '/out.txt'));
		self::assertSame([], $asset->writtenTo);
	}

	public function testRewriteReturningFalseMakesPublishingInvalid(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['rewriteFilter' => fn ($path) => false]));

		self::assertFalse($asset->getAssetFilePath());
		self::assertThrowsInvalidValue('asset_assetfilepath_invalid', fn () => $asset->publish($this->tempDir . '/out.txt'));
	}

	public function testDyAlterAssetFilePathPassesThroughWithoutBehaviors(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		self::assertSame('/virtual/a.txt', $asset->dyAlterAssetFilePath('/virtual/a.txt'));

		$asset->attachBehavior('suffix', new TSuffixAssetBehavior());
		self::assertSame('/virtual/a.txt.renamed', $asset->dyAlterAssetFilePath('/virtual/a.txt'));
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath(), 'dyAlterAssetFilePath does not change the AssetFilePath.');
	}

	// ---- Behavior changes reset the cache ----

	/**
	 * @param ?\Closure $vote
	 * @return array{0:TProbeAsset, 1:TAssetPathFilterBehavior} an asset with a
	 *   counting "keeper" behavior whose path was computed.
	 */
	protected function assetWithKeeper(?\Closure $vote = null): array
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('keeper', $keeper = static::filter(['label' => 'keeper', 'resetVote' => $vote]));
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath());
		TAssetPathFilterBehavior::$journal = [];
		$keeper->resetCount = 0;
		return [$asset, $keeper];
	}

	public function testAttachingABehaviorResetsTheCache(): void
	{
		[$asset, $keeper] = $this->assetWithKeeper();
		$renamer = static::filter(['label' => 'renamer', 'rewriteFilter' => fn ($path) => $path . '.min']);

		$asset->attachBehavior('renamer', $renamer);

		self::assertSame(1, $keeper->resetCount);
		self::assertSame([['vote:keeper', 'renamer']], static::journal('vote:'), 'The attached behavior is offered to the attached behaviors.');
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
	}

	public function testABehaviorCanVetoTheResetOnAttach(): void
	{
		$offered = [];
		[$asset, $keeper] = $this->assetWithKeeper(function ($reset, $name, $behavior) use (&$offered) {
			$offered[] = [$reset, $name, $behavior];
			return false;
		});
		$renamer = static::filter(['label' => 'renamer', 'rewriteFilter' => fn ($path) => $path . '.min']);

		$asset->attachBehavior('renamer', $renamer);

		self::assertSame([[true, 'renamer', $renamer]], $offered);
		self::assertSame(0, $keeper->resetCount);
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath(), 'The vetoed reset keeps the cached path.');

		$asset->resetFilePathCache();
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
	}

	public function testDetachingABehaviorResetsTheCache(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('renamer', static::filter(['rewriteFilter' => fn ($path) => $path . '.min']));
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());

		self::assertInstanceOf(TAssetPathFilterBehavior::class, $asset->detachBehavior('renamer'));

		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath());
	}

	public function testABehaviorCanVetoTheResetOnDetach(): void
	{
		$offered = [];
		[$asset, $keeper] = $this->assetWithKeeper(function ($reset, $name, $behavior) use (&$offered) {
			$offered[] = [$name, $behavior];
			return $name !== 'renamer';
		});
		$renamer = static::filter(['label' => 'renamer', 'rewriteFilter' => fn ($path) => $path . '.min']);
		$asset->attachBehavior('renamer', $renamer);
		$asset->resetFilePathCache();
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
		$offered = [];

		self::assertSame($renamer, $asset->detachBehavior('renamer'));

		self::assertSame([['renamer', $renamer]], $offered, 'The detached behavior is offered after detaching.');
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath(), 'The vetoed reset keeps the cached path.');
	}

	public function testDetachingAMissingBehaviorReturnsNull(): void
	{
		[$asset, $keeper] = $this->assetWithKeeper();
		$offered = [];
		$keeper->resetVote = function ($reset, $name, $behavior) use (&$offered) {
			$offered[] = [$name, $behavior];
			return $reset;
		};

		self::assertNull($asset->detachBehavior('missing'));
		self::assertSame([['missing', null]], $offered);
	}

	public function testDisablingAndEnablingABehaviorResetTheCache(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('keeper', $keeper = static::filter(['label' => 'keeper']));
		$asset->attachBehavior('renamer', static::filter(['label' => 'renamer', 'rewriteFilter' => fn ($path) => $path . '.min']));
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
		$keeper->resetCount = 0;

		self::assertTrue($asset->disableBehavior('renamer'));
		self::assertSame(1, $keeper->resetCount);
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath());

		self::assertTrue($asset->enableBehavior('renamer'));
		self::assertSame(2, $keeper->resetCount);
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
	}

	public function testABehaviorCanVetoTheResetOnDisableAndEnable(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('keeper', static::filter(['label' => 'keeper', 'resetVote' => fn ($reset, $name) => $name !== 'renamer']));
		$asset->attachBehavior('renamer', static::filter(['label' => 'renamer', 'rewriteFilter' => fn ($path) => $path . '.min']));
		$asset->resetFilePathCache();
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());

		self::assertTrue($asset->disableBehavior('renamer'));
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath(), 'The vetoed disable keeps the cached path.');

		$asset->resetFilePathCache();
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath());

		self::assertTrue($asset->enableBehavior('renamer'));
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath(), 'The vetoed enable keeps the cached path.');
	}

	public function testEnablingOrDisablingAMissingBehaviorDoesNotResetTheCache(): void
	{
		[$asset, $keeper] = $this->assetWithKeeper();

		self::assertFalse($asset->enableBehavior('missing'));
		self::assertFalse($asset->disableBehavior('missing'));

		self::assertSame(0, $keeper->resetCount);
		self::assertSame([], static::journal('vote:'));
	}

	public function testDisablingAndEnablingAllBehaviorsResetTheCache(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		$asset->attachBehavior('renamer', static::filter(['rewriteFilter' => fn ($path) => $path . '.min']));
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());

		$asset->disableBehaviors();
		self::assertFalse($asset->getBehaviorsEnabled());
		self::assertSame('/virtual/a.txt', $asset->getAssetFilePath());

		$asset->enableBehaviors();
		self::assertTrue($asset->getBehaviorsEnabled());
		self::assertSame('/virtual/a.txt.min', $asset->getAssetFilePath());
	}

	public function testClassBehaviorsApplyToNewAssets(): void
	{
		$this->attachClassBehavior('renamer', ['class' => TAssetPathFilterBehavior::class, 'rewriteFilter' => fn ($path) => $path . '.cls'], TProbeAsset::class);

		self::assertSame('/virtual/a.txt.cls', (new TProbeAsset('/virtual/a.txt'))->getAssetFilePath());
		self::assertSame(TAsset::virtualpath('/virtual/a.txt'), (new TGeneratedAsset('/virtual/a.txt'))->getAssetFilePath(), 'A sibling class is unaffected.');
	}

	// ---- Modification date ----

	public function testBaseModificationTimeIsZeroForAPath(): void
	{
		$asset = new TProbeAsset('/virtual/a.txt');
		self::assertSame(0, $asset->getAssetModificationDate());
		self::assertSame(0, $asset->probeModificationTime('/virtual/a.txt'));
		self::assertFalse($asset->probeModificationTime(''));
		self::assertFalse($asset->probeModificationTime(null));
	}

	public function testModificationDateIsFilteredByBehaviors(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->modified = 1000;
		self::assertSame(1000, $asset->getAssetModificationDate());

		$asset->attachBehavior('filter', static::filter(['dateFilter' => fn ($time) => $time + 5]));
		self::assertSame(1005, $asset->getAssetModificationDate());
		self::assertSame([['date:filter', 1000]], static::journal('date:'));
	}

	public function testModificationDateWithoutAPathIsFilteredNull(): void
	{
		$asset = new TProbeAsset();
		$asset->attachBehavior('filter', static::filter(['dateFilter' => fn ($time) => $time ?? 42]));

		self::assertSame(42, $asset->getAssetModificationDate());
		self::assertSame([['date:filter', null]], static::journal('date:'));
	}

	// ---- Publishing ----

	public function testPublishWritesTheAssetAndRaisesOnProcessAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->content = 'generated content';
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';
		$events = [];
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$events, $dst) {
			$events[] = [$sender, $param];
			self::assertFileExists($dst, 'The asset is processed after it is written.');
		});

		self::assertTrue($asset->publish($dst));

		self::assertSame([$dst], $asset->writtenTo);
		self::assertSame('generated content', file_get_contents($dst));
		self::assertCount(1, $events);
		[$sender, $param] = $events[0];
		self::assertSame($asset, $sender);
		self::assertInstanceOf(TAssetEventParameter::class, $param);
		self::assertSame('onProcessAsset', $param->getName());
		self::assertSame($dst, $param->getFilePath());
		self::assertSame($asset, $param->getAsset());
	}

	public function testPublishWithoutAFilePathThrows(): void
	{
		self::assertThrowsInvalidValue('asset_assetfilepath_invalid', fn () => (new TGeneratedAsset())->publish($this->tempDir . '/out.txt'));
	}

	public function testPublishRejectsAnEmptyDestination(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		self::assertThrowsInvalidValue('asset_dst_filepath_invalid', fn () => $asset->publish(''));
		self::assertSame([], $asset->writtenTo);
	}

	public function testPublishDoesNotChangeThePublishedCapture(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->publish($this->tempDir . '/out.txt');
		self::assertNull($asset->getPublishedPath(), 'The manager, not publish, captures the published path.');
		self::assertNull($asset->getPublishedUrl());
	}

	public function testBaseWriteAssetPlacesABlankFile(): void
	{
		$asset = new TProbeAsset('/virtual/blank.txt');
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'blank.txt';
		$processed = 0;
		$asset->attachEventHandler('onProcessAsset', function () use (&$processed) {
			$processed++;
		});

		self::assertTrue($asset->publish($dst), 'Writing a blank asset is a successful publish.');
		self::assertFileExists($dst);
		self::assertSame('', file_get_contents($dst));
		self::assertSame(1, $processed);
	}

	public function testBaseWriteAssetFailsQuietly(): void
	{
		$asset = new TProbeAsset('/virtual/blank.txt');
		self::assertFalse($asset->writeAsset($this->tempDir . '/missing/dir/blank.txt'));
	}

	public function testFailedWriteIsNotProcessed(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt'));
		$processed = 0;
		$asset->attachEventHandler('onProcessAsset', function () use (&$processed) {
			$processed++;
		});

		self::assertFalse($asset->publish($this->tempDir . '/missing/dir/a.txt'));
		self::assertSame(0, $processed);
	}

	public function testPublishReportsWhetherTheProcessedDestinationIsAFile(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->attachEventHandler('onProcessAsset', fn ($sender, $param) => unlink($param->getFilePath()));
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';

		self::assertFalse($asset->publish($dst), 'A processor removing the file fails the publish.');
		self::assertSame([$dst], $asset->writtenTo);
	}

	public function testDyWriteAssetPassThroughWritesTheAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['writeFilter' => fn ($return, $dst) => $return]));
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';

		self::assertTrue($asset->publish($dst));
		self::assertSame([['write:filter', $dst]], static::journal('write:'));
		self::assertSame([$dst], $asset->writtenTo);
	}

	public static function writeTakeoverProvider(): array
	{
		return [
			'handled' => [true],
			'failed' => [false],
			'undetermined' => [null],
		];
	}

	/**
	 * @dataProvider writeTakeoverProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('writeTakeoverProvider')]
	public function testDyWriteAssetTakesOverWriting(?bool $result): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->attachBehavior('filter', static::filter(['writeFilter' => fn ($return, $dst) => $result]));
		$processed = 0;
		$asset->attachEventHandler('onProcessAsset', function () use (&$processed) {
			$processed++;
		});
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';

		self::assertSame($result, $asset->publish($dst));
		self::assertSame([], $asset->writtenTo, 'The asset is not written.');
		self::assertSame(0, $processed, 'The asset is not processed.');
		self::assertFileDoesNotExist($dst);
	}

	// ---- onProcessAsset and finalizers ----

	public function testOnProcessAssetRunsTheFinalizersInPriorityOrderAfterTheHandlers(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$order = [];
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$order) {
			$order[] = 'handler1';
			$param->addFinalizer(new TRecordingAssetFinalizer('late', function () use (&$order) {
				$order[] = 'late';
			}), 20);
			$param->addFinalizer(new TRecordingAssetFinalizer('default', function () use (&$order) {
				$order[] = 'default';
			}));
		});
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$order) {
			$order[] = 'handler2';
			$param->addFinalizer(new TRecordingAssetFinalizer('early', function () use (&$order) {
				$order[] = 'early';
			}), 5);
		});
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.txt';

		$asset->publish($dst);

		self::assertSame(['handler1', 'handler2', 'early', 'default', 'late'], $order);
		$params = array_column(TRecordingAssetFinalizer::$journal, 2);
		self::assertSame([$dst, $dst, $dst], array_column(TRecordingAssetFinalizer::$journal, 1));
		self::assertSame($params[0], $params[1]);
		self::assertSame($params[0], $params[2]);
	}

	public function testOnProcessAssetCanBeRaisedDirectly(): void
	{
		$asset = new TProbeAsset();
		$seen = [];
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$seen) {
			$seen[] = $param->getFilePath();
			$param->addFinalizer(new TRecordingAssetFinalizer());
		});

		$asset->onProcessAsset('/any/file.txt');

		self::assertSame(['/any/file.txt'], $seen);
		self::assertSame('/any/file.txt', TRecordingAssetFinalizer::$journal[0][1]);
	}

	public function testOnProcessAssetWithoutHandlersDoesNothing(): void
	{
		$asset = new TProbeAsset();
		$asset->onProcessAsset($dst = $this->tempDir . '/none.txt');
		self::assertFalse($asset->hasEventHandler('onProcessAsset'));
		self::assertFileDoesNotExist($dst);
	}

	public function testOnProcessAssetRejectsAFinalizerThatIsNotAnIAssetFinalizer(): void
	{
		$asset = new TProbeAsset();
		$asset->attachEventHandler('onProcessAsset', fn ($sender, $param) => $param->getFinalizers()->add(new \stdClass()));

		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		$asset->onProcessAsset($this->tempDir . '/out.txt');
	}

	public function testBehaviorEventHandlersProcessThePublishedAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->content = 'lower';
		$asset->attachBehavior('upper', new \Prado\Web\Tests\Fixtures\TUppercaseAssetBehavior());

		self::assertTrue($asset->publish($dst = $this->tempDir . '/out.txt'));
		self::assertSame('LOWER', file_get_contents($dst));
	}

	// ---- Directories ----

	/**
	 * @return string a source directory with a.txt and sub/b.txt.
	 */
	protected function writeTree(): string
	{
		$this->writeSource('tree/a.txt', 'alpha');
		$this->writeSource('tree/sub/b.txt', 'beta');
		return $this->srcDir . DIRECTORY_SEPARATOR . 'tree';
	}

	public function testDirectoryIsCopiedByThePublishingManagerAsAssets(): void
	{
		$this->newManager();
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.out'], TFileAsset::class);
		$asset = new TFileAsset($this->writeTree());
		$asset->detachBehavior('suffix');
		$seen = [];
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$seen) {
			$seen[] = $param->getFilePath();
		});
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'published';

		self::assertTrue($asset->publish($dst));

		self::assertSame('alpha', file_get_contents($dst . '/a.txt.out'), 'Each file is published as its own asset.');
		self::assertSame('beta', file_get_contents($dst . '/sub/b.txt.out'));
		self::assertSame([$dst], $seen, 'The directory asset is processed once, on the directory.');
	}

	public function testDirectoryIsCopiedVerbatimByAnAssetManager(): void
	{
		$manager = new TAssetManager();
		$manager->setBasePath(static::ASSETS_ALIAS);
		$manager->setBaseUrl(static::BASE_URL);
		$manager->init(null);
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.out'], TFileAsset::class);
		$asset = new TFileAsset($this->writeTree());
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'published';

		self::assertTrue($asset->publish($dst));

		self::assertSame('alpha', file_get_contents($dst . '/a.txt'));
		self::assertSame('beta', file_get_contents($dst . '/sub/b.txt'));
		self::assertFileDoesNotExist($dst . '/a.txt.out');
	}

	public function testVirtualDirectoryPublishesTheOriginalDirectory(): void
	{
		$this->newManager();
		$asset = new TFileAsset($this->writeTree(), '/virtual/bundle');
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'published';

		self::assertSame('/virtual/bundle' . DIRECTORY_SEPARATOR, $asset->getAssetFilePath());
		self::assertTrue($asset->publish($dst));
		self::assertSame('alpha', file_get_contents($dst . '/a.txt'));
	}

	public function testDyWriteAssetTakesOverDirectories(): void
	{
		$this->newManager();
		$asset = new TFileAsset($this->writeTree());
		$asset->attachBehavior('filter', static::filter(['writeFilter' => fn () => true]));
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'published';

		self::assertTrue($asset->publish($dst));
		self::assertDirectoryDoesNotExist($dst);
	}

	public function testCopyDirectoryUsesTheApplicationAssetManager(): void
	{
		$this->newManager();
		$src = $this->writeTree();
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'copied';

		(new TProbeAsset())->probeCopyDirectory($src, $dst);

		self::assertSame('alpha', file_get_contents($dst . '/a.txt'));
		self::assertSame('beta', file_get_contents($dst . '/sub/b.txt'));
	}

	public function testBaseDirectoryDesignationIsATrailingSeparator(): void
	{
		$asset = new TProbeAsset();
		self::assertTrue($asset->probeIsDirectory('/virtual/dir' . DIRECTORY_SEPARATOR));
		self::assertFalse($asset->probeIsDirectory('/virtual/dir'));
		self::assertFalse($asset->probeIsDirectory($this->srcDir), 'A real directory without a separator is not a virtual directory.');
	}

	public function testBaseValidatePathNormalizesVirtually(): void
	{
		$asset = new TProbeAsset();
		self::assertSame(TAsset::virtualpath('/a/b/../c'), $asset->validatePath('/a/b/../c'));
		self::assertSame(TAsset::virtualpath('/does/not/exist'), $asset->validatePath('/does/not/exist'), 'Virtual paths need not exist.');
	}
}
