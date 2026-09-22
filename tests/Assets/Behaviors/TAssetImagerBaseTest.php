<?php

/**
 * TAssetImagerBaseTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Caching\TFileCacheDependency;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TTIFF;
use Prado\Prado;
use Prado\TApplication;
use Prado\TApplicationMode;
use Prado\Util\IBaseBehavior;
use Prado\Util\TBehavior;
use Prado\Web\Assets\Behaviors\Filters\TFilterImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TImagerFilterFactory;
use Prado\Web\Assets\Behaviors\Filters\TResizeImagerFilter;
use Prado\Web\Assets\Behaviors\TAssetBlocker;
use Prado\Web\Assets\Behaviors\TAssetImageFilter;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Web\Assets\Behaviors\TAssetImagerBase;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TArrayCache;
use Prado\Web\Tests\Fixtures\TFaultyImageMetaData;
use Prado\Web\Tests\Fixtures\TFixedOrientationMetaData;
use Prado\Web\Tests\Fixtures\TGdImagerFilter;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TImagickImagerFilter;
use Prado\Web\Tests\Fixtures\TNoInterchangeImageFilter;
use Prado\Web\Tests\Fixtures\TRecordingImagerFilter;
use Prado\Web\Tests\Fixtures\TSimpleImageParameter;
use Prado\Web\Tests\Fixtures\TUnwritableImageFilter;
use Prado\Web\Tests\Fixtures\TUnavailableModeImagerFilter;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;
use Prado\Xml\TXmlElement;

/**
 * TAssetImagerBaseTest class.
 *
 * Tests {@see TAssetImagerBase} through {@see TAssetImageFilter}: its accessors, the
 * filter configuration (XML, arrays, the `<metadata>` element, registered filter types,
 * filter files in XML and PHP, their merging and caching, and the ordered filters), the modification date, matching,
 * the guards of processing, the image formats it opens, and finalizing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetImagerBaseTest extends PublishingTestCase
{
	/** The alias of the per-test temporary directory, for filter files. */
	public const TEMP_ALIAS = 'PublishingImagerBaseTemp';

	/** @var ?string the application mode to restore. */
	private ?string $_mode = null;

	protected function setUp(): void
	{
		parent::setUp();
		Prado::setPathOfAlias(static::TEMP_ALIAS, $this->tempDir);
		$this->_mode = static::application()->getMode();
	}

	protected function tearDown(): void
	{
		static::application()->setMode($this->_mode);
		parent::tearDown();
	}

	/**
	 * @param TAssetImagerBase $imager
	 * @param string $method a protected method.
	 * @param mixed ...$args
	 * @return mixed the result of the method.
	 */
	protected static function invoke(TAssetImagerBase $imager, string $method, ...$args)
	{
		return (new \ReflectionMethod($imager, $method))->invoke($imager, ...$args);
	}

	/**
	 * Writes a filter file into the temporary directory.
	 * @param string $name the file name, without extension.
	 * @param string $content the file content.
	 * @param string $extension the file extension.
	 * @return string the file path.
	 */
	protected function writeFilterFile(string $name, string $content, string $extension = '.xml'): string
	{
		file_put_contents($path = $this->tempDir . DIRECTORY_SEPARATOR . $name . $extension, $content);
		return $path;
	}

	/**
	 * @param string[] $keywords the IPTC keywords.
	 * @return string a 40x20 JPEG with the IPTC keywords.
	 */
	protected static function keywordJpeg(array $keywords): string
	{
		$jpeg = TJPEG::fromImage(static::createImage(), 95);
		$iptc = new TIPTC();
		$iptc['Keywords'] = $keywords;
		$jpeg->setIPTC($iptc);
		$file = tempnam(sys_get_temp_dir(), 'kw');
		try {
			$jpeg->save($file);
			return file_get_contents($file);
		} finally {
			@unlink($file);
		}
	}

	/**
	 * Restores the application cache, which cannot be set to null by its setter.
	 * @param mixed $cache the prior cache.
	 */
	protected static function restoreCache($cache): void
	{
		(new \ReflectionProperty(TApplication::class, '_cache'))->setValue(static::application(), $cache);
	}

	// ---------------------------------------------------------------------------
	// Accessors.
	// ---------------------------------------------------------------------------

	public function testFilterFilePathChangeReloadsTheFileFilters(): void
	{
		$this->writeFilterFile('first', '<filters><filter name="shrink" type="Resize" MaximumWidth="8" /></filters>');
		$this->writeFilterFile('second', '<filters><filter name="gray" type="Grayscale" /></filters>');
		$imager = new TAssetImageFilter();
		self::assertNull($imager->getFilterFilePath());

		$imager->setFilterFilePath(static::TEMP_ALIAS . '.first');
		self::assertSame(static::TEMP_ALIAS . '.first', $imager->getFilterFilePath());
		self::assertInstanceOf(TResizeImagerFilter::class, $shrink = $imager->getFilter('shrink'));
		self::assertSame(8.0, $shrink->getMaximumWidth());

		$imager->setFilterFilePath(static::TEMP_ALIAS . '.first');
		self::assertSame($shrink, $imager->getFilter('shrink'), 'The same path keeps the filters.');

		$imager->setFilterFilePath(static::TEMP_ALIAS . '.second');
		self::assertNull($imager->getFilter('shrink'), 'A new path replaces the file filters.');
		self::assertInstanceOf(TFilterImagerFilter::class, $imager->getFilter('gray'));

		$imager->setFilterFilePath('');
		self::assertSame([], $imager->getFilters());
		self::assertNull(static::invoke($imager, 'getFilterFile'), 'No filter file.');
		self::assertSame(['metadata' => null, 'filters' => []], static::invoke($imager, 'loadFilterFile'));
	}

	public function testMatchFilesResetsTheMatch(): void
	{
		$asset = new TImageAsset($this->writeImage('match.png'));
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		self::assertTrue($imager->hasMatch());

		$imager->setMatchFiles('/\.gif$/');
		self::assertSame('/\.gif$/', $imager->getMatchFiles());
		self::assertFalse($imager->hasMatch(), 'The match is re-evaluated.');

		$imager->setMatchFiles('/\.png$/');
		self::assertTrue($imager->hasMatch());
	}

	public function testMetaClass(): void
	{
		$imager = new TAssetImageFilter();
		self::assertSame(TAssetImageMetaData::class, $imager->getMetaClass());
		$imager->setMetaClass(TFixedOrientationMetaData::class);
		self::assertSame(TFixedOrientationMetaData::class, $imager->getMetaClass());
		$imager->setMetaMatch('Keywords');
		self::assertSame('Keywords', $imager->getMetaMatch());
		self::assertNull($imager->getImageMetaData());

		$asset = new TImageAsset($source = $this->writeImage('meta-class.jpg'));
		$asset->attachBehavior('imager', $imager);
		self::assertFalse($imager->hasMatch(), 'The image has no keywords.');

		$metaData = $imager->getImageMetaData();
		self::assertInstanceOf(TFixedOrientationMetaData::class, $metaData, 'The metadata is the MetaClass.');
		self::assertSame($source, $metaData->getMetaDataSource());

		$imager->setMetaClass(TAssetImageMetaData::class);
		self::assertNull($imager->getImageMetaData(), 'A new class drops the metadata.');

		try {
			$imager->setMetaClass(TBehavior::class);
			self::fail('A class that is not metadata is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetimagerbase_bad_metaclass', $e->getErrorCode());
		}
		self::assertSame(TAssetImageMetaData::class, $imager->getMetaClass());
	}

	public function testMetaDataSourceIsResetWhenThePreviousSourceIsGone(): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><filter class="' . TRecordingImagerFilter::class . '" /></behavior>');
		$asset = new TImageAsset($this->writeImage('republish.png', 30, 12));
		$asset->attachBehavior('imager', ['class' => TAssetImageFilter::class, IBaseBehavior::CONFIG_KEY => $doc]);
		$manager = $this->newManager();

		$url = $manager->publish($asset);
		$firstSource = $asset->asa('imager')->getImageMetaData()->getMetaDataSource();
		self::assertStringStartsWith('tmp-', basename($firstSource), 'The metadata is read from the atomic temporary file.');
		self::assertFalse(is_file($firstSource), 'The temporary file was moved into place.');
		unlink($this->urlToPath($url));

		self::assertSame($url, $manager->publish($asset, ['forceCopy' => true]), 'The asset object republishes with another option.');

		$secondSource = $asset->asa('imager')->getImageMetaData()->getMetaDataSource();
		self::assertNotSame($firstSource, $secondSource, 'The new temporary file is read.');
		self::assertStringStartsWith('tmp-', basename($secondSource));
		self::assertSame([30, 12, IMAGETYPE_PNG], array_slice(getimagesize($this->urlToPath($url)), 0, 3), 'The republished image is written.');

		$existing = $this->writeImage('existing.png');
		$metaData = $asset->asa('imager')->getImageMetaData();
		$metaData->setMetaDataSource($existing);
		static::invoke($asset->asa('imager'), 'ensureMetaData', $this->writeImage('other.png'));
		self::assertSame($existing, $metaData->getMetaDataSource(), 'An existing source is kept.');
	}

	public function testFinalizerPriority(): void
	{
		$imager = new TAssetImageFilter();
		self::assertSame(5.0, $imager->getFinalizerPriority(), 'The default priority.');

		$imager->setFinalizerPriority('20');
		self::assertSame(20.0, $imager->getFinalizerPriority());
		$source = $this->writeImage('priority20.png');
		$asset20 = new TImageAsset($source);
		$asset20->attachBehavior('imager', $imager);
		copy($source, $dst20 = $this->assetDir . '/priority20.png');
		$param20 = new TAssetEventParameter('onProcessAsset', $dst20, $asset20);
		self::assertTrue($imager->processAsset($asset20, $param20));
		self::assertEquals(20, $param20->getFinalizers()->priorityOf($imager), 'The imager finalizes at its priority.');

		$imager = new TAssetImageFilter();

		$source = $this->writeImage('priority.png');
		$asset = new TImageAsset($source);
		$asset->attachBehavior('imager', $imager);
		copy($source, $dst = $this->assetDir . '/priority.png');
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);

		self::assertTrue($imager->processAsset($asset, $param));

		self::assertSame([$imager], $param->getFinalizers()->toArray());
		self::assertEquals(5, $param->getFinalizers()->priorityOf($imager));
	}

	public function testMetaDataIsNotAnAssetBehavior(): void
	{
		$asset = new TImageAsset($source = $this->writeImage('reset.jpg'));
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		copy($source, $dst = $this->assetDir . '/reset.jpg');
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);

		self::assertTrue($imager->processAsset($asset, $param));

		self::assertInstanceOf(TAssetImageMetaData::class, $metaData = $param->getImageMetaData(), 'The metadata is shared through the parameter.');
		self::assertSame($metaData, $imager->getImageMetaData());
		self::assertNotInstanceOf(IBaseBehavior::class, $metaData);
		self::assertNull($asset->asa('MetaData'));

		$asset->attachBehavior('block', new TAssetBlocker());
		self::assertNull($imager->getImageMetaData(), 'Resetting the path drops the metadata.');
		self::assertSame($metaData, $param->getImageMetaData(), 'The publishing parameter keeps its metadata.');
	}

	// ---------------------------------------------------------------------------
	// Configuration.
	// ---------------------------------------------------------------------------

	public function testConfigFromXml(): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><metadata Scrub="Location" MetaDataPreserve="IPTC" /><filter type=" Resize " MaximumWidth="10" /><filter name="gray" type="GrayScale" /><filter class="' . TRecordingImagerFilter::class . '" /></behavior>');
		$imager = new TAssetImageFilter();
		$imager->init($doc);

		$config = $imager->getFilterConfig();
		self::assertSame(['appFilter0', 'gray', 'appFilter1'], array_keys($config['filters']));
		self::assertSame(['scrub' => 'Location', 'metadatapreserve' => 'IPTC'], $config['metadata']);
		self::assertSame(['appFilter0', 'gray', 'appFilter1'], array_keys($filters = $imager->getFilters()), 'The filters are in configuration order.');
		self::assertInstanceOf(TResizeImagerFilter::class, $filters['appFilter0']);
		self::assertSame(10.0, $filters['appFilter0']->getMaximumWidth());
		self::assertSame('GrayScale', $filters['gray']->getEffect());
		self::assertInstanceOf(TRecordingImagerFilter::class, $filters['appFilter1']);
		self::assertInstanceOf(TXmlElement::class, $filters['appFilter1']->config, 'A filter is initialized with its element.');
		self::assertSame('filter', $filters['appFilter1']->config->getTagName());
		self::assertSame($filters, $imager->getFilters(), 'The filters are created once.');

		$metaData = static::invoke($imager, 'ensureMetaData', $source = $this->writeImage('xml.jpg'));
		self::assertSame($metaData, $imager->getImageMetaData());
		self::assertSame(\Prado\IO\Image\TPrivacyCategory::Location, $metaData->getScrub());
		self::assertTrue($metaData->getIsPreserved('IPTC'));
		self::assertFalse($metaData->getIsPreserved('EXIF'));
		self::assertSame($source, $metaData->getMetaDataSource());
		self::assertSame($metaData, static::invoke($imager, 'ensureMetaData', $this->writeImage('other.jpg')), 'The metadata is reused, with its source.');
		self::assertSame($source, $metaData->getMetaDataSource());
	}

	public function testConfigIsSharedByImagers(): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><filter name="shrink" type="Resize" MaximumWidth="10" /></behavior>');
		$first = new TAssetImageFilter();
		$first->init($doc);
		$second = new TAssetImageFilter();
		$second->init($doc);

		self::assertSame($first->getFilterConfig(), $second->getFilterConfig());
		self::assertNotSame($first->getFilter('shrink'), $second->getFilter('shrink'), 'Each imager has its own filters.');
	}

	public function testConfigArrayWithMetaDataProperties(): void
	{
		$imager = new TAssetImageFilter();
		$imager->init([
			'metadata' => ['properties' => ['Scrub' => 'Author'], 'extra' => 'config'],
			'filters' => ['shrink' => ['type' => 'Resize', 'MaximumWidth' => 12]],
		]);
		self::assertSame(12.0, $imager->getFilter('shrink')->getMaximumWidth());

		$metaData = static::invoke($imager, 'ensureMetaData', $this->writeImage('props.jpg'));
		self::assertSame(\Prado\IO\Image\TPrivacyCategory::Author, $metaData->getScrub(), 'The properties configure the metadata.');
	}

	public function testConfigArrayWithMetaData(): void
	{
		$imager = new TAssetImageFilter();
		$imager->init([
			'metadata' => ['Scrub' => 'Location'],
			'shrink' => ['type' => 'Resize'],
			['class' => TRecordingImagerFilter::class],
		]);

		$config = $imager->getFilterConfig();
		self::assertSame(['shrink', 'appFilter0'], array_keys($config['filters']), 'Without "filters", the configuration is the filters; "metadata" is not a filter.');
		self::assertSame(['type' => 'Resize'], $config['filters']['shrink']['config']);

		$metaData = static::invoke($imager, 'ensureMetaData', $this->writeImage('array.jpg'));
		self::assertSame(\Prado\IO\Image\TPrivacyCategory::Location, $metaData->getScrub());
	}

	public function testUnsupportedConfigHasNoFilters(): void
	{
		foreach (['filters', [], null] as $config) {
			$imager = new TAssetImageFilter();
			$imager->init($config);
			self::assertSame(['metadata' => null, 'filters' => []], $imager->getFilterConfig());
			self::assertSame([], $imager->getFilters());
		}

		$asset = new TImageAsset($source = $this->writeImage('unsupported.png', 40, 20));
		touch($source, 1000000);
		$asset->attachBehavior('imager', $imager);
		self::assertSame(1000000, $asset->getAssetModificationDate(), 'Without application filters, the application configuration is not a dependency.');
	}

	public function testFilterFileMergesWithTheConfig(): void
	{
		$this->writeFilterFile('merged', '<filters><metadata Scrub="Author" JFIFSize="100" /><filter name="gray" type="Negate" /><filter name="extra" type="Blur" /></filters>');
		$imager = new TAssetImageFilter();
		$imager->init(['metadata' => ['Scrub' => 'Location', 'JFIFMode' => 'None'], 'filters' => ['gray' => ['type' => 'Grayscale'], 'shrink' => ['type' => 'Resize']]]);
		$imager->setFilterFilePath(static::TEMP_ALIAS . '.merged');

		$config = $imager->getFilterConfig();
		self::assertSame(['gray', 'shrink', 'extra'], array_keys($config['filters']), 'The filter file follows the configuration, replacing filters in place.');
		self::assertSame('Negate', $imager->getFilter('gray')->getEffect());
		self::assertSame(['scrub' => 'Author', 'jfifsize' => '100', 'jfifmode' => 'None'], $config['metadata'], 'The filter file metadata overrides the configuration.');

		$imager = new TAssetImageFilter();
		$imager->setFilterFilePath(static::TEMP_ALIAS . '.merged');
		self::assertSame(['scrub' => 'Author', 'jfifsize' => '100'], $imager->getFilterConfig()['metadata'], 'The filter file metadata alone.');
	}

	public function testAddAndRemoveFilters(): void
	{
		$imager = new TAssetImageFilter();
		$imager->init(['filters' => ['first' => ['class' => TRecordingImagerFilter::class], 'second' => ['class' => TRecordingImagerFilter::class]]]);
		$log = new \ArrayObject();
		$added = new TRecordingImagerFilter();
		$replaced = new TRecordingImagerFilter();

		$imager->addFilter('added', $added);
		$imager->addFilter('first', $replaced);
		self::assertSame(['first', 'second', 'added'], array_keys($imager->getFilters()));
		self::assertSame($replaced, $imager->getFilter('first'), 'A filter of the same name is replaced in place.');
		foreach ($imager->getFilters() as $filter) {
			$filter->log = $log;
		}

		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage());
		self::assertTrue($imager->applyFilters($param));
		self::assertSame([$replaced, $imager->getFilter('second'), $added], $log->getArrayCopy(), 'The filters run in order.');

		self::assertSame($added, $imager->removeFilter('added'));
		self::assertNull($imager->removeFilter('added'));
		self::assertNull($imager->getFilter('added'));
		self::assertSame(['first', 'second'], array_keys($imager->getFilters()));
	}

	public function testRegisteredFilterTypes(): void
	{
		TImagerFilterFactory::registerType('Sparkle', TRecordingImagerFilter::class);
		TImagerFilterFactory::registerType('resize', TRecordingImagerFilter::class);
		try {
			$imager = new TAssetImageFilter();
			$imager->init(['filters' => [
				'sparkle' => ['type' => 'SPARKLE'],
				'resize' => ['type' => 'Resize'],
				'gray' => ['type' => 'Grayscale'],
				'orient' => ['type' => 'Orient'],
			]]);
			$filters = $imager->getFilters();
		} finally {
			TImagerFilterFactory::unregisterType('sparkle');
			TImagerFilterFactory::unregisterType('Resize');
		}

		self::assertInstanceOf(TRecordingImagerFilter::class, $filters['sparkle'], 'A new type is provided.');
		self::assertInstanceOf(TRecordingImagerFilter::class, $filters['resize'], 'A built in type is replaced.');
		self::assertInstanceOf(TFilterImagerFilter::class, $filters['gray']);
		self::assertInstanceOf(\Prado\Web\Assets\Behaviors\Filters\TOrientImagerFilter::class, $filters['orient']);
		self::assertSame(TImagerFilterFactory::TYPES, TImagerFilterFactory::getTypes(), 'The types are unregistered.');
	}

	public function testDebugModeInstancesFiltersOnInit(): void
	{
		$config = ['filters' => ['shrink' => ['type' => 'Resize']]];
		$filters = new \ReflectionProperty(TAssetImagerBase::class, '_filters');

		static::application()->setMode(TApplicationMode::Normal);
		$imager = new TAssetImageFilter();
		$imager->init($config);
		self::assertNull($filters->getValue($imager), 'Normal mode delays the filters until they are needed.');
		self::assertInstanceOf(TResizeImagerFilter::class, $imager->getFilter('shrink'));

		static::application()->setMode(TApplicationMode::Debug);
		$imager = new TAssetImageFilter();
		$imager->init($config);
		self::assertInstanceOf(TResizeImagerFilter::class, $filters->getValue($imager)['shrink'], 'Debug mode instances the filters.');

		$this->expectException(TInvalidDataValueException::class);
		(new TAssetImageFilter())->init(['filters' => ['bad' => ['class' => TBehavior::class]]]);
	}

	public function testApplicationFilterMustBeAnImagerFilter(): void
	{
		static::application()->setMode(TApplicationMode::Normal);
		$imager = new TAssetImageFilter();
		$imager->init(['filters' => ['bad' => ['class' => TBehavior::class]]]);

		try {
			$imager->getFilters();
			self::fail('A behavior that is not an imager filter is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('imagerfilterfactory_bad_class', $e->getErrorCode());
		}
	}

	public function testFileFilterMustBeAnImagerFilter(): void
	{
		$this->writeFilterFile('bad', '<filters><filter name="bad" class="' . TBehavior::class . '" /></filters>');
		$imager = new TAssetImageFilter();
		$imager->setFilterFilePath(static::TEMP_ALIAS . '.bad');

		try {
			$imager->getFilters();
			self::fail('A behavior that is not an imager filter is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('imagerfilterfactory_bad_class', $e->getErrorCode());
		}
	}

	public function testFilterFileThatDoesNotLoad(): void
	{
		$this->writeFilterFile('broken', '<filters><filter type="Resize"></filters');
		$imager = new TAssetImageFilter();
		$imager->setFilterFilePath(static::TEMP_ALIAS . '.broken');

		try {
			$imager->getFilters();
			self::fail('A malformed filter file is rejected.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetimagerbase_filterfile_not_load', $e->getErrorCode());
		}
	}

	public function testPhpFilterFile(): void
	{
		$app = static::application();
		$type = new \ReflectionProperty(TApplication::class, '_configType');
		$ext = new \ReflectionProperty(TApplication::class, '_configFileExt');
		$priorType = $type->getValue($app);
		$priorExt = $ext->getValue($app);
		$this->writeFilterFile('php-filters', "<?php\nreturn ['filters' => ['shrink' => ['type' => 'Resize', 'MaximumWidth' => 6], 'gray' => ['type' => 'Grayscale']]];\n", '.php');
		try {
			$app->setConfigurationType(TApplication::CONFIG_TYPE_PHP);
			$ext->setValue($app, null);
			$imager = new TAssetImageFilter();
			$imager->setFilterFilePath(static::TEMP_ALIAS . '.php-filters');
			self::assertStringEndsWith('php-filters.php', static::invoke($imager, 'getFilterFile'));

			$imager->getFilters();
		} finally {
			$type->setValue($app, $priorType);
			$ext->setValue($app, $priorExt);
		}

		self::assertInstanceOf(TResizeImagerFilter::class, $imager->getFilter('shrink'));
		self::assertSame(6.0, $imager->getFilter('shrink')->getMaximumWidth());
		self::assertInstanceOf(TFilterImagerFilter::class, $imager->getFilter('gray'));
	}

	public function testFilterFileIsCachedInPerformanceMode(): void
	{
		$app = static::application();
		$prior = $app->getCache();
		$path = $this->writeFilterFile('cached', '<filters><filter name="shrink" type="Resize" MaximumWidth="8" /></filters>');
		try {
			$app->setCache($cache = new TArrayCache());
			$app->setMode(TApplicationMode::Performance);
			$first = new TAssetImageFilter();
			$first->setFilterFilePath(static::TEMP_ALIAS . '.cached');
			$first->getFilters();

			self::assertArrayHasKey($key = TAssetImagerBase::FILTERS_CACHE . $path, $cache->values);
			self::assertNull($cache->values[$key][1], 'Performance mode does not depend on the file.');
			unlink($path);

			$second = new TAssetImageFilter();
			$second->setFilterFilePath(static::TEMP_ALIAS . '.cached');
			$second->getFilters();
		} finally {
			static::restoreCache($prior);
		}

		self::assertSame(8.0, $first->getFilter('shrink')->getMaximumWidth());
		self::assertSame(8.0, $second->getFilter('shrink')->getMaximumWidth(), 'The filters are read from the cache.');
		self::assertNotSame($first->getFilter('shrink'), $second->getFilter('shrink'));
	}

	public function testCachedFilterFileDependsOnTheFile(): void
	{
		$app = static::application();
		$prior = $app->getCache();
		$path = $this->writeFilterFile('dependent', '<filters><filter name="shrink" type="Resize" MaximumWidth="8" /></filters>');
		touch($path, 1000000);
		try {
			$app->setCache($cache = new TArrayCache());
			$app->setMode(TApplicationMode::Normal);
			$first = new TAssetImageFilter();
			$first->setFilterFilePath(static::TEMP_ALIAS . '.dependent');
			$first->getFilters();
			self::assertInstanceOf(TFileCacheDependency::class, $cache->values[TAssetImagerBase::FILTERS_CACHE . $path][1]);

			$this->writeFilterFile('dependent', '<filters><filter name="shrink" type="Resize" MaximumWidth="4" /></filters>');
			touch($path, 2000000);
			clearstatcache();
			$second = new TAssetImageFilter();
			$second->setFilterFilePath(static::TEMP_ALIAS . '.dependent');
			$second->getFilters();
		} finally {
			static::restoreCache($prior);
		}

		self::assertSame(8.0, $first->getFilter('shrink')->getMaximumWidth());
		self::assertSame(4.0, $second->getFilter('shrink')->getMaximumWidth(), 'A changed file is read again.');
	}

	// ---------------------------------------------------------------------------
	// Modification date and matching.
	// ---------------------------------------------------------------------------

	public function testModificationDateIncludesTheFilterFile(): void
	{
		$source = $this->writeImage('dated.png');
		touch($source, 1000000);
		touch($this->writeFilterFile('dated', '<filters><filter type="Resize" /></filters>'), 2000000);
		$asset = new TImageAsset($source);
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		self::assertSame(1000000, $asset->getAssetModificationDate());

		$imager->setFilterFilePath(static::TEMP_ALIAS . '.dated');
		self::assertSame(2000000, $asset->getAssetModificationDate(), 'The newer filter file raises the date.');

		touch($source, 3000000);
		clearstatcache();
		self::assertSame(3000000, $asset->getAssetModificationDate(), 'A newer source keeps its date.');

		$imager->setMatchFiles('/\.gif$/');
		touch($source, 1000000);
		clearstatcache();
		self::assertSame(1000000, $asset->getAssetModificationDate(), 'Unmatched assets do not depend on the filter file.');
	}

	public function testModificationDateIncludesTheApplicationConfiguration(): void
	{
		$priorAlias = Prado::getPathOfAlias('Application');
		mkdir($appDir = $this->tempDir . DIRECTORY_SEPARATOR . 'app');
		$config = $appDir . DIRECTORY_SEPARATOR . 'application' . static::application()->getConfigurationFileExt();
		file_put_contents($config, '<application />');
		touch($config, 2000000);
		$source = $this->writeImage('app.png');
		touch($source, 1000000);
		$asset = new TImageAsset($source);
		$imager = new TAssetImageFilter();
		$imager->init(['filters' => [['type' => 'Resize']]]);
		$asset->attachBehavior('imager', $imager);
		try {
			Prado::setPathOfAlias('Application', $appDir);
			$withConfig = $asset->getAssetModificationDate();
			unlink($config);
			$withoutConfig = $asset->getAssetModificationDate();
		} finally {
			Prado::setPathOfAlias('Application', $priorAlias);
		}

		self::assertSame(2000000, $withConfig, 'Application filters depend on the newer application configuration.');
		self::assertSame(1000000, $withoutConfig, 'A missing application configuration does not change the date.');
	}

	public function testHasMatchWithoutAnAssetPath(): void
	{
		$imager = new TAssetImageFilter();
		self::assertFalse($imager->hasMatch(), 'No owner.');
		self::assertFalse($imager->hasMatch('/dst.png', new TGeneratedAsset()), 'No asset path.');
	}

	public function testMetaMatchOfVirtualAssetsIsDelayedUntilPublishing(): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><filter type="Resize" MaximumWidth="10" /></behavior>');
		$manager = $this->newManager();
		$widths = $pending = $resolved = [];
		foreach (['portfolio', 'private'] as $keyword) {
			$asset = new TGeneratedAsset("/virtual/$keyword.jpg");
			$asset->content = static::keywordJpeg([$keyword]);
			$asset->attachBehavior('imager', ['class' => TAssetImageFilter::class, 'MetaMatch' => 'Keywords=portfolio', IBaseBehavior::CONFIG_KEY => $doc]);
			$imager = $asset->asa('imager');

			$pending[$keyword] = [$imager->hasMatch(), $imager->hasMatch(), $imager->getImageMetaData()];
			$widths[$keyword] = getimagesize($this->urlToPath($manager->publish($asset)))[0];
			$resolved[$keyword] = $imager->hasMatch('/virtual/any.jpg');
		}

		self::assertSame(['portfolio' => 10, 'private' => 40], $widths, 'Only the virtual image whose published metadata matches is processed.');
		self::assertSame(['portfolio' => true, 'private' => false], $resolved, 'The metadata match is resolved on publishing.');
		foreach ($pending as $keyword => [$first, $second, $metaData]) {
			self::assertSame(1, $first, 'A virtual asset matches its metadata when published.');
			self::assertSame(1, $second, 'The pending match is kept.');
			self::assertNull($metaData, 'No metadata is read before publishing.');
		}
	}

	// ---------------------------------------------------------------------------
	// Processing.
	// ---------------------------------------------------------------------------

	public function testProcessAssetGuards(): void
	{
		$source = $this->writeImage('guard.png');
		copy($source, $dst = $this->assetDir . '/guard.png');
		$asset = new TImageAsset($source);
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);

		self::assertNull($imager->processAsset(null, $param), 'No asset.');
		self::assertNull($imager->processAsset($asset, null), 'No parameter.');
		self::assertNull($imager->processAsset($asset, new TSimpleImageParameter()), 'Not an asset event parameter.');
		self::assertNull($imager->processAsset($asset, new TAssetEventParameter('onProcessAsset', '', $asset)), 'No destination.');

		$imager->setEnabled(false);
		self::assertNull($imager->processAsset($asset, $param), 'Disabled.');
		$imager->setEnabled(true);

		$imager->setMatchFiles('/\.gif$/');
		self::assertNull($imager->processAsset($asset, $param), 'Not matched.');
		self::assertNull($param->getImage());
		self::assertSame(0, $param->getFinalizers()->getCount());

		$imager->setMatchFiles('/\.png$/');
		self::assertTrue($imager->processAsset($asset, $param));
		self::assertInstanceOf(\GdImage::class, $param->getImage());
		self::assertSame(IMAGETYPE_PNG, $param->getImageType());
	}

	public function testSecondImagerUsesTheImageOfTheFirst(): void
	{
		$source = $this->writeImage('shared.png');
		copy($source, $dst = $this->assetDir . '/shared.png');
		$asset = new TImageAsset($source);
		$asset->attachBehavior('first', ['class' => TAssetImageFilter::class]);
		$asset->attachBehavior('second', ['class' => TAssetImageFilter::class]);
		$first = $asset->asa('first');
		$second = $asset->asa('second');
		$first->addFilter('shrink', $shrink = new TResizeImagerFilter());
		$shrink->setMaximumWidth(20);
		$second->addFilter('record', $recorder = new TRecordingImagerFilter());
		$second->addFilter('gray', $gray = new TFilterImagerFilter());
		$gray->setEffect('Grayscale');
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);

		self::assertTrue($first->processAsset($asset, $param));
		$image = $param->getImage();
		self::assertSame(20, imagesx($image));
		self::assertTrue($second->processAsset($asset, $param));

		self::assertCount(1, $recorder->calls);
		self::assertSame($image, $recorder->calls[0]['image'], 'The second imager filters the already loaded image.');
		self::assertSame($first->getImageMetaData(), $recorder->calls[0]['param']->getImageMetaData(), 'The metadata of the loading imager is shared.');
		self::assertSame([$first], $param->getFinalizers()->toArray(), 'Only the loading imager finalizes.');

		$param->finalize();

		$result = imagecreatefrompng($dst);
		self::assertSame([20, 10], [imagesx($result), imagesy($result)]);
		$red = static::rgbAt($result, 2, 2);
		self::assertSame($red & 0xFF, ($red >> 16) & 0xFF, 'Both imagers filtered the image.');
		self::assertNull($param->getImage(), 'Finalizing releases the image.');
	}

	public function testImageFormatsAreOpened(): void
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<behavior><filter type="Resize" MaximumWidth="10" /></behavior>');
		$this->attachClassBehavior('imager', ['class' => TAssetImageFilter::class, 'MatchFiles' => '/\.(gif|bmp|wbmp|xbm|webp)$/', IBaseBehavior::CONFIG_KEY => $doc], TImageAsset::class);
		$manager = $this->newManager();

		foreach (['gif' => IMAGETYPE_GIF, 'bmp' => IMAGETYPE_BMP, 'wbmp' => IMAGETYPE_WBMP, 'xbm' => IMAGETYPE_XBM, 'webp' => IMAGETYPE_WEBP] as $extension => $type) {
			$published = $this->urlToPath($manager->publish($this->writeImage("format.$extension")));
			$size = getimagesize($published);
			self::assertSame([10, 5, $type], array_slice($size, 0, 3), "The $extension image is opened, filtered, and saved as $extension.");
		}
	}

	public function testOpenImageReadsEveryFormat(): void
	{
		$imager = new TAssetImageFilter();
		$metaData = new TAssetImageMetaData();

		// GD decodes what it can, from the file bytes.
		$png = $this->writeImage('open.png', 12, 6);
		$metaData->setMetaDataSource($png);
		self::assertSame([12, 6], static::imageSize(static::invoke($imager, 'openImage', $metaData, $png, IMAGETYPE_PNG)));

		// A TIFF, which GD cannot read, is read by Imagick or by the prado-image container.
		$tiff = $this->writeSource('open.tif', '');
		TTIFF::fromImage(static::createImage(10, 4))->save($tiff);
		self::assertFalse(@imagecreatefromstring(file_get_contents($tiff)), 'GD cannot read a TIFF.');
		$metaData->setMetaDataSource($tiff);
		self::assertSame([10, 4], static::imageSize(static::invoke($imager, 'openImage', $metaData, $tiff, IMAGETYPE_TIFF_II)));

		// An XBM is read by its own GD reader.
		$xbm = $this->writeSource('open.xbm', '');
		imagexbm(static::createImage(8, 8), $xbm);
		$metaData->setMetaDataSource($xbm);
		self::assertSame([8, 8], static::imageSize(static::invoke($imager, 'openImage', $metaData, $xbm, IMAGETYPE_XBM)));

		// Neither GD nor a container can read it.
		$metaData->setMetaDataSource($missing = $this->srcDir . '/gone.png');
		self::assertFalse(static::invoke($imager, 'openImage', $metaData, $missing, IMAGETYPE_PNG));
		$psd = $this->writeSource('open.psd', "8BPS\x00\x01" . str_repeat("\x00", 6) . "\x00\x03" . pack('N', 20) . pack('N', 40) . "\x00\x08\x00\x03");
		$metaData->setMetaDataSource($psd);
		self::assertFalse(static::invoke($imager, 'openImage', $metaData, $psd, IMAGETYPE_PSD));
	}

	public function testOpenImageWhenTheContainerCannotConvert(): void
	{
		$imager = new TAssetImageFilter();
		$metaData = new TFaultyImageMetaData();
		$metaData->imageFile = new class () extends TTIFF {
			public function getImage(?string $mode = null): false|\GdImage|\Imagick
			{
				throw new \RuntimeException('no raster');
			}
		};
		$psd = $this->writeSource('faulty.psd', 'not an image');

		self::assertFalse(static::invoke($imager, 'openImage', $metaData, $psd, IMAGETYPE_PSD), 'A container that cannot convert its raster is not published.');
	}

	/**
	 * @param mixed $image the image.
	 * @return array{0:int, 1:int} the image size.
	 */
	protected static function imageSize($image): array
	{
		self::assertTrue(TImageGraphics::isImage($image), 'The image is a graphics library image.');
		return TImageGraphics::getSize($image);
	}

	public function testFiltersRunInTheirOwnGraphicsLibrary(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$imager = new TAssetImageFilter();
		$imager->addFilter('gd', $first = new TGdImagerFilter());
		$imager->addFilter('imagick', $imagick = new TImagickImagerFilter());
		$imager->addFilter('either', $either = new TRecordingImagerFilter());
		$imager->addFilter('gd2', $second = new TGdImagerFilter());
		$either->result = false;
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage(static::createImage(8, 4));

		self::assertTrue($imager->applyFilters($param), 'The filters changed the image.');

		self::assertSame(['GdImage'], $first->calls);
		self::assertSame(['Imagick'], $imagick->calls, 'The image is converted into the library of the filter.');
		self::assertSame(['Imagick'], array_map(fn ($call) => get_debug_type($call['image']), $either->calls), 'A filter of either library takes the image where it is.');
		self::assertSame(['GdImage'], $second->calls, 'And back for the filter of GD.');
		self::assertInstanceOf(\GdImage::class, $param->getImage());
		self::assertTrue($param->getSaveImage());
	}

	public function testConvertImageKeepsTheAlphaChannel(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}
		$imager = new TAssetImageFilter();
		$image = imagecreatetruecolor(4, 4);
		imagealphablending($image, false);
		imagefilledrectangle($image, 0, 0, 3, 3, 0x7F000000);
		imagefilledrectangle($image, 0, 0, 1, 3, 0x00FF00);

		$imagick = static::invoke($imager, 'convertImage', $image, TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $imagick);
		self::assertSame($imagick, static::invoke($imager, 'convertImage', $imagick, TImageGraphicsMode::Imagick), 'An image of the mode is returned as it is.');

		$back = static::invoke($imager, 'convertImage', $imagick, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $back);
		self::assertSame([4, 4], [imagesx($back), imagesy($back)]);
		self::assertSame(0x00FF00, imagecolorat($back, 0, 0) & 0xFFFFFF);
		self::assertSame(127, (imagecolorat($back, 3, 0) >> 24) & 0x7F, 'The transparent pixels are still transparent.');
	}

	public function testConvertImageWithoutAnInterchangeFormat(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('Imagick is not installed.');
		}

		self::assertFalse(TNoInterchangeImageFilter::convertImage(static::createImage(4, 4), TImageGraphicsMode::Imagick), 'Without an interchange format the image cannot be converted.');
	}

	public function testAFilterOfAnUninstalledLibraryIsSkipped(): void
	{
		$imager = new TAssetImageFilter();
		$imager->addFilter('missing', $skipped = new TUnavailableModeImagerFilter());
		$imager->addFilter('gd', $ran = new TRecordingImagerFilter());
		$param = new TAssetEventParameter('onProcessAsset');
		$param->setImage($image = static::createImage(8, 4));

		self::assertTrue($imager->applyFilters($param));

		self::assertSame([], $skipped->calls, 'The filter of a library that is not installed does not run.');
		self::assertCount(1, $ran->calls, 'The filters that follow still run.');
		self::assertSame($image, $param->getImage());
	}

	public function testPaletteImagesAreOpenedAsTrueColor(): void
	{
		$source = $this->writeImage('palette.gif', 40, 20, $gif = imagecreate(40, 20));
		imagecolorallocate($gif, 0, 0, 255);
		$green = imagecolorallocate($gif, 0, 255, 0);
		imagefilledrectangle($gif, 0, 0, 19, 19, $green);
		imagecolortransparent($gif, $green);
		imagegif($gif, $source);
		copy($source, $dst = $this->assetDir . '/palette.gif');
		$asset = new TImageAsset($source);
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);

		self::assertTrue($imager->processAsset($asset, $param));

		$image = $param->getImage();
		self::assertTrue(imageistruecolor($image));
		self::assertSame(2, $param->getPaletteColors(), 'The source palette size is kept for saving.');
		self::assertSame(IMAGETYPE_GIF, $param->getOriginalImageType());
		self::assertSame(0x0000FF, imagecolorat($image, 30, 10));
		self::assertSame(0x7F00FF00, imagecolorat($image, 10, 10), 'The transparent color is kept.');
	}

	public function testImageTypeGdCannotOpenIsNotPublished(): void
	{
		$this->attachClassBehavior('imager', ['class' => TAssetImageFilter::class], TImageAsset::class);
		// A Photoshop header: getimagesize reads it, GD cannot open it.
		$source = $this->writeSource('photoshop.png', "8BPS\x00\x01" . str_repeat("\x00", 6) . "\x00\x03" . pack('N', 20) . pack('N', 40) . "\x00\x08\x00\x03");
		self::assertSame(IMAGETYPE_PSD, getimagesize($source)[2]);

		$url = $this->newManager()->publish($source);

		self::assertStringEndsWith('/photoshop.png', $url);
		self::assertFileDoesNotExist($this->urlToPath($url));
		self::assertEmpty(glob(dirname($this->urlToPath($url)) . '/*'));
	}

	public function testImagePaletteToTrueColorKeepsTheTransparentColor(): void
	{
		$make = function () {
			$image = imagecreate(4, 4);
			imagecolorallocate($image, 0, 0, 255);
			$green = imagecolorallocate($image, 0, 255, 0);
			imagefilledrectangle($image, 0, 0, 1, 3, $green);
			imagecolortransparent($image, $green);
			return $image;
		};

		$plain = $make();
		imagepalettetotruecolor($plain);
		self::assertSame(0x7F000000, imagecolorat($plain, 0, 0), 'GD makes transparent pixels transparent black.');

		$image = $make();
		self::assertTrue(TAssetImagerBase::imagePaletteToTrueColor($image));
		self::assertTrue(imageistruecolor($image));
		self::assertSame(0x7F00FF00, imagecolorat($image, 0, 0), 'The transparent pixels keep their color.');
		self::assertSame(0x0000FF, imagecolorat($image, 3, 3));

		$opaque = imagecreate(2, 2);
		imagecolorallocate($opaque, 10, 20, 30);
		self::assertTrue(TAssetImagerBase::imagePaletteToTrueColor($opaque));
		self::assertSame(0x0A141E, imagecolorat($opaque, 1, 1));
		self::assertFalse(TAssetImagerBase::imagePaletteToTrueColor(null));
	}

	// ---------------------------------------------------------------------------
	// Finalizing.
	// ---------------------------------------------------------------------------

	public function testFinalizeWithoutAnImageOrDestination(): void
	{
		$imager = new TAssetImageFilter();
		$dst = $this->writeSource('final.png', 'unchanged');
		$param = new TAssetEventParameter('onProcessAsset', $dst);
		$param->setSaveImage(true);

		$imager->finalize($dst, $param);
		self::assertSame('unchanged', file_get_contents($dst), 'Without an image, nothing is saved.');

		$param->setImage($image = static::createImage());
		$param->setImageType(IMAGETYPE_PNG);
		$imager->finalize('', $param);
		self::assertSame('unchanged', file_get_contents($dst), 'Without a destination, nothing is saved.');
		self::assertSame($image, $param->getImage());
	}

	public function testFinalizeWithoutAnAssetSavesTheImage(): void
	{
		$imager = new TAssetImageFilter();
		$dst = $this->writeSource('final.png', 'unchanged');
		$param = new TAssetEventParameter('onProcessAsset', $dst);
		$param->setImage(static::createImage());
		$param->setOriginalImageType(IMAGETYPE_JPEG);
		$param->setImageType(IMAGETYPE_PNG);

		$imager->finalize($dst, $param);

		self::assertSame(IMAGETYPE_PNG, getimagesize($dst)[2], 'A changed image type is saved.');
		self::assertNull($param->getImage());
	}

	public function testFinalizeWhenTheImageIsNotSaved(): void
	{
		$source = $this->writeImage('unsaved.jpg');
		$asset = new TImageAsset($source);
		$asset->attachBehavior('imager', $imager = new TAssetImageFilter());
		$dst = $this->writeSource('unsaved.psd', 'unchanged');
		$param = new TAssetEventParameter('onProcessAsset', $dst, $asset);
		$param->setImageMetaData(static::invoke($imager, 'ensureMetaData', $source));
		$param->setImage(static::createImage());
		$param->setImageType(IMAGETYPE_PSD);
		$param->setSaveImage(true);

		$imager->finalize($dst, $param);

		self::assertSame('unchanged', file_get_contents($dst), 'An unsaved image has no metadata written.');
		self::assertNull($param->getImage());

		$param->setImage(static::createImage());
		$param->setImageType(IMAGETYPE_PSD);
		$param->setOriginalImageType(IMAGETYPE_PSD);
		$param->setSaveImage(false);
		$imager->finalize($dst, $param);
		self::assertSame('unchanged', file_get_contents($dst), 'An unchanged image without metadata changes is not written.');
	}
	public function testFinalizeThrowsWhenTheEncodedImageCannotBeWritten(): void
	{
		$source = $this->writeImage('unwritable.jpg');
		$imager = new TAssetImageFilter();
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'a-directory';
		mkdir($dst);
		$param = new TAssetEventParameter('onProcessAsset', $dst);
		$param->setImageMetaData(static::invoke($imager, 'ensureMetaData', $source));
		$param->setImage(static::createImage());
		$param->setOriginalImageType(IMAGETYPE_JPEG);
		$param->setImageType(IMAGETYPE_PNG);

		try {
			$imager->finalize($dst, $param);
			self::fail('An image that cannot be written throws.');
		} catch (TIOException $e) {
			self::assertStringContainsString('assetimagerbase_write_failed', $e->getMessage());
		}
	}

	public function testFinalizeThrowsWhenTheImageCannotBeEncoded(): void
	{
		$source = $this->writeImage('unencodable.jpg');
		$imager = new TUnwritableImageFilter();
		$imager->failEncode = true;
		$dst = $this->writeSource('unencodable.png', 'unchanged');
		$param = new TAssetEventParameter('onProcessAsset', $dst);
		$param->setImageMetaData(static::invoke($imager, 'ensureMetaData', $source));
		$param->setImage(static::createImage());
		$param->setOriginalImageType(IMAGETYPE_JPEG);
		$param->setImageType(IMAGETYPE_PNG);

		try {
			$imager->finalize($dst, $param);
			self::fail('An image that cannot be encoded throws.');
		} catch (TIOException $e) {
			self::assertStringContainsString('assetimagerbase_encode_failed', $e->getMessage());
		}
		self::assertSame('unchanged', file_get_contents($dst), 'The unfinished file is left to the manager to remove.');
	}

	public function testFinalizeThrowsWhenTheImageWithoutMetaDataCannotBeSaved(): void
	{
		$imager = new TUnwritableImageFilter();
		$imager->failSave = true;
		$dst = $this->writeSource('unsavable.bmp', 'unchanged');
		$param = new TAssetEventParameter('onProcessAsset', $dst);
		$param->setImage(static::createImage());
		$param->setOriginalImageType(IMAGETYPE_JPEG);
		$param->setImageType(IMAGETYPE_BMP);

		try {
			$imager->finalize($dst, $param);
			self::fail('An image type without metadata that cannot be saved throws.');
		} catch (TIOException $e) {
			self::assertStringContainsString('assetimagerbase_write_failed', $e->getMessage());
		}
	}
}
