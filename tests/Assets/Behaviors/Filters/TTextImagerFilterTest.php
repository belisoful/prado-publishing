<?php

/**
 * TTextImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplicationMode;
use Prado\Web\Assets\Behaviors\Filters\TTextImagerFilter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TTextImagerFilterTest class.
 *
 * Tests the text watermark filter: its properties from their string forms, text with
 * the built-in GD fonts, GDF font files and their byte order verification, and
 * TrueType text with its alignment, font namespace, angle, and line spacing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TTextImagerFilterTest extends PublishingTestCase
{
	/** The alias of the test data directory. */
	public const DATA_ALIAS = 'TextImagerFilterTestData';

	/** @var false|string the GDFONTPATH environment before the test. */
	private $_fontPath;

	protected function setUp(): void
	{
		parent::setUp();
		$this->_fontPath = getenv('GDFONTPATH');
		Prado::setPathOfAlias(static::DATA_ALIAS, static::dataDir());
	}

	protected function tearDown(): void
	{
		putenv($this->_fontPath === false ? 'GDFONTPATH' : 'GDFONTPATH=' . $this->_fontPath);
		parent::tearDown();
	}

	/**
	 * @return string the TrueType font path.
	 */
	protected static function ttFont(): string
	{
		return static::dataDir() . DIRECTORY_SEPARATOR . 'BlackChancery' . DIRECTORY_SEPARATOR . 'BLKCHCRY.TTF';
	}

	protected static function requireFreeType(): void
	{
		if (!function_exists('imagefttext')) {
			self::markTestSkipped('GD is built without FreeType.');
		}
	}

	/**
	 * @param array<string, mixed> $properties the filter properties.
	 * @return TTextImagerFilter the filter.
	 */
	protected static function newFilter(array $properties): TTextImagerFilter
	{
		$filter = new TTextImagerFilter();
		foreach ($properties as $name => $value) {
			$filter->{'set' . $name}($value);
		}
		return $filter;
	}

	/**
	 * @param \GdImage $image the image.
	 * @param int $color the 0xRRGGBB color.
	 * @return ?array{0:int, 1:int, 2:int, 3:int} the inclusive [x1, x2, y1, y2] bounds
	 *   of the color, or null when absent.
	 */
	protected static function colorBounds(\GdImage $image, int $color): ?array
	{
		$x1 = $y1 = PHP_INT_MAX;
		$x2 = $y2 = -1;
		for ($y = 0, $h = imagesy($image); $y < $h; $y++) {
			for ($x = 0, $w = imagesx($image); $x < $w; $x++) {
				if (static::rgbAt($image, $x, $y) === $color) {
					[$x1, $x2, $y1, $y2] = [min($x1, $x), max($x2, $x), min($y1, $y), max($y2, $y)];
				}
			}
		}
		return $x2 < 0 ? null : [$x1, $x2, $y1, $y2];
	}

	/**
	 * @param TTextImagerFilter $filter the filter.
	 * @param int $width the image width.
	 * @param int $height the image height.
	 * @return \GdImage the white image with the rendered text.
	 */
	protected static function render(TTextImagerFilter $filter, int $width = 100, int $height = 100): \GdImage
	{
		$image = static::createImage($width, $height, 0xFFFFFF, 0xFFFFFF);
		self::assertTrue($filter->filterImage($image));
		return $image;
	}

	/**
	 * @param callable $test the test that throws its exception.
	 * @param string $errorCode the expected error code.
	 * @param string $class the expected exception class.
	 */
	protected static function assertErrorCode(callable $test, string $errorCode, string $class = TConfigurationException::class): void
	{
		try {
			$test();
			self::fail("An exception $errorCode was expected.");
		} catch (\Prado\Exceptions\TException $e) {
			self::assertInstanceOf($class, $e);
			self::assertSame($errorCode, $e->getErrorCode());
		}
	}

	public function testDefaults(): void
	{
		$filter = new TTextImagerFilter();

		self::assertSame('', $filter->getText());
		self::assertSame('80%', $filter->getTextX());
		self::assertSame('-10', $filter->getTextY());
		self::assertSame('#00000000', $filter->getTextColor());
		self::assertFalse($filter->getGDVertical());
		self::assertSame('Auto', $filter->getVerifyGDFont());
		self::assertSame(1, $filter->getGDFont());
		self::assertNull($filter->getFontNamespace());
		self::assertNull($filter->getTTFont());
		self::assertSame(12.0, $filter->getTTFontSize());
		self::assertSame(0.0, $filter->getTTAngle());
		self::assertSame('Left', $filter->getTTAlignX());
		self::assertSame('Bottom', $filter->getTTAlignY());
		self::assertNull($filter->getTTLineSpacing());
	}

	public function testProperties(): void
	{
		$filter = static::newFilter([
			'Text' => 'Hello', 'TextX' => 5, 'TextY' => '-0%', 'GDVertical' => 'true', 'GDFont' => '3',
			'FontNamespace' => 'Some.Fonts', 'TTFont' => 'Font.ttf', 'TTFontSize' => '18.5', 'TTAngle' => '370',
			'TTAlignX' => ' center ', 'TTAlignY' => 'TOP', 'TTLineSpacing' => '1.5',
		]);

		self::assertSame('Hello', $filter->getText());
		self::assertSame('5', $filter->getTextX());
		self::assertSame('-0%', $filter->getTextY());
		self::assertTrue($filter->getGDVertical());
		self::assertSame(3, $filter->getGDFont());
		self::assertSame('Some.Fonts', $filter->getFontNamespace());
		self::assertSame('Font.ttf', $filter->getTTFont());
		self::assertSame(18.5, $filter->getTTFontSize());
		self::assertSame(10.0, $filter->getTTAngle());
		self::assertSame('center', $filter->getTTAlignX());
		self::assertSame('TOP', $filter->getTTAlignY());
		self::assertSame(1.5, $filter->getTTLineSpacing());

		$filter->setGDFont('font.gdf');
		$filter->setFontNamespace('');
		$filter->setTTFont('');
		$filter->setTTFontSize(-4);
		$filter->setTTLineSpacing('');
		self::assertSame('font.gdf', $filter->getGDFont());
		self::assertNull($filter->getFontNamespace());
		self::assertNull($filter->getTTFont());
		self::assertSame(0.0, $filter->getTTFontSize());
		self::assertNull($filter->getTTLineSpacing());
		$filter->setTTLineSpacing(2);
		self::assertSame(2.0, $filter->getTTLineSpacing());
		$filter->setTTLineSpacing(null);
		self::assertNull($filter->getTTLineSpacing());
	}

	public function testTextColor(): void
	{
		$filter = new TTextImagerFilter();

		$filter->setTextColor('#00FF00');
		self::assertSame('#0000FF00', $filter->getTextColor());
		$filter->setTextColor('red');
		self::assertSame('#00FF0000', $filter->getTextColor());
		$filter->setTextColor('#3F0000FF');
		self::assertSame('#3F0000FF', $filter->getTextColor());
		$filter->setTextColor(255);
		self::assertSame('255', $filter->getTextColor());

		$method = new \ReflectionMethod($filter, 'getTextColorIndex');
		$method->setAccessible(true);
		self::assertSame(255, $method->invoke($filter));
		$filter->setTextColor('#3F0000FF');
		self::assertSame(0x3F0000FF, $method->invoke($filter));

		static::assertErrorCode(fn () => $filter->setTextColor('#FFFFFFFF'), 'propertyvalue_invalid_hex_color', TInvalidDataValueException::class);
	}

	public function testVerifyGDFont(): void
	{
		$filter = new TTextImagerFilter();
		$filter->setVerifyGDFont('convert');
		self::assertSame('convert', $filter->getVerifyGDFont());
		$filter->setVerifyGDFont('AUTO');
		self::assertSame('AUTO', $filter->getVerifyGDFont());
		$filter->setVerifyGDFont('true');
		self::assertTrue($filter->getVerifyGDFont());
		$filter->setVerifyGDFont('false');
		self::assertFalse($filter->getVerifyGDFont());
	}

	public function testAlignment(): void
	{
		// Regression: the documented blank (automatic) alignment was rejected.
		$filter = new TTextImagerFilter();
		$filter->setTTAlignX('');
		$filter->setTTAlignY(' ');
		self::assertSame('', $filter->getTTAlignX());
		self::assertSame('', $filter->getTTAlignY());

		static::assertErrorCode(fn () => $filter->setTTAlignX('Top'), 'propertyvalue_enumvalue_invalid', TInvalidDataValueException::class);
		static::assertErrorCode(fn () => $filter->setTTAlignY('Left'), 'propertyvalue_enumvalue_invalid', TInvalidDataValueException::class);
	}

	public function testNoImageOrNoText(): void
	{
		$image = null;
		self::assertNull(static::newFilter(['Text' => 'x'])->filterImage($image));
		$image = static::createImage();
		self::assertNull((new TTextImagerFilter())->filterImage($image));
	}

	public function testBuiltInGDFont(): void
	{
		$image = static::render(static::newFilter(['Text' => 'Hi', 'TextColor' => '#0000FF']));

		$bounds = static::colorBounds($image, 0x0000FF);
		self::assertNotNull($bounds);
		// X 80% is 79; the baseline Y -10 is 89, less the 8 pixel font height.
		self::assertGreaterThanOrEqual(79, $bounds[0]);
		self::assertLessThanOrEqual(88, $bounds[1]);
		self::assertGreaterThanOrEqual(81, $bounds[2]);
		self::assertLessThanOrEqual(88, $bounds[3]);
	}

	public function testBuiltInGDFontVertically(): void
	{
		$image = static::render(static::newFilter(['Text' => 'Hi', 'TextColor' => '#0000FF', 'GDVertical' => true, 'TextX' => '10.5', 'TextY' => '50', 'GDFont' => '1']));

		$bounds = static::colorBounds($image, 0x0000FF);
		self::assertNotNull($bounds);
		// X 10.5 rounds to 11, less the 8 pixel font height; the text rises from Y 50.
		self::assertGreaterThanOrEqual(3, $bounds[0]);
		self::assertLessThanOrEqual(10, $bounds[1]);
		self::assertGreaterThanOrEqual(40, $bounds[2]);
		self::assertLessThanOrEqual(50, $bounds[3]);
	}

	public function testBadGDFont(): void
	{
		// Regression: a missing or malformed font file warned instead of failing configuration.
		$this->writeSource('short.gdf', 'short');
		Prado::setPathOfAlias('TextImagerFilterTestSrc', $this->srcDir);
		$image = static::createImage();
		$cases = [
			'missing path' => ['GDFont' => $this->srcDir . '/missing.gdf'],
			'missing in namespace' => ['GDFont' => 'missing.gdf', 'FontNamespace' => 'TextImagerFilterTestSrc', 'VerifyGDFont' => true],
			'short file' => ['GDFont' => 'short.gdf', 'FontNamespace' => 'TextImagerFilterTestSrc', 'VerifyGDFont' => true],
			'wrong byte order, unverified' => ['GDFont' => 'Teletext_6x10_BE.gdf', 'FontNamespace' => static::DATA_ALIAS, 'VerifyGDFont' => false],
		];
		foreach ($cases as $case => $properties) {
			$filter = static::newFilter(['Text' => 'Hi'] + $properties);
			static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_bad_gd_font');
		}
	}

	public function testVerifyingTheWrongByteOrder(): void
	{
		$image = static::createImage();
		$filter = static::newFilter(['Text' => 'Hi', 'GDFont' => 'Teletext_6x10_BE.gdf', 'FontNamespace' => static::DATA_ALIAS, 'VerifyGDFont' => 'true']);
		static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_gdf_bad_endian');

		$filter->setVerifyGDFont('Auto');
		static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_gdf_bad_endian', TConfigurationException::class);

		$application = static::application();
		$mode = $application->getMode();
		$application->setMode(TApplicationMode::Normal);
		try {
			static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_bad_gd_font');
		} finally {
			$application->setMode($mode);
		}
	}

	public function testVerifyGDFEndian(): void
	{
		$le = static::dataDir() . DIRECTORY_SEPARATOR . 'Teletext_6x10_LE.gdf';
		$path = $le;
		TTextImagerFilter::verifyGDFEndian($path, false);
		self::assertSame($le, $path, 'A font in the machine byte order is used as is.');

		$be = static::dataDir() . DIRECTORY_SEPARATOR . 'Teletext_6x10_BE.gdf';
		$path = $be;
		static::assertErrorCode(function () use (&$path) {
			TTextImagerFilter::verifyGDFEndian($path, false);
		}, 'textimagerfilter_gdf_bad_endian');

		$this->newManager();
		TTextImagerFilter::verifyGDFEndian($path, true);
		self::assertStringStartsWith($this->assetDir, $path, 'The font is published in the machine byte order.');
		self::assertSame(file_get_contents($le), file_get_contents($path));

		$missing = $this->srcDir . '/missing.gdf';
		TTextImagerFilter::verifyGDFEndian($missing, false);
		self::assertSame($this->srcDir . '/missing.gdf', $missing);
	}

	public function testGDFontFile(): void
	{
		$this->newManager();
		$filter = static::newFilter(['Text' => 'Hi', 'TextColor' => '#0000FF', 'GDFont' => 'Teletext_6x10_BE.gdf', 'FontNamespace' => static::DATA_ALIAS, 'VerifyGDFont' => 'Convert', 'TextX' => 0, 'TextY' => 20]);
		$image = static::createImage(100, 100, 0xFFFFFF, 0xFFFFFF);

		self::assertTrue($filter->filterImage($image), 'A loaded GDF font renders, including on PHP 8.1.');
		self::assertCount(1, glob($this->assetDir . '/*/Teletext_6x10_BE.gdf'), 'The font was converted before loading.');
		$bounds = static::colorBounds($image, 0x0000FF);
		self::assertNotNull($bounds);
		self::assertGreaterThanOrEqual(10, $bounds[2], 'The 10 pixel font sits on Y 20.');
		self::assertLessThanOrEqual(19, $bounds[3]);
	}

	public function testGDFontFileVertical(): void
	{
		$this->newManager();
		$filter = static::newFilter(['Text' => 'Hi', 'TextColor' => '#0000FF', 'GDFont' => 'Teletext_6x10_LE.gdf', 'FontNamespace' => static::DATA_ALIAS, 'GDVertical' => true, 'TextX' => 20, 'TextY' => 40]);
		$image = static::createImage(100, 100, 0xFFFFFF, 0xFFFFFF);

		self::assertTrue($filter->filterImage($image));
		$bounds = static::colorBounds($image, 0x0000FF);
		self::assertNotNull($bounds);
		self::assertGreaterThanOrEqual(10, $bounds[0], 'The 10 pixel font sits left of X 20.');
		self::assertLessThanOrEqual(19, $bounds[1]);
	}

	public function testGdFontSize(): void
	{
		self::assertSame([imagefontwidth(5), imagefontheight(5)], TTextImagerFilter::gdFontSize(5));
		self::assertSame([imagefontwidth(2), imagefontheight(2)], TTextImagerFilter::gdFontSize('2'));
		self::assertSame([6, 10], TTextImagerFilter::gdFontSize(static::dataDir() . DIRECTORY_SEPARATOR . 'Teletext_6x10_LE.gdf'));
		foreach (['missing' => $this->srcDir . '/missing.gdf', 'short' => $this->writeSource('short.gdf', 'abc'), 'zero' => $this->writeSource('zero.gdf', pack('L4', 1, 32, 0, 0))] as $case => $file) {
			try {
				TTextImagerFilter::gdFontSize($file);
				self::fail("A $case font file is rejected.");
			} catch (TConfigurationException $e) {
				self::assertSame('textimagerfilter_bad_gd_font', $e->getErrorCode());
			}
		}
	}

	public function testTrueTypeLeftBottom(): void
	{
		static::requireFreeType();
		$filter = static::newFilter(['Text' => 'Hello', 'TTFont' => static::ttFont(), 'TTFontSize' => 14, 'TextX' => 10, 'TextY' => 50, 'TextColor' => '#0000FF']);
		$image = static::render($filter, 200, 100);

		$box = imageftbbox(14, 0, static::ttFont(), 'Hello');
		$bounds = static::colorBounds($image, 0x0000FF);
		self::assertNotNull($bounds);
		self::assertGreaterThanOrEqual(10 + $box[6] - 2, $bounds[0]);
		self::assertLessThanOrEqual(10 + $box[4] + 2, $bounds[1]);
		self::assertGreaterThanOrEqual(50 + $box[5] - 2, $bounds[2]);
		self::assertLessThanOrEqual(50 + $box[1] + 2, $bounds[3]);
	}

	public function testTrueTypeAlignment(): void
	{
		static::requireFreeType();
		$base = ['Text' => 'HHHH', 'TTFont' => static::ttFont(), 'TTFontSize' => 14, 'TextColor' => '#0000FF'];

		$bounds = static::colorBounds(static::render(static::newFilter($base + ['TextX' => '-0', 'TextY' => '50', 'TTAlignX' => 'Right']), 200, 100), 0x0000FF);
		self::assertGreaterThanOrEqual(194, $bounds[1], 'Right aligned to the last column.');

		$bounds = static::colorBounds(static::render(static::newFilter($base + ['TextX' => 10, 'TextY' => '0', 'TTAlignY' => 'Top']), 200, 100), 0x0000FF);
		self::assertLessThanOrEqual(4, $bounds[2], 'Top aligned to the first row.');

		// Regression: fractional centered positions were passed to GD as floats.
		$bounds = static::colorBounds(static::render(static::newFilter($base + ['TextX' => '50%', 'TextY' => '50%', 'TTAlignX' => 'Center', 'TTAlignY' => 'Center']), 201, 101), 0x0000FF);
		self::assertEqualsWithDelta(100, ($bounds[0] + $bounds[1]) / 2, 4, 'Centered in x.');
		self::assertEqualsWithDelta(50, ($bounds[2] + $bounds[3]) / 2, 4, 'Centered in y.');
	}

	public function testTrueTypeAutomaticAlignment(): void
	{
		// Regression: the blank alignment by the sign of the position could not be set.
		static::requireFreeType();
		$base = ['Text' => 'HHHH', 'TTFont' => static::ttFont(), 'TTFontSize' => 14, 'TextColor' => '#0000FF', 'TTAlignX' => '', 'TTAlignY' => ''];

		$bounds = static::colorBounds(static::render(static::newFilter($base + ['TextX' => '-0', 'TextY' => '-0']), 200, 100), 0x0000FF);
		self::assertGreaterThanOrEqual(194, $bounds[1], 'A negative X is right aligned.');
		self::assertGreaterThanOrEqual(94, $bounds[3], 'A negative Y is bottom aligned.');

		$bounds = static::colorBounds(static::render(static::newFilter($base + ['TextX' => '5', 'TextY' => '5']), 200, 100), 0x0000FF);
		self::assertLessThanOrEqual(10, $bounds[0], 'A positive X is left aligned.');
		self::assertGreaterThanOrEqual(3, $bounds[2], 'A positive Y is top aligned.');
		self::assertLessThanOrEqual(10, $bounds[2]);
	}

	public function testTrueTypeAngleAndLineSpacing(): void
	{
		static::requireFreeType();
		$base = ['TTFont' => static::ttFont(), 'TTFontSize' => 14, 'TextColor' => '#0000FF', 'TextX' => 100, 'TextY' => 20];

		$bounds = static::colorBounds(static::render(static::newFilter(['Text' => 'HHHHHH', 'TTAngle' => 90, 'TextY' => 150] + $base), 200, 200), 0x0000FF);
		self::assertGreaterThan($bounds[1] - $bounds[0], $bounds[3] - $bounds[2], 'The text at 90° is taller than wide.');

		$single = static::colorBounds(static::render(static::newFilter($base + ['Text' => "H\nH", 'TTLineSpacing' => 1, 'TTAlignY' => 'Top']), 200, 200), 0x0000FF);
		$double = static::colorBounds(static::render(static::newFilter($base + ['Text' => "H\nH", 'TTLineSpacing' => '2', 'TTAlignY' => 'Top']), 200, 200), 0x0000FF);
		self::assertGreaterThan($single[3] - $single[2], $double[3] - $double[2], 'The line spacing separates the lines.');
	}

	public function testTrueTypeFontNamespace(): void
	{
		static::requireFreeType();
		Prado::setPathOfAlias('TextImagerFilterTestFonts', dirname(static::ttFont()));
		$filter = static::newFilter(['Text' => 'Hi', 'TTFont' => basename(static::ttFont()), 'FontNamespace' => 'TextImagerFilterTestFonts', 'TextX' => 10, 'TextY' => 50, 'TextColor' => '#0000FF']);

		$image = static::render($filter);
		self::assertNotNull(static::colorBounds($image, 0x0000FF));
		self::assertSame(realpath(dirname(static::ttFont())), getenv('GDFONTPATH'));
	}

	public function testBadTrueTypeFont(): void
	{
		static::requireFreeType();
		$image = static::createImage();

		self::assertFalse(static::newFilter(['Text' => 'Hi', 'TTFont' => $this->srcDir . '/missing.ttf'])->filterImage($image), 'A missing font is not rendered.');

		$filter = static::newFilter(['Text' => 'Hi', 'TTFont' => 'Font.ttf', 'FontNamespace' => 'NoSuchTextFilterAlias']);
		static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_bad_font_namespace');
		$filter->setFontNamespace(static::DATA_ALIAS . '.NoSuchDirectory');
		static::assertErrorCode(fn () => $filter->filterImage($image), 'textimagerfilter_bad_font_namespace');
	}

	public function testHandlerFlagsTheSave(): void
	{
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());

		self::assertTrue(static::applyFilter(static::newFilter(['Text' => 'Hi']), $param));
		self::assertTrue($param->getSaveImage());
	}
}
