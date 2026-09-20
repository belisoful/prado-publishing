<?php

/**
 * TFileAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TGDFAsset;
use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TProbeFileAsset;
use Prado\Web\Tests\Fixtures\TUppercaseAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TFileAssetTest class.
 *
 * Tests the file system asset: real path validation, directory detection, modification
 * times, copying the source file on publish, and publishing directories.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFileAssetTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TAssetPathFilterBehavior::$journal = [];
		TUppercaseAssetBehavior::$processed = [];
	}

	protected function requireSymlink(string $target, string $link): void
	{
		if (!@symlink($target, $link)) {
			self::markTestSkipped('Symbolic links are not supported.');
		}
	}

	public function testIsARealFileAsset(): void
	{
		$asset = new TFileAsset();
		self::assertInstanceOf(TAsset::class, $asset);
		self::assertFalse($asset->getIsVirtual());
	}

	public function testExistingFileIsItsOwnPaths(): void
	{
		$source = $this->writeSource('css/site.css', 'body{}');
		$asset = new TFileAsset($source);

		self::assertSame($source, $asset->getAssetOriginalFilePath());
		self::assertSame($source, $asset->getAssetFilePath());
		self::assertNull($asset->getAssetVirtualFilePath());
	}

	public function testDotSegmentsAreResolved(): void
	{
		$source = $this->writeSource('css/site.css');
		$asset = new TFileAsset($this->srcDir . '/js/../css/./site.css');

		self::assertSame($source, $asset->getAssetFilePath());
	}

	public function testDirectoryPathsEndInASeparator(): void
	{
		$this->writeSource('dir/a.txt');
		$dir = $this->srcDir . DIRECTORY_SEPARATOR . 'dir';

		$asset = new TFileAsset($dir . DIRECTORY_SEPARATOR);

		self::assertSame($dir . DIRECTORY_SEPARATOR, $asset->getAssetOriginalFilePath());
		self::assertSame($dir . DIRECTORY_SEPARATOR, $asset->getAssetFilePath());
	}

	public function testMissingFileIsInvalid(): void
	{
		$missing = $this->srcDir . DIRECTORY_SEPARATOR . 'missing.txt';
		$asset = new TFileAsset($missing);

		try {
			$asset->getAssetFilePath();
			self::fail('A missing file is invalid.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
			self::assertStringContainsString($missing, $e->getMessage());
		}
	}

	public function testSymbolicLinksResolveToTheirTarget(): void
	{
		$target = $this->writeSource('target.txt', 'target');
		$link = $this->srcDir . DIRECTORY_SEPARATOR . 'link.txt';
		$this->requireSymlink($target, $link);

		$asset = new TFileAsset($link);

		self::assertSame($target, $asset->getAssetOriginalFilePath());
	}

	public function testValidatePathIsTheRealPath(): void
	{
		$source = $this->writeSource('a.txt');
		$asset = new TFileAsset();

		self::assertSame($source, $asset->validatePath($this->srcDir . '/./../src/a.txt'));
		self::assertSame($this->srcDir, $asset->validatePath($this->srcDir . DIRECTORY_SEPARATOR));
		self::assertFalse($asset->validatePath($this->srcDir . '/missing.txt'));
	}

	public function testIsDirectoryChecksTheFileSystem(): void
	{
		$asset = new TProbeFileAsset();

		self::assertTrue($asset->probeIsDirectory($this->srcDir));
		self::assertFalse($asset->probeIsDirectory($this->writeSource('a.txt')));
		self::assertFalse($asset->probeIsDirectory($this->srcDir . '/missing'));
	}

	public function testDeletedSourceStaysCachedUntilReset(): void
	{
		$source = $this->writeSource('a.txt');
		$asset = new TFileAsset($source);
		self::assertSame($source, $asset->getAssetFilePath());

		unlink($source);
		self::assertSame($source, $asset->getAssetFilePath(), 'The validated path is cached.');

		$asset->resetFilePathCache();
		$this->expectException(TInvalidDataValueException::class);
		$asset->getAssetFilePath();
	}

	// ---- Modification time ----

	public function testModificationDateIsTheFileModificationTime(): void
	{
		$source = $this->writeSource('a.txt', 'x', 1600000000);
		self::assertSame(1600000000, (new TFileAsset($source))->getAssetModificationDate());
	}

	public function testModificationDateOfADirectoryIsTheDirectoryTime(): void
	{
		$this->writeSource('dir/a.txt', 'x', 1500000000);
		$dir = $this->srcDir . DIRECTORY_SEPARATOR . 'dir';
		touch($dir, 1400000000);
		clearstatcache();

		self::assertSame(1400000000, (new TFileAsset($dir))->getAssetModificationDate());
	}

	public function testModificationDateIsFilteredByBehaviors(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt', 'x', 1600000000));
		$filter = new TAssetPathFilterBehavior();
		$filter->dateFilter = fn ($time) => $time - 100;
		$asset->attachBehavior('filter', $filter);

		self::assertSame(1599999900, $asset->getAssetModificationDate());
	}

	public function testModificationDateUsesTheOriginalFileNotTheVirtualPath(): void
	{
		$source = $this->writeSource('a.txt', 'x', 1600000000);
		$this->writeSource('alias.txt', 'y', 1700000000);

		$asset = new TFileAsset($source, $this->srcDir . '/alias.txt');

		self::assertSame(1600000000, $asset->getAssetModificationDate());
	}

	public function testModificationTimeOfAMissingPathIsFalse(): void
	{
		$asset = new TProbeFileAsset();
		self::assertFalse($asset->probeModificationTime($this->srcDir . '/missing.txt'));
		self::assertSame(filemtime($this->srcDir), $asset->probeModificationTime($this->srcDir));
	}

	// ---- Publishing ----

	public function testPublishCopiesTheSourceFile(): void
	{
		$bytes = "binary\0data\xFF";
		$source = $this->writeSource('a.bin', $bytes);
		$asset = new TFileAsset($source);
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'a.bin';

		self::assertTrue($asset->publish($dst));

		self::assertSame($bytes, file_get_contents($dst));
		self::assertSame($bytes, file_get_contents($source), 'The source is unchanged.');
	}

	public function testPublishOverwritesAnExistingDestination(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt', 'new'));
		file_put_contents($dst = $this->tempDir . '/a.txt', 'old content');

		self::assertTrue($asset->publish($dst));
		self::assertSame('new', file_get_contents($dst));
	}

	public function testPublishOfAVirtualPathCopiesTheOriginalFile(): void
	{
		$source = $this->writeSource('real.txt', 'real');
		$asset = new TFileAsset($source, '/virtual/alias.txt');
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'alias.txt';

		self::assertSame('/virtual/alias.txt', $asset->getAssetFilePath());
		self::assertTrue($asset->publish($dst));
		self::assertSame('real', file_get_contents($dst));
	}

	public function testPublishProcessesTheCopy(): void
	{
		$source = $this->writeSource('a.txt', 'hello');
		$asset = new TFileAsset($source);
		$asset->attachBehavior('upper', new TUppercaseAssetBehavior());
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'a.txt';

		self::assertTrue($asset->publish($dst));

		self::assertSame('HELLO', file_get_contents($dst));
		self::assertSame('hello', file_get_contents($source));
		self::assertSame([$dst], TUppercaseAssetBehavior::$processed);
	}

	public function testPublishToAMissingDirectoryFails(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt'));
		self::assertFalse($asset->publish($this->tempDir . '/missing/a.txt'));
		self::assertFileDoesNotExist($this->tempDir . '/missing/a.txt');
	}

	public function testPublishOfADeletedSourceFails(): void
	{
		$source = $this->writeSource('a.txt');
		$asset = new TFileAsset($source);
		$asset->getAssetFilePath();
		unlink($source);

		self::assertFalse($asset->publish($dst = $this->tempDir . '/a.txt'));
		self::assertFileDoesNotExist($dst);
	}

	public function testWriteAssetCopiesTheOriginalFile(): void
	{
		$asset = new TFileAsset($this->writeSource('a.txt', 'written'));

		self::assertTrue($asset->writeAsset($dst = $this->tempDir . '/w.txt'));
		self::assertSame('written', file_get_contents($dst));
	}

	public function testPutFileCopiesAndReportsFailureQuietly(): void
	{
		$asset = new TProbeFileAsset();
		$source = $this->writeSource('a.txt', 'put');

		self::assertTrue($asset->probePutFile($source, $dst = $this->tempDir . '/put.txt'));
		self::assertSame('put', file_get_contents($dst));
		self::assertFalse($asset->probePutFile($this->srcDir . '/missing.txt', $this->tempDir . '/none.txt'));
		self::assertFileDoesNotExist($this->tempDir . '/none.txt');
	}

	public function testIsPassThrough(): void
	{
		$source = $this->writeSource('pass.txt');
		$asset = new TFileAsset($source);
		self::assertTrue($asset->getIsPassThrough(), 'A file asset copies its source unchanged.');
		self::assertTrue((new TProbeFileAsset($source))->getIsPassThrough(), 'A subclass that does not override putFile passes through.');
		self::assertFalse((new TGDFAsset($source))->getIsPassThrough(), 'A subclass overriding putFile converts the file.');

		$asset->attachBehavior('upper', TUppercaseAssetBehavior::class);
		self::assertTrue($asset->getIsPassThrough(), 'A behavior without dyWriteAsset does not write the file.');

		$asset->attachBehavior('writer', TAssetPathFilterBehavior::class);
		self::assertFalse($asset->getIsPassThrough(), 'An enabled dyWriteAsset behavior may write the file.');

		$asset->disableBehavior('writer');
		self::assertTrue($asset->getIsPassThrough(), 'A disabled dyWriteAsset behavior does not.');
	}

	public function testPublishDirectoryCopiesTheTree(): void
	{
		$this->newManager();
		$this->writeSource('dir/a.txt', 'alpha');
		$this->writeSource('dir/sub/b.txt', 'beta');
		$asset = new TFileAsset($this->srcDir . '/dir');
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out';

		self::assertTrue($asset->publish($dst));

		self::assertDirectoryExists($dst);
		self::assertSame('alpha', file_get_contents($dst . '/a.txt'));
		self::assertSame('beta', file_get_contents($dst . '/sub/b.txt'));
	}

	public function testPublishOfAMissingDirectoryThrows(): void
	{
		$this->newManager();
		mkdir($dir = $this->srcDir . DIRECTORY_SEPARATOR . 'gone');
		$asset = new TFileAsset($dir);
		$asset->getAssetFilePath();
		rmdir($dir);

		try {
			$asset->publish($this->tempDir . '/out');
			self::fail('The missing source directory throws.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_source_directory_invalid', $e->getErrorCode());
		}
	}
}
