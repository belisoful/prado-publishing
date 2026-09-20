<?php

/**
 * TAssetImagerTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TTIFF;
use Prado\Prado;
use Prado\Util\IBaseBehavior;
use Prado\Web\Assets\Behaviors\Filters\TFilterImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TResizeImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetBMPize;
use Prado\Web\Assets\Behaviors\TAssetGIFize;
use Prado\Web\Assets\Behaviors\TAssetGZCompress;
use Prado\Web\Assets\Behaviors\TAssetImageFilter;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\Behaviors\TAssetJPEGize;
use Prado\Web\Assets\Behaviors\TAssetPNGize;
use Prado\Web\Assets\Behaviors\TAssetTIFFize;
use Prado\Web\Assets\Behaviors\TAssetWBMPize;
use Prado\Web\Assets\Behaviors\TAssetWebPFallbackMode;
use Prado\Web\Assets\Behaviors\TAssetWebPize;
use Prado\Web\Assets\Behaviors\TAssetXBMize;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TGraphicslessImageFilter;
use Prado\Web\Tests\Fixtures\TGraphicslessPNGize;
use Prado\Web\Tests\Fixtures\TImagickImagerFilter;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;

/**
 * TAssetImagerTest class.
 *
 * Tests the image pipeline end to end through {@see \Prado\Web\TPublishingManager}: the
 * imager behaviors configured with `<filter>` and `<metadata>` elements, filter
 * parsing, format conversion, orientation, metadata writing, metadata matching, filter
 * files, and gzip compression after image processing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetImagerTest extends PublishingTestCase
{
	/** The alias of the package test data directory. */
	public const DATA_ALIAS = 'PublishingTestData';

	protected function setUp(): void
	{
		parent::setUp();
		Prado::setPathOfAlias(static::DATA_ALIAS, static::dataDir());
		unset($_SERVER['HTTP_ACCEPT']);
	}

	protected function tearDown(): void
	{
		unset($_SERVER['HTTP_ACCEPT']);
		parent::tearDown();
	}

	/**
	 * Attaches an imager to TImageAsset as a class behavior.
	 * @param string $class the imager class.
	 * @param string $xml the inner XML of the behavior element: `<filter>` and `<metadata>`.
	 * @param array<string, mixed> $properties the imager properties.
	 * @param string $name the behavior name.
	 */
	protected function attachImager(string $class, string $xml = '', array $properties = [], string $name = 'imager'): void
	{
		$config = ['class' => $class] + $properties;
		if ($xml !== '') {
			$doc = new TXmlDocument();
			$doc->loadFromString('<behavior>' . $xml . '</behavior>');
			$config[IBaseBehavior::CONFIG_KEY] = $doc;
		}
		$this->attachClassBehavior($name, $config, TImageAsset::class);
	}

	/**
	 * Writes a JPEG with EXIF and IPTC metadata.
	 * @param string $relative the source path.
	 * @param int $orientation the EXIF orientation.
	 * @param string[] $keywords the IPTC keywords.
	 * @param int $width
	 * @param int $height
	 * @return string the file path.
	 */
	protected function writeMetaJpeg(string $relative, int $orientation = 1, array $keywords = ['portfolio'], int $width = 40, int $height = 20): string
	{
		$jpeg = TJPEG::fromImage(static::createImage($width, $height), 95);
		$exif = new TEXIF();
		$exif->setValueByName('Artist', 'Ann Author');
		$exif->getIfd0()->setTagValues(TAssetImageMetaData::ORIENTATION_TAG, TTIFFDataType::UShort, [$orientation]);
		$jpeg->setEXIF($exif);
		$iptc = new TIPTC();
		$iptc['Keywords'] = $keywords;
		$jpeg->setIPTC($iptc);
		$path = $this->writeSource($relative, '');
		$jpeg->save($path);
		return $path;
	}

	public function testResizeFilterProcessesAndKeepsMetaData(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="20" />');
		$source = $this->writeMetaJpeg('photo.jpg');

		$url = $this->newManager()->publish($source);
		$published = $this->urlToPath($url);

		self::assertStringEndsWith('/photo.jpg', $url);
		self::assertSame([20, 10, IMAGETYPE_JPEG], array_slice(getimagesize($published), 0, 3));
		$file = TImageFile::fromFile($published);
		self::assertSame('Ann Author', $file->getEXIF()->getValueByName('Artist'));
		self::assertSame(20, $file->getIPTC()[TIPTCTags::IPTCImageWidth]);
		self::assertSame(10, $file->getIPTC()[TIPTCTags::IPTCImageHeight]);
		self::assertSame([40, 20], array_slice(getimagesize($source), 0, 2), 'The source is untouched.');
		self::assertEmpty(glob(dirname($published) . '/tmp-*'));
	}

	public function testUnchangedImageIsNotReencoded(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="100" />');
		$source = $this->writeImage('plain.jpg');

		$url = $this->newManager()->publish($source);

		self::assertSame(file_get_contents($source), file_get_contents($this->urlToPath($url)), 'An image no filter changed is published byte for byte.');
	}

	public function testFilterTypesAreCaseInsensitive(): void
	{
		$imager = new TAssetImageFilter();
		$imager->init(['filters' => [
			'shrink' => ['type' => 'Resize', 'MaximumWidth' => 10],
			'gray' => ['type' => 'GrayScale'],
			'resize2' => ['type' => 'RESIZE'],
		]]);

		self::assertInstanceOf(TResizeImagerFilter::class, $imager->getFilter('shrink'));
		self::assertInstanceOf(TFilterImagerFilter::class, $imager->getFilter('gray'));
		self::assertInstanceOf(TResizeImagerFilter::class, $imager->getFilter('resize2'));
	}

	public function testUnknownFilterType(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		$imager = new TAssetImageFilter();
		$imager->init(['filters' => [['type' => 'Sparkle']]]);
		$imager->getFilters();
	}

	public function testFilterWithClassAndType(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageFilter())->init(['filters' => [['type' => 'Resize', 'class' => TResizeImagerFilter::class]]]);
	}

	public function testFilterWithoutClassOrType(): void
	{
		$this->expectException(TConfigurationException::class);
		(new TAssetImageFilter())->init(['filters' => [['MaximumWidth' => 10]]]);
	}

	public function testFilterConfigMustBeAnArray(): void
	{
		$this->expectException(TConfigurationException::class);
		(new TAssetImageFilter())->init(['filters' => ['resize']]);
	}

	public function testDefaultMatchFilesSkipsFullAndOriginal(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="10" />');
		$manager = $this->newManager();

		self::assertSame(10, getimagesize($this->urlToPath($manager->publish($this->writeImage('a.png'))))[0]);
		self::assertSame(40, getimagesize($this->urlToPath($manager->publish($this->writeImage('b.full.png'))))[0]);
		self::assertSame(40, getimagesize($this->urlToPath($manager->publish($this->writeImage('c.original.png'))))[0]);
	}

	public function testOrientFilterRotatesAndResetsOrientation(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Orient" />');
		$source = $this->writeMetaJpeg('rotated.jpg', 6);

		$published = $this->urlToPath($this->newManager()->publish($source));

		$size = getimagesize($published);
		self::assertSame([20, 40], [$size[0], $size[1]], 'Orientation 6 rotates 90° clockwise.');
		$image = imagecreatefromjpeg($published);
		self::assertColorNear(0xFF0000, static::rgbAt($image, 15, 5), 40, 'The top-left mark is now top-right.');
		self::assertColorNear(0x3366CC, static::rgbAt($image, 5, 5), 40);
		self::assertSame(1, TImageFile::fromFile($published)->getEXIF()->getValueByName('Orientation'));
	}

	public function testOrientationsMapToTheUprightImage(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Orient" />');
		$manager = $this->newManager();
		// The upright size and a point inside the source's top-left mark once upright.
		$expected = [
			1 => [40, 20, 5, 5], 2 => [40, 20, 35, 5], 3 => [40, 20, 35, 15], 4 => [40, 20, 5, 15],
			5 => [20, 40, 5, 5], 6 => [20, 40, 15, 5], 7 => [20, 40, 15, 35], 8 => [20, 40, 5, 35],
		];
		foreach ($expected as $orientation => [$w, $h, $x, $y]) {
			$published = $this->urlToPath($manager->publish($this->writeMetaJpeg("o$orientation.jpg", $orientation)));
			$size = getimagesize($published);
			self::assertSame([$w, $h], [$size[0], $size[1]], "Orientation $orientation size.");
			self::assertColorNear(0xFF0000, static::rgbAt(imagecreatefromjpeg($published), $x, $y), 40, "Orientation $orientation mark.");
		}
	}

	public function testMetaDataFilterRewritesOnlyTheMetaData(): void
	{
		$parameters = static::application()->getParameters();
		$parameters->add('SiteOwner', 'Example Co');
		$this->attachImager(TAssetImageFilter::class, '<filter type="MetaData"><meta name="Keywords" content="web, ${SiteOwner}" separator="," append="true" /><meta name="EXIF:Copyright" content="(c) ${date:Y}" /></filter>');
		$source = $this->writeMetaJpeg('meta.jpg');

		try {
			$published = $this->urlToPath($this->newManager()->publish($source));
		} finally {
			$parameters->remove('SiteOwner');
		}

		$file = TImageFile::fromFile($published);
		self::assertSame(TImageFile::fromFile($source)->getScan(), $file->getScan(), 'The encoded image is unchanged.');
		self::assertSame(['portfolio', 'web', 'Example Co'], $file->getIPTC()['Keywords']);
		self::assertSame('(c) ' . date('Y'), $file->getEXIF()->getValueByName('Copyright'));
	}

	public function testMetaMatchSelectsImagesByMetaData(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="10" />', ['MetaMatch' => 'Keywords=portfolio']);
		$manager = $this->newManager();

		$match = $this->urlToPath($manager->publish($this->writeMetaJpeg('match.jpg', 1, ['portfolio'])));
		$other = $this->urlToPath($manager->publish($this->writeMetaJpeg('other.jpg', 1, ['private'])));

		self::assertSame(10, getimagesize($match)[0]);
		self::assertSame(40, getimagesize($other)[0]);
	}

	public function testMetadataElementScrubsAndDropsCarriers(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<metadata MetaDataPreserve="IPTC" Scrub="Author" /><filter type="Resize" MaximumWidth="20" />');
		$source = $this->writeMetaJpeg('scrub.jpg');

		$file = TImageFile::fromFile($this->urlToPath($this->newManager()->publish($source)));

		self::assertNull($file->getEXIF());
		self::assertNotNull($file->getIPTC());
	}

	public function testJPEGizeConvertsAndCarriesMetaData(): void
	{
		$this->attachImager(TAssetJPEGize::class, '', ['ImageQuality' => 90]);
		$png = TPNG::fromImage(static::createImage());
		$iptc = new TIPTC();
		$iptc['Keywords'] = ['from png'];
		$png->setIPTC($iptc);
		$source = $this->writeSource('graphic.png', '');
		$png->save($source);
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertStringEndsWith('/graphic.jpg', $url);
		self::assertSame($url, $manager->getPublishedUrl($source));
		self::assertSame(IMAGETYPE_JPEG, getimagesize($this->urlToPath($url))[2]);
		self::assertSame(['from png'], (array) TImageFile::fromFile($this->urlToPath($url))->getIPTC()['Keywords']);
	}

	public function testFormatConversions(): void
	{
		$conversions = [
			TAssetPNGize::class => ['png', IMAGETYPE_PNG],
			TAssetGIFize::class => ['gif', IMAGETYPE_GIF],
			TAssetBMPize::class => ['bmp', IMAGETYPE_BMP],
			TAssetWBMPize::class => ['wbmp', IMAGETYPE_WBMP],
			TAssetXBMize::class => ['xbm', IMAGETYPE_XBM],
		];
		foreach ($conversions as $class => [$extension, $type]) {
			$this->attachImager($class, '', [], 'convert');
			$source = $this->writeImage("convert-$extension.jpg");
			$url = $this->newManager()->publish($source);
			self::assertStringEndsWith("/convert-$extension.$extension", $url, "$class renames.");
			self::assertSame($type, getimagesize($this->urlToPath($url))[2], "$class converts.");
			\Prado\TComponent::detachClassBehavior('convert', TImageAsset::class);
		}
	}

	public function testWebPizeFollowsTheAcceptHeader(): void
	{
		$this->attachImager(TAssetWebPize::class);
		$source = $this->writeImage('pic.png');

		$_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp,*/*';
		$url = $this->newManager()->publish($source);
		self::assertStringEndsWith('/pic.webp', $url);
		self::assertSame(IMAGETYPE_WEBP, getimagesize($this->urlToPath($url))[2]);

		$_SERVER['HTTP_ACCEPT'] = 'image/png,*/*';
		$url = $this->newManager()->publish($source);
		self::assertStringEndsWith('/pic.png', $url, 'Without WebP support and a Preserve fallback, the original publishes.');
	}

	public function testWebPizeFallback(): void
	{
		$this->attachImager(TAssetWebPize::class, '', ['WebPFallback' => TAssetWebPFallbackMode::JPEG]);
		$_SERVER['HTTP_ACCEPT'] = 'image/png,*/*';

		$url = $this->newManager()->publish($this->writeImage('fallback.png'));

		self::assertStringEndsWith('/fallback.jpg', $url);
		self::assertSame(IMAGETYPE_JPEG, getimagesize($this->urlToPath($url))[2]);
	}

	public function testFilterFile(): void
	{
		Prado::setPathOfAlias('PublishingTestTemp', $this->tempDir);
		file_put_contents($this->tempDir . '/resize-filter.xml', '<filters><filter name="shrink" type="Resize" MaximumWidth="8" /></filters>');
		$this->attachImager(TAssetImageFilter::class, '', ['FilterFilePath' => 'PublishingTestTemp.resize-filter']);

		$url = $this->newManager()->publish($this->writeImage('filtered.png'));

		self::assertSame(8, getimagesize($this->urlToPath($url))[0]);
	}

	public function testUnreadableImageIsNotPublished(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="10" />');
		$source = $this->writeSource('broken.jpg', 'not really a jpeg');

		$url = $this->newManager()->publish($source);

		self::assertFalse(is_file($this->urlToPath($url)));
		self::assertEmpty(glob(dirname($this->urlToPath($url)) . '/tmp-*'));
	}

	public function testGZCompressRunsAfterImageProcessing(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="10" />');
		$this->attachClassBehavior('gzip', ['class' => TAssetGZCompress::class, 'MatchFiles' => '/\.png$/'], TAsset::class);

		$url = $this->newManager()->publish($this->writeImage('packed.png'));

		self::assertStringEndsWith('/packed.png.gz', $url);
		$png = gzdecode(file_get_contents($this->urlToPath($url)));
		self::assertIsString($png);
		self::assertSame([10, 5], array_slice(getimagesizefromstring($png), 0, 2));
	}

	public function testMetaDataIsReadForMatchingAndDroppedWithThePath(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<metadata Scrub="Location" />', ['MetaMatch' => 'Keywords']);
		$source = $this->writeMetaJpeg('attach.jpg');
		$asset = new TImageAsset($source);
		$imager = $asset->asa('imager');

		self::assertTrue((bool) $imager->hasMatch());
		$metaData = $imager->getImageMetaData();
		self::assertInstanceOf(TAssetImageMetaData::class, $metaData);
		self::assertSame(\Prado\IO\Image\TPrivacyCategory::Location, $metaData->getScrub(), 'The <metadata> element configures the metadata.');
		self::assertNull($asset->asa('MetaData'), 'The metadata is not a behavior of the asset.');

		$asset->setAssetFilePath($this->writeImage('other.jpg'));
		self::assertNull($imager->getImageMetaData(), 'A new path drops the metadata.');
	}

	public function testImagerStaticHelpers(): void
	{
		$palette = imagecreate(4, 4);
		$white = imagecolorallocate($palette, 255, 255, 255);
		imagecolortransparent($palette, $white);
		self::assertTrue(TAssetImagerBase::imagePaletteToTrueColor($palette));
		self::assertTrue(imageistruecolor($palette));
		self::assertFalse(TAssetImagerBase::imagePaletteToTrueColor($palette), 'Already true color.');

		$image = static::createImage(8, 8);
		self::assertTrue(TAssetImageFilter::imagePalettize($image, 4, false));
		self::assertFalse(imageistruecolor($image));
		self::assertLessThanOrEqual(4, imagecolorstotal($image));

		$bw = static::createImage(8, 8, 0xFFFFFF, 0x000000);
		self::assertTrue(TAssetImageFilter::imagePalettize($bw, 256, false, true));
		self::assertSame(2, imagecolorstotal($bw));

		$alpha = imagecreatetruecolor(2, 2);
		imagealphablending($alpha, false);
		imagesetpixel($alpha, 0, 0, 0x7F00FF00);
		self::assertTrue(TAssetImageFilter::imageRemoveAlpha($alpha, 0xFF0000));
		self::assertSame(0xFF0000, imagecolorat($alpha, 0, 0) & 0x7FFFFFFF);

		self::assertEqualsWithDelta(255.0, TAssetImageFilter::imageColorBrightness(0xFFFFFF), 0.01);
		self::assertEqualsWithDelta(0.0, TAssetImageFilter::imageColorBrightness(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 127]), 0.01);

		$sides = static::createImage(6, 6, 0x102030, 0x102030);
		self::assertTrue(TAssetImageFilter::imagePalettizeAlpha($sides, 'Sides', 0, 0, true));
		self::assertFalse(TAssetImageFilter::imagePalettize(null));
	}

	/**
	 * Writes a TIFF image, which GD cannot write, with prado-image.
	 * @param string $relative the source path.
	 * @param int $width
	 * @param int $height
	 * @return string the file path.
	 */
	protected function writeTiff(string $relative, int $width = 40, int $height = 20): string
	{
		$path = $this->writeSource($relative, '');
		TTIFF::fromImage(static::createImage($width, $height))->save($path);
		return $path;
	}

	public function testATiffSourceIsProcessed(): void
	{
		$this->attachImager(TAssetImageFilter::class, '<filter type="Resize" MaximumWidth="20" />');
		$source = $this->writeTiff('scan.tif');

		$published = $this->urlToPath($url = $this->newManager()->publish($source));

		self::assertStringEndsWith('/scan.tif', $url);
		self::assertSame([20, 10], array_slice(getimagesize($published), 0, 2), 'GD cannot read a TIFF; the prado-image container converts it.');
		self::assertInstanceOf(TTIFF::class, TImageFile::fromFile($published));
	}

	public function testTIFFizeConvertsAndCarriesMetaData(): void
	{
		$this->attachImager(TAssetTIFFize::class, '<filter type="Resize" MaximumWidth="20" />', ['TiffCompression' => 'PackBits']);
		$source = $this->writeMetaJpeg('photo.jpg', 1, ['portfolio']);

		$published = $this->urlToPath($url = $this->newManager()->publish($source));

		self::assertStringEndsWith('/photo.tif', $url);
		$file = TImageFile::fromFile($published);
		self::assertInstanceOf(TTIFF::class, $file);
		self::assertSame([20, 10], [$file->getWidth(), $file->getHeight()]);
		self::assertSame(['portfolio'], (array) $file->getIPTC()[TIPTCTags::Keywords], 'The IPTC is carried into the TIFF.');
		self::assertSame([20, 10], array_slice(getimagesize($published), 0, 2));
	}

	public function testWithoutAGraphicsLibraryTheImageIsPublishedUnprocessed(): void
	{
		$this->attachImager(TGraphicslessImageFilter::class, '<filter type="Resize" MaximumWidth="10" />');
		$source = $this->writeImage('untouched.png', 40, 20);

		$published = $this->urlToPath($this->newManager()->publish($source));

		self::assertSame(file_get_contents($source), file_get_contents($published), 'The image publishes byte for byte.');
	}

	public function testWithoutAGraphicsLibraryAConversionDoesNotRename(): void
	{
		$this->attachImager(TGraphicslessPNGize::class);
		$source = $this->writeMetaJpeg('keep.jpg');

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/keep.jpg', $url, 'A file that cannot be converted is not renamed.');
		self::assertSame(file_get_contents($source), file_get_contents($this->urlToPath($url)));
	}

	public function testAnImagickFilterInAGdChain(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$imager = new TAssetImageFilter();
		$this->attachClassBehavior('imager', $imager, TImageAsset::class);
		$imager->addFilter('shrink', $shrink = new TResizeImagerFilter());
		$shrink->setMaximumWidth(20);
		$imager->addFilter('negate', $negate = new TImagickImagerFilter());
		$source = $this->writeImage('mixed.png', 40, 20);

		$published = $this->urlToPath($this->newManager()->publish($source));

		self::assertSame(['Imagick'], $negate->calls);
		self::assertSame([20, 10, IMAGETYPE_PNG], array_slice(getimagesize($published), 0, 3));
		$image = imagecreatefrompng($published);
		self::assertSame(0x00FFFF, static::rgbAt($image, 2, 2), 'The GD resize and the Imagick negate both applied.');
	}
}
