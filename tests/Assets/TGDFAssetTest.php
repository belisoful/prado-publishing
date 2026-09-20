<?php

/**
 * TGDFAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TGDFAsset;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TGDFAssetTest class.
 *
 * Tests the GD font asset: publishing converts the GDF header to the byte order of this
 * machine, whichever byte order the source font is in, so the published font loads with
 * imageloadfont().
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TGDFAssetTest extends PublishingTestCase
{
	/** The Teletext fixture header: characters, first character, width, height. */
	public const TELETEXT_HEADER = [224, 32, 6, 10];

	/**
	 * @return bool whether this machine is little endian.
	 */
	protected static function isLittleEndian(): bool
	{
		return pack('L', 1) === pack('V', 1);
	}

	/**
	 * @param string $path the GDF file.
	 * @return int[] the header in the byte order of this machine.
	 */
	protected static function nativeHeader(string $path): array
	{
		return array_values(unpack('L4', file_get_contents($path, false, null, 0, 16)));
	}

	/**
	 * Measures a loaded GD font. imagefontwidth() and imagefontheight() reject a GdFont
	 * object on some PHP 8.1 builds ("must be of type GdFont|int, GdFont given"), so the
	 * metrics are then measured by rendering the underscore glyph of the Teletext font,
	 * which has ink in its last row: the height is its bottom row, and the width is how
	 * far a second glyph advances.
	 * @param \GdFont|int $font the font.
	 * @return int[] the width and height.
	 */
	protected static function fontSize($font): array
	{
		try {
			return [imagefontwidth($font), imagefontheight($font)];
		} catch (\TypeError $e) {
			$extent = function (string $text) use ($font): array {
				$image = imagecreate(64, 64);
				imagecolorallocate($image, 0, 0, 0);
				imagestring($image, $font, 0, 0, $text, imagecolorallocate($image, 255, 255, 255));
				$maxX = $maxY = -1;
				for ($y = 0; $y < 64; $y++) {
					for ($x = 0; $x < 64; $x++) {
						if (imagecolorat($image, $x, $y)) {
							$maxX = max($maxX, $x);
							$maxY = max($maxY, $y);
						}
					}
				}
				return [$maxX, $maxY];
			};
			[$oneX, $oneY] = $extent('_');
			[$twoX] = $extent('__');
			return [$twoX - $oneX, $oneY + 1];
		}
	}

	/**
	 * @param string $name the fixture file name.
	 * @return string the published copy of the fixture font.
	 */
	protected function publishFixture(string $name): string
	{
		$source = $this->writeSource($name, file_get_contents(static::dataDir() . DIRECTORY_SEPARATOR . $name));
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'published-' . $name;
		self::assertTrue((new TGDFAsset($source))->publish($dst));
		return $dst;
	}

	/**
	 * @param string $header the 16 header bytes.
	 * @param string $body the glyph bytes.
	 * @return array{0:bool, 1:string} the publish result and the published bytes.
	 */
	protected function publishBytes(string $header, string $body = ''): array
	{
		$source = $this->writeSource('font.gdf', $header . $body);
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'font.gdf';
		$result = (new TGDFAsset($source))->publish($dst);
		return [$result, file_get_contents($dst)];
	}

	public function testIsAFileAsset(): void
	{
		$asset = new TGDFAsset();
		self::assertInstanceOf(TFileAsset::class, $asset);
		self::assertFalse($asset->getIsVirtual());
	}

	public function testFixturesAreTheSameFontInBothByteOrders(): void
	{
		$be = file_get_contents(static::dataDir() . '/Teletext_6x10_BE.gdf');
		$le = file_get_contents(static::dataDir() . '/Teletext_6x10_LE.gdf');

		self::assertSame(self::TELETEXT_HEADER, array_values(unpack('N4', $be)));
		self::assertSame(self::TELETEXT_HEADER, array_values(unpack('V4', $le)));
		self::assertSame(substr($be, 16), substr($le, 16));
	}

	public static function fixtureProvider(): array
	{
		return [
			'big endian' => ['Teletext_6x10_BE.gdf'],
			'little endian' => ['Teletext_6x10_LE.gdf'],
		];
	}

	/**
	 * @dataProvider fixtureProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('fixtureProvider')]
	public function testPublishedFontIsInTheMachineByteOrder(string $name): void
	{
		$dst = $this->publishFixture($name);
		$source = file_get_contents(static::dataDir() . DIRECTORY_SEPARATOR . $name);

		self::assertSame(self::TELETEXT_HEADER, static::nativeHeader($dst));
		self::assertSame(strlen($source), filesize($dst));
		self::assertSame(substr($source, 16), substr(file_get_contents($dst), 16), 'The glyph data is unchanged.');
	}

	/**
	 * @dataProvider fixtureProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('fixtureProvider')]
	public function testPublishedFontLoadsWithTheRightMetrics(string $name): void
	{
		$font = imageloadfont($this->publishFixture($name));

		self::assertInstanceOf(\GdFont::class, $font);
		self::assertSame([6, 10], static::fontSize($font));
	}

	public function testBothByteOrdersRenderIdentically(): void
	{
		$render = function (string $name): string {
			$font = imageloadfont($this->publishFixture($name));
			$image = imagecreate(6 * 5, 10);
			imagecolorallocate($image, 0, 0, 0);
			imagestring($image, $font, 0, 0, 'Prado', imagecolorallocate($image, 255, 255, 255));
			$pixels = '';
			for ($y = 0; $y < 10; $y++) {
				for ($x = 0; $x < 30; $x++) {
					$pixels .= imagecolorat($image, $x, $y) ? '#' : '.';
				}
			}
			return $pixels;
		};

		$be = $render('Teletext_6x10_BE.gdf');
		self::assertStringContainsString('#', $be, 'Text is drawn.');
		self::assertSame($be, $render('Teletext_6x10_LE.gdf'));
	}

	/**
	 * @dataProvider fixtureProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('fixtureProvider')]
	public function testTheSourceFontIsNotModified(string $name): void
	{
		$source = $this->writeSource($name, $bytes = file_get_contents(static::dataDir() . DIRECTORY_SEPARATOR . $name));
		(new TGDFAsset($source))->publish($this->tempDir . '/out.gdf');

		self::assertSame($bytes, file_get_contents($source));
	}

	public function testANativeFontIsCopiedByteForByte(): void
	{
		$native = static::isLittleEndian() ? 'Teletext_6x10_LE.gdf' : 'Teletext_6x10_BE.gdf';
		self::assertSame(sha1_file(static::dataDir() . DIRECTORY_SEPARATOR . $native), sha1_file($this->publishFixture($native)));
	}

	public function testAWideNativeWidthIsKept(): void
	{
		[$result, $bytes] = $this->publishBytes(pack('L4', 1, 65, 0xFFFF, 10), 'glyphs');

		self::assertTrue($result);
		self::assertSame([1, 65, 0xFFFF, 10], array_values(unpack('L4', $bytes)), 'A width up to 0xFFFF is taken as native.');
		self::assertSame('glyphs', substr($bytes, 16));
	}

	public function testAWidthBeyond16BitsIsFlipped(): void
	{
		$foreign = static::isLittleEndian() ? pack('N4', 1, 65, 256, 8) : pack('V4', 1, 65, 256, 8);
		self::assertSame(0x00010000, unpack('L4', $foreign)[3], 'The foreign width reads beyond 16 bits natively.');

		[$result, $bytes] = $this->publishBytes($foreign, 'glyphs');

		self::assertTrue($result);
		self::assertSame([1, 65, 256, 8], array_values(unpack('L4', $bytes)), 'Every header field is flipped.');
		self::assertSame('glyphs', substr($bytes, 16));
	}

	public function testAnEmptyFontIsCopied(): void
	{
		[$result, $bytes] = $this->publishBytes('');

		self::assertTrue($result);
		self::assertSame('', $bytes);
	}

	public function testATruncatedHeaderIsCopiedAsIs(): void
	{
		[$result, $bytes] = $this->publishBytes(pack('N2', 2, 65));

		self::assertTrue($result);
		self::assertSame(pack('N2', 2, 65), $bytes);
	}

	public function testPublishToAMissingDirectoryFails(): void
	{
		$source = $this->writeSource('font.gdf', file_get_contents(static::dataDir() . '/Teletext_6x10_BE.gdf'));

		self::assertFalse((new TGDFAsset($source))->publish($this->tempDir . '/missing/font.gdf'));
		self::assertFileDoesNotExist($this->tempDir . '/missing/font.gdf');
	}

	public function testManagerPublishesADiscoveredFont(): void
	{
		$source = $this->writeSource('fonts/teletext.gdf', file_get_contents(static::dataDir() . '/Teletext_6x10_BE.gdf'));
		$manager = $this->newManager();
		$manager->attachEventHandler('onDiscoverClass', function ($sender, $param) {
			if (str_ends_with($param->getFilePath(), '.gdf')) {
				$param->setClass(TGDFAsset::class);
			}
		});

		$url = $manager->publish($source);

		self::assertInstanceOf(TGDFAsset::class, $manager->getPublishedAssets()[$source]);
		$font = imageloadfont($this->urlToPath($url));
		self::assertInstanceOf(\GdFont::class, $font);
		self::assertSame([6, 10], static::fontSize($font));
	}
}
