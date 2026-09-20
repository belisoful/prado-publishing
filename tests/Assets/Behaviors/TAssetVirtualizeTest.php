<?php

/**
 * TAssetVirtualizeTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Web\Assets\Behaviors\IAssetVirtualize;
use Prado\Web\Assets\Behaviors\TAssetVirtualize;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Tests\Fixtures\TCountingFileAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetVirtualizeTest class.
 *
 * Tests the asset virtualizer: its configuration, mapping a virtual file path to its
 * real file and the real file back to a virtual published name, folder and layered
 * virtualization, its configuration errors, and publishing virtual files end to end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetVirtualizeTest extends PublishingTestCase
{
	/** Matches "name.copy.ext" into the name and extension. */
	public const COPY_FILES = '/(?<=\/)([^\/]*?)\.copy\.([^\/\.]*)$/i';

	/**
	 * @param array $properties
	 * @return array the configuration of a ".copy" virtualizer.
	 */
	protected static function copyConfig(array $properties = []): array
	{
		return $properties + [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => self::COPY_FILES,
			'MapToVirtual' => '${1}.copy.${2}',
		];
	}

	/**
	 * @param string $path the asset file path.
	 * @param array $config the behavior configuration.
	 * @param string $class the asset class.
	 * @return TAsset the asset with the virtualizer attached as "virtual".
	 */
	protected function virtualAsset(string $path, array $config, string $class = TFileAsset::class): TAsset
	{
		$asset = new $class($path);
		$asset->attachBehavior('virtual', $config);
		return $asset;
	}

	public function testImplementsIAssetVirtualize(): void
	{
		self::assertInstanceOf(IAssetVirtualize::class, new TAssetVirtualize());
	}

	public function testDefaults(): void
	{
		$behavior = new TAssetVirtualize();

		self::assertNull($behavior->getVirtualFiles());
		self::assertSame('${1}.${2}', $behavior->getMapFromVirtual());
		self::assertSame('/(?<=\/)([^\/]*?)(?:\.([^\/\.]*))?$/i', $behavior->getOriginalFiles());
		self::assertNull($behavior->getMapToVirtual());
	}

	public function testDefaultOriginalFilesCapturesTheNameAndExtension(): void
	{
		$regex = (new TAssetVirtualize())->getOriginalFiles();

		self::assertSame('/dir/name-ext', preg_replace($regex, '${1}-${2}', '/dir/name.ext'));
		self::assertSame('/dir/name.min-js', preg_replace($regex, '${1}-${2}', '/dir/name.min.js'));
		self::assertSame('/dir/LICENSE-', preg_replace($regex, '${1}-${2}', '/dir/LICENSE'));
	}

	public function testPropertiesAreStrings(): void
	{
		$behavior = new TAssetVirtualize();

		$behavior->setVirtualFiles('/\.v$/');
		$behavior->setMapFromVirtual(12);
		$behavior->setOriginalFiles('/\.o$/');
		$behavior->setMapToVirtual(3.5);

		self::assertSame('/\.v$/', $behavior->getVirtualFiles());
		self::assertSame('12', $behavior->getMapFromVirtual());
		self::assertSame('/\.o$/', $behavior->getOriginalFiles());
		self::assertSame('3.5', $behavior->getMapToVirtual());
	}

	public function testPropertiesAreSetFromConfiguration(): void
	{
		$asset = $this->virtualAsset($this->srcDir . '/x.txt', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/a/',
			'MapFromVirtual' => 'b',
			'OriginalFiles' => '/c/',
			'MapToVirtual' => 'd',
		]);
		$behavior = $asset->asa('virtual');

		self::assertSame(['/a/', 'b', '/c/', 'd'], [$behavior->getVirtualFiles(), $behavior->getMapFromVirtual(), $behavior->getOriginalFiles(), $behavior->getMapToVirtual()]);
	}

	public function testSettersResetTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.txt'));
		$asset->attachBehavior('virtual', TAssetVirtualize::class);
		$behavior = $asset->asa('virtual');

		foreach (['VirtualFiles', 'MapFromVirtual', 'OriginalFiles', 'MapToVirtual'] as $property) {
			$resets = $asset->resets;
			$behavior->{'set' . $property}('/x/');
			self::assertSame($resets + 1, $asset->resets, "Setting $property resets the owner's file path cache.");
		}
	}

	public function testSettersOfADisabledBehaviorDoNotResetTheOwnerFilePathCache(): void
	{
		$asset = new TCountingFileAsset($this->writeSource('a.txt'));
		$asset->attachBehavior('virtual', TAssetVirtualize::class);
		$behavior = $asset->asa('virtual');
		$behavior->setEnabled(false);
		$resets = $asset->resets;

		$behavior->setVirtualFiles('/x/');
		$behavior->setMapFromVirtual('x');
		$behavior->setOriginalFiles('/x/');
		$behavior->setMapToVirtual('x');

		self::assertSame($resets, $asset->resets);
	}

	public function testVirtualFileMapsToItsRealFileAndBack(): void
	{
		$real = $this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', static::copyConfig());

		self::assertSame($real, $asset->getAssetOriginalFilePath());
		self::assertSame($this->srcDir . '/docs/readme.copy.txt', $asset->getAssetFilePath());
		self::assertNull($asset->getAssetVirtualFilePath());
	}

	public function testChangingMapToVirtualRecomputesTheFilePath(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', static::copyConfig());
		self::assertSame($this->srcDir . '/docs/readme.copy.txt', $asset->getAssetFilePath());

		$asset->asa('virtual')->setMapToVirtual('${1}.alias.${2}');

		self::assertSame($this->srcDir . '/docs/readme.alias.txt', $asset->getAssetFilePath());
	}

	public function testChangingVirtualFilesRecomputesTheFilePath(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', static::copyConfig());
		self::assertSame($this->srcDir . '/docs/readme.copy.txt', $asset->getAssetFilePath());

		$asset->asa('virtual')->setVirtualFiles('/\.nothing$/');

		$this->expectException(TInvalidDataValueException::class);
		$asset->getAssetFilePath();
	}

	public function testAnExistingFileIsNotVirtualized(): void
	{
		$real = $this->writeSource('docs/readme.copy.txt', 'a real copy');
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($real, static::copyConfig(['MapToVirtual' => '${1}.other.${2}']));

		self::assertSame($real, $asset->getAssetOriginalFilePath());
		self::assertSame($real, $asset->getAssetFilePath(), 'A real file is not rewritten.');
	}

	public function testANonMatchingFileIsNotVirtualized(): void
	{
		$real = $this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($real, static::copyConfig());

		self::assertSame($real, $asset->getAssetOriginalFilePath());
		self::assertSame($real, $asset->getAssetFilePath());
	}

	public function testWithoutVirtualFilesNothingIsVirtualized(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', ['class' => TAssetVirtualize::class, 'MapToVirtual' => 'x']);

		$this->expectException(TInvalidDataValueException::class);
		$asset->getAssetFilePath();
	}

	public function testAVirtualFileWithoutARealFileIsInvalid(): void
	{
		$asset = $this->virtualAsset($this->srcDir . '/docs/missing.copy.txt', static::copyConfig());

		try {
			$asset->getAssetFilePath();
			self::fail('A virtual file mapped to a missing file is invalid.');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('assetmanager_filepath_invalid', $e->getErrorCode());
			self::assertStringContainsString($this->srcDir . '/docs/missing.txt', $e->getMessage(), 'The mapped real path is reported.');
		}
	}

	public function testAVirtualAssetAlwaysValidatesSoIsNotVirtualized(): void
	{
		$asset = $this->virtualAsset('/virtual/readme.copy.txt', static::copyConfig(), TGeneratedAsset::class);

		self::assertSame('/virtual/readme.copy.txt', $asset->getAssetOriginalFilePath());
		self::assertSame('/virtual/readme.copy.txt', $asset->getAssetFilePath());
	}

	public function testFolderVirtualization(): void
	{
		$real = $this->writeSource('realdir/icon.txt', 'icon');
		$asset = $this->virtualAsset($this->srcDir . '/userIcons/icon.txt', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/\/userIcons\//i',
			'MapFromVirtual' => '/realdir/',
			'OriginalFiles' => '/\/realdir\//i',
			'MapToVirtual' => '/icons/',
		]);

		self::assertSame($real, $asset->getAssetOriginalFilePath());
		self::assertSame($this->srcDir . '/icons/icon.txt', $asset->getAssetFilePath(), 'The re-virtualization need not match the virtual path.');
	}

	public function testVirtualDirectory(): void
	{
		$this->writeSource('realdir/icon.txt', 'icon');
		$asset = $this->virtualAsset($this->srcDir . '/virtualdir', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/\/virtualdir$/',
			'MapFromVirtual' => '/realdir',
			'OriginalFiles' => '/\/realdir\/$/',
			'MapToVirtual' => '/virtualdir/',
		]);

		self::assertSame($this->srcDir . '/realdir/', $asset->getAssetOriginalFilePath(), 'The real directory gets its trailing separator.');
		self::assertSame($this->srcDir . '/virtualdir/', $asset->getAssetFilePath());
	}

	public function testLayeredVirtualizers(): void
	{
		$real = $this->writeSource('a.txt', 'a');
		$asset = new TFileAsset($this->srcDir . '/a.thumb.small.txt');
		$asset->attachBehavior('small', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/\.small(\.txt)$/',
			'MapFromVirtual' => '${1}',
			'MapToVirtual' => '${1}.small.${2}',
		]);
		$asset->attachBehavior('thumb', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/\.thumb(\.small\.txt)$/',
			'MapFromVirtual' => '${1}',
			'OriginalFiles' => '/\.small\.txt$/',
			'MapToVirtual' => '.thumb.small.txt',
		]);

		self::assertSame($real, $asset->getAssetOriginalFilePath(), 'The later virtualizer decodes first.');
		self::assertSame($this->srcDir . '/a.thumb.small.txt', $asset->getAssetFilePath(), 'The earlier virtualizer encodes first.');
	}

	public function testTheInstanceVirtualFilePathIsReVirtualized(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = new TFileAsset($this->srcDir . '/docs/readme.copy.txt', '/elsewhere/name.txt');
		$asset->attachBehavior('virtual', static::copyConfig());

		self::assertSame('/elsewhere/name.copy.txt', $asset->getAssetFilePath());
	}

	public function testMappingAVirtualFileToItselfThrows(): void
	{
		$asset = $this->virtualAsset($this->srcDir . '/missing.txt', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/missing/',
			'MapFromVirtual' => 'missing',
			'MapToVirtual' => 'x',
		]);

		try {
			$asset->getAssetFilePath();
			self::fail('A virtual file mapped to itself throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetvirtualize_no_alias_self', $e->getErrorCode());
		}
	}

	public function testPublishingAsTheOriginalPathThrows(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', static::copyConfig([
			'OriginalFiles' => '/\.nomatch$/',
		]));

		try {
			$asset->getAssetFilePath();
			self::fail('An unchanged re-virtualization throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetvirtualize_no_publish_as_original', $e->getErrorCode());
		}
	}

	public function testAnEmptyMapToVirtualThrows(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = $this->virtualAsset($this->srcDir . '/docs/readme.copy.txt', static::copyConfig(['MapToVirtual' => '']));

		try {
			$asset->getAssetFilePath();
			self::fail('An empty MapToVirtual throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetvirtualize_no_publish_as_original', $e->getErrorCode());
		}
	}

	public function testAMissingMapToVirtualThrows(): void
	{
		$this->writeSource('docs/readme.txt', 'real');
		$asset = new TFileAsset($this->srcDir . '/docs/readme.copy.txt');
		$asset->attachBehavior('virtual', ['class' => TAssetVirtualize::class, 'VirtualFiles' => self::COPY_FILES]);

		try {
			$asset->getAssetFilePath();
			self::fail('A virtual file without MapToVirtual throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetvirtualize_no_publish_as_original', $e->getErrorCode());
		}
	}

	public function testDisablingTheBehaviorStopsVirtualization(): void
	{
		$this->writeSource('docs/other.txt', 'other');
		$asset = $this->virtualAsset($this->srcDir . '/docs/other.copy.txt', static::copyConfig());
		self::assertSame($this->srcDir . '/docs/other.copy.txt', $asset->getAssetFilePath());

		$asset->disableBehavior('virtual');

		$this->expectException(TInvalidDataValueException::class);
		$asset->getAssetFilePath();
	}

	public function testPublishAVirtualFile(): void
	{
		$real = $this->writeSource('docs/readme.txt', 'real content');
		$this->attachClassBehavior('virtual', static::copyConfig(), TAsset::class);
		$manager = $this->newManager();
		$virtual = $this->srcDir . '/docs/readme.copy.txt';

		$url = $manager->publish($virtual);

		self::assertStringEndsWith('/readme.copy.txt', $url);
		self::assertSame('real content', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $manager->getPublishedUrl($virtual));
		self::assertFalse(is_file(dirname($this->urlToPath($url)) . '/readme.txt'), 'The real file name is not published.');
		self::assertSame($real, $manager->getPublishedAssets()[$virtual]->getAssetOriginalFilePath());

		$realUrl = $manager->publish($real);
		self::assertStringEndsWith('/readme.txt', $realUrl, 'The real file still publishes under its own name.');
		self::assertSame('real content', file_get_contents($this->urlToPath($realUrl)));
	}

	public function testPublishAVirtualFolder(): void
	{
		$this->writeSource('realdir/icon.txt', 'icon');
		$this->attachClassBehavior('virtual', [
			'class' => TAssetVirtualize::class,
			'VirtualFiles' => '/\/userIcons\//i',
			'MapFromVirtual' => '/realdir/',
			'OriginalFiles' => '/\/realdir\//i',
			'MapToVirtual' => '/icons/',
		], TAsset::class);
		$manager = $this->newManager();

		$url = $manager->publish($this->srcDir . '/userIcons/icon.txt');

		$iconsUrl = $manager->getPublishedUrl($this->writeSource('icons/other.txt'));
		self::assertSame(dirname($iconsUrl) . '/icon.txt', $url, 'It publishes where the re-virtualized path publishes.');
		self::assertNotSame($manager->getPublishedUrl($this->srcDir . '/realdir/icon.txt'), $url);
		self::assertSame('icon', file_get_contents($this->urlToPath($url)));
	}
}
