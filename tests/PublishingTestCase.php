<?php

/**
 * PublishingTestCase class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests;

use PHPUnit\Framework\TestCase;
use Prado\Prado;
use Prado\TApplication;
use Prado\TComponent;
use Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImageFilter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\TPublishingManager;

/**
 * PublishingTestCase class.
 *
 * The shared base for the package tests. Each test gets a private temporary tree with a
 * source directory and an assets directory, a request environment, the global Prado
 * application (shared with the framework's TAssetManagerTest), and helpers to build
 * managers, source files, and images. Class behaviors attached through
 * {@see attachClassBehavior} are detached after the test.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
abstract class PublishingTestCase extends TestCase
{
	/** The alias of the per-test assets directory. */
	public const ASSETS_ALIAS = 'PublishingTestAssets';

	/** The base URL of published assets. */
	public const BASE_URL = '/assets';

	/** @var string the per-test temporary root. */
	protected string $tempDir;

	/** @var string the per-test assets (publishing) directory. */
	protected string $assetDir;

	/** @var string the per-test source directory. */
	protected string $srcDir;

	/** @var array<array{0:string, 1:string}> class behaviors to detach, as [name, class]. */
	private array $_classBehaviors = [];

	protected function setUp(): void
	{
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['SERVER_NAME'] = 'localhost';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/index.php';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['PHP_SELF'] = '/index.php';
		$_SERVER['SCRIPT_FILENAME'] = __FILE__;
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		static::application();
		$this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prado-publishing-' . getmypid() . '-' . bin2hex(random_bytes(4));
		$this->assetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'assets';
		$this->srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
		mkdir($this->assetDir, 0o777, true);
		mkdir($this->srcDir, 0o777, true);
		$this->tempDir = realpath($this->tempDir);
		$this->assetDir = realpath($this->assetDir);
		$this->srcDir = realpath($this->srcDir);
		Prado::setPathOfAlias(static::ASSETS_ALIAS, $this->assetDir);
	}

	protected function tearDown(): void
	{
		foreach (array_reverse($this->_classBehaviors) as [$name, $class]) {
			TComponent::detachClassBehavior($name, $class);
		}
		$this->_classBehaviors = [];
		static::removeTree($this->tempDir);
	}

	/**
	 * @return TApplication the global application, created from the framework test
	 *   application when none exists.
	 */
	protected static function application(): TApplication
	{
		return Prado::getApplication() ?? new TApplication(dirname((new \ReflectionClass(\Prado\Test\Unit\Web\TAssetManagerTest::class))->getFileName()) . '/app');
	}

	/**
	 * @return string the directory of the package test fixtures.
	 */
	protected static function dataDir(): string
	{
		return __DIR__ . DIRECTORY_SEPARATOR . 'data';
	}

	/**
	 * Creates and initializes a publishing manager on the per-test assets directory.
	 * @param array<string, mixed> $properties properties set before initialization.
	 * @param string $class the manager class.
	 * @return TPublishingManager the initialized manager, registered as the application's.
	 */
	protected function newManager(array $properties = [], string $class = TPublishingManager::class): TPublishingManager
	{
		$manager = new $class();
		$manager->setBasePath(static::ASSETS_ALIAS);
		$manager->setBaseUrl(static::BASE_URL);
		foreach ($properties as $name => $value) {
			$manager->{'set' . $name}($value);
		}
		$manager->init(null);
		return $manager;
	}

	/**
	 * Attaches a class behavior for the duration of the test.
	 * @param string $name the behavior name.
	 * @param mixed $behavior the behavior configuration or instance.
	 * @param string $class the class to attach to.
	 * @param null|numeric $priority the behavior priority.
	 */
	protected function attachClassBehavior(string $name, $behavior, string $class, $priority = null): void
	{
		TComponent::attachClassBehavior($name, $behavior, $class, $priority);
		$this->_classBehaviors[] = [$name, $class];
	}

	/**
	 * Writes a source file.
	 * @param string $relative the path relative to the source directory.
	 * @param string $content the file content.
	 * @param ?int $mtime the modification time to set.
	 * @return string the absolute file path.
	 */
	protected function writeSource(string $relative, string $content = 'content', ?int $mtime = null): string
	{
		$path = $this->srcDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0o777, true);
		}
		file_put_contents($path, $content);
		if ($mtime !== null) {
			touch($path, $mtime);
		}
		return $path;
	}

	/**
	 * Creates a true-color GD image, filled with a color, with a mark in the top-left
	 * quadrant so orientation changes are observable.
	 * @param int $width the width.
	 * @param int $height the height.
	 * @param int $color the fill color, 0xRRGGBB.
	 * @param int $mark the top-left quadrant color, 0xRRGGBB.
	 * @return \GdImage the image.
	 */
	protected static function createImage(int $width = 40, int $height = 20, int $color = 0x3366CC, int $mark = 0xFF0000): \GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
		imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, $mark);
		return $image;
	}

	/**
	 * Writes an image source file.
	 * @param string $relative the path relative to the source directory; the extension
	 *   selects the format: jpg, jpeg, png, gif, webp, bmp, wbmp, or xbm.
	 * @param int $width the width.
	 * @param int $height the height.
	 * @param ?\GdImage $image the image to write, default {@see createImage}.
	 * @return string the absolute file path.
	 */
	protected function writeImage(string $relative, int $width = 40, int $height = 20, ?\GdImage $image = null): string
	{
		$path = $this->writeSource($relative, '');
		$image ??= static::createImage($width, $height);
		$ok = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
			'jpg', 'jpeg' => imagejpeg($image, $path, 95),
			'png' => imagepng($image, $path),
			'gif' => imagegif($image, $path),
			'webp' => imagewebp($image, $path, 95),
			'bmp' => imagebmp($image, $path),
			'wbmp' => imagewbmp($image, $path),
			'xbm' => imagexbm($image, $path),
		};
		self::assertTrue($ok, "The $relative fixture image was written.");
		return $path;
	}

	/**
	 * Creates a symbolic link, skipping the test when symbolic links are not supported.
	 * @param string $target the link target.
	 * @param string $link the link path.
	 * @return string the link path.
	 */
	protected function createSymlink(string $target, string $link): string
	{
		if (!function_exists('symlink') || !@symlink($target, $link)) {
			self::markTestSkipped('Symbolic links are not supported.');
		}
		return $link;
	}

	/**
	 * Runs a filter on the parameter's image through an imager.
	 * @param IBaseImagerFilter $filter the filter.
	 * @param TAssetEventParameter $param the parameter holding the image.
	 * @return bool whether the filter changed the image.
	 */
	protected static function applyFilter(IBaseImagerFilter $filter, TAssetEventParameter $param): bool
	{
		$imager = new TAssetImageFilter();
		$imager->addFilter('filter', $filter);
		return $imager->applyFilters($param);
	}

	/**
	 * Converts a published URL into its file path in the assets directory.
	 * @param string $url the published URL, possibly with a query.
	 * @return string the published file path.
	 */
	protected function urlToPath(string $url): string
	{
		$url = explode('?', $url, 2)[0];
		self::assertStringStartsWith(static::BASE_URL . '/', $url, 'The URL is under the base URL.');
		return $this->assetDir . str_replace('/', DIRECTORY_SEPARATOR, substr($url, strlen(static::BASE_URL)));
	}

	/**
	 * @param \GdImage $image the image.
	 * @param int $x the x coordinate.
	 * @param int $y the y coordinate.
	 * @return int the 0xRRGGBB color at the point.
	 */
	protected static function rgbAt(\GdImage $image, int $x, int $y): int
	{
		return imagecolorat($image, $x, $y) & 0xFFFFFF;
	}

	/**
	 * Asserts two colors are within a per-channel tolerance, for lossy formats.
	 * @param int $expected the expected 0xRRGGBB color.
	 * @param int $actual the actual 0xRRGGBB color.
	 * @param int $tolerance the per-channel tolerance.
	 * @param string $message the failure message.
	 */
	protected static function assertColorNear(int $expected, int $actual, int $tolerance = 24, string $message = ''): void
	{
		foreach ([16, 8, 0] as $shift) {
			$e = ($expected >> $shift) & 0xFF;
			$a = ($actual >> $shift) & 0xFF;
			self::assertLessThanOrEqual($tolerance, abs($e - $a), sprintf('%s expected #%06X, got #%06X', $message, $expected, $actual));
		}
	}

	/**
	 * Removes a directory tree, including symbolic links, without following links.
	 * @param string $dir the directory.
	 */
	protected static function removeTree(string $dir): void
	{
		if (is_link($dir) || is_file($dir)) {
			@unlink($dir);
			return;
		}
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				static::removeTree($dir . DIRECTORY_SEPARATOR . $entry);
			}
		}
		@rmdir($dir);
	}
}
