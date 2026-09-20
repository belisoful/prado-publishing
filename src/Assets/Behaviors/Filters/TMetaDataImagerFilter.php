<?php

/**
 * TMetaDataImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Xml\TXmlElement;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TMetaDataImagerFilter class
 *
 * Writes metadata fields into the image being published, through the image's
 * {@see \Prado\Web\Assets\Behaviors\TAssetImageMetaData}. The pixels are not changed,
 * so an image with no other change has only its metadata rewritten.
 *
 * Each `<meta>` element names a field and its content:
 * - `name`: the field, as resolved by {@see TAssetImageMetaData::resolveField}: an IPTC
 *   dataset name or id ("Keywords", "2#116"), an EXIF tag name ("Artist"), or a
 *   carrier-prefixed name ("EXIF:Copyright", "XMP:dc:title").
 * - `content`: the value. Without a `content`, the field is removed.
 * - `separator`: splits the content into multiple values for a repeatable field.
 * - `append`: "true" to add the values to a repeatable field instead of replacing it.
 *
 * The content may include `${param}`, replaced with the application parameter "param",
 * and `${date:format}`, replaced with the current time formatted by
 * {@see https://www.php.net/manual/en/function.date.php date()}.
 *
 * ```xml
 * <filter type="MetaData">
 *   <meta name="Copyright" content="© ${date:Y} ${SiteOwner}" />
 *   <meta name="Keywords" content="web, published" separator="," append="true" />
 *   <meta name="EXIF:Artist" content="${SiteOwner}" />
 *   <meta name="Caption-Abstract" />
 * </filter>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TMetaDataImagerFilter extends TBaseImagerFilter
{
	/** @var array<array{field:string, content:null|string|string[], append:bool}> the fields to write, in order. */
	private array $_meta = [];

	/**
	 * Reads the `<meta>` child elements of an XML filter configuration. An array
	 * configuration gives its fields as the {@see setMeta Meta} property.
	 * @param mixed $config the filter configuration.
	 */
	public function init($config): void
	{
		if ($config instanceof TXmlElement) {
			$this->parseConfig($config);
		}
	}

	/**
	 * Adds the `<meta>` fields of a configuration.
	 * @param null|array|TXmlElement $config the filter configuration: `<meta>` elements,
	 *   or an array with a "meta" list of field arrays.
	 * @throws TConfigurationException when a field has no name or an unknown name.
	 */
	public function parseConfig($config): void
	{
		if ($config instanceof TXmlElement) {
			$entries = [];
			foreach ($config->getElementsByTagName('meta') as $tag) {
				$entries[] = $tag->getAttributes()->toArray();
			}
		} elseif (is_array($config)) {
			$entries = $config['meta'] ?? [];
		} else {
			return;
		}
		$this->addEntries($entries);
	}

	/**
	 * Adds fields given as arrays with "name", "content", "separator", and "append".
	 * @param iterable $entries the field arrays.
	 * @throws TConfigurationException when a field has no name or an unknown name.
	 */
	protected function addEntries(iterable $entries): void
	{
		foreach ($entries as $entry) {
			$entry = array_change_key_case((array) $entry);
			$this->addMeta($entry['name'] ?? '', array_key_exists('content', $entry) ? $entry['content'] : null, $entry['separator'] ?? null, TPropertyValue::ensureBoolean($entry['append'] ?? false));
		}
	}

	/**
	 * The filter writes metadata and does not touch the pixels, so it works with the image
	 * of either graphics library.
	 * @return ?string null, for either library.
	 */
	public function getGraphicsMode(): ?string
	{
		return null;
	}

	/**
	 * Adds a field to write.
	 * @param string $name the field name.
	 * @param null|string|string[] $content the content, or null to remove the field.
	 * @param ?string $separator splits a string content into values.
	 * @param bool $append whether to add to a repeatable field.
	 * @throws TConfigurationException when the name is empty or not a known field.
	 */
	public function addMeta(string $name, $content = null, ?string $separator = null, bool $append = false): void
	{
		if (trim($name) === '' || TAssetImageMetaData::resolveField($name) === null) {
			throw new TConfigurationException('metadatafilter_bad_field', $name);
		}
		if (is_string($content) && $separator !== null && $separator !== '') {
			$content = array_values(array_filter(array_map('trim', explode($separator, $content)), 'strlen'));
		}
		$this->_meta[] = ['field' => $name, 'content' => $content, 'append' => $append];
	}

	/**
	 * @return array<array{field:string, content:null|string|string[], append:bool}> the
	 *   fields to write, in order.
	 */
	public function getMeta(): array
	{
		return $this->_meta;
	}

	/**
	 * Replaces the fields to write.
	 * @param array $value the field arrays, each with "name", and optionally "content",
	 *   "separator", and "append".
	 * @throws TConfigurationException when a field has no name or an unknown name.
	 */
	public function setMeta($value): void
	{
		$this->_meta = [];
		$this->addEntries((array) $value);
	}

	/**
	 * Replaces `${param}` with an application parameter and `${date:format}` with the
	 * formatted current time. An unknown parameter is replaced with ''.
	 * @param string $value the content.
	 * @return string the content with its placeholders replaced.
	 */
	public static function transformContent(string $value): string
	{
		return preg_replace_callback('/\$\{([^}]*)\}/', function ($match) {
			$key = trim($match[1]);
			if (preg_match('/^(?:date|time):(.*)$/is', $key, $format)) {
				return date(trim($format[1]));
			}
			$app = Prado::getApplication();
			return (string) ($app ? $app->getParameters()->itemAt($key) : '');
		}, $value);
	}

	/**
	 * Writes the fields into the image metadata.
	 * @param \GdImage|\Imagick &$image The image being filtered, of either graphics
	 *   library, as the pixels are not touched.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool false, as the pixels do not change; null when there is no metadata.
	 */
	public function filterImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!($metaData = $param?->getImageMetaData())) {
			return null;
		}
		foreach ($this->_meta as $meta) {
			$content = $meta['content'];
			if (is_array($content)) {
				$content = array_map([static::class, 'transformContent'], $content);
			} elseif ($content !== null) {
				$content = static::transformContent((string) $content);
			}
			$metaData->setMetaData($meta['field'], $content, $meta['append']);
		}
		return false;
	}
}
