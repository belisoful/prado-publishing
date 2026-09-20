<?php

/**
 * TAssetVirtualPathTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use Prado\Web\Assets\TAsset;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetVirtualPathTest class.
 *
 * Tests {@see TAsset::virtualpath} with an explicit separator, so the Windows forms are
 * verified on any platform: drive roots, UNC roots, ".." clamped at a root, both
 * separators, and relative paths made absolute from the working directory.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetVirtualPathTest extends PublishingTestCase
{
	public static function windowsPathProvider(): array
	{
		return [
			'drive path' => ['C:\\a\\b.txt', 'C:\\a\\b.txt'],
			'drive dot segments' => ['C:\\a\\..\\b', 'C:\\b'],
			'drive only' => ['C:', 'C:\\'],
			'drive root' => ['C:\\', 'C:\\'],
			'drive parent clamped at the root' => ['C:\\..\\..\\x', 'C:\\x'],
			'drive root parent' => ['D:\\a\\..\\..', 'D:\\'],
			'lowercase drive with slashes' => ['c:/a/./b/', 'c:\\a\\b'],
			'drive duplicate separators' => ['C:\\\\a\\\\\\b', 'C:\\a\\b'],
			'unc path' => ['\\\\srv\\share\\x\\y.txt', '\\\\srv\\share\\x\\y.txt'],
			'unc parent clamped at the share' => ['\\\\srv\\share\\x\\..\\..\\y', '\\\\srv\\share\\y'],
			'unc share only' => ['\\\\srv\\share', '\\\\srv\\share\\'],
			'unc share parent' => ['\\\\srv\\share\\..\\..', '\\\\srv\\share\\'],
			'unc with slashes' => ['//srv/share/a/../b', '\\\\srv\\share\\b'],
			'rooted without a drive' => ['\\a\\.\\b\\..\\c', '\\a\\c'],
			'root parent' => ['\\..', '\\'],
		];
	}

	#[DataProvider('windowsPathProvider')]
	public function testWindowsPaths(string $path, string $expected): void
	{
		self::assertSame($expected, TAsset::virtualpath($path, '\\'));
	}

	public function testWindowsRelativePathsResolveFromTheWorkingDirectory(): void
	{
		$cwd = TAsset::virtualpath(getcwd(), '\\');

		self::assertStringStartsWith('\\', $cwd);
		self::assertSame($cwd . '\\x\\y.txt', TAsset::virtualpath('x\\.\\z\\..\\y.txt', '\\'));
		self::assertSame($cwd . '\\x\\y.txt', TAsset::virtualpath('x/y.txt', '\\'), 'Both separators separate segments.');
		self::assertSame($cwd, TAsset::virtualpath('', '\\'));
	}

	public static function slashPathProvider(): array
	{
		return [
			'drive with slashes' => ['C:/a/../b', 'C:/b'],
			'drive with backslashes' => ['C:\\a\\b', 'C:/a/b'],
			'drive only' => ['C:', 'C:/'],
			'drive parent clamped at the root' => ['C:/../x', 'C:/x'],
			'double slash is not a unc root' => ['//srv/share/../x', '/srv/x'],
			'backslashes' => ['\\a\\..\\b', '/b'],
		];
	}

	#[DataProvider('slashPathProvider')]
	public function testSlashSeparatedPaths(string $path, string $expected): void
	{
		self::assertSame($expected, TAsset::virtualpath($path, '/'));
	}

	public function testTheDefaultSeparatorIsTheDirectorySeparator(): void
	{
		self::assertSame(TAsset::virtualpath('/a/../b', DIRECTORY_SEPARATOR), TAsset::virtualpath('/a/../b'));
		self::assertSame(TAsset::virtualpath('C:/a', DIRECTORY_SEPARATOR), TAsset::virtualpath('C:/a'));
	}
}
