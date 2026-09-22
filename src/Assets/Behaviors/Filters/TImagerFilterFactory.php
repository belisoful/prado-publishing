<?php

/**
 * TImagerFilterFactory class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\Xml\TXmlElement;

/**
 * TImagerFilterFactory class
 *
 * The filter type registry, configuration parser, and filter factory of the imagers
 * ({@see \Prado\Web\Assets\Behaviors\TAssetImagerBase}).
 *
 * A filter `type` names a filter class, case-insensitively. The built in types are in
 * {@see TYPES}, and each GD `imagefilter()` effect of {@see TFilterImagerFilter::FILTER_MAP}
 * ("Grayscale", "Negate", ...) is a type of {@see TFilterImagerFilter} with that effect.
 * An application adds or replaces types with {@see registerType}:
 * ```php
 * TImagerFilterFactory::registerType('Sepia', \App\Imaging\TSepiaFilter::class);
 * ```
 * A registered type takes precedence over an effect and a built in type of the same name.
 *
 * {@see parse} reads an imager configuration into its `<metadata>` properties and its
 * filter specifications, in configuration order:
 * ```php
 * [
 *   'metadata' => ?array,  // the <metadata> properties, null when there is none
 *   'filters' => [name => ['class' => string, 'properties' => array, 'config' => mixed]],
 * ]
 * ```
 * The specifications are plain data, shared by every asset the imager is attached to:
 * an XML configuration is parsed once per element. {@see createFilters} instances the
 * filters of the specifications.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TImagerFilterFactory
{
	/** The built in filter types, lower case, and their classes. */
	public const TYPES = [
		'autocrop' => TAutoCropImagerFilter::class,
		'blur' => TBlurImagerFilter::class,
		'boxblur' => TBoxBlurImagerFilter::class,
		'circlemask' => TCircleMaskImagerFilter::class,
		'convolution' => TConvolutionImagerFilter::class,
		'filter' => TFilterImagerFilter::class,
		'gammacorrect' => TGammaCorrectImagerFilter::class,
		'image' => TImageImagerFilter::class,
		'metadata' => TMetaDataImagerFilter::class,
		'orient' => TOrientImagerFilter::class,
		'rectangle' => TRectangleImagerFilter::class,
		'resize' => TResizeImagerFilter::class,
		'text' => TTextImagerFilter::class,
	];

	/** @var array<string, string> the registered filter types, lower case, and their classes. */
	private static array $_types = [];

	/** @var ?\WeakMap<TXmlElement, array<string, array>> the parsed XML configurations, by name prefix. */
	private static ?\WeakMap $_parsed = null;

	/**
	 * Registers a filter type.
	 * @param string $type the filter type, case-insensitive.
	 * @param string $class the filter class, implementing {@see IBaseImagerFilter}.
	 * @throws TInvalidDataValueException when the type is empty or the class is not a filter.
	 */
	public static function registerType(string $type, string $class): void
	{
		if (($type = strtolower(trim($type))) === '') {
			throw new TInvalidDataValueException('imagerfilterfactory_bad_type', $type);
		}
		static::ensureFilterClass($class);
		self::$_types[$type] = ltrim($class, '\\');
	}

	/**
	 * Removes a registered filter type. A built in type or effect of the same name is
	 * used again.
	 * @param string $type the filter type, case-insensitive.
	 */
	public static function unregisterType(string $type): void
	{
		unset(self::$_types[strtolower(trim($type))]);
	}

	/**
	 * @return array<string, string> the filter types, lower case, and their classes:
	 *   the registered types and the built in types. The effect types are not listed.
	 */
	public static function getTypes(): array
	{
		return self::$_types + self::TYPES;
	}

	/**
	 * Resolves a filter type to its class and the properties the type implies.
	 * @param string $type the filter type, case-insensitive.
	 * @throws TInvalidDataValueException when the type is not known.
	 * @return array{0:string, 1:array} the filter class and its implied properties.
	 */
	public static function resolveType(string $type): array
	{
		$type = trim($type);
		$ltype = strtolower($type);
		if (isset(self::$_types[$ltype])) {
			return [self::$_types[$ltype], []];
		}
		if (array_key_exists($ltype, TFilterImagerFilter::FILTER_MAP)) {
			return [TFilterImagerFilter::class, ['effect' => $type]];
		}
		if (isset(self::TYPES[$ltype])) {
			return [self::TYPES[$ltype], []];
		}
		throw new TInvalidDataValueException('imagerfilterfactory_bad_type', $type);
	}

	/**
	 * Parses an imager configuration: a {@see TXmlElement} with `<metadata>` and `<filter>`
	 * child elements, or an array with "metadata" and "filters" entries (or a list of
	 * filters). A filter has a `type` or a `class`, and an optional `name`; its other
	 * attributes are its properties. An XML element is parsed once per name prefix; later
	 * calls with that prefix return the same result.
	 * @param mixed $config the configuration.
	 * @param string $namePrefix the name prefix of filters without a name, numbered in order.
	 * @throws TConfigurationException when a filter is not an array or has neither a type nor a class.
	 * @throws TInvalidDataValueException when a filter has an unknown type, or both a type and a class.
	 * @return array{metadata:?array, filters:array<string, array{class:string, properties:array, config:mixed}>}
	 *   the configuration.
	 */
	public static function parse($config, string $namePrefix = 'filter'): array
	{
		if ($config instanceof TXmlElement) {
			self::$_parsed ??= new \WeakMap();
			// The memo is per name prefix, which names the filters that have no name.
			$parsed = self::$_parsed[$config] ?? [];
			if (!array_key_exists($namePrefix, $parsed)) {
				$parsed[$namePrefix] = static::parseXml($config, $namePrefix);
				self::$_parsed[$config] = $parsed;
			}
			return $parsed[$namePrefix];
		}
		$result = ['metadata' => null, 'filters' => []];
		if (!is_array($config)) {
			return $result;
		}
		if (array_key_exists('metadata', $config)) {
			if (is_array($metaData = $config['metadata'])) {
				$result['metadata'] = array_change_key_case($metaData['properties'] ?? $metaData);
			}
			unset($config['metadata']);
		}
		if (array_key_exists('filters', $config)) {
			$config = (array) $config['filters'];
		}
		$result['filters'] = static::parseFilters($config, $namePrefix);
		return $result;
	}

	/**
	 * @param TXmlElement $config the configuration element.
	 * @param string $namePrefix the name prefix of filters without a name.
	 * @return array{metadata:?array, filters:array} the configuration.
	 */
	protected static function parseXml(TXmlElement $config, string $namePrefix): array
	{
		$metaData = null;
		if ($tag = $config->getElementByTagName('metadata')) {
			$metaData = array_change_key_case($tag->getAttributes()->toArray());
		}
		return ['metadata' => $metaData, 'filters' => static::parseFilters($config->getElementsByTagName('filter'), $namePrefix)];
	}

	/**
	 * @param iterable $filters the filter elements or arrays.
	 * @param string $namePrefix the name prefix of filters without a name.
	 * @return array<string, array{class:string, properties:array, config:mixed}> the filters.
	 */
	protected static function parseFilters(iterable $filters, string $namePrefix): array
	{
		$result = [];
		$index = 0;
		foreach ($filters as $key => $element) {
			if ($element instanceof TXmlElement) {
				$properties = $element->getAttributes()->toArray();
			} elseif (is_array($element)) {
				$properties = $element;
			} else {
				throw new TConfigurationException('imagerfilterfactory_config_array_required');
			}
			$properties = array_change_key_case($properties);
			$name = (string) ($properties['name'] ?? (is_string($key) ? $key : $namePrefix . ($index++)));
			$class = $properties['class'] ?? null;
			$type = $properties['type'] ?? null;
			unset($properties['name'], $properties['class'], $properties['type']);

			if ($class && $type) {
				throw new TInvalidDataValueException('imagerfilterfactory_cannot_class_type', $class, $type);
			}
			if ($type) {
				[$class, $implied] = static::resolveType((string) $type);
				$properties = $implied + $properties;
			} elseif (!$class) {
				throw new TConfigurationException('imagerfilterfactory_no_filter_type', $name);
			}
			$result[$name] = ['class' => (string) $class, 'properties' => $properties, 'config' => $element];
		}
		return $result;
	}

	/**
	 * Instances a filter from its specification: the class is created, its properties
	 * are set, and a filter with an `init()` method is given its configuration.
	 * @param array{class:string, properties?:array, config?:mixed} $spec the specification.
	 * @throws TInvalidDataValueException when the class is not a filter.
	 * @return IBaseImagerFilter the filter.
	 */
	public static function createFilter(array $spec): IBaseImagerFilter
	{
		$class = static::ensureFilterClass($spec['class'] ?? '');
		$filter = new $class();
		foreach ($spec['properties'] ?? [] as $name => $value) {
			if ($filter instanceof TComponent) {
				$filter->setSubProperty($name, $value);
			} else {
				$filter->$name = $value;
			}
		}
		if (method_exists($filter, 'init')) {
			$filter->init($spec['config'] ?? null);
		}
		return $filter;
	}

	/**
	 * @param array<string, array> $specs the filter specifications, by name.
	 * @return array<string, IBaseImagerFilter> the filters, by name, in order.
	 */
	public static function createFilters(array $specs): array
	{
		$filters = [];
		foreach ($specs as $name => $spec) {
			$filters[$name] = static::createFilter($spec);
		}
		return $filters;
	}

	/**
	 * @param mixed $class the class to check.
	 * @throws TInvalidDataValueException when the class is not an instantiable filter.
	 * @return string the class.
	 */
	protected static function ensureFilterClass($class): string
	{
		if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, IBaseImagerFilter::class) || !(new \ReflectionClass($class))->isInstantiable()) {
			throw new TInvalidDataValueException('imagerfilterfactory_bad_class', is_string($class) ? $class : get_debug_type($class));
		}
		return $class;
	}
}
