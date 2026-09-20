<?php

/**
 * TAssetFormatBehaviorsTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\Util\IBaseBehavior;
use Prado\Web\Assets\Behaviors\TAssetGIFize;
use Prado\Web\Assets\Behaviors\TAssetPNGColorMode;
use Prado\Web\Assets\Behaviors\TAssetPNGize;
use Prado\Web\Assets\Behaviors\TAssetWebPFallbackMode;
use Prado\Web\Assets\Behaviors\TAssetWebPize;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;

/**
 * TAssetFormatBehaviorsTest class.
 *
 * Tests the palette handling of the format conversion behaviors: {@see TAssetPNGize}
 * PNG color modes, {@see TAssetGIFize} palette colors, and {@see TAssetWebPize}
 * properties, browser support, PNG fallbacks, and WebP sources.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetFormatBehaviorsTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		unset($_SERVER['HTTP_ACCEPT']);
	}

	protected function tearDown(): void
	{
		unset($_SERVER['HTTP_ACCEPT']);
		parent::tearDown();
	}

	/**
	 * Attaches a format behavior to TImageAsset as a class behavior, replacing a prior one.
	 * @param string $class the format behavior class.
	 * @param array<string, mixed> $properties the behavior properties.
	 * @param string $xml the inner XML of the behavior element.
	 */
	protected function attachFormat(string $class, array $properties = [], string $xml = ''): void
	{
		TComponent::detachClassBehavior('format', TImageAsset::class);
		$config = ['class' => $class] + $properties;
		if ($xml !== '') {
			$doc = new TXmlDocument();
			$doc->loadFromString('<behavior>' . $xml . '</behavior>');
			$config[IBaseBehavior::CONFIG_KEY] = $doc;
		}
		$this->attachClassBehavior('format', $config, TImageAsset::class);
	}

	/**
	 * Publishes a source with a new manager into an emptied assets directory.
	 * @param string $source the source file.
	 * @return string the published URL.
	 */
	protected function publishFresh(string $source): string
	{
		static::removeTree($this->assetDir);
		mkdir($this->assetDir);
		return $this->newManager()->publish($source);
	}

	/**
	 * @return \GdImage a 64x64 image with many colors.
	 */
	protected static function colorfulImage(): \GdImage
	{
		$image = imagecreatetruecolor(64, 64);
		for ($y = 0; $y < 64; $y++) {
			for ($x = 0; $x < 64; $x++) {
				imagesetpixel($image, $x, $y, ($x * 4 << 16) | ($y * 4 << 8) | 128);
			}
		}
		return $image;
	}

	/**
	 * @param string $file a PNG file.
	 * @return int the IHDR color type: 2 or 6 true color, 3 palette.
	 */
	protected static function pngColorType(string $file): int
	{
		$data = file_get_contents($file);
		self::assertSame("\x89PNG\r\n\x1a\n", substr($data, 0, 8), 'The file is a PNG.');
		return ord($data[25]);
	}

	public function testPNGizeColorModes(): void
	{
		$source = $this->writeImage('colorful.jpg', 64, 64, static::colorfulImage());

		$this->attachFormat(TAssetPNGize::class);
		$url = $this->publishFresh($source);
		self::assertStringEndsWith('/colorful.png', $url);
		self::assertNotSame(3, static::pngColorType($this->urlToPath($url)), 'Preserve keeps the true color JPEG in true color.');

		$this->attachFormat(TAssetPNGize::class, ['PngColorMode' => TAssetPNGColorMode::Palette, 'PaletteColors' => 16]);
		$published = $this->urlToPath($this->publishFresh($source));
		self::assertSame(3, static::pngColorType($published), 'Palette saves a palette PNG.');
		$image = imagecreatefrompng($published);
		self::assertLessThanOrEqual(17, imagecolorstotal($image), 'PaletteColors, and the transparent color.');
		self::assertGreaterThan(8, imagecolorstotal($image));

		$this->attachFormat(TAssetPNGize::class, ['PngColorMode' => TAssetPNGColorMode::Palette]);
		$published = $this->urlToPath($this->publishFresh($source));
		self::assertSame(3, static::pngColorType($published));
		self::assertGreaterThan(17, imagecolorstotal(imagecreatefrompng($published)), 'Without PaletteColors, the palette has up to 256 colors.');

		$gif = imagecreate(40, 20);
		imagecolorallocate($gif, 0, 0, 255);
		imagefilledrectangle($gif, 0, 0, 19, 9, imagecolorallocate($gif, 255, 255, 0));
		$paletteSource = $this->writeImage('palette.gif', 40, 20, $gif);

		$this->attachFormat(TAssetPNGize::class, ['PngColorMode' => TAssetPNGColorMode::TrueColor]);
		$url = $this->publishFresh($paletteSource);
		self::assertStringEndsWith('/palette.png', $url);
		$published = $this->urlToPath($url);
		self::assertNotSame(3, static::pngColorType($published), 'TrueColor saves the palette GIF in true color.');
		$image = imagecreatefrompng($published);
		self::assertTrue(imageistruecolor($image));
		self::assertSame(0xFFFF00, static::rgbAt($image, 5, 5));
		self::assertSame(0x0000FF, static::rgbAt($image, 30, 15));

		$this->attachFormat(TAssetPNGize::class);
		self::assertSame(3, static::pngColorType($this->urlToPath($this->publishFresh($paletteSource))), 'Preserve keeps the palette of the GIF.');
	}

	public function testGIFizePaletteColors(): void
	{
		$source = $this->writeImage('colorful.png', 64, 64, static::colorfulImage());

		$this->attachFormat(TAssetGIFize::class, ['PaletteColors' => 4, 'PaletteDither' => false]);
		$url = $this->publishFresh($source);
		self::assertStringEndsWith('/colorful.gif', $url);
		$image = imagecreatefromgif($this->urlToPath($url));
		self::assertLessThanOrEqual(5, imagecolorstotal($image), 'Four colors, and the transparent color.');
		$colors = [];
		for ($y = 0; $y < 64; $y += 3) {
			for ($x = 0; $x < 64; $x += 3) {
				$colors[imagecolorat($image, $x, $y)] = true;
			}
		}
		self::assertLessThanOrEqual(4, count($colors), 'The pixels use at most four colors.');

		$this->attachFormat(TAssetGIFize::class);
		$image = imagecreatefromgif($this->urlToPath($this->publishFresh($source)));
		self::assertGreaterThan(64, imagecolorstotal($image), 'Without PaletteColors, GIF has up to 256 colors.');

		$this->attachFormat(TAssetGIFize::class, ['ToBlackAndWhite' => true]);
		$image = imagecreatefromgif($this->urlToPath($this->publishFresh($source)));
		self::assertSame(2, imagecolorstotal($image), 'Black and white is two colors.');
	}

	public function testWebPizeProperties(): void
	{
		$webp = new TAssetWebPize();
		self::assertSame(TAssetWebPFallbackMode::Preserve, $webp->getWebPFallback());
		$webp->setWebPFallback('PNGPalette');
		self::assertSame(TAssetWebPFallbackMode::PNGPalette, $webp->getWebPFallback());
		$webp->setWebPFallback(TAssetWebPFallbackMode::JPEG);
		self::assertSame(TAssetWebPFallbackMode::JPEG, $webp->getWebPFallback());

		self::assertSame(256, $webp->getWebPFallbackPngPaletteColors());
		$webp->setWebPFallbackPngPaletteColors('32');
		self::assertSame(32, $webp->getWebPFallbackPngPaletteColors());
		$webp->setWebPFallbackPngPaletteColors(1000);
		self::assertSame(256, $webp->getWebPFallbackPngPaletteColors());
		$webp->setWebPFallbackPngPaletteColors(-5);
		self::assertSame(0, $webp->getWebPFallbackPngPaletteColors());

		$this->expectException(TInvalidDataValueException::class);
		$webp->setWebPFallback('GIF');
	}

	public function testBrowserSupportsWebP(): void
	{
		self::assertFalse(TAssetWebPize::browserSupportsWebP(), 'No Accept header.');
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/png,*/*';
		self::assertFalse(TAssetWebPize::browserSupportsWebP());
		$_SERVER['HTTP_ACCEPT'] = 'image/avif,IMAGE/WEBP;q=0.9';
		self::assertTrue(TAssetWebPize::browserSupportsWebP(), 'The media type is case insensitive.');
	}

	public function testWebPizePngFallbacks(): void
	{
		$_SERVER['HTTP_ACCEPT'] = 'image/png,*/*';
		$source = $this->writeImage('fallback.jpg', 64, 64, static::colorfulImage());

		$this->attachFormat(TAssetWebPize::class, ['WebPFallback' => TAssetWebPFallbackMode::PNG]);
		$url = $this->publishFresh($source);
		self::assertStringEndsWith('/fallback.png', $url);
		self::assertNotSame(3, static::pngColorType($this->urlToPath($url)), 'The PNG fallback is true color.');

		$expected = [16 => 17, 1 => 257, 0 => 257];
		foreach ($expected as $colors => $maximum) {
			$this->attachFormat(TAssetWebPize::class, ['WebPFallback' => TAssetWebPFallbackMode::PNGPalette, 'WebPFallbackPngPaletteColors' => $colors]);
			$url = $this->publishFresh($source);
			self::assertStringEndsWith('/fallback.png', $url);
			$published = $this->urlToPath($url);
			self::assertSame(3, static::pngColorType($published), 'The PNGPalette fallback is a palette PNG.');
			$total = imagecolorstotal(imagecreatefrompng($published));
			self::assertLessThanOrEqual($maximum, $total, "$colors fallback palette colors.");
			self::assertGreaterThan($colors >= 2 ? $colors / 2 : 17, $total, "$colors fallback palette colors.");
		}

		$_SERVER['HTTP_ACCEPT'] = 'image/webp,*/*';
		$url = $this->publishFresh($source);
		self::assertStringEndsWith('/fallback.webp', $url, 'With WebP support, the fallback is unused.');
		self::assertSame(IMAGETYPE_WEBP, getimagesize($this->urlToPath($url))[2]);
	}

	public function testWebPizeProcessesWebPSourcesWithoutBrowserSupport(): void
	{
		$_SERVER['HTTP_ACCEPT'] = 'image/png,*/*';
		$this->attachFormat(TAssetWebPize::class, [], '<filter type="Resize" MaximumWidth="10" />');
		$webp = $this->writeImage('photo.webp');
		$png = $this->writeImage('photo.png');

		$webpUrl = $this->newManager()->publish($webp);
		$pngUrl = $this->newManager()->publish($png);

		self::assertStringEndsWith('/photo.webp', $webpUrl);
		self::assertSame([10, 5, IMAGETYPE_WEBP], array_slice(getimagesize($this->urlToPath($webpUrl)), 0, 3), 'A WebP destination is processed and stays WebP.');
		self::assertStringEndsWith('/photo.png', $pngUrl);
		self::assertSame([40, 20, IMAGETYPE_PNG], array_slice(getimagesize($this->urlToPath($pngUrl)), 0, 3), 'Other images are not processed.');

		$asset = new TImageAsset($png);
		$asset->attachBehavior('webp', $behavior = new TAssetWebPize());
		self::assertFalse($behavior->hasMatch());
		self::assertFalse($behavior->hasMatch($this->assetDir . '/photo.png'));
		self::assertTrue($behavior->hasMatch($this->assetDir . '/photo.WEBP'), 'A WebP destination matches.');
	}
}
