<?php

/**
 * TImagerFilterFactoryTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Util\TBehavior;
use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TBlurImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TFilterImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TImagerFilterFactory;
use Prado\Web\Assets\Behaviors\Filters\TResizeImagerFilter;
use Prado\Web\Tests\Fixtures\TPlainImagerFilter;
use Prado\Web\Tests\Fixtures\TRecordingImagerFilter;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Xml\TXmlDocument;
use Prado\Xml\TXmlElement;

/**
 * TImagerFilterFactoryTest class.
 *
 * Tests the imager filter factory: the type registry, type resolution, configuration
 * parsing from XML and arrays (and the sharing of parsed XML), and filter creation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImagerFilterFactoryTest extends PublishingTestCase
{
	protected function tearDown(): void
	{
		foreach (array_keys(array_diff_key(TImagerFilterFactory::getTypes(), TImagerFilterFactory::TYPES)) as $type) {
			TImagerFilterFactory::unregisterType($type);
		}
		TImagerFilterFactory::unregisterType('blur');
		parent::tearDown();
	}

	/**
	 * @param string $xml the XML.
	 * @return TXmlDocument the document.
	 */
	protected static function xml(string $xml): TXmlDocument
	{
		$doc = new TXmlDocument();
		$doc->loadFromString($xml);
		return $doc;
	}

	public function testBuiltInTypes(): void
	{
		self::assertSame(TImagerFilterFactory::TYPES, TImagerFilterFactory::getTypes());
		foreach (TImagerFilterFactory::TYPES as $type => $class) {
			self::assertSame(strtolower($type), $type);
			self::assertSame([$class, []], TImagerFilterFactory::resolveType(strtoupper($type)));
		}
	}

	public function testEffectTypes(): void
	{
		self::assertSame([TFilterImagerFilter::class, ['effect' => 'GrayScale']], TImagerFilterFactory::resolveType(' GrayScale '));
		foreach (array_keys(TFilterImagerFilter::FILTER_MAP) as $effect) {
			self::assertSame(TFilterImagerFilter::class, TImagerFilterFactory::resolveType($effect)[0]);
			self::assertArrayNotHasKey($effect, TImagerFilterFactory::TYPES, 'An effect is not a built in type name.');
		}
	}

	public function testUnknownType(): void
	{
		try {
			TImagerFilterFactory::resolveType('Sparkle');
			self::fail('An unknown type is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('imagerfilterfactory_bad_type', $e->getErrorCode());
		}
	}

	public function testRegisterType(): void
	{
		TImagerFilterFactory::registerType(' Sparkle ', '\\' . TRecordingImagerFilter::class);
		TImagerFilterFactory::registerType('Grayscale', TPlainImagerFilter::class);
		TImagerFilterFactory::registerType('BLUR', TRecordingImagerFilter::class);

		self::assertSame([TRecordingImagerFilter::class, []], TImagerFilterFactory::resolveType('sparkle'));
		self::assertSame([TPlainImagerFilter::class, []], TImagerFilterFactory::resolveType('grayscale'), 'A registered type precedes an effect.');
		self::assertSame([TRecordingImagerFilter::class, []], TImagerFilterFactory::resolveType('Blur'), 'A registered type precedes a built in type.');
		self::assertSame(['sparkle' => TRecordingImagerFilter::class, 'grayscale' => TPlainImagerFilter::class, 'blur' => TRecordingImagerFilter::class] + TImagerFilterFactory::TYPES, TImagerFilterFactory::getTypes());

		TImagerFilterFactory::unregisterType(' BLUR ');
		TImagerFilterFactory::unregisterType('grayscale');
		TImagerFilterFactory::unregisterType('never-registered');
		self::assertSame([TBlurImagerFilter::class, []], TImagerFilterFactory::resolveType('blur'), 'The built in type is used again.');
		self::assertSame(TFilterImagerFilter::class, TImagerFilterFactory::resolveType('grayscale')[0]);
	}

	public static function badRegistrationProvider(): array
	{
		return [
			'empty type' => ['  ', TRecordingImagerFilter::class, 'imagerfilterfactory_bad_type'],
			'missing class' => ['x', 'Prado\NoSuchFilter', 'imagerfilterfactory_bad_class'],
			'not a filter' => ['x', TBehavior::class, 'imagerfilterfactory_bad_class'],
			'abstract filter' => ['x', TBaseImagerFilter::class, 'imagerfilterfactory_bad_class'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('badRegistrationProvider')]
	public function testRegisterTypeRejectsBadRegistrations(string $type, string $class, string $code): void
	{
		try {
			TImagerFilterFactory::registerType($type, $class);
			self::fail('The registration is rejected.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame($code, $e->getErrorCode());
		}
		self::assertSame(TImagerFilterFactory::TYPES, TImagerFilterFactory::getTypes());
	}

	public function testParseXml(): void
	{
		$doc = static::xml('<behavior><metadata Scrub="Location" /><filter type="Resize" MaximumWidth="10" /><filter Name="gray" Type="GrayScale" /><filter class="' . TRecordingImagerFilter::class . '" Result="false" /></behavior>');

		$config = TImagerFilterFactory::parse($doc, 'xml');

		self::assertSame(['scrub' => 'Location'], $config['metadata']);
		self::assertSame(['xml0', 'gray', 'xml1'], array_keys($config['filters']));
		self::assertSame(TResizeImagerFilter::class, $config['filters']['xml0']['class']);
		self::assertSame(['maximumwidth' => '10'], $config['filters']['xml0']['properties']);
		self::assertInstanceOf(TXmlElement::class, $config['filters']['xml0']['config']);
		self::assertSame(['effect' => 'GrayScale'], $config['filters']['gray']['properties'], 'An effect type becomes the effect.');
		self::assertSame(['class' => TRecordingImagerFilter::class, 'properties' => ['result' => 'false'], 'config' => $config['filters']['xml1']['config']], $config['filters']['xml1']);

		self::assertSame($config, TImagerFilterFactory::parse($doc, 'xml'), 'An element is parsed once per name prefix.');
		self::assertSame(['other0', 'gray', 'other1'], array_keys(TImagerFilterFactory::parse($doc, 'other')['filters']), 'Another name prefix names the filters without a name of its own.');
		self::assertSame(['metadata' => null, 'filters' => []], TImagerFilterFactory::parse(static::xml('<behavior />')));
	}

	public function testParseArrays(): void
	{
		self::assertSame(['metadata' => null, 'filters' => []], TImagerFilterFactory::parse(null));
		self::assertSame(['metadata' => null, 'filters' => []], TImagerFilterFactory::parse('filters'));
		self::assertSame(['metadata' => null, 'filters' => []], TImagerFilterFactory::parse([]));

		$config = TImagerFilterFactory::parse([
			'metadata' => ['properties' => ['Scrub' => 'Author'], 'other' => 'ignored'],
			'filters' => [
				'shrink' => ['Type' => 'Resize', 'MaximumWidth' => 12],
				['NAME' => 'named', 'CLASS' => TPlainImagerFilter::class, 'Amount' => 3],
				['class' => TPlainImagerFilter::class],
			],
		]);
		self::assertSame(['scrub' => 'Author'], $config['metadata']);
		self::assertSame(['shrink', 'named', 'filter0'], array_keys($config['filters']));
		self::assertSame(['class' => TResizeImagerFilter::class, 'properties' => ['maximumwidth' => 12], 'config' => ['Type' => 'Resize', 'MaximumWidth' => 12]], $config['filters']['shrink']);
		self::assertSame(['amount' => 3], $config['filters']['named']['properties']);

		$config = TImagerFilterFactory::parse(['metadata' => 'not an array', 'gray' => ['type' => 'Grayscale']]);
		self::assertNull($config['metadata']);
		self::assertSame(['gray'], array_keys($config['filters']), 'A metadata entry that is not an array is ignored.');
	}

	public function testParseRejectsBadFilters(): void
	{
		$cases = [
			'imagerfilterfactory_config_array_required' => [['filters' => ['bad' => 'Resize']], TConfigurationException::class],
			'imagerfilterfactory_no_filter_type' => [['filters' => ['bad' => ['MaximumWidth' => 1]]], TConfigurationException::class],
			'imagerfilterfactory_cannot_class_type' => [['filters' => [['type' => 'Resize', 'class' => TResizeImagerFilter::class]]], TInvalidDataValueException::class],
			'imagerfilterfactory_bad_type' => [['filters' => [['type' => 'Sparkle']]], TInvalidDataValueException::class],
		];
		foreach ($cases as $code => [$config, $exception]) {
			try {
				TImagerFilterFactory::parse($config);
				self::fail("$code was expected.");
			} catch (\Exception $e) {
				self::assertInstanceOf($exception, $e);
				self::assertSame($code, $e->getErrorCode());
			}
		}
	}

	public function testCreateFilter(): void
	{
		$element = new TXmlElement('filter');
		$filter = TImagerFilterFactory::createFilter(['class' => TRecordingImagerFilter::class, 'properties' => ['enabled' => 'false'], 'config' => $element]);
		self::assertInstanceOf(TRecordingImagerFilter::class, $filter);
		self::assertFalse($filter->getEnabled(), 'The properties are set.');
		self::assertSame($element, $filter->config, 'The filter is initialized with its configuration.');

		$filter = TImagerFilterFactory::createFilter(['class' => TRecordingImagerFilter::class]);
		self::assertNull($filter->config, 'Without a configuration, init() is given null.');

		$plain = TImagerFilterFactory::createFilter(['class' => TPlainImagerFilter::class, 'properties' => ['amount' => 5]]);
		self::assertSame(5, $plain->amount, 'A filter that is not a component has its public properties set.');

		$filters = TImagerFilterFactory::createFilters(TImagerFilterFactory::parse(['b' => ['type' => 'Blur', 'BlurCount' => 2], 'a' => ['type' => 'Negate']])['filters']);
		self::assertSame(['b', 'a'], array_keys($filters));
		self::assertSame(2, $filters['b']->getBlurCount());
		self::assertSame('Negate', $filters['a']->getEffect());
		self::assertNotSame($filters['b'], TImagerFilterFactory::createFilter(['class' => TBlurImagerFilter::class]));
	}

	public function testCreateFilterRejectsBadClasses(): void
	{
		foreach ([[], ['class' => ''], ['class' => TBehavior::class], ['class' => TBaseImagerFilter::class], ['class' => ['array']]] as $spec) {
			try {
				TImagerFilterFactory::createFilter($spec);
				self::fail('The class is rejected: ' . json_encode($spec));
			} catch (TInvalidDataValueException $e) {
				self::assertSame('imagerfilterfactory_bad_class', $e->getErrorCode());
			}
		}
	}
}
