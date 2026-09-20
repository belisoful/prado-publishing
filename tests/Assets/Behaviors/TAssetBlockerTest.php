<?php

/**
 * TAssetBlockerTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use PHPUnit\Framework\Attributes\DataProvider;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Web\Assets\Behaviors\TAssetBlocker;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Tests\Fixtures\TCountingFileAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Web\TAssetManager;

/**
 * TAssetBlockerTest class.
 *
 * Tests the asset blocker: its configuration, the default blocked files, blocking by a
 * custom expression, substituting the NoAsset replacement for a blocked asset, and
 * publishing blocked files and directories end to end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetBlockerTest extends PublishingTestCase
{
	/** The alias of the per-test NoAsset directory. */
	public const NOASSET_ALIAS = 'PublishingTestNoAsset';

	/** @var string the per-test NoAsset directory. */
	protected string $noAssetDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->noAssetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'noasset';
		mkdir($this->noAssetDir);
		Prado::setPathOfAlias(static::NOASSET_ALIAS, $this->noAssetDir);
	}

	/**
	 * @param string $path the asset file path.
	 * @param array $properties the blocker properties.
	 * @param string $class the asset class.
	 * @return TAsset the asset with a blocker attached as "blocker".
	 */
	protected function blockedAsset(string $path, array $properties = [], string $class = TFileAsset::class): TAsset
	{
		$asset = new $class($path);
		$asset->attachBehavior('blocker', ['class' => TAssetBlocker::class] + $properties);
		return $asset;
	}

	/**
	 * @param string $name
	 * @param string $content
	 * @return string the NoAsset replacement file path, written with 'no asset'.
	 */
	protected function writeNoAsset(string $name = 'blocked.txt', string $content = 'no asset'): string
	{
		file_put_contents($path = $this->noAssetDir . DIRECTORY_SEPARATOR . $name, $content);
		return $path;
	}

	public function testDefaults(): void
	{
		$blocker = new TAssetBlocker();

		self::assertSame('/(\/\.|\.php$|cron\.jobs$|\.cache$|protected.application\.xml$|config\.xml$)/i', $blocker->getBlockedFiles());
		self::assertSame('Application.Pages', $blocker->getNoAssetPath());
		self::assertNull($blocker->getNoAssetFile());
	}

	public function testPropertiesAreStrings(): void
	{
		$blocker = new TAssetBlocker();

		$blocker->setBlockedFiles('/\.pdf$/');
		$blocker->setNoAssetPath('Application.images');
		$blocker->setNoAssetFile(404);

		self::assertSame('/\.pdf$/', $blocker->getBlockedFiles());
		self::assertSame('Application.images', $blocker->getNoAssetPath());
		self::assertSame('404', $blocker->getNoAssetFile());
	}

	public function testSettersResetTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.txt'));
		$asset->attachBehavior('blocker', TAssetBlocker::class);
		$blocker = $asset->asa('blocker');

		foreach (['BlockedFiles' => '/x/', 'NoAssetPath' => 'A.b', 'NoAssetFile' => 'x.txt'] as $property => $value) {
			$resets = $asset->resets;
			$blocker->{'set' . $property}($value);
			self::assertSame($resets + 1, $asset->resets, "Setting $property resets the owner's file path cache.");
		}
	}

	public function testSettersOfADisabledBehaviorDoNotResetTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.txt'));
		$asset->attachBehavior('blocker', TAssetBlocker::class);
		$blocker = $asset->asa('blocker');
		$blocker->setEnabled(false);
		$resets = $asset->resets;

		$blocker->setBlockedFiles('/x/');
		$blocker->setNoAssetPath('A.b');
		$blocker->setNoAssetFile('x.txt');

		self::assertSame($resets, $asset->resets);
	}

	public function testChangingBlockedFilesRecomputesTheFilePath(): void
	{
		$source = $this->writeSource('report.pdf');
		$asset = $this->blockedAsset($source);
		self::assertSame($source, $asset->getAssetFilePath());

		$asset->asa('blocker')->setBlockedFiles('/\.pdf$/');

		self::assertNull($asset->getAssetFilePath());
		self::assertNull($asset->getAssetOriginalFilePath());
	}

	public static function defaultBlockedFiles(): array
	{
		return [
			'hidden file' => ['.env', true],
			'file in a hidden directory' => ['.git/config', true],
			'file under a hidden ancestor directory' => ['.config/web/assets/app.js', true],
			'php file' => ['index.php', true],
			'uppercase php file' => ['INDEX.PHP', true],
			'cron jobs' => ['cron.jobs', true],
			'cache file' => ['data.cache', true],
			'protected application.xml' => ['protected/application.xml', true],
			'config.xml' => ['pages/config.xml', true],
			'css file' => ['style.css', false],
			'php in the name' => ['php.txt', false],
			'application.xml outside protected' => ['application.xml', false],
			'dotted name' => ['jquery.min.js', false],
		];
	}

	#[DataProvider('defaultBlockedFiles')]
	public function testDefaultBlockedFiles(string $relative, bool $blocked): void
	{
		$source = $this->writeSource($relative);
		$asset = $this->blockedAsset($source);

		if ($blocked) {
			self::assertNull($asset->getAssetFilePath(), "$relative is blocked.");
		} else {
			self::assertSame($source, $asset->getAssetFilePath(), "$relative is not blocked.");
		}
	}

	public function testBlockingAVirtualAsset(): void
	{
		$asset = $this->blockedAsset('/virtual/secret.json', ['BlockedFiles' => '/secret/'], TGeneratedAsset::class);

		self::assertNull($asset->getAssetFilePath());
		self::assertFalse($asset->publish($this->assetDir . '/secret.json'));
		self::assertFalse(is_file($this->assetDir . '/secret.json'));
	}

	public function testNoAssetReplacesABlockedFile(): void
	{
		$noAsset = $this->writeNoAsset();
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'blocked.txt',
		]);

		self::assertSame($noAsset, $asset->getAssetOriginalFilePath());
		self::assertSame($noAsset, $asset->getAssetFilePath());
	}

	public function testNoAssetInANamespaceSubdirectory(): void
	{
		mkdir($this->noAssetDir . '/images');
		file_put_contents($noAsset = $this->noAssetDir . '/images/none.svg', '<svg/>');
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS . '.images',
			'NoAssetFile' => 'none.svg',
		]);

		self::assertSame($noAsset, $asset->getAssetFilePath());
	}

	public function testNoAssetDoesNotReplaceAnUnblockedFile(): void
	{
		$this->writeNoAsset();
		$source = $this->writeSource('movie.mov');
		$asset = $this->blockedAsset($source, [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'blocked.txt',
		]);

		self::assertSame($source, $asset->getAssetFilePath());
	}

	public function testNoAssetFileWithoutAPathBlocks(): void
	{
		$this->writeNoAsset();
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => '',
			'NoAssetFile' => 'blocked.txt',
		]);

		self::assertNull($asset->getAssetFilePath());
	}

	public function testAnEmptyNoAssetFileBlocks(): void
	{
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => '',
		]);

		self::assertNull($asset->getAssetFilePath());
	}

	public function testANoAssetMatchingItsOwnBlockerIsNotBlocked(): void
	{
		$noAsset = $this->writeNoAsset('blocked.avi');
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'blocked.avi',
		]);

		self::assertSame($noAsset, $asset->getAssetFilePath());
	}

	public function testASymbolicLinkToABlockedFileIsBlocked(): void
	{
		$report = $this->writeSource('report.pdf');
		$link = $this->createSymlink($report, $this->srcDir . DIRECTORY_SEPARATOR . 'report.txt');
		$asset = $this->blockedAsset($link, ['BlockedFiles' => '/\.pdf$/']);

		self::assertSame(0, preg_match('/\.pdf$/', $link), 'The link itself does not match.');
		self::assertNull($asset->getAssetFilePath(), 'The link resolves to a blocked file.');
		self::assertNull($asset->getAssetOriginalFilePath());
	}

	public function testASymbolicLinkToABlockedFileIsSubstituted(): void
	{
		$noAsset = $this->writeNoAsset('unavailable.txt');
		$link = $this->createSymlink($this->writeSource('report.pdf'), $this->srcDir . DIRECTORY_SEPARATOR . 'report.txt');
		$asset = $this->blockedAsset($link, [
			'BlockedFiles' => '/\.pdf$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'unavailable.txt',
		]);

		self::assertSame($noAsset, $asset->getAssetFilePath());
	}

	public function testTheNoAssetMatchingTheBlockedFilesIsPublished(): void
	{
		$noAsset = $this->writeNoAsset('unavailable.pdf');
		$properties = [
			'BlockedFiles' => '/\.pdf$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'unavailable.pdf',
		];

		self::assertSame($noAsset, $this->blockedAsset($noAsset, $properties)->getAssetFilePath(), 'The placeholder itself passes.');

		$link = $this->createSymlink($this->writeSource('report.pdf'), $this->srcDir . DIRECTORY_SEPARATOR . 'report.txt');
		self::assertSame($noAsset, $this->blockedAsset($link, $properties)->getAssetFilePath(), 'A link substituted after resolution passes as the placeholder.');
	}

	public function testAnotherBlockerCanBlockTheNoAsset(): void
	{
		$this->writeNoAsset('blocked.avi');
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'blocked.avi',
		]);
		$asset->attachBehavior('noAssetBlocker', ['class' => TAssetBlocker::class, 'BlockedFiles' => '/\/blocked\.avi$/']);

		self::assertNull($asset->getAssetFilePath());
	}

	public function testAMissingNoAssetFileIsInvalid(): void
	{
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'missing.txt',
		]);

		try {
			$asset->getAssetFilePath();
			self::fail('A missing NoAsset file is invalid.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
		}
	}

	public function testAnUnresolvableNoAssetPathIsInvalid(): void
	{
		$asset = $this->blockedAsset($this->writeSource('movie.avi'), [
			'BlockedFiles' => '/\.avi$/',
			'NoAssetPath' => 'NoSuchPublishingAlias.dir',
			'NoAssetFile' => 'prado-publishing-missing-' . bin2hex(random_bytes(4)) . '.txt',
		]);

		try {
			$asset->getAssetFilePath();
			self::fail('An unresolvable NoAsset path is invalid.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
		}
	}

	public function testDisabledBlockerDoesNotBlock(): void
	{
		$source = $this->writeSource('secret.txt');
		$asset = $this->blockedAsset($source, ['BlockedFiles' => '/secret/']);
		self::assertNull($asset->getAssetFilePath());

		$asset->disableBehavior('blocker');
		self::assertSame($source, $asset->getAssetFilePath());

		$asset->enableBehavior('blocker');
		self::assertNull($asset->getAssetFilePath());
	}

	public function testPublishSubstitutesTheNoAsset(): void
	{
		$noAsset = $this->writeNoAsset('unavailable.txt', 'unavailable');
		$this->attachClassBehavior('blocker', [
			'class' => TAssetBlocker::class,
			'BlockedFiles' => '/\.pdf$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'unavailable.txt',
		], TAsset::class);
		$source = $this->writeSource('docs/report.pdf', 'secret report');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertStringEndsWith('/unavailable.txt', $url);
		self::assertSame('unavailable', file_get_contents($this->urlToPath($url)));
		self::assertSame($manager->getPublishedUrl($noAsset), $url, 'The NoAsset publishes where it publishes itself.');
		self::assertSame($url, $manager->getPublishedUrl($source));
		self::assertSame([], glob($this->assetDir . '/*/report.pdf'), 'The blocked file is not published.');
	}

	public function testPublishDirectorySubstitutesTheNoAssetInPlace(): void
	{
		$this->writeNoAsset('unavailable.txt', 'unavailable');
		$this->attachClassBehavior('blocker', [
			'class' => TAssetBlocker::class,
			'BlockedFiles' => '/\.pdf$/',
			'NoAssetPath' => static::NOASSET_ALIAS,
			'NoAssetFile' => 'unavailable.txt',
		], TAsset::class);
		$this->writeSource('site/report.pdf', 'secret report');
		$this->writeSource('site/style.css', 'body{}');

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/site'));

		self::assertSame('body{}', file_get_contents($dir . '/style.css'));
		self::assertFalse(is_file($dir . '/report.pdf'));
		self::assertSame('unavailable', file_get_contents($dir . '/unavailable.txt'));
	}

	public function testDefaultBlockerOnPublish(): void
	{
		$this->attachClassBehavior('blocker', TAssetBlocker::class, TAsset::class);
		$this->writeSource('app/.htaccess', 'deny');
		$this->writeSource('app/index.php', '<?php');
		$this->writeSource('app/config.xml', '<configuration/>');
		$this->writeSource('app/app.js', 'js');
		$manager = $this->newManager();

		self::assertSame('', $manager->publish($this->srcDir . '/app/index.php'));
		$dir = $this->urlToPath($manager->publish($this->srcDir . '/app'));

		self::assertSame(['app.js'], array_values(array_filter(scandir($dir), fn ($f) => $f[0] !== '.' && !str_starts_with($f, TAssetManager::DIRECTORY_COMPLETE_MARKER_PREFIX))));
	}
}
