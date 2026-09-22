<?php

/**
 * TAssetImageFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TTIFF;
use Prado\Util\IBaseBehavior;
use Prado\Web\Assets\Behaviors\TAssetImageFilter;
use Prado\Web\Assets\Behaviors\TAssetPNGColorMode;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TGraphicslessImageFilter;
use Prado\Web\Tests\Fixtures\TNoInterchangeImageFilter;
use Prado\Web\Tests\Fixtures\TRecordingImagerFilter;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;

/**
 * TAssetImageFilterTest class.
 *
 * Tests the saving properties of {@see TAssetImageFilter} and their effect on the saved
 * file (quality, PNG color mode, alpha, interlace, background, the palette properties,
 * and black and white), and each branch of its static image helpers.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetImageFilterTest extends PublishingTestCase
{
	/**
	 * Attaches an imager to TImageAsset as a class behavior. A recording filter marks
	 * every image as changed, so the image is always saved with the saving properties.
	 * @param string $class the imager class.
	 * @param array<string, mixed> $properties the imager properties.
	 * @param string $name the behavior name.
	 */
	protected function attachSavingImager(string $class = TAssetImageFilter::class, array $properties = [], string $name = 'imager'): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><filter class="' . TRecordingImagerFilter::class . '" /></behavior>');
		$this->attachClassBehavior($name, ['class' => $class, IBaseBehavior::CONFIG_KEY => $doc] + $properties, TImageAsset::class);
	}

	/**
	 * @param string $file a PNG file.
	 * @return array{colorType:int, interlace:int} the IHDR color type and interlace method.
	 */
	protected static function pngHeader(string $file): array
	{
		$data = file_get_contents($file);
		self::assertSame("\x89PNG\r\n\x1a\n", substr($data, 0, 8), 'The file is a PNG.');
		self::assertSame('IHDR', substr($data, 12, 4));
		return ['colorType' => ord($data[25]), 'interlace' => ord($data[28])];
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @return \GdImage a true color image with a varied, deterministic content.
	 */
	protected static function noiseImage(int $width = 64, int $height = 64): \GdImage
	{
		mt_srand(7);
		$image = imagecreatetruecolor($width, $height);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
			}
		}
		return $image;
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @return \GdImage a horizontal gray gradient, black to white.
	 */
	protected static function gradientImage(int $width = 64, int $height = 8): \GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		for ($x = 0; $x < $width; $x++) {
			$v = intdiv($x * 255, $width - 1);
			imageline($image, $x, 0, $x, $height - 1, ($v << 16) | ($v << 8) | $v);
		}
		return $image;
	}

	/**
	 * @param \GdImage $image a true color image.
	 * @param int $x
	 * @param int $y
	 * @param int $argb the 0xAARRGGBB pixel.
	 */
	protected static function setPixel(\GdImage $image, int $x, int $y, int $argb): void
	{
		imagealphablending($image, false);
		imagesetpixel($image, $x, $y, $argb);
	}

	/**
	 * @param \GdImage $image a true color or palette image.
	 * @param int $x
	 * @param int $y
	 * @return int the 0xRRGGBB color at the point, resolving palette indexes.
	 */
	protected static function colorAt(\GdImage $image, int $x, int $y): int
	{
		$c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
		return ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
	}

	/**
	 * @param \GdImage $image a palette image.
	 * @return array<int, array{0:int, 1:int, 2:int, 3:int}> the palette as [red, green, blue, alpha].
	 */
	protected static function palette(\GdImage $image): array
	{
		$palette = [];
		for ($i = 0, $n = imagecolorstotal($image); $i < $n; $i++) {
			$c = imagecolorsforindex($image, $i);
			$palette[$i] = [$c['red'], $c['green'], $c['blue'], $c['alpha']];
		}
		return $palette;
	}

	// ---------------------------------------------------------------------------
	// Property accessors.
	// ---------------------------------------------------------------------------

	public function testQualityPropertiesAreClamped(): void
	{
		$filter = new TAssetImageFilter();
		self::assertSame(75, $filter->getImageQuality());
		self::assertSame(-1, $filter->getPngQuality());

		$filter->setImageQuality('120');
		self::assertSame(100, $filter->getImageQuality());
		$filter->setImageQuality(-20);
		self::assertSame(-1, $filter->getImageQuality());

		$filter->setPngQuality('6');
		self::assertSame(6, $filter->getPngQuality());
		$filter->setPngQuality(15);
		self::assertSame(9, $filter->getPngQuality());
		$filter->setPngQuality(-4);
		self::assertSame(-1, $filter->getPngQuality());
	}

	public function testSetQualityMapsToImageAndPngQuality(): void
	{
		$filter = new TAssetImageFilter();
		$asset = new TImageAsset($this->writeImage('quality.png'));
		$asset->attachBehavior('imager', $filter);

		$expected = [100 => 0, 99 => 1, 90 => 1, 89 => 1, 88 => 2, 50 => 5, 1 => 9, 0 => 9];
		foreach ($expected as $quality => $pngQuality) {
			self::assertSame($quality, $asset->dySetQuality($quality), 'The dynamic event returns the quality.');
			self::assertSame($quality, $filter->getImageQuality());
			self::assertSame($pngQuality, $filter->getPngQuality(), "Quality $quality maps to PNG quality $pngQuality.");
		}

		self::assertSame(-1, $filter->dySetQuality(-1), 'Without a call chain, the quality is returned.');
		self::assertSame(-1, $filter->getImageQuality());
		self::assertSame(-1, $filter->getPngQuality(), 'The default quality maps to the default PNG compression.');
		$filter->dySetQuality(150);
		self::assertSame(100, $filter->getImageQuality());
		self::assertSame(0, $filter->getPngQuality());
	}

	public function testSavingPropertyAccessors(): void
	{
		$filter = new TAssetImageFilter();

		self::assertSame(TAssetPNGColorMode::Preserve, $filter->getPngColorMode());
		$filter->setPngColorMode('Palette');
		self::assertSame(TAssetPNGColorMode::Palette, $filter->getPngColorMode());

		self::assertTrue($filter->getSaveAlpha());
		$filter->setSaveAlpha('false');
		self::assertFalse($filter->getSaveAlpha());

		self::assertNull($filter->getSaveInterlace());
		$filter->setSaveInterlace('true');
		self::assertTrue($filter->getSaveInterlace());
		$filter->setSaveInterlace(false);
		self::assertFalse($filter->getSaveInterlace());
		$filter->setSaveInterlace('');
		self::assertNull($filter->getSaveInterlace(), 'An empty string restores the encoder default.');
		$filter->setSaveInterlace(true);
		$filter->setSaveInterlace(null);
		self::assertNull($filter->getSaveInterlace());

		self::assertSame('#000000', $filter->getBackgroundColor());
		$filter->setBackgroundColor('Red');
		self::assertSame('#FF0000', $filter->getBackgroundColor(), 'Web color names are accepted.');
		$filter->setBackgroundColor('#0f0');
		self::assertSame('#00FF00', $filter->getBackgroundColor());
		$filter->setBackgroundColor(0x123456);
		self::assertSame('#123456', $filter->getBackgroundColor());

		self::assertFalse($filter->getPalettizeTrueColor());
		$filter->setPalettizeTrueColor('true');
		self::assertTrue($filter->getPalettizeTrueColor());

		self::assertTrue($filter->getPaletteDither());
		$filter->setPaletteDither('0');
		self::assertFalse($filter->getPaletteDither());

		self::assertFalse($filter->getPaletteColorIsTransparent());
		$filter->setPaletteColorIsTransparent('true');
		self::assertTrue($filter->getPaletteColorIsTransparent());
	}

	public function testPaletteColorsAndBlackAndWhite(): void
	{
		$filter = new TAssetImageFilter();
		self::assertSame(0, $filter->getPaletteColors());

		$filter->setPaletteColors('16');
		self::assertSame(16, $filter->getPaletteColors());
		$filter->setPaletteColors(1000);
		self::assertSame(256, $filter->getPaletteColors());
		$filter->setPaletteColors(-3);
		self::assertSame(0, $filter->getPaletteColors());
		$filter->setPaletteColors(2);
		self::assertSame(2, $filter->getPaletteColors());
		$filter->setPaletteColors(1);
		self::assertSame(0, $filter->getPaletteColors(), 'A one color palette is no palette.');

		self::assertFalse($filter->getToBlackAndWhite());
		$filter->setPaletteColors(64);
		$filter->setToBlackAndWhite('true');
		self::assertTrue($filter->getToBlackAndWhite());
		self::assertSame(2, $filter->getPaletteColors(), 'Black and white is a two color palette.');
		$filter->setToBlackAndWhite(false);
		self::assertSame(64, $filter->getPaletteColors());
	}

	public function testPaletteAlphaColor(): void
	{
		$filter = new TAssetImageFilter();
		self::assertSame('#7F000000', $filter->getPaletteAlphaColor());

		$filter->setPaletteAlphaColor('#7F00ff00');
		self::assertSame('#7F00ff00', $filter->getPaletteAlphaColor(), 'An #AARRGGBB color is kept.');
		$filter->setPaletteAlphaColor(5);
		self::assertSame(5, $filter->getPaletteAlphaColor(), 'A GD color is kept.');
		$filter->setPaletteAlphaColor('2130706432');
		self::assertSame('2130706432', $filter->getPaletteAlphaColor());
		$filter->setPaletteAlphaColor('sides');
		self::assertSame('sides', $filter->getPaletteAlphaColor());
		$filter->setPaletteAlphaColor('Red');
		self::assertSame('#00FF0000', $filter->getPaletteAlphaColor(), 'A web color is opaque.');
		$filter->setPaletteAlphaColor('#abc');
		self::assertSame('#00AABBCC', $filter->getPaletteAlphaColor());
		$filter->setPaletteAlphaColor('#123456');
		self::assertSame('#00123456', $filter->getPaletteAlphaColor());

		try {
			$filter->setPaletteAlphaColor('#80FF0000');
			self::fail('An alpha over 0x7F is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('propertyvalue_invalid_hex_color', $e->getErrorCode());
		}
	}

	public function testPaletteAlphaColorIndex(): void
	{
		$filter = new TAssetImageFilter();
		$method = new \ReflectionMethod($filter, 'getPaletteAlphaColorIndex');
		$image = imagecreatetruecolor(2, 2);

		self::assertSame(0x7F000000, $method->invoke($filter), 'Without an image, the ARGB color is packed.');
		$filter->setPaletteAlphaColor('#3F102030');
		self::assertSame(0x3F102030, $method->invoke($filter));
		self::assertSame(0x3F102030, $method->invoke($filter, $image), 'A true color image allocates the ARGB color.');

		$palette = imagecreate(2, 2);
		imagecolorallocate($palette, 1, 2, 3);
		$index = $method->invoke($filter, $palette);
		self::assertSame(1, $index, 'A palette image allocates a palette entry.');
		self::assertSame(['red' => 0x10, 'green' => 0x20, 'blue' => 0x30, 'alpha' => 0x3F], imagecolorsforindex($palette, $index));

		$filter->setPaletteAlphaColor('42');
		self::assertSame(42, $method->invoke($filter, $image), 'A numeric color is used as is.');
		$filter->setPaletteAlphaColor('Sides');
		self::assertSame('Sides', $method->invoke($filter, $image));
	}

	public function testPaletteAlphaThresholdIsClamped(): void
	{
		$filter = new TAssetImageFilter();
		self::assertSame(-1, $filter->getPaletteAlphaThreshold());
		$filter->setPaletteAlphaThreshold('64');
		self::assertSame(64, $filter->getPaletteAlphaThreshold());
		$filter->setPaletteAlphaThreshold(500);
		self::assertSame(127, $filter->getPaletteAlphaThreshold());
		$filter->setPaletteAlphaThreshold(-9);
		self::assertSame(-1, $filter->getPaletteAlphaThreshold());
	}

	// ---------------------------------------------------------------------------
	// Saving through publishing.
	// ---------------------------------------------------------------------------

	public function testPngColorModeThroughPublishing(): void
	{
		$palette = imagecreate(40, 20);
		imagecolorallocate($palette, 0, 0, 200);
		$red = imagecolorallocate($palette, 255, 0, 0);
		imagefilledrectangle($palette, 0, 0, 19, 9, $red);
		$paletteSource = $this->writeImage('palette.png', 40, 20, $palette);
		$trueColorSource = $this->writeImage('truecolor.png');
		self::assertSame(3, static::pngHeader($paletteSource)['colorType']);

		$modes = [
			TAssetPNGColorMode::Preserve => ['palette' => 3, 'truecolor' => 6],
			TAssetPNGColorMode::TrueColor => ['palette' => 6, 'truecolor' => 6],
			TAssetPNGColorMode::Palette => ['palette' => 3, 'truecolor' => 3],
		];
		foreach ($modes as $mode => $expected) {
			$this->attachSavingImager(TAssetImageFilter::class, ['PngColorMode' => $mode], "imager$mode");
			$manager = $this->newManager();
			$fromPalette = $this->urlToPath($manager->publish($paletteSource));
			$fromTrueColor = $this->urlToPath($manager->publish($trueColorSource));

			self::assertSame($expected['palette'], static::pngHeader($fromPalette)['colorType'], "$mode saves the palette source.");
			self::assertSame($expected['truecolor'], static::pngHeader($fromTrueColor)['colorType'], "$mode saves the true color source.");
			self::assertSame(3 !== $expected['palette'], imageistruecolor(imagecreatefrompng($fromPalette)));
			$image = imagecreatefrompng($fromPalette);
			self::assertSame(0xFF0000, static::colorAt($image, 5, 5), "$mode keeps the colors.");
			self::assertSame(0x0000C8, static::colorAt($image, 30, 15));

			\Prado\TComponent::detachClassBehavior("imager$mode", TImageAsset::class);
			static::removeTree($this->assetDir);
			mkdir($this->assetDir);
		}
	}

	public function testSaveAlphaThroughPublishing(): void
	{
		$image = static::createImage();
		imagealphablending($image, false);
		imagesavealpha($image, true);
		imagefilledrectangle($image, 20, 0, 39, 19, 0x7F00FF00);
		$source = $this->writeImage('alpha.png', 40, 20, $image);

		$this->attachSavingImager(TAssetImageFilter::class, [], 'keep');
		$kept = $this->urlToPath($this->newManager()->publish($source));
		self::assertSame(6, static::pngHeader($kept)['colorType'], 'The alpha channel is saved.');
		self::assertSame(127, (imagecolorat(imagecreatefrompng($kept), 30, 10) >> 24) & 0x7F);
		\Prado\TComponent::detachClassBehavior('keep', TImageAsset::class);
		static::removeTree($this->assetDir);
		mkdir($this->assetDir);

		$this->attachSavingImager(TAssetImageFilter::class, ['SaveAlpha' => false, 'BackgroundColor' => 'White'], 'drop');
		$dropped = $this->urlToPath($this->newManager()->publish($source));
		self::assertSame(2, static::pngHeader($dropped)['colorType'], 'The alpha channel is not saved.');
		$result = imagecreatefrompng($dropped);
		self::assertSame(0xFFFFFF, imagecolorat($result, 30, 10), 'The transparent pixels are composited onto the background.');
		self::assertSame(0xFF0000, imagecolorat($result, 5, 5), 'Opaque pixels are unchanged.');
	}

	public function testPaletteColorIsTransparentThroughPublishing(): void
	{
		$this->attachSavingImager(TAssetImageFilter::class, [
			'PngColorMode' => TAssetPNGColorMode::Palette,
			'PaletteColors' => 8,
			'PaletteAlphaColor' => '#7F00FF00',
			'PaletteColorIsTransparent' => true,
		]);
		$source = $this->writeImage('green.png', 40, 20, static::createImage(40, 20, 0x3366CC, 0x00FF00));

		$result = imagecreatefrompng($this->urlToPath($this->newManager()->publish($source)));

		$transparent = imagecolortransparent($result);
		self::assertGreaterThanOrEqual(0, $transparent, 'The palette has a transparent color.');
		self::assertSame(0x3366CC, static::colorAt($result, 30, 15));
		// The loaded image blends alpha, so imagePalettizeAlpha setting a pixel to the
		// fully transparent color leaves the pixel unchanged.
		self::assertSame($transparent, imagecolorat($result, 5, 5), 'The green matched the transparent color.');
	}

	// ---------------------------------------------------------------------------
	// saveImage.
	// ---------------------------------------------------------------------------

	public function testImageQualityOrdersJpegAndWebPSizes(): void
	{
		foreach ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'] as $type => $extension) {
			$sizes = [];
			foreach ([10, 50, 95] as $quality) {
				$filter = new TAssetImageFilter();
				$filter->setImageQuality($quality);
				$file = "$this->tempDir/q$quality.$extension";
				self::assertTrue($filter->saveImage(static::noiseImage(), $type, 0, $file));
				self::assertSame($type, getimagesize($file)[2]);
				clearstatcache();
				$sizes[] = filesize($file);
			}
			self::assertLessThan($sizes[1], $sizes[0], "A lower $extension quality is smaller.");
			self::assertLessThan($sizes[2], $sizes[1], "A higher $extension quality is larger.");
		}
	}

	public function testPngQualityIsTheCompressionLevel(): void
	{
		$sizes = [];
		foreach ([0, 9] as $level) {
			$filter = new TAssetImageFilter();
			$filter->setPngQuality($level);
			$file = "$this->tempDir/level$level.png";
			self::assertTrue($filter->saveImage(static::gradientImage(128, 128), IMAGETYPE_PNG, 0, $file));
			clearstatcache();
			$sizes[$level] = filesize($file);
			self::assertSame(static::rgbAt(static::gradientImage(128, 128), 100, 5), static::rgbAt(imagecreatefrompng($file), 100, 5), 'PNG is lossless at every level.');
		}
		self::assertGreaterThan($sizes[9], $sizes[0], 'No compression is larger than maximum compression.');
	}

	public function testSaveAlphaForWebP(): void
	{
		foreach ([true, false] as $saveAlpha) {
			$image = static::createImage();
			static::setPixel($image, 0, 0, 0x7F00FF00);
			imagefilledrectangle($image, 20, 0, 39, 19, 0x7F00FF00);
			$filter = new TAssetImageFilter();
			$filter->setSaveAlpha($saveAlpha);
			$filter->setBackgroundColor('#FFFF00');
			$filter->setImageQuality(100);
			$file = "$this->tempDir/alpha" . (int) $saveAlpha . '.webp';

			self::assertTrue($filter->saveImage($image, IMAGETYPE_WEBP, 0, $file));

			$pixel = imagecolorat(imagecreatefromwebp($file), 30, 10);
			if ($saveAlpha) {
				self::assertSame(127, ($pixel >> 24) & 0x7F, 'WebP keeps the alpha channel.');
			} else {
				self::assertSame(0, ($pixel >> 24) & 0x7F, 'WebP ignores imagesavealpha, so the alpha is removed.');
				self::assertColorNear(0xFFFF00, $pixel & 0xFFFFFF, 8, 'The transparent pixels are on the background.');
			}
		}
	}

	public function testSaveInterlace(): void
	{
		$cases = [[null, false, 0], [true, true, 1], [false, false, 0]];
		foreach ($cases as [$interlace, $progressive, $pngInterlace]) {
			$name = var_export($interlace, true);
			$filter = new TAssetImageFilter();
			$filter->setSaveInterlace($interlace);

			$image = static::createImage(64, 64);
			imageinterlace($image, $interlace === null);
			self::assertTrue($filter->saveImage($image, IMAGETYPE_JPEG, 0, $jpeg = "$this->tempDir/$name.jpg"));
			self::assertTrue($filter->saveImage(static::createImage(64, 64), IMAGETYPE_PNG, 0, $png = "$this->tempDir/$name.png"));

			$data = file_get_contents($jpeg);
			if ($interlace === null) {
				self::assertStringContainsString("\xFF\xC2", $data, 'Without SaveInterlace, the image interlace is kept.');
			} else {
				self::assertSame($progressive, str_contains($data, "\xFF\xC2"), "SaveInterlace $name: progressive JPEG (SOF2).");
				self::assertSame(!$progressive, str_contains($data, "\xFF\xC0"), "SaveInterlace $name: baseline JPEG (SOF0).");
			}
			self::assertSame($pngInterlace, static::pngHeader($png)['interlace'], "SaveInterlace $name: PNG interlace method.");
		}
	}

	public function testPalettizeTrueColor(): void
	{
		$image = static::createImage(64, 64, 0xD0D0D0, 0x303030);
		$filter = new TAssetImageFilter();
		$filter->setToBlackAndWhite(true);
		$filter->setImageQuality(100);
		self::assertTrue($filter->saveImage($image, IMAGETYPE_JPEG, 0, $plain = "$this->tempDir/plain.jpg"));
		$result = imagecreatefromjpeg($plain);
		self::assertColorNear(0x303030, static::rgbAt($result, 10, 10), 8, 'JPEG is true color, the palette is not applied.');
		self::assertColorNear(0xD0D0D0, static::rgbAt($result, 50, 50), 8);

		foreach ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'] as $type => $extension) {
			$image = static::createImage(64, 64, 0xD0D0D0, 0x303030);
			$filter->setPalettizeTrueColor(true);
			self::assertTrue($filter->saveImage($image, $type, 0, $file = "$this->tempDir/palettized.$extension"));
			self::assertTrue(imageistruecolor($image), 'The image returns to true color for saving.');
			$result = $type === IMAGETYPE_JPEG ? imagecreatefromjpeg($file) : imagecreatefromwebp($file);
			self::assertColorNear(0x000000, static::rgbAt($result, 10, 10), 8, "The $extension dark area is black.");
			self::assertColorNear(0xFFFFFF, static::rgbAt($result, 50, 50), 8, "The $extension light area is white.");
		}

		$image = static::createImage(64, 64, 0x0000FF, 0xFF0000);
		imagefilledrectangle($image, 32, 32, 63, 63, 0x00F000);
		$filter = new TAssetImageFilter();
		$filter->setPalettizeTrueColor(true);
		$filter->setPaletteColors(2);
		$filter->setPaletteDither(false);
		self::assertTrue($filter->saveImage($image, IMAGETYPE_WEBP, 0, $file = "$this->tempDir/two.webp"));
		$colors = [];
		foreach ([[10, 10], [50, 10], [50, 50], [10, 50]] as [$x, $y]) {
			$colors[static::rgbAt($image, $x, $y)] = true;
		}
		self::assertCount(2, $colors, 'A three color image is restricted to two colors.');
	}

	public function testPaletteColorsLimitThePngPalette(): void
	{
		foreach ([4, 16, 0] as $count) {
			$filter = new TAssetImageFilter();
			$filter->setPngColorMode(TAssetPNGColorMode::Palette);
			$filter->setPaletteColors($count);
			$filter->setSaveAlpha(false);
			self::assertTrue($filter->saveImage(static::noiseImage(), IMAGETYPE_PNG, 0, $file = "$this->tempDir/p$count.png"));

			self::assertSame(3, static::pngHeader($file)['colorType']);
			$total = imagecolorstotal(imagecreatefrompng($file));
			self::assertLessThanOrEqual($count ?: 256, $total, "PaletteColors $count limits the palette.");
			self::assertGreaterThan($count ? $count / 2 : 16, $total, 'The palette uses the colors it is given.');
		}
	}

	public function testPaletteColorsOfTheSourcePreservePalettePngs(): void
	{
		$filter = new TAssetImageFilter();
		self::assertTrue($filter->saveImage(static::noiseImage(), IMAGETYPE_PNG, 8, $file = "$this->tempDir/source8.png"));
		self::assertSame(3, static::pngHeader($file)['colorType'], 'The source palette size is preserved.');
		self::assertLessThanOrEqual(9, imagecolorstotal(imagecreatefrompng($file)), 'Eight colors, and the transparent color.');

		self::assertTrue($filter->saveImage(static::noiseImage(), IMAGETYPE_PNG, 1, $file = "$this->tempDir/source1.png"));
		self::assertSame(6, static::pngHeader($file)['colorType'], 'A single color source palette is saved as true color.');
	}

	public function testPaletteDither(): void
	{
		$transitions = [];
		foreach ([true, false] as $dither) {
			$filter = new TAssetImageFilter();
			$filter->setPngColorMode(TAssetPNGColorMode::Palette);
			$filter->setPaletteColors(2);
			$filter->setPaletteDither($dither);
			self::assertTrue($filter->saveImage(static::gradientImage(), IMAGETYPE_PNG, 0, $file = "$this->tempDir/dither" . (int) $dither . '.png'));
			$image = imagecreatefrompng($file);
			$count = 0;
			for ($y = 0; $y < 8; $y++) {
				for ($x = 1; $x < 64; $x++) {
					$count += imagecolorat($image, $x, $y) !== imagecolorat($image, $x - 1, $y);
				}
			}
			$transitions[(int) $dither] = $count;
		}
		self::assertLessThanOrEqual(8, $transitions[0], 'Without dithering, each row has one step.');
		self::assertGreaterThan(4 * $transitions[0], $transitions[1], 'Dithering interleaves the colors.');
	}

	public function testPaletteAlphaColorIsTheTransparentColorOfThePalette(): void
	{
		// Alpha blending is disabled here; see testPaletteColorIsTransparentThroughPublishing
		// for an image in its default, blending, state.
		$image = static::createImage(40, 20, 0x3366CC, 0x00FF00);
		imagealphablending($image, false);
		$filter = new TAssetImageFilter();
		$filter->setPngColorMode(TAssetPNGColorMode::Palette);
		$filter->setPaletteColors(8);
		$filter->setPaletteAlphaColor('#7F00FF00');
		$filter->setPaletteColorIsTransparent(true);
		self::assertTrue($filter->saveImage($image, IMAGETYPE_PNG, 0, $file = "$this->tempDir/green.png"));

		$result = imagecreatefrompng($file);
		$transparent = imagecolortransparent($result);
		self::assertGreaterThanOrEqual(0, $transparent, 'The palette has a transparent color.');
		self::assertSame($transparent, imagecolorat($result, 5, 5), 'The opaque green matched the transparent color.');
		self::assertNotSame($transparent, imagecolorat($result, 30, 15));
		self::assertSame(127, imagecolorsforindex($result, $transparent)['alpha']);

		$image = static::createImage(40, 20, 0x3366CC, 0x00FF00);
		imagealphablending($image, false);
		$filter->setPaletteColorIsTransparent(false);
		self::assertTrue($filter->saveImage($image, IMAGETYPE_PNG, 0, $file = "$this->tempDir/opaque-green.png"));
		$result = imagecreatefrompng($file);
		self::assertSame(0x00FF00, static::colorAt($result, 5, 5), 'Without matching the color, opaque green stays.');
		self::assertSame(0, imagecolorsforindex($result, imagecolorat($result, 5, 5))['alpha']);
	}

	public function testPaletteAlphaThresholdMakesTranslucentPixelsTransparent(): void
	{
		foreach ([-1 => 0, 50 => 127] as $threshold => $alpha) {
			$image = static::createImage();
			imagealphablending($image, false);
			imagefilledrectangle($image, 20, 0, 39, 19, 0x3C3366CC);
			$filter = new TAssetImageFilter();
			$filter->setPngColorMode(TAssetPNGColorMode::Palette);
			$filter->setPaletteAlphaThreshold($threshold);
			$filter->setBackgroundColor('#FFFFFF');
			self::assertTrue($filter->saveImage($image, IMAGETYPE_PNG, 0, $file = "$this->tempDir/threshold$threshold.png"));

			$result = imagecreatefrompng($file);
			$color = imagecolorsforindex($result, imagecolorat($result, 30, 10));
			self::assertSame($alpha, $color['alpha'], "Threshold $threshold.");
			if ($threshold < 0) {
				self::assertColorNear(0x99B2E5, ($color['red'] << 16) | ($color['green'] << 8) | $color['blue'], 12, 'Below the threshold, the pixel fades onto the background.');
			}
		}
	}

	public function testSidesPaletteAlphaColor(): void
	{
		$image = static::createImage(40, 20, 0x3366CC, 0x3366CC);
		imagealphablending($image, false);
		imagefilledrectangle($image, 10, 5, 29, 14, 0xFF0000);
		$filter = new TAssetImageFilter();
		$filter->setPaletteColors(4);
		$filter->setPaletteAlphaColor('Sides');
		$filter->setPaletteColorIsTransparent(true);
		// The opaque sides make an opaque transparent color, which GIF, unlike PNG, keeps transparent.
		self::assertTrue($filter->saveImage($image, IMAGETYPE_GIF, 0, $file = "$this->tempDir/sides.gif"));

		$result = imagecreatefromgif($file);
		self::assertSame(0xFF0000, static::colorAt($result, 20, 10), 'The rectangle keeps its color.');
		if (($transparent = imagecolortransparent($result)) === -1) {
			// A quantizer that reserves no transparent entry for a fully transparent color
			// writes those pixels opaque, and the GIF carries no transparency at all.
			self::markTestSkipped('This GD build drops an opaque transparent color from a GIF.');
		}

		self::assertSame($transparent, imagecolorat($result, 0, 0), 'The color of the sides is transparent.');
		self::assertSame($transparent, imagecolorat($result, 39, 19));
		self::assertNotSame($transparent, imagecolorat($result, 20, 10));
		self::assertSame(0x3366CC, static::colorAt($result, 0, 0), 'The sides keep the computed alpha color.');
	}

	public function testUnsetPaletteAlphaColorUsesTheImageTransparentColor(): void
	{
		$filter = new TAssetImageFilter();
		$property = new \ReflectionProperty(TAssetImageFilter::class, '_paletteAlphaColor');
		$property->setValue($filter, -1);
		$filter->setPngColorMode(TAssetPNGColorMode::Palette);
		$filter->setBackgroundColor('#FFFFFF');

		$image = static::createImage();
		imagealphablending($image, false);
		imagefilledrectangle($image, 20, 0, 39, 19, 0x7F3366CC);
		self::assertTrue($filter->saveImage($image, IMAGETYPE_PNG, 0, $file = "$this->tempDir/no-alpha-color.png"));

		$result = imagecreatefrompng($file);
		self::assertSame(-1, imagecolortransparent($result), 'Without a transparent color, the alpha is removed.');
		self::assertSame([255, 255, 255, 0], array_values(imagecolorsforindex($result, imagecolorat($result, 30, 10))));
	}

	public function testToBlackAndWhite(): void
	{
		foreach ([IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_BMP => 'bmp'] as $type => $extension) {
			$filter = new TAssetImageFilter();
			$filter->setToBlackAndWhite(true);
			$image = static::createImage(40, 20, 0xE0C0A0, 0x402010);
			self::assertTrue($filter->saveImage($image, $type, 0, $file = "$this->tempDir/bw.$extension"));

			$result = imagecreatefromstring(file_get_contents($file));
			if (imageistruecolor($result)) {
				self::assertSame($type, IMAGETYPE_BMP, 'Only BMP loads as true color.');
				self::assertSame(0x000000, static::rgbAt($result, 5, 5));
				self::assertSame(0xFFFFFF, static::rgbAt($result, 30, 15));
			} else {
				// The order of the two colors in the palette is the library's choice.
				self::assertEqualsCanonicalizing([[0, 0, 0, 0], [255, 255, 255, 0]], static::palette($result), "$extension is saved in black and white.");
				self::assertSame(0x000000, static::colorAt($result, 5, 5));
				self::assertSame(0xFFFFFF, static::colorAt($result, 30, 15));
			}
		}
	}

	public function testSaveImageOfAnUnknownType(): void
	{
		$filter = new TAssetImageFilter();
		self::assertFalse($filter->saveImage(static::createImage(), IMAGETYPE_PSD, null, $file = "$this->tempDir/image.psd"));
		self::assertFileDoesNotExist($file);
	}

	public function testSaveImageConvertsAnImagickImage(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TAssetImageFilter();
		$image = TAssetImageFilter::convertImage(static::createImage(8, 4), TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);

		// The GD encoders of a format without a prado-image container take a GD image.
		self::assertTrue($filter->saveImage($image, IMAGETYPE_BMP, 0, $file = "$this->tempDir/imagick.bmp"));

		self::assertSame([8, 4, IMAGETYPE_BMP], array_slice(getimagesize($file), 0, 3), 'The Imagick image is saved as a BMP.');
	}

	// ---------------------------------------------------------------------------
	// Static helpers.
	// ---------------------------------------------------------------------------

	/**
	 * @param bool $prior
	 * @return \GdImage a 5x1 true color image of: the transparent color 0x7F000000,
	 *   red with alpha 64, opaque green, fully transparent blue, and opaque black.
	 *   A sixth pixel, 0x00FFFFFF, is made the prior transparent color when $prior.
	 */
	protected static function alphaRow(bool $prior = false): \GdImage
	{
		$image = imagecreatetruecolor(6, 1);
		foreach ([0x7F000000, 0x40FF0000, 0x0000FF00, 0x7F0000FF, 0x00000000, 0x00FFFFFF] as $x => $argb) {
			static::setPixel($image, $x, 0, $argb);
		}
		if ($prior) {
			imagecolortransparent($image, 0x00FFFFFF);
		}
		return $image;
	}

	/**
	 * @param \GdImage $image
	 * @return int[] the 0xAARRGGBB pixels of the first row.
	 */
	protected static function row(\GdImage $image): array
	{
		$pixels = [];
		for ($x = 0; $x < imagesx($image); $x++) {
			$pixels[] = imagecolorat($image, $x, 0);
		}
		return $pixels;
	}

	public function testImagePalettizeAlphaRejectsPaletteImages(): void
	{
		self::assertFalse(TAssetImageFilter::imagePalettizeAlpha(null, 0x7F000000));
		self::assertFalse(TAssetImageFilter::imagePalettizeAlpha(imagecreate(2, 2), 0x7F000000));
	}

	public function testImagePalettizeAlphaWithANegativeColorRemovesTheAlpha(): void
	{
		$image = static::alphaRow();
		imagecolortransparent($image, 0x7F000000);

		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, -1, 0x0000FF));

		self::assertSame([0x0000FF, 0x7E0080, 0x00FF00, 0x0000FF, 0x000000, 0xFFFFFF], static::row($image));
		self::assertSame(-1, imagecolortransparent($image));
	}

	public function testImagePalettizeAlphaThresholdAndMatchColor(): void
	{
		$t = 0x7F000000;
		$image = static::alphaRow(true);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF, 100, true));
		self::assertSame([$t, 0x7E0080, 0x00FF00, $t, $t, $t], static::row($image), 'Faded onto the background; over the threshold, the matched color, and the prior transparent color are transparent.');
		self::assertSame($t, imagecolortransparent($image));

		$image = static::alphaRow(true);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0, 100, true));
		self::assertSame([$t, 0xFF0000, 0x00FF00, $t, $t, $t], static::row($image), 'Without a background, the alpha is dropped.');
	}

	public function testImagePalettizeAlphaThreshold(): void
	{
		$t = 0x7F000000;
		$image = static::alphaRow(true);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF, 64));
		self::assertSame([$t, $t, 0x00FF00, $t, 0x000000, $t], static::row($image), 'Alpha 64 meets the threshold; black is not matched.');

		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF, 100));
		self::assertSame([$t, 0x7E0080, 0x00FF00, $t, 0x000000, 0xFFFFFF], static::row($image));

		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0, 200));
		self::assertSame([$t, 0xFF0000, 0x00FF00, $t, 0x000000, 0xFFFFFF], static::row($image), 'The threshold is clamped to 127.');
	}

	public function testImagePalettizeAlphaMatchColorOrPriorTransparent(): void
	{
		$t = 0x7F000000;
		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF, -1, true));
		self::assertSame([$t, 0x7E0080, 0x00FF00, 0x0000FF, $t, 0xFFFFFF], static::row($image), 'The color is matched regardless of alpha.');

		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0, -1, true));
		self::assertSame([$t, 0xFF0000, 0x00FF00, 0x0000FF, $t, 0xFFFFFF], static::row($image));

		$image = static::alphaRow(true);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF));
		self::assertSame([$t, 0x7E0080, 0x00FF00, 0x0000FF, 0x000000, $t], static::row($image), 'The prior transparent color is replaced.');
		self::assertSame($t, imagecolortransparent($image));
	}

	public function testImagePalettizeAlphaWithoutReplacement(): void
	{
		$t = 0x7F000000;
		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t, 0x0000FF));
		self::assertSame([$t, 0x7E0080, 0x00FF00, 0x0000FF, 0x000000, 0xFFFFFF], static::row($image), 'Only the alpha is faded onto the background.');
		self::assertSame($t, imagecolortransparent($image), 'The transparent color is set.');

		$image = static::alphaRow();
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, $t));
		self::assertSame([$t, 0xFF0000, 0x00FF00, 0x0000FF, 0x000000, 0xFFFFFF], static::row($image), 'Without a background, the alpha is dropped.');

		// A numeric string equal to the prior transparent color: the prior pixels are not
		// skipped by the strict comparison, and are set to the transparent color.
		$image = static::alphaRow(true);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, (string) 0x00FFFFFF));
		self::assertSame([0, 0xFF0000, 0x00FF00, 0x0000FF, 0x000000, 0xFFFFFF], static::row($image));
		self::assertSame(0xFFFFFF, imagecolortransparent($image));
	}

	public function testImagePalettizeAlphaSides(): void
	{
		$image = imagecreatetruecolor(4, 4);
		imagealphablending($image, false);
		imagefilledrectangle($image, 0, 0, 3, 3, 0x00808080);
		foreach ([[0, 0, 0x00FF0000], [3, 0, 0x000000FF], [0, 3, 0x7F000000], [3, 3, 0x7F00FF00]] as [$x, $y, $argb]) {
			imagesetpixel($image, $x, $y, $argb);
		}

		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($image, 'SIDES'));

		self::assertSame(0x3F3F3F3F, imagecolortransparent($image), 'The transparent color is the average of the corners.');
		self::assertSame(0xFF0000, imagecolorat($image, 0, 0));
		self::assertSame(0x00FF00, imagecolorat($image, 3, 3), 'Without a background, the alpha is dropped.');
		self::assertSame(0x808080, imagecolorat($image, 1, 1));
	}

	public function testImageColorBrightness(): void
	{
		self::assertEqualsWithDelta(65025.0, TAssetImageFilter::imageColorBrightness(0xFFFFFF, true), 0.01);
		self::assertEqualsWithDelta(0.299 * 255 * 255, TAssetImageFilter::imageColorBrightness(0xFF0000, true), 0.01);
		self::assertEqualsWithDelta(0.0, TAssetImageFilter::imageColorBrightness(0x7FFFFFFF, true), 0.01, 'Transparent colors have no brightness.');
		self::assertEqualsWithDelta(sqrt(0.587) * 255, TAssetImageFilter::imageColorBrightness(0x00FF00), 0.01);

		$halfWhite = ['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 127 - 127 / 2];
		self::assertEqualsWithDelta(65025.0 / 2, TAssetImageFilter::imageColorBrightness($halfWhite, true), 0.01);
		self::assertEqualsWithDelta(0.114 * 255 * 255, TAssetImageFilter::imageColorBrightness(['red' => 0, 'green' => 0, 'blue' => 255, 'alpha' => 0], true), 0.01);
		self::assertEqualsWithDelta(sqrt(0.114) * 255, TAssetImageFilter::imageColorBrightness(['red' => 0, 'green' => 0, 'blue' => 255, 'alpha' => 0]), 0.01);
	}

	public function testImagePalettizeTwoColorPaletteToBlackAndWhite(): void
	{
		foreach ([[[20, 30, 40], [200, 210, 220], [[0, 0, 0, 0], [255, 255, 255, 0]]], [[200, 210, 220], [20, 30, 40], [[255, 255, 255, 0], [0, 0, 0, 0]]]] as [$first, $second, $expected]) {
			$image = imagecreate(4, 4);
			imagecolorallocate($image, ...$first);
			imagecolorallocate($image, ...$second);

			self::assertTrue(TAssetImageFilter::imagePalettize($image, 256, true, true));

			self::assertFalse(imageistruecolor($image));
			self::assertSame($expected, static::palette($image), 'The darker color is black, the brighter white.');
		}
	}

	public function testImagePalettizeTwoColorPaletteIgnoresTheRemovedTransparency(): void
	{
		$image = imagecreate(4, 4);
		imagecolorallocate($image, 20, 30, 40);
		$transparent = imagecolorallocate($image, 200, 210, 220);
		imagecolortransparent($image, $transparent);

		self::assertTrue(TAssetImageFilter::imagePalettize($image, 256, true, true));

		self::assertSame(-1, imagecolortransparent($image), 'Black and white has no transparency.');
		// GD keeps alpha 127 on the former transparent entry, so its brightness is 0 and
		// the brighter color became black.
		self::assertSame([[0, 0, 0, 0], [255, 255, 255, 0]], static::palette($image), 'The darker color is black, the brighter, formerly transparent, white.');
	}

	public function testImagePalettizeOneColorPaletteToBlackAndWhite(): void
	{
		foreach ([[[100, 100, 100], [0, 0, 0, 0]], [[160, 160, 160], [255, 255, 255, 0]]] as [$color, $expected]) {
			$image = imagecreate(4, 4);
			imagecolorallocate($image, ...$color);

			self::assertTrue(TAssetImageFilter::imagePalettize($image, 2, false, true));

			self::assertSame([$expected], static::palette($image));
		}
	}

	public function testImagePalettizeLargerPaletteToBlackAndWhite(): void
	{
		$image = imagecreate(8, 8);
		$dark = imagecolorallocate($image, 20, 20, 20);
		imagecolorallocate($image, 100, 100, 100);
		$light = imagecolorallocate($image, 230, 230, 230);
		imagefilledrectangle($image, 0, 0, 3, 7, $dark);
		imagefilledrectangle($image, 4, 0, 7, 7, $light);

		self::assertTrue(TAssetImageFilter::imagePalettize($image, null, false, true));

		self::assertEqualsCanonicalizing([[0, 0, 0, 0], [255, 255, 255, 0]], static::palette($image));
		self::assertSame(0x000000, static::colorAt($image, 1, 1));
		self::assertSame(0xFFFFFF, static::colorAt($image, 6, 6));
	}

	public function testImagePalettizeReducesOnlyLargerPalettes(): void
	{
		$image = imagecreate(8, 8);
		foreach ([0xFF0000, 0x00FF00, 0x0000FF, 0xFFFF00] as $i => $rgb) {
			$index = imagecolorallocate($image, $rgb >> 16, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
			imagefilledrectangle($image, $i * 2, 0, $i * 2 + 1, 7, $index);
		}
		self::assertFalse(TAssetImageFilter::imagePalettize($image, 4), 'A palette within the colors is unchanged.');
		self::assertFalse(TAssetImageFilter::imagePalettize($image, 0), 'Zero colors is the full palette.');
		self::assertSame(4, imagecolorstotal($image));

		self::assertTrue(TAssetImageFilter::imagePalettize($image, 2, false));

		self::assertFalse(imageistruecolor($image));
		self::assertLessThanOrEqual(2, imagecolorstotal($image));
	}

	public function testImagePalettizeTrueColorToBlackAndWhite(): void
	{
		foreach ([[0x202020, [0, 0, 0, 0]], [0xE0E0E0, [255, 255, 255, 0]]] as [$rgb, $expected]) {
			$image = static::createImage(8, 8, $rgb, $rgb);
			self::assertTrue(TAssetImageFilter::imagePalettize($image, 256, false, true));
			self::assertSame([$expected], static::palette($image), 'A single color image is one color.');
		}

		// The brightness is weighted by alpha, so a transparent white is darker than an opaque gray.
		$image = imagecreatetruecolor(8, 8);
		imagealphablending($image, false);
		imagefilledrectangle($image, 0, 0, 3, 7, 0x00404040);
		imagefilledrectangle($image, 4, 0, 7, 7, 0x7FFFFFFF);
		self::assertTrue(TAssetImageFilter::imagePalettize($image, 256, false, true));
		self::assertEqualsCanonicalizing([[255, 255, 255, 0], [0, 0, 0, 0]], static::palette($image));
		self::assertSame(0xFFFFFF, static::colorAt($image, 1, 1), 'The opaque gray is the brighter.');
		self::assertSame(0x000000, static::colorAt($image, 6, 6));
	}

	public function testImageRemoveAlphaOfAPaletteImage(): void
	{
		foreach ([true, false] as $withBackground) {
			$image = imagecreate(4, 4);
			imagecolorallocatealpha($image, 255, 255, 255, 0);
			imagecolorallocatealpha($image, 255, 0, 0, 127);
			imagecolorallocatealpha($image, 0, 255, 0, 63);
			$transparent = imagecolorallocatealpha($image, 10, 20, 30, 100);
			imagecolortransparent($image, $transparent);
			$background = $withBackground ? imagecolorallocate($image, 0, 0, 255) : null;

			self::assertTrue(TAssetImageFilter::imageRemoveAlpha($image, $background));

			$palette = static::palette($image);
			self::assertSame([255, 255, 255, 0], $palette[0], 'Opaque colors are unchanged.');
			self::assertSame($withBackground ? [0, 0, 255, 0] : [0, 0, 0, 0], $palette[1], 'Transparent colors are the background.');
			// alpha 63 keeps 64/127 of the color: 255 * 64 / 127 = 128.5 and 255 * 63 / 127 = 126.5.
			self::assertSame($withBackground ? [0, 129, 126, 0] : [0, 129, 0, 0], $palette[2], 'Translucent colors are faded onto the background.');
			self::assertSame([10, 20, 30], array_slice($palette[3], 0, 3), 'The transparent index is skipped.');
			self::assertSame(-1, imagecolortransparent($image));
		}
		self::assertFalse(TAssetImageFilter::imageRemoveAlpha(null));
	}

	public function testImageRemoveAlphaOfATrueColorImageDefaultsToBlack(): void
	{
		$image = imagecreatetruecolor(2, 1);
		static::setPixel($image, 0, 0, 0x7F00FF00);
		static::setPixel($image, 1, 0, 0x00FF0000);
		imagecolortransparent($image, 0x00FF0000);

		self::assertTrue(TAssetImageFilter::imageRemoveAlpha($image));

		self::assertSame([0x000000, 0x000000], static::row($image), 'Transparent pixels, and pixels of the transparent color, are the background.');
		self::assertSame(-1, imagecolortransparent($image));
	}

	// ---------------------------------------------------------------------------
	// encodeImage and TIFF.
	// ---------------------------------------------------------------------------

	public function testEncodeImageMatchesSaveImage(): void
	{
		$filter = new TAssetImageFilter();
		foreach ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_BMP => 'bmp', IMAGETYPE_WBMP => 'wbmp'] as $type => $extension) {
			$bytes = $filter->encodeImage(static::noiseImage(), $type, 0);
			self::assertTrue($filter->saveImage(static::noiseImage(), $type, 0, $file = "$this->tempDir/encoded.$extension"));

			self::assertIsString($bytes);
			self::assertSame(file_get_contents($file), $bytes, "The encoded $extension is the saved file.");
			self::assertSame($type, getimagesizefromstring($bytes)[2]);
		}
	}

	public function testEncodeImageOfAnUnwritableType(): void
	{
		self::assertFalse((new TAssetImageFilter())->encodeImage(static::noiseImage(), IMAGETYPE_PSD, 0));
	}

	public function testEncodeImageWritesTiffWithItsCompression(): void
	{
		$filter = new TAssetImageFilter();
		$filter->setTiffCompression('PackBits');

		$bytes = $filter->encodeImage(static::createImage(40, 20), IMAGETYPE_TIFF_II, 0);

		self::assertIsString($bytes);
		$size = getimagesizefromstring($bytes);
		self::assertSame([40, 20], array_slice($size, 0, 2), 'GD has no TIFF encoder; prado-image writes it.');
		self::assertContains($size[2], [IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], 'The byte order is the container\'s.');
		$tiff = TImageFile::fromString($bytes);
		self::assertInstanceOf(TTIFF::class, $tiff);
		self::assertSame(TTIFF::CompressionPackBits, $tiff->getTiff()->getIfd(0)->getTagValue(259), 'The compression is the TiffCompression.');

		self::assertTrue($filter->saveImage(static::createImage(8, 4), IMAGETYPE_TIFF_MM, 0, $file = "$this->tempDir/saved.tif"));
		self::assertSame([8, 4], array_slice(getimagesize($file), 0, 2));
	}

	public function testTiffCompression(): void
	{
		$filter = new TAssetImageFilter();
		self::assertSame(TTIFF::CompressionLzw, $filter->getTiffCompression());

		$filter->setTiffCompression(' packBITS ');
		self::assertSame(TTIFF::CompressionPackBits, $filter->getTiffCompression(), 'A name is case insensitive.');

		$filter->setTiffCompression((string) TTIFF::CompressionNone);
		self::assertSame(TTIFF::CompressionNone, $filter->getTiffCompression(), 'A numeric compression is the constant.');

		foreach (['Sparkle', 12345] as $value) {
			try {
				$filter->setTiffCompression($value);
				self::fail('An invalid compression is rejected.');
			} catch (TInvalidDataValueException $e) {
				self::assertSame('assetimagefilter_bad_tiff_compression', $e->getErrorCode());
			}
		}
		self::assertSame(TTIFF::CompressionNone, $filter->getTiffCompression());
	}

	// ---------------------------------------------------------------------------
	// Graphics libraries.
	// ---------------------------------------------------------------------------

	public function testCanEncode(): void
	{
		$filter = new TAssetImageFilter();

		foreach ([IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP, IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_WEBP] as $type) {
			self::assertTrue($filter->canEncode($type), "GD writes the type $type.");
		}
		self::assertTrue($filter->canEncode(IMAGETYPE_TIFF_II), 'The prado-image container writes TIFF.');
		self::assertFalse($filter->canEncode(IMAGETYPE_PSD));
		self::assertFalse((new TGraphicslessImageFilter())->canEncode(IMAGETYPE_PNG), 'Without a graphics library nothing is encoded.');
	}

	public function testEncodeImageOfAnImagickImage(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TAssetImageFilter();
		$imagick = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(20, 10)), TImageGraphicsMode::Imagick);

		$bytes = $filter->encodeImage($imagick, IMAGETYPE_JPEG, 0);

		self::assertIsString($bytes);
		self::assertSame([20, 10, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3), 'An Imagick image is converted to GD for the saving properties.');
	}

	public function testEncodeWithGraphics(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$filter = new TAssetImageFilter();
		$imagick = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(20, 10)), TImageGraphicsMode::Imagick);

		// The image is encoded by the library and the containers, without GD.
		foreach ([IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF] as $type) {
			$bytes = static::invoke($filter, 'encodeWithGraphics', $imagick, $type);
			self::assertIsString($bytes, "The type $type is encoded.");
			self::assertSame([20, 10, $type], array_slice(getimagesizefromstring($bytes), 0, 3));
		}
		$tiff = static::invoke($filter, 'encodeWithGraphics', $imagick, IMAGETYPE_TIFF_MM);
		self::assertSame([20, 10], array_slice(getimagesizefromstring($tiff), 0, 2));

		$filter->setPngQuality(9);
		self::assertIsString(static::invoke($filter, 'encodeWithGraphics', $imagick, IMAGETYPE_PNG), 'The PNG compression becomes a quality.');

		foreach ([IMAGETYPE_BMP, IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_PSD] as $type) {
			self::assertFalse(static::invoke($filter, 'encodeWithGraphics', $imagick, $type), "The type $type needs GD.");
		}
	}

	public function testEncodeImageOfAnImagickImageWithoutGd(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		// The image cannot be converted to GD, as on a host without it.
		$filter = new TNoInterchangeImageFilter();
		$imagick = TImageGraphics::decode((string) TPNG::fromImage(static::createImage(20, 10)), TImageGraphicsMode::Imagick);

		$bytes = $filter->encodeImage($imagick, IMAGETYPE_JPEG, 0);

		self::assertIsString($bytes);
		self::assertSame([20, 10, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3), 'The graphics library encodes the image.');
		self::assertFalse($filter->encodeImage($imagick, IMAGETYPE_BMP, 0), 'A GD-only format cannot be written.');
	}

	/**
	 * @param object $filter the image filter.
	 * @param string $method a protected method.
	 * @param mixed ...$args
	 * @return mixed the result of the method.
	 */
	protected static function invoke($filter, string $method, ...$args)
	{
		return (new \ReflectionMethod($filter, $method))->invoke($filter, ...$args);
	}
}
