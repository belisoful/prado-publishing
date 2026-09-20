<?php

/**
 * TMetaDataImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\TJPEG;
use Prado\Web\Assets\Behaviors\Filters\TMetaDataImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;

/**
 * TMetaDataImagerFilterTest class.
 *
 * Tests the metadata filter: its `<meta>` configuration from XML and arrays, content
 * placeholders, and writing the fields into the asset's metadata without changing
 * the pixels.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TMetaDataImagerFilterTest extends PublishingTestCase
{
	/** @var string[] the application parameters added by the test. */
	private array $_parameters = [];

	protected function tearDown(): void
	{
		foreach ($this->_parameters as $name) {
			static::application()->getParameters()->remove($name);
		}
		parent::tearDown();
	}

	/**
	 * @param string $name the application parameter.
	 * @param mixed $value the value.
	 */
	protected function addParameter(string $name, $value): void
	{
		static::application()->getParameters()->add($name, $value);
		$this->_parameters[] = $name;
	}

	/**
	 * Writes a JPEG with IPTC keywords and copyright and an EXIF artist.
	 * @return string the file path.
	 */
	protected function writeMetaJpeg(): string
	{
		$jpeg = TJPEG::fromImage(static::createImage(40, 20), 95);
		$exif = new TEXIF();
		$exif->setValueByName('Artist', 'Ann Author');
		$jpeg->setEXIF($exif);
		$iptc = new TIPTC();
		$iptc['Keywords'] = ['portfolio'];
		$iptc['Copyright'] = 'Ann Author 2026';
		$iptc['Caption-Abstract'] = 'A caption';
		$jpeg->setIPTC($iptc);
		$path = $this->writeSource('meta.jpg', '');
		$jpeg->save($path);
		return $path;
	}

	/**
	 * @return TAssetEventParameter the publishing parameter of an asset on a metadata JPEG,
	 *   with its metadata.
	 */
	protected function newParam(): TAssetEventParameter
	{
		$path = $this->writeMetaJpeg();
		$metaData = new TAssetImageMetaData();
		$metaData->setMetaDataSource($path);
		$param = new TAssetEventParameter('onProcessAsset', '', new TImageAsset($path));
		$param->setImageMetaData($metaData);
		return $param;
	}

	public function testAddMeta(): void
	{
		$filter = new TMetaDataImagerFilter();
		self::assertSame([], $filter->getMeta());

		$filter->addMeta('Keywords', ' a, b ,, c ', ',', true);
		$filter->addMeta('Copyright', 'Mine', '');
		$filter->addMeta('EXIF:Artist', ['x', 'y'], ',');
		$filter->addMeta('Caption-Abstract');

		self::assertSame([
			['field' => 'Keywords', 'content' => ['a', 'b', 'c'], 'append' => true],
			['field' => 'Copyright', 'content' => 'Mine', 'append' => false],
			['field' => 'EXIF:Artist', 'content' => ['x', 'y'], 'append' => false],
			['field' => 'Caption-Abstract', 'content' => null, 'append' => false],
		], $filter->getMeta());
	}

	public function testAddMetaRejectsAnUnknownField(): void
	{
		foreach (['', '  ', 'NotAField', 'XMP:title'] as $name) {
			try {
				(new TMetaDataImagerFilter())->addMeta($name, 'x');
				self::fail("An exception was expected for '$name'.");
			} catch (TConfigurationException $e) {
				self::assertSame('metadatafilter_bad_field', $e->getErrorCode());
			}
		}
	}

	public function testXmlConfiguration(): void
	{
		$document = new TXmlDocument();
		$document->loadFromString('<filter type="MetaData">'
			. '<meta name="Keywords" content="web, published" separator="," append="true" />'
			. '<meta Name="Copyright" Content="(c) ${date:Y}" />'
			. '<meta name="Caption-Abstract" />'
			. '<other name="Ignored" />'
			. '</filter>');
		$filter = new TMetaDataImagerFilter();
		$filter->init($document);

		self::assertSame([
			['field' => 'Keywords', 'content' => ['web', 'published'], 'append' => true],
			['field' => 'Copyright', 'content' => '(c) ${date:Y}', 'append' => false],
			['field' => 'Caption-Abstract', 'content' => null, 'append' => false],
		], $filter->getMeta());
	}

	public function testXmlConfigurationRejectsANamelessField(): void
	{
		$document = new TXmlDocument();
		$document->loadFromString('<filter><meta content="x" /></filter>');
		try {
			(new TMetaDataImagerFilter())->init($document);
			self::fail('An exception was expected.');
		} catch (TConfigurationException $e) {
			self::assertSame('metadatafilter_bad_field', $e->getErrorCode());
		}
	}

	public function testArrayConfiguration(): void
	{
		$filter = new TMetaDataImagerFilter();
		$filter->init(['meta' => [['name' => 'Keywords', 'content' => 'ignored']]]);
		self::assertSame([], $filter->getMeta(), 'An array configuration gives its fields as the Meta property.');

		$filter->parseConfig(['meta' => [
			['name' => 'Keywords', 'content' => 'one;two', 'separator' => ';', 'append' => 'false'],
			['NAME' => 'EXIF:Artist', 'CONTENT' => '${Owner}'],
		]]);
		$filter->parseConfig(['other' => 'ignored']);
		$filter->parseConfig(null);

		self::assertSame([
			['field' => 'Keywords', 'content' => ['one', 'two'], 'append' => false],
			['field' => 'EXIF:Artist', 'content' => '${Owner}', 'append' => false],
		], $filter->getMeta());
	}

	public function testTransformContent(): void
	{
		$this->addParameter('MetaFilterOwner', 'Example Co');

		self::assertSame('plain', TMetaDataImagerFilter::transformContent('plain'));
		self::assertSame('By Example Co.', TMetaDataImagerFilter::transformContent('By ${MetaFilterOwner}.'));
		self::assertSame('By Example Co.', TMetaDataImagerFilter::transformContent('By ${ MetaFilterOwner }.'));
		self::assertSame('[]', TMetaDataImagerFilter::transformContent('[${NoSuchMetaFilterParameter}]'), 'An unknown parameter is empty.');
		self::assertSame('(c) ' . date('Y'), TMetaDataImagerFilter::transformContent('(c) ${date:Y}'));
		self::assertSame(date('Y') . '-' . date('Y'), TMetaDataImagerFilter::transformContent('${time: Y}-${DATE:Y}'));
	}

	public function testNoMetaData(): void
	{
		$filter = new TMetaDataImagerFilter();
		$filter->addMeta('Keywords', 'x');
		$image = static::createImage();

		self::assertNull($filter->filterImage($image));
		self::assertNull($filter->filterImage($image, new TAssetEventParameter('onProcessAsset', '', new TImageAsset($this->writeImage('plain.png')))));
	}

	public function testWritesTheFields(): void
	{
		$this->addParameter('MetaFilterOwner', 'Example Co');
		$param = $this->newParam();
		$metaData = $param->getImageMetaData();
		$filter = new TMetaDataImagerFilter();
		$filter->setMeta([
			['name' => 'Keywords', 'content' => 'replaced'],
		]);
		$filter->setMeta([
			['name' => 'Keywords', 'content' => 'web, ${MetaFilterOwner}', 'separator' => ',', 'append' => true],
			['name' => 'Copyright', 'content' => '(c) ${date:Y}'],
			['name' => 'EXIF:Artist', 'content' => '${MetaFilterOwner}'],
			['name' => 'Caption-Abstract'],
		]);
		self::assertCount(4, $filter->getMeta(), 'Setting the fields replaces them.');
		$image = static::createImage();

		self::assertFalse($filter->filterImage($image, $param), 'The pixels are not changed.');

		self::assertSame(['portfolio', 'web', 'Example Co'], $metaData->getMetaData('Keywords'));
		self::assertSame('(c) ' . date('Y'), $metaData->getMetaData('Copyright'));
		self::assertSame('Example Co', $metaData->getMetaData('Artist'));
		self::assertNull($metaData->getMetaData('Caption-Abstract'));
		self::assertTrue($metaData->getChanged());
	}

	public function testHandlerDoesNotFlagTheSave(): void
	{
		$param = $this->newParam();
		$filter = new TMetaDataImagerFilter();
		$filter->addMeta('Keywords', ['replaced']);
		$param->setImage(static::createImage());

		self::assertFalse(static::applyFilter($filter, $param));
		self::assertFalse($param->getSaveImage(), 'Only the metadata is rewritten.');
		self::assertSame(['replaced'], (array) $param->getImageMetaData()->getMetaData('Keywords'));
	}
}
