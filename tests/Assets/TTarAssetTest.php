<?php

/**
 * TTarAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\TTarFileExtractor;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TTarAsset;
use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TProbeTarAsset;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Test\Unit\Harness\IO\TarTestHelper;

/**
 * TTarAssetTest class.
 *
 * Tests the tar archive asset: its checksum file and extraction settings, the published
 * directory and completion marker names, path validation, modification dates, and
 * extraction on publish, including checksum verification, the options reaching the
 * extractor, virtual archives extracted from a temporary file, and failures.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TTarAssetTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TAssetPathFilterBehavior::$journal = [];
	}

	/**
	 * @return string[] the default archive entries.
	 */
	protected static function defaultEntries(): array
	{
		return [
			TarTestHelper::entry('style.css', 'body{color:red}'),
			TarTestHelper::entry('app.js', 'window.app=1;'),
			TarTestHelper::entry('sub/', '', '5'),
			TarTestHelper::entry('sub/nested.txt', 'nested'),
		];
	}

	/**
	 * Writes a tar archive into the source directory.
	 * @param string $relative the archive path relative to the source directory.
	 * @param ?array $entries the archive entries, default {@see defaultEntries}.
	 * @return string the archive path.
	 */
	protected function writeTar(string $relative = 'bundle/bundle.tar', ?array $entries = null): string
	{
		return $this->writeSource($relative, TarTestHelper::archive($entries ?? static::defaultEntries()), time() - 100);
	}

	/**
	 * Writes the checksum file of an archive.
	 * @param string $tar the archive path.
	 * @param string $relative the checksum path relative to the source directory.
	 * @param ?string $content the checksum file content, default the md5sum line of the archive.
	 * @return string the checksum file path.
	 */
	protected function writeChecksum(string $tar, string $relative = 'bundle/bundle.md5', ?string $content = null): string
	{
		return $this->writeSource($relative, $content ?? md5_file($tar) . '  ' . basename($tar), time() - 100);
	}

	/**
	 * @param string $dir the directory.
	 * @return string[] the sorted entries of the directory.
	 */
	protected static function entries(string $dir): array
	{
		$entries = array_values(array_diff(scandir($dir), ['.', '..']));
		sort($entries);
		return $entries;
	}

	/**
	 * @param string $dir the directory.
	 * @return string[] the temporary files left in the directory.
	 */
	protected static function temporaries(string $dir): array
	{
		return glob($dir . DIRECTORY_SEPARATOR . 'tmp-*') ?: [];
	}

	protected function requireModes(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('File permission modes are not honored on Windows.');
		}
	}

	// ---------------------------------------------------------------------------
	// Properties
	// ---------------------------------------------------------------------------

	public function testIsAFileAssetWithDefaultSettings(): void
	{
		$asset = new TTarAsset();

		self::assertInstanceOf(TFileAsset::class, $asset);
		self::assertNull($asset->getMd5FilePath());
		self::assertNull($asset->getAtomic());
		self::assertNull($asset->getStrict());
		self::assertNull($asset->getConflictMode());
		self::assertNull($asset->getDirMode());
		self::assertNull($asset->getFileMode());
		self::assertFalse($asset->getVerifyChecksum());
		self::assertNull($asset->getExtractor());
		self::assertNull($asset->getMd5FileRealPath());
	}

	public function testConstructorSetsTheArchiveChecksumAndVirtualPath(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);

		$asset = new TTarAsset($tar, $md5, $this->srcDir . '/alias.tar');

		self::assertSame($tar, $asset->getAssetOriginalFilePath());
		self::assertSame($md5, $asset->getMd5FilePath());
		self::assertSame($this->srcDir . '/alias.tar', $asset->getAssetVirtualFilePath());
		self::assertNull((new TTarAsset($tar, ''))->getMd5FilePath(), 'An empty checksum path is none.');
	}

	public function testSetMd5FilePathNormalizesAndResetsThePathCache(): void
	{
		$tar = $this->writeTar();
		$asset = new TTarAsset($tar);
		$asset->attachBehavior('filter', new TAssetPathFilterBehavior());
		$resets = $asset->asa('filter')->resetCount;

		$asset->setMd5FilePath($this->srcDir . '/bundle/bundle.md5');
		self::assertSame($this->srcDir . '/bundle/bundle.md5', $asset->getMd5FilePath());
		self::assertSame($resets + 1, $asset->asa('filter')->resetCount, 'Changing the checksum file resets the path cache.');

		$asset->setMd5FilePath('');
		self::assertNull($asset->getMd5FilePath());
		$asset->setMd5FilePath(null);
		self::assertNull($asset->getMd5FilePath());
		self::assertSame($resets + 3, $asset->asa('filter')->resetCount);
	}

	public static function nullableBooleanProvider(): array
	{
		return [
			'null' => [null, null],
			'empty' => ['', null],
			'true' => [true, true],
			'false' => [false, false],
			'string true' => ['true', true],
			'string false' => ['false', false],
			'one' => [1, true],
		];
	}

	/**
	 * @dataProvider nullableBooleanProvider
	 * @param mixed $value
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('nullableBooleanProvider')]
	public function testAtomicAndStrictAreNullableBooleans($value, ?bool $expected): void
	{
		$asset = new TTarAsset();
		$asset->setAtomic(!$expected);
		$asset->setStrict(!$expected);

		$asset->setAtomic($value);
		$asset->setStrict($value);

		self::assertSame($expected, $asset->getAtomic());
		self::assertSame($expected, $asset->getStrict());
		self::assertSame($expected, TProbeTarAsset::probeEnsureNullableBoolean($value));
	}

	public function testDirAndFileModesAreNullableIntegers(): void
	{
		$asset = new TTarAsset();

		$asset->setDirMode(0o750);
		$asset->setFileMode('416');
		self::assertSame(0o750, $asset->getDirMode());
		self::assertSame(0o640, $asset->getFileMode(), 'A numeric string is converted.');

		$asset->setDirMode('');
		$asset->setFileMode(null);
		self::assertNull($asset->getDirMode());
		self::assertNull($asset->getFileMode());
	}

	public function testVerifyChecksumIsABoolean(): void
	{
		$asset = new TTarAsset();

		$asset->setVerifyChecksum('true');
		self::assertTrue($asset->getVerifyChecksum());
		$asset->setVerifyChecksum(false);
		self::assertFalse($asset->getVerifyChecksum());
	}

	public static function conflictModeProvider(): array
	{
		$callable = fn () => true;
		return [
			'constant' => [TTarFileExtractor::CONFLICT_NEWER, TTarFileExtractor::CONFLICT_NEWER],
			'name' => ['Skip', TTarFileExtractor::CONFLICT_SKIP],
			'lowercase padded name' => [' overwrite ', TTarFileExtractor::CONFLICT_OVERWRITE],
			'uppercase name' => ['OLDER', TTarFileExtractor::CONFLICT_OLDER],
			'error name' => ['Error', TTarFileExtractor::CONFLICT_ERROR],
			'numeric string' => ['1', TTarFileExtractor::CONFLICT_SKIP],
			'float' => [3.0, TTarFileExtractor::CONFLICT_NEWER],
			'callable name' => ['strlen', 'strlen'],
			'closure' => [$callable, $callable],
			'empty' => ['', null],
			'null' => [null, null],
		];
	}

	/**
	 * @dataProvider conflictModeProvider
	 * @param mixed $value
	 * @param mixed $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('conflictModeProvider')]
	public function testConflictModeAcceptsConstantsNamesAndCallables($value, $expected): void
	{
		$asset = new TTarAsset();
		$asset->setConflictMode(TTarFileExtractor::CONFLICT_ERROR);

		$asset->setConflictMode($value);

		self::assertSame($expected, $asset->getConflictMode());
	}

	public function testConflictModeRejectsAnUnknownName(): void
	{
		$asset = new TTarAsset();
		try {
			$asset->setConflictMode('Sometimes');
			self::fail('An unknown conflict mode name is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('tarasset_conflictmode_invalid', $e->getErrorCode());
		}
		self::assertNull($asset->getConflictMode(), 'A rejected name keeps the previous mode.');
	}

	public function testApplyPublishingOptionsFillsOnlyTheNullSettings(): void
	{
		$options = ['atomic' => false, 'strict' => 'false', 'conflictMode' => 'Newer', 'dirMode' => 0o700, 'fileMode' => '384', 'only' => ['*.js']];

		$empty = new TTarAsset();
		$empty->applyPublishingOptions($options);
		self::assertFalse($empty->getAtomic());
		self::assertFalse($empty->getStrict());
		self::assertSame(TTarFileExtractor::CONFLICT_NEWER, $empty->getConflictMode());
		self::assertSame(0o700, $empty->getDirMode());
		self::assertSame(0o600, $empty->getFileMode());

		$set = new TTarAsset();
		$set->setAtomic(true);
		$set->setStrict(true);
		$set->setConflictMode('Skip');
		$set->setDirMode(0o755);
		$set->setFileMode(0o644);
		$set->applyPublishingOptions($options);
		self::assertTrue($set->getAtomic());
		self::assertTrue($set->getStrict());
		self::assertSame(TTarFileExtractor::CONFLICT_SKIP, $set->getConflictMode());
		self::assertSame(0o755, $set->getDirMode());
		self::assertSame(0o644, $set->getFileMode());
	}

	public function testApplyPublishingOptionsIgnoresAbsentKeys(): void
	{
		$asset = new TTarAsset();
		$asset->applyPublishingOptions(['forceCopy' => true]);

		self::assertNull($asset->getAtomic());
		self::assertNull($asset->getStrict());
		self::assertNull($asset->getConflictMode());
		self::assertNull($asset->getDirMode());
		self::assertNull($asset->getFileMode());

		$asset->applyPublishingOptions(['dirMode' => null, 'conflictMode' => null]);
		self::assertNull($asset->getDirMode(), 'A null option leaves the setting null.');
		self::assertNull($asset->getConflictMode());
	}

	// ---------------------------------------------------------------------------
	// Paths, markers, and dates
	// ---------------------------------------------------------------------------

	public function testPublishFilePathIsTheChecksumDirectory(): void
	{
		$tar = $this->writeTar('archives/bundle.tar');
		$md5 = $this->writeChecksum($tar, 'sums/bundle.md5');

		$asset = new TTarAsset($tar, $md5);

		self::assertSame($tar, $asset->getAssetFilePath());
		self::assertSame($this->srcDir . DIRECTORY_SEPARATOR . 'sums' . DIRECTORY_SEPARATOR, $asset->getAssetPublishFilePath());
		self::assertSame($md5, $asset->getMd5FileRealPath());
	}

	public function testPublishFilePathWithoutAChecksumIsTheArchiveDirectory(): void
	{
		$tar = $this->writeTar('archives/bundle.tar');

		$asset = new TTarAsset($tar);

		self::assertSame($this->srcDir . DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR, $asset->getAssetPublishFilePath());
		self::assertNull($asset->getMd5FileRealPath());
	}

	public function testPublishFilePathOfACancelledArchiveIsNull(): void
	{
		$tar = $this->writeTar();
		$asset = new TTarAsset($tar, $this->writeChecksum($tar));
		$filter = new TAssetPathFilterBehavior();
		$filter->preFilter = fn ($path) => null;
		$asset->attachBehavior('filter', $filter);

		self::assertNull($asset->getAssetFilePath());
		self::assertNull($asset->getAssetPublishFilePath());
		self::assertFalse($asset->publish($this->assetDir . '/cancelled'), 'A cancelled archive does not publish.');
		self::assertFalse(is_dir($this->assetDir . '/cancelled'));
		self::assertNull($asset->getAssetModificationDate(), 'A cancelled archive has no date, even with a checksum.');
	}

	public function testChecksumRealPathIsResolved(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);

		$asset = new TTarAsset($tar, $this->srcDir . '/bundle/../bundle/./bundle.md5');

		self::assertSame($md5, $asset->getMd5FileRealPath());
	}

	public function testMissingChecksumFileIsInvalid(): void
	{
		$tar = $this->writeTar();
		mkdir($this->srcDir . '/bundle/dir.md5');

		foreach ([$this->srcDir . '/bundle/missing.md5', $this->srcDir . '/bundle/dir.md5'] as $md5) {
			$asset = new TTarAsset($tar, $md5);
			try {
				$asset->getMd5FileRealPath();
				self::fail("$md5 is not a checksum file.");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('assetmanager_tarchecksum_invalid', $e->getErrorCode());
			}
		}
	}

	public function testChecksumIsValidatedBeforeTheArchive(): void
	{
		$asset = new TTarAsset($this->srcDir . '/missing.tar', $this->srcDir . '/missing.md5');

		$this->expectException(TInvalidDataValueException::class);
		$asset->getAssetFilePath();
	}

	public function testMissingArchiveIsInvalid(): void
	{
		mkdir($this->srcDir . '/folder.tar');

		foreach ([$this->srcDir . '/missing.tar', $this->srcDir . '/folder.tar'] as $tar) {
			$asset = new TTarAsset($tar);
			try {
				$asset->getAssetFilePath();
				self::fail("$tar is not an archive.");
			} catch (TIOException $e) {
				self::assertSame('assetmanager_tarfile_invalid', $e->getErrorCode());
			}
		}
	}

	public function testValidateAssetPathPassesNullAndFalseThrough(): void
	{
		$asset = new TProbeTarAsset(null, $this->srcDir . '/missing.md5');

		self::assertNull($asset->probeValidateAssetPath(null));
		self::assertFalse($asset->probeValidateAssetPath(false));
	}

	public function testVirtualArchiveNeedNotExist(): void
	{
		$asset = new TProbeTarAsset();
		$asset->virtual = true;
		$asset->setAssetFilePath($this->srcDir . '/generated/bundle.tar');

		self::assertSame($this->srcDir . '/generated/bundle.tar', $asset->getAssetFilePath());
		self::assertSame($this->srcDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR, $asset->getAssetPublishFilePath());
	}

	public function testExtractedMarkerSourceIsTheChecksumOrTheArchive(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar, 'bundle/sum.md5');

		self::assertSame(realpath($md5), (new TTarAsset($tar, $md5))->getExtractedMarkerSource());
		self::assertSame($tar, (new TTarAsset($tar))->getExtractedMarkerSource());
	}

	public function testModificationDateIsTheChecksumTime(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		touch($tar, 1000000);
		touch($md5, 2000000);
		clearstatcache();

		self::assertSame(2000000, (new TTarAsset($tar, $md5))->getAssetModificationDate());
		self::assertSame(1000000, (new TTarAsset($tar))->getAssetModificationDate(), 'Without a checksum, the archive time.');
	}

	public function testChecksumModificationDateIsFilteredByBehaviors(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		touch($md5, 2000000);
		clearstatcache();
		$asset = new TTarAsset($tar, $md5);
		$filter = new TAssetPathFilterBehavior();
		$filter->dateFilter = fn ($time) => $time + 5;
		$asset->attachBehavior('filter', $filter);

		self::assertSame(2000005, $asset->getAssetModificationDate());
	}

	// ---------------------------------------------------------------------------
	// Publishing
	// ---------------------------------------------------------------------------

	public function testPublishExtractsTheArchiveAndPublishesTheChecksum(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar);
		$dst = $this->assetDir . '/published';
		$processed = [];
		$asset = new TTarAsset($tar, $md5);
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$processed, $dst) {
			$processed[] = [$param->getFilePath(), is_file($dst . '/bundle.md5')];
		});

		self::assertTrue($asset->publish($dst));

		self::assertSame(['app.js', 'bundle.md5', 'style.css', 'sub'], static::entries($dst));
		self::assertSame('body{color:red}', file_get_contents($dst . '/style.css'));
		self::assertSame('nested', file_get_contents($dst . '/sub/nested.txt'));
		self::assertSame(file_get_contents($md5), file_get_contents($dst . '/bundle.md5'));
		self::assertSame([[$dst, true]], $processed, 'onProcessAsset is raised on the directory after the checksum is published.');
		self::assertInstanceOf(TTarFileExtractor::class, $asset->getExtractor());
		self::assertSame($tar, (string) $asset->getExtractor()->getTarPath());
		self::assertFalse($asset->getExtractor()->hasSkippedFiles());
		self::assertSame(['.', '..', 'published'], scandir($this->assetDir), 'No temporary file is left.');
	}

	public function testPublishWithoutAChecksumExtractsOnlyTheArchive(): void
	{
		$tar = $this->writeTar();
		$dst = $this->assetDir . '/nested/published';

		self::assertTrue((new TTarAsset($tar))->publish($dst));

		self::assertSame(['app.js', 'style.css', 'sub'], static::entries($dst), 'The missing directories are created, and no checksum or marker is written.');
	}

	public function testPublishExtractsAGzipArchive(): void
	{
		$tar = $this->writeSource('bundle/bundle.tar.gz', gzencode(TarTestHelper::archive(static::defaultEntries())));
		$dst = $this->assetDir . '/gz';

		$asset = new TTarAsset($tar);

		self::assertTrue($asset->publish($dst));

		self::assertSame('window.app=1;', file_get_contents($dst . '/app.js'));
		self::assertSame('nested', file_get_contents($dst . '/sub/nested.txt'));
		self::assertSame(TTarFileExtractor::COMPRESSION_GZIP, $asset->getExtractor()->getCompression());
	}

	public function testPublishOfAnInvalidRewrittenPathThrows(): void
	{
		$tar = $this->writeTar();
		$asset = new TTarAsset($tar);
		$filter = new TAssetPathFilterBehavior();
		$filter->rewriteFilter = fn ($path) => false;
		$asset->attachBehavior('filter', $filter);

		try {
			$asset->publish($this->assetDir . '/invalid');
			self::fail('An invalid asset file path is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('asset_assetfilepath_invalid', $e->getErrorCode());
		}
		self::assertFalse(is_dir($this->assetDir . '/invalid'));
	}

	public function testPublishToAnEmptyDestinationThrows(): void
	{
		$asset = new TTarAsset($this->writeTar());

		try {
			$asset->publish('');
			self::fail('An empty destination is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('asset_dst_filepath_invalid', $e->getErrorCode());
		}
	}

	public function testDyWriteAssetCanReplaceTheExtraction(): void
	{
		$tar = $this->writeTar();
		$dst = $this->assetDir . '/replaced';
		$asset = new TProbeTarAsset($tar, $this->writeChecksum($tar));
		$filter = new TAssetPathFilterBehavior();
		$asset->attachBehavior('filter', $filter);

		foreach ([false, true, null] as $result) {
			$filter->writeFilter = fn ($return, $path) => $result;
			self::assertSame($result, $asset->publish($dst));
		}

		self::assertSame([], $asset->extractedFrom, 'The archive is not extracted.');
		self::assertFalse(is_dir($dst));
		self::assertSame([['write:filter', $dst], ['write:filter', $dst], ['write:filter', $dst]], array_values(array_filter(TAssetPathFilterBehavior::$journal, fn ($e) => str_starts_with($e[0], 'write:'))));
	}

	public function testFileAndDirModesReachTheExtractorAndTheChecksum(): void
	{
		$this->requireModes();
		$tar = $this->writeTar();
		$dst = $this->assetDir . '/modes';
		$asset = new TTarAsset($tar, $this->writeChecksum($tar));
		$asset->setFileMode(0o640);
		$asset->setDirMode(0o750);

		$asset->publish($dst);

		clearstatcache();
		self::assertSame(0o640, fileperms($dst . '/style.css') & 0o777);
		self::assertSame(0o640, fileperms($dst . '/sub/nested.txt') & 0o777);
		self::assertSame(0o750, fileperms($dst . '/sub') & 0o777);
		self::assertSame(0o640, fileperms($dst . '/bundle.md5') & 0o777, 'The published checksum takes the file mode.');
		self::assertSame(0o640, $asset->getExtractor()->getFileModeOverride());
		self::assertSame(0o750, $asset->getExtractor()->getDirModeOverride());
	}

	public function testNullSettingsLeaveTheExtractorDefaults(): void
	{
		$tar = $this->writeTar();
		$asset = new TTarAsset($tar);
		$defaults = new TTarFileExtractor($tar);

		$asset->publish($this->assetDir . '/defaults');

		$extractor = $asset->getExtractor();
		self::assertSame($defaults->getAtomic(), $extractor->getAtomic());
		self::assertSame($defaults->getStrict(), $extractor->getStrict());
		self::assertSame($defaults->getConflictMode(), $extractor->getConflictMode());
		self::assertNull($extractor->getFileModeOverride());
		self::assertSame($defaults->getDirModeOverride(), $extractor->getDirModeOverride());
	}

	public function testAtomicReachesTheExtractor(): void
	{
		$tar = $this->writeTar();
		foreach ([true, false] as $atomic) {
			$dst = $this->assetDir . '/atomic-' . (int) $atomic;
			$asset = new TTarAsset($tar);
			$asset->setAtomic($atomic);

			self::assertTrue($asset->publish($dst));

			self::assertSame($atomic, $asset->getExtractor()->getAtomic());
			self::assertSame('nested', file_get_contents($dst . '/sub/nested.txt'));
		}
	}

	public function testStrictRejectsAnUnsafeArchive(): void
	{
		$tar = $this->writeTar('bundle/unsafe.tar', [
			TarTestHelper::entry('safe.txt', 'safe'),
			TarTestHelper::entry('../escaped.txt', 'escaped'),
		]);
		$dst = $this->assetDir . '/unsafe';

		$lenient = new TTarAsset($tar);
		$lenient->setStrict(false);
		self::assertTrue($lenient->publish($dst));
		self::assertSame('safe', file_get_contents($dst . '/safe.txt'));
		self::assertFalse(is_file($this->assetDir . '/escaped.txt'));
		self::assertFalse($lenient->getExtractor()->getStrict());
		self::assertSame([TTarFileExtractor::REASON_ZIP_SLIP], array_values(array_column($lenient->getExtractor()->getSkippedFiles(), 'reason')));

		$strict = new TTarAsset($tar);
		$strict->setStrict(true);
		$processed = false;
		$strict->attachEventHandler('onProcessAsset', function () use (&$processed) {
			$processed = true;
		});
		try {
			$strict->publish($this->assetDir . '/strict');
			self::fail('A strict extraction rejects the unsafe entry.');
		} catch (\Exception $e) {
			self::assertStringContainsString('../escaped.txt', $e->getMessage());
		}
		self::assertFalse(is_file($this->assetDir . '/escaped.txt'));
		self::assertFalse($processed, 'onProcessAsset is not raised on a failed extraction.');
	}

	public function testConflictModesResolveExistingFiles(): void
	{
		$tar = $this->writeTar();
		$dst = $this->assetDir . '/conflict';
		$seen = [];
		$cases = [
			'Skip' => 'existing',
			'1' => 'existing',
			TTarFileExtractor::CONFLICT_OVERWRITE => 'body{color:red}',
			'Overwrite' => 'body{color:red}',
		];
		foreach ($cases as $mode => $expected) {
			mkdir($dst, 0o777, true);
			file_put_contents($dst . '/style.css', 'existing');
			$asset = new TTarAsset($tar);
			$asset->setConflictMode($mode);

			self::assertTrue($asset->publish($dst));

			self::assertSame($expected, file_get_contents($dst . '/style.css'), "Conflict mode $mode.");
			self::assertSame('window.app=1;', file_get_contents($dst . '/app.js'), 'A new file is extracted.');
			static::removeTree($dst);
		}

		mkdir($dst, 0o777, true);
		file_put_contents($dst . '/style.css', 'existing');
		file_put_contents($dst . '/app.js', 'existing');
		$asset = new TTarAsset($tar);
		$asset->setConflictMode(function (array $entry, string $path, ?string &$reason) use (&$seen) {
			$seen[] = basename($path);
			return basename($path) === 'app.js';
		});
		$asset->publish($dst);

		sort($seen);
		self::assertSame(['app.js', 'style.css'], $seen, 'The callable resolves each existing file.');
		self::assertSame('existing', file_get_contents($dst . '/style.css'));
		self::assertSame('window.app=1;', file_get_contents($dst . '/app.js'));
		self::assertSame([TTarFileExtractor::REASON_CONFLICT_CALLABLE_SKIP], array_values(array_column($asset->getExtractor()->getSkippedFiles(), 'reason')));
	}

	public function testVerifyChecksumAcceptsAMatchingChecksum(): void
	{
		$tar = $this->writeTar();
		$formats = [
			'md5sum' => md5_file($tar) . '  bundle.tar',
			'binary uppercase' => strtoupper(md5_file($tar)) . " *bundle.tar\n",
			'bare' => md5_file($tar),
		];
		foreach ($formats as $name => $content) {
			$dst = $this->assetDir . '/verified-' . str_replace(' ', '-', $name);
			$asset = new TTarAsset($tar, $this->writeChecksum($tar, "sums-$name/bundle.md5", $content));
			$asset->setVerifyChecksum(true);

			self::assertTrue($asset->publish($dst), "The $name checksum matches.");
			self::assertTrue(is_file($dst . '/style.css'));
		}
	}

	public function testVerifyChecksumRejectsAMismatch(): void
	{
		$tar = $this->writeTar();
		$md5 = $this->writeChecksum($tar, 'bundle/bundle.md5', md5('other') . '  bundle.tar');
		$dst = $this->assetDir . '/mismatch';
		$asset = new TProbeTarAsset($tar, $md5);
		$asset->setVerifyChecksum(true);

		try {
			$asset->publish($dst);
			self::fail('A mismatched checksum is rejected.');
		} catch (TIOException $e) {
			self::assertSame('tarasset_checksum_mismatch', $e->getErrorCode());
		}
		self::assertFalse(is_dir($dst), 'Nothing is extracted.');
		self::assertSame([], $asset->extractedFrom);
	}

	public function testChecksumIsNotVerifiedByDefaultOrWithoutAChecksumFile(): void
	{
		$tar = $this->writeTar();
		$sentinel = new TTarAsset($tar, $this->writeChecksum($tar, 'bundle/bundle.md5', 'not a checksum'));
		self::assertTrue($sentinel->publish($this->assetDir . '/sentinel'), 'An unverified checksum file is a sentinel.');
		self::assertSame('not a checksum', file_get_contents($this->assetDir . '/sentinel/bundle.md5'));

		$none = new TTarAsset($tar);
		$none->setVerifyChecksum(true);
		self::assertTrue($none->publish($this->assetDir . '/none'), 'There is nothing to verify without a checksum file.');
	}

	public function testExtractionFailureThrows(): void
	{
		$tar = $this->writeTar();
		$dst = $this->assetDir . '/failed';
		$asset = new TProbeTarAsset($tar, $this->writeChecksum($tar));
		$asset->extractorFactory = fn ($path) => new class ($path) extends TTarFileExtractor {
			public function extract(string $p_destPath = ''): bool
			{
				return false;
			}
		};
		$processed = false;
		$asset->attachEventHandler('onProcessAsset', function () use (&$processed) {
			$processed = true;
		});

		try {
			$asset->publish($dst);
			self::fail('A failed extraction throws.');
		} catch (TIOException $e) {
			self::assertSame('tarasset_extract_failed', $e->getErrorCode());
		}
		self::assertFalse(is_file($dst . '/bundle.md5'), 'The checksum does not mark a failed extraction.');
		self::assertFalse($processed);
		self::assertNotNull($asset->getExtractor(), 'The failed extractor is kept for inspection.');
	}

	public function testCreatedExtractorIsConfiguredByTheAsset(): void
	{
		$tar = $this->writeTar();
		$custom = null;
		$asset = new TProbeTarAsset($tar);
		$asset->extractorFactory = function ($path) use (&$custom) {
			return $custom = (new TTarFileExtractor($path))->setExceptionClass(\RuntimeException::class);
		};
		$asset->setConflictMode('Newer');
		$asset->setStrict(false);
		$asset->setAtomic(false);

		$asset->publish($this->assetDir . '/custom');

		self::assertSame($custom, $asset->getExtractor());
		self::assertSame([[$tar, true]], $asset->extractedFrom);
		self::assertSame(\RuntimeException::class, $custom->getExceptionClass());
		self::assertSame(TTarFileExtractor::CONFLICT_NEWER, $custom->getConflictMode());
		self::assertFalse($custom->getStrict());
		self::assertFalse($custom->getAtomic());
	}

	public function testFailedChecksumPublishLeavesNoTemporaryFile(): void
	{
		$tar = $this->writeTar('bundle/bundle.tar', [
			TarTestHelper::entry('style.css', 'body{}'),
			TarTestHelper::entry('bundle.md5/', '', '5'),
			TarTestHelper::entry('bundle.md5/inner.txt', 'inner'),
		]);
		$dst = $this->assetDir . '/blocked';

		self::assertTrue((new TTarAsset($tar, $this->writeChecksum($tar)))->publish($dst));

		self::assertTrue(is_dir($dst . '/bundle.md5'), 'The checksum cannot replace a directory of the same name.');
		self::assertSame(['bundle.md5', 'style.css'], static::entries($dst), 'The temporary checksum copy is removed.');
	}

	// ---------------------------------------------------------------------------
	// Virtual archives
	// ---------------------------------------------------------------------------

	/**
	 * @param ?string $md5 the checksum file.
	 * @return TProbeTarAsset a virtual archive generating the default entries.
	 */
	protected function virtualArchive(?string $md5 = null): TProbeTarAsset
	{
		$asset = new TProbeTarAsset(null, $md5);
		$asset->virtual = true;
		$asset->archive = TarTestHelper::archive(static::defaultEntries());
		$asset->setAssetFilePath($this->srcDir . '/generated/bundle.tar');
		return $asset;
	}

	public function testVirtualArchiveIsExtractedFromATemporaryFile(): void
	{
		$dst = $this->assetDir . '/virtual';
		$asset = $this->virtualArchive();

		self::assertTrue($asset->publish($dst));

		self::assertCount(1, $asset->writtenTo);
		self::assertSame($this->assetDir, dirname($asset->writtenTo[0]), 'The temporary archive is written beside the directory.');
		self::assertStringStartsWith('tmp-', basename($asset->writtenTo[0]));
		self::assertStringEndsWith('-bundle.tar', $asset->writtenTo[0]);
		self::assertSame([[$asset->writtenTo[0], true]], $asset->extractedFrom, 'The extractor reads the temporary archive.');
		self::assertFalse(is_file($asset->writtenTo[0]), 'The temporary archive is removed.');
		self::assertSame(['.', '..', 'virtual'], scandir($this->assetDir));
		self::assertSame(['app.js', 'style.css', 'sub'], static::entries($dst));
	}

	public function testVirtualArchiveWithAChecksumPublishesTheChecksum(): void
	{
		$md5 = $this->writeSource('generated/bundle.md5', 'sentinel');
		$dst = $this->assetDir . '/virtual-md5';
		$asset = $this->virtualArchive($md5);

		self::assertTrue($asset->publish($dst));

		self::assertSame(['app.js', 'bundle.md5', 'style.css', 'sub'], static::entries($dst));
		self::assertSame([], static::temporaries($this->assetDir));
	}

	public function testVirtualArchiveWriteFailureThrowsAndRemovesTheTemporaryFile(): void
	{
		$asset = $this->virtualArchive();
		$asset->archive = null;

		try {
			$asset->publish($this->assetDir . '/unwritten');
			self::fail('A virtual archive that cannot be written throws.');
		} catch (TIOException $e) {
			self::assertSame('assetmanager_tarfile_invalid', $e->getErrorCode());
		}
		self::assertCount(1, $asset->writtenTo);
		self::assertSame([], static::temporaries($this->assetDir), 'The partial temporary archive is removed.');
		self::assertSame([], $asset->extractedFrom);
	}

	public function testVirtualArchiveWriteExceptionRemovesTheTemporaryFile(): void
	{
		$asset = new class () extends TProbeTarAsset {
			public function writeAsset(string $filepath): bool
			{
				$this->writtenTo[] = $filepath;
				file_put_contents($filepath, 'partial');
				throw new \RuntimeException('The archive generator failed.');
			}
		};
		$asset->virtual = true;
		$asset->setAssetFilePath($this->srcDir . '/generated/bundle.tar');

		try {
			$asset->publish($this->assetDir . '/thrown');
			self::fail('The writer exception is rethrown.');
		} catch (\RuntimeException $e) {
			self::assertSame('The archive generator failed.', $e->getMessage());
		}
		self::assertCount(1, $asset->writtenTo);
		self::assertSame([], static::temporaries($this->assetDir), 'The partial temporary archive is removed.');
		self::assertSame([], $asset->extractedFrom);
	}

	public function testVirtualArchiveTemporaryFileIsRemovedWhenExtractionThrows(): void
	{
		$dst = $this->assetDir . '/virtual-conflict';
		mkdir($dst);
		file_put_contents($dst . '/style.css', 'existing');
		$asset = $this->virtualArchive();
		$asset->setConflictMode('Error');

		try {
			$asset->publish($dst);
			self::fail('An extraction conflict throws.');
		} catch (\Exception $e) {
			self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
		}
		self::assertSame([[$asset->writtenTo[0], true]], $asset->extractedFrom);
		self::assertSame([], static::temporaries($this->assetDir), 'The temporary archive is removed.');
		self::assertSame('existing', file_get_contents($dst . '/style.css'));
	}

	public function testVirtualArchiveChecksumIsVerifiedAgainstTheGeneratedArchive(): void
	{
		$archive = TarTestHelper::archive(static::defaultEntries());
		$md5 = $this->writeSource('generated/bundle.md5', md5($archive) . '  bundle.tar');
		$dst = $this->assetDir . '/virtual-verified';
		$asset = $this->virtualArchive($md5);
		$asset->archive = $archive;
		$asset->setVerifyChecksum(true);

		self::assertTrue($asset->publish($dst));

		self::assertSame('nested', file_get_contents($dst . '/sub/nested.txt'));
		self::assertSame([], static::temporaries($this->assetDir));
	}

	public function testVirtualArchiveChecksumMismatchOfTheGeneratedArchiveIsRejected(): void
	{
		$md5 = $this->writeSource('generated/bundle.md5', md5('another archive') . '  bundle.tar');
		$dst = $this->assetDir . '/virtual-mismatch';
		$asset = $this->virtualArchive($md5);
		$asset->setVerifyChecksum(true);

		try {
			$asset->publish($dst);
			self::fail('A generated archive not matching the checksum is rejected.');
		} catch (TIOException $e) {
			self::assertSame('tarasset_checksum_mismatch', $e->getErrorCode());
		}
		self::assertSame([], $asset->extractedFrom);
		self::assertSame([], static::temporaries($this->assetDir));
		self::assertFalse(is_dir($dst));
	}

	public function testVirtualArchiveCreatesTheParentOfTheDirectory(): void
	{
		$asset = new TProbeTarAsset();
		$asset->virtual = true;
		$asset->archive = TarTestHelper::archive(static::defaultEntries());
		$asset->setAssetFilePath('/virtual/nested/bundle.tar');
		$dst = $this->tempDir . '/new/parent/bundle';

		self::assertTrue($asset->publish($dst));

		self::assertTrue(is_file($dst . '/style.css'));
		self::assertSame([], glob(dirname($dst) . '/tmp-*'), 'The generated archive is removed.');
	}

}
