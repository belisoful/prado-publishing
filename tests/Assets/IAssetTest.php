<?php

/**
 * IAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Assets\IAsset;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\IPublishable;
use Prado\Web\Tests\PublishingTestCase;

/**
 * IAssetTest class.
 *
 * Tests the asset interface contract: it extends IPublishable with the original file
 * path, the package asset classes implement it, and a plain implementation that is not a
 * TComponent publishes through TPublishingManager from its original source.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class IAssetTest extends PublishingTestCase
{
	/**
	 * @param string $source the original source file.
	 * @param string $virtual the asset file path.
	 * @return IAsset a plain asset that copies the source and uppercases it.
	 */
	protected static function plainAsset(string $source, string $virtual): IAsset
	{
		return new class ($source, $virtual) implements IAsset {
			public array $published = [];

			public function __construct(private string $source, private string $virtual)
			{
			}

			public function getAssetOriginalFilePath()
			{
				return $this->source;
			}

			public function getAssetFilePath()
			{
				return $this->virtual;
			}

			public function getAssetModificationDate()
			{
				return filemtime($this->source);
			}

			public function publish(string $dst): ?bool
			{
				$this->published[] = $dst;
				return file_put_contents($dst, strtoupper(file_get_contents($this->source))) !== false;
			}
		};
	}

	public function testExtendsIPublishableWithTheOriginalFilePath(): void
	{
		$interface = new \ReflectionClass(IAsset::class);

		self::assertTrue($interface->isInterface());
		self::assertTrue($interface->isSubclassOf(IPublishable::class));
		self::assertSame(['getAssetOriginalFilePath'], array_map(fn ($m) => $m->getName(), array_filter($interface->getMethods(), fn ($m) => $m->getDeclaringClass()->getName() === IAsset::class)));
		self::assertSame(0, $interface->getMethod('getAssetOriginalFilePath')->getNumberOfParameters());
	}

	public function testPackageAssetsImplementIt(): void
	{
		self::assertTrue(is_a(TAsset::class, IAsset::class, true));
		self::assertInstanceOf(IAsset::class, new TFileAsset());
	}

	public function testAPlainAssetPublishesThroughTheManager(): void
	{
		$source = $this->writeSource('plain/source.txt', 'plain');
		$asset = static::plainAsset($source, '/virtual/plain/published.txt');
		$manager = $this->newManager();

		$url = $manager->publish($asset);

		self::assertStringEndsWith('/published.txt', $url);
		self::assertSame('PLAIN', file_get_contents($this->urlToPath($url)));
		self::assertCount(1, $asset->published);
		self::assertSame($asset, $manager->getPublishedAssets()['/virtual/plain/published.txt']);
	}

	public function testAPlainAssetIsMatchedByItsOriginalFileName(): void
	{
		$source = $this->writeSource('plain/source.txt', 'plain');
		$asset = static::plainAsset($source, '/virtual/plain/published.css');

		$url = $this->newManager()->publish($asset, ['only' => ['*.css']]);

		self::assertFileDoesNotExist($this->urlToPath($url), 'The only pattern matches the original source.txt, not published.css.');
		self::assertSame([], $asset->published);
	}
}
