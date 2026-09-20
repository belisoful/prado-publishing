<?php

/**
 * TAssetDiscoveryTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Web\Assets\Behaviors\IAssetMatching;
use Prado\Web\Assets\Behaviors\IAssetReplacement;
use Prado\Web\Assets\Behaviors\TAssetDiscovery;
use Prado\Web\Assets\TAssetDiscoverClassEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Web\TPublishingManager;

/**
 * TAssetDiscoveryTest class.
 *
 * Tests the asset class discovery behavior: its configuration, routing matching file
 * paths to a class with an optional path rewrite, its configuration error, and
 * discovering and publishing routed assets through TPublishingManager.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetDiscoveryTest extends PublishingTestCase
{
	/**
	 * @param array $properties the discovery properties.
	 * @return TAssetDiscovery the discovery behavior.
	 */
	protected static function discovery(array $properties): TAssetDiscovery
	{
		$behavior = new TAssetDiscovery();
		foreach ($properties as $name => $value) {
			$behavior->{'set' . $name}($value);
		}
		return $behavior;
	}

	/**
	 * @param array $properties the discovery properties.
	 * @return TPublishingManager a manager with the discovery class behavior attached.
	 */
	protected function discoveringManager(array $properties): TPublishingManager
	{
		$this->attachClassBehavior('discovery', ['class' => TAssetDiscovery::class] + $properties, TPublishingManager::class);
		return $this->newManager();
	}

	public function testImplementsIAssetReplacement(): void
	{
		$behavior = new TAssetDiscovery();

		self::assertInstanceOf(IAssetReplacement::class, $behavior);
		self::assertInstanceOf(IAssetMatching::class, $behavior);
		self::assertSame(['onDiscoverClass' => 'discoverClass'], $behavior->events());
	}

	public function testDefaultsAndProperties(): void
	{
		$behavior = new TAssetDiscovery();
		self::assertNull($behavior->getRoutedClass());
		self::assertNull($behavior->getMatchFiles());
		self::assertNull($behavior->getMatchReplacement());

		$behavior->setRoutedClass(TGeneratedAsset::class);
		$behavior->setMatchFiles('/\.db$/');
		$behavior->setMatchReplacement(1);

		self::assertSame(TGeneratedAsset::class, $behavior->getRoutedClass());
		self::assertSame('/\.db$/', $behavior->getMatchFiles());
		self::assertSame('1', $behavior->getMatchReplacement());
	}

	public function testRoutesAMatchingPath(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.db');

		static::discovery(['MatchFiles' => '/\.db$/', 'RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);

		self::assertSame(TGeneratedAsset::class, $param->getClass());
		self::assertSame('/data/file.db', $param->getFilePath(), 'Without a replacement the path is unchanged.');
	}

	public function testRoutesAndRewritesAMatchingPath(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/dbFiles/a/file.db');

		static::discovery(['MatchFiles' => '/^\/dbFiles\//', 'MatchReplacement' => '/db/structure/', 'RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);

		self::assertSame(TGeneratedAsset::class, $param->getClass());
		self::assertSame('/db/structure/a/file.db', $param->getFilePath());
	}

	public function testANonMatchingPathIsNotRouted(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.txt');

		static::discovery(['MatchFiles' => '/\.db$/', 'MatchReplacement' => '.x', 'RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);

		self::assertSame(TFileAsset::class, $param->getClass());
		self::assertSame('/data/file.txt', $param->getFilePath());
	}

	public function testWithoutMatchFilesNothingIsRouted(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.db');

		static::discovery(['RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);
		static::discovery(['MatchFiles' => '', 'RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);

		self::assertSame(TFileAsset::class, $param->getClass());
	}

	public function testAnEmptyPathIsNotRouted(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '');

		static::discovery(['MatchFiles' => '/.*/', 'RoutedClass' => TGeneratedAsset::class])->discoverClass(null, $param);

		self::assertSame(TFileAsset::class, $param->getClass());
	}

	public function testADisabledBehaviorDoesNotRoute(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.db');
		$behavior = static::discovery(['MatchFiles' => '/\.db$/', 'RoutedClass' => TGeneratedAsset::class]);
		$behavior->setEnabled(false);

		$behavior->discoverClass(null, $param);

		self::assertSame(TFileAsset::class, $param->getClass());
	}

	public function testAMatchWithoutARoutedClassThrows(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.db');

		try {
			static::discovery(['MatchFiles' => '/\.db$/', 'MatchReplacement' => '.x'])->discoverClass(null, $param);
			self::fail('A match without a RoutedClass throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetdiscovery_requires_class', $e->getErrorCode());
		}
		self::assertSame('/data/file.db', $param->getFilePath(), 'The path is not rewritten.');
	}

	public function testANonMatchWithoutARoutedClassDoesNotThrow(): void
	{
		$param = new TAssetDiscoverClassEventParameter(TFileAsset::class, '/data/file.txt');

		static::discovery(['MatchFiles' => '/\.db$/'])->discoverClass(null, $param);

		self::assertSame(TFileAsset::class, $param->getClass());
	}

	public function testManagerInstancesTheRoutedClass(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.gen$/', 'RoutedClass' => TGeneratedAsset::class]);

		$asset = $manager->ensureAsset('/virtual/data.gen');

		self::assertInstanceOf(TGeneratedAsset::class, $asset);
		self::assertSame('/virtual/data.gen', $asset->getAssetFilePath());
		self::assertInstanceOf(TFileAsset::class, $manager->ensureAsset($this->writeSource('plain.txt')), 'Other paths use the default class.');
	}

	public function testManagerRewritesTheRoutedPath(): void
	{
		$real = $this->writeSource('real/data.txt', 'real');
		$manager = $this->discoveringManager(['MatchFiles' => '/\/alias\//', 'MatchReplacement' => '/real/', 'RoutedClass' => TFileAsset::class]);

		$asset = $manager->ensureAsset($this->srcDir . '/alias/data.txt');

		self::assertSame($real, $asset->getAssetFilePath());
	}

	public function testRoutingOverridesImageDetection(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\/raw\//', 'RoutedClass' => TGeneratedAsset::class]);

		self::assertInstanceOf(TGeneratedAsset::class, $manager->ensureAsset($this->writeSource('raw/picture.png', 'x')));
		self::assertInstanceOf(TImageAsset::class, $manager->ensureAsset($this->writeSource('images/picture.png', 'x')));
	}

	public function testRoutingToTheDefaultClassOverridesImageDetection(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.png$/', 'RoutedClass' => TFileAsset::class]);

		$asset = $manager->ensureAsset($this->writeSource('picture.png', 'x'));

		self::assertSame(TFileAsset::class, get_class($asset), 'A class set by a handler is used, even when it is the default class.');
	}

	public function testManagerThrowsWithoutARoutedClass(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.db$/']);

		try {
			$manager->publish($this->writeSource('data.db'));
			self::fail('A match without a RoutedClass throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetdiscovery_requires_class', $e->getErrorCode());
		}
	}

	public function testManagerRejectsARoutedNonAssetClass(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.db$/', 'RoutedClass' => \stdClass::class]);

		try {
			$manager->ensureAsset($this->writeSource('data.db'));
			self::fail('A routed non-asset class throws.');
		} catch (TInvalidDataTypeException $e) {
			self::assertSame('publishingmanager_invalid_class', $e->getErrorCode());
		}
	}

	public function testDisabledBehaviorOnTheManager(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.gen$/', 'RoutedClass' => TGeneratedAsset::class]);
		$source = $this->writeSource('data.gen');

		$manager->asa('discovery')->setEnabled(false);
		self::assertInstanceOf(TFileAsset::class, $manager->ensureAsset($source), 'A disabled behavior does not handle the event.');

		$manager->asa('discovery')->setRetainDisabledHandlers(true);
		self::assertTrue($manager->hasEventHandler('onDiscoverClass'));
		self::assertInstanceOf(TFileAsset::class, $manager->ensureAsset($source), 'A disabled behavior with retained handlers does not route.');

		$manager->asa('discovery')->setEnabled(true);
		self::assertInstanceOf(TGeneratedAsset::class, $manager->ensureAsset($source));
	}

	public function testPublishTheRoutedAsset(): void
	{
		$manager = $this->discoveringManager(['MatchFiles' => '/\.txt$/', 'MatchReplacement' => '.json', 'RoutedClass' => TGeneratedAsset::class]);
		$source = $this->srcDir . '/virtual/data.txt';

		$url = $manager->publish($source);

		self::assertStringEndsWith('/data.json', $url);
		self::assertSame('generated', file_get_contents($this->urlToPath($url)));
		self::assertInstanceOf(TGeneratedAsset::class, $manager->getPublishedAssets()[$source]);
		self::assertSame($url, $manager->getPublishedUrl($source));
	}
}
