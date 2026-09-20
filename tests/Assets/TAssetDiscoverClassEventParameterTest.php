<?php

/**
 * TAssetDiscoverClassEventParameterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\TEventParameter;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetDiscoverClassEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetDiscoverClassEventParameterTest class.
 *
 * Tests the onDiscoverClass event parameter: its class and file path properties, their
 * string conversion, and what TPublishingManager hands to and takes from handlers.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetDiscoverClassEventParameterTest extends PublishingTestCase
{
	public function testIsAnEventParameter(): void
	{
		self::assertInstanceOf(TEventParameter::class, new TAssetDiscoverClassEventParameter());
	}

	public function testDefaults(): void
	{
		$param = new TAssetDiscoverClassEventParameter();
		self::assertSame('', $param->getClass());
		self::assertSame('', $param->getFilePath());
	}

	public function testConstructorSetsTheClassAndFilePath(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/src/a.txt');
		self::assertSame(TFileAsset::class, $param->getClass());
		self::assertSame('/src/a.txt', $param->getFilePath());
	}

	public function testPropertiesRoundTrip(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/src/a.txt');

		$param->setClass(TGeneratedAsset::class);
		$param->setFilePath('/src/b.txt');

		self::assertSame(TGeneratedAsset::class, $param->getClass());
		self::assertSame('/src/b.txt', $param->getFilePath());
	}

	public static function stringProvider(): array
	{
		return [
			'integer' => [42, '42'],
			'float' => [1.5, '1.5'],
			'true' => [true, 'true'],
			'false' => [false, 'false'],
			'null' => [null, ''],
			'stringable' => [new class () {
				public function __toString(): string
				{
					return 'Stringable\\Asset';
				}
			}, 'Stringable\\Asset'],
		];
	}

	/**
	 * @dataProvider stringProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('stringProvider')]
	public function testPropertiesAreConvertedToStrings($value, string $expected): void
	{
		$param = new TAssetDiscoverClassEventParameter();

		$param->setClass($value);
		$param->setFilePath($value);

		self::assertSame($expected, $param->getClass());
		self::assertSame($expected, $param->getFilePath());
	}

	public function testManagerHandsHandlersTheDefaultClassAndAbsolutePath(): void
	{
		$manager = $this->newManager();
		$seen = [];
		$manager->attachEventHandler('onDiscoverClass', function ($sender, $param) use (&$seen) {
			$seen[] = [$sender, get_class($param), $param->getClass(), $param->getFilePath()];
		});
		$source = $this->writeSource('a.txt');

		$manager->ensureAsset($this->srcDir . '/sub/../a.txt');

		self::assertSame([[$manager, TAssetDiscoverClassEventParameter::class, TFileAsset::class, TAsset::virtualpath($source)]], $seen);
	}

	public function testManagerAppliesTheRewrittenFilePathWithTheDefaultClass(): void
	{
		$manager = $this->newManager();
		$image = $this->writeImage('real.png');
		$manager->attachEventHandler('onDiscoverClass', fn ($sender, $param) => $param->setFilePath($image));

		$asset = $manager->ensureAsset($this->srcDir . '/alias.txt');

		self::assertInstanceOf(TImageAsset::class, $asset, 'The image extension of the rewritten path selects the image class.');
		self::assertSame($image, $asset->getAssetFilePath());
	}
}
