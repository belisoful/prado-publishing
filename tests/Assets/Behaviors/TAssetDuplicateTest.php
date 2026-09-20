<?php

/**
 * TAssetDuplicateTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TConfigurationException;
use Prado\TEventParameter;
use Prado\Web\Assets\Behaviors\IAssetMatching;
use Prado\Web\Assets\Behaviors\IAssetReplacement;
use Prado\Web\Assets\Behaviors\TAssetDuplicate;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TTarAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TRecordingPublishingManager;
use Prado\Web\Tests\PublishingTestCase;
use Prado\Test\Unit\Harness\IO\TarTestHelper;

/**
 * TAssetDuplicateTest class.
 *
 * Tests the asset duplicator: its configuration, the duplicate asset it builds and
 * publishes, its recursion guard and the guard's reset, and publishing duplicates end
 * to end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetDuplicateTest extends PublishingTestCase
{
	/** Matches the extension of a file not already named "-copy". */
	public const COPY_MATCH = '/(?<!-copy)(\.[^\/\.]*)$/i';

	/**
	 * @return int the recursion depth of {@see TAssetDuplicate::duplicateAsset}.
	 */
	protected static function depth(): int
	{
		return (new \ReflectionMethod(TAssetDuplicate::class, 'duplicateAsset'))->getStaticVariables()['depth'] ?? 0;
	}

	/**
	 * @param array $properties the duplicator properties.
	 * @return TAssetDuplicate the duplicator.
	 */
	protected static function duplicator(array $properties): TAssetDuplicate
	{
		$behavior = new TAssetDuplicate();
		foreach ($properties as $name => $value) {
			$behavior->{'set' . $name}($value);
		}
		return $behavior;
	}

	/**
	 * @param TAsset $asset the asset being processed.
	 * @return TAssetEventParameter the onProcessAsset parameter.
	 */
	protected static function processParam(TAsset $asset): TAssetEventParameter
	{
		return new TAssetEventParameter('onProcessAsset', '/published/file', $asset);
	}

	public function testImplementsIAssetReplacement(): void
	{
		$behavior = new TAssetDuplicate();

		self::assertInstanceOf(IAssetReplacement::class, $behavior);
		self::assertInstanceOf(IAssetMatching::class, $behavior);
		self::assertSame(['onProcessAsset' => 'duplicateAsset'], $behavior->events());
	}

	public function testDefaultsAndProperties(): void
	{
		$behavior = new TAssetDuplicate();
		self::assertNull($behavior->getDuplicateClass());
		self::assertNull($behavior->getMatchFiles());
		self::assertNull($behavior->getMatchReplacement());

		$behavior->setDuplicateClass(TGeneratedAsset::class);
		$behavior->setMatchFiles('/\.js$/');
		$behavior->setMatchReplacement(7);

		self::assertSame(TGeneratedAsset::class, $behavior->getDuplicateClass());
		self::assertSame('/\.js$/', $behavior->getMatchFiles());
		self::assertSame('7', $behavior->getMatchReplacement());
	}

	public function testDuplicatesAMatchingAssetUnderTheReplacementPath(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$source = $this->writeSource('js/app.js');
		$asset = new TFileAsset($source);

		self::assertNull(static::duplicator(['MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}'])->duplicateAsset($asset, static::processParam($asset)));

		self::assertCount(1, $manager->recorded);
		$duplicate = $manager->recorded[0];
		self::assertInstanceOf(TFileAsset::class, $duplicate);
		self::assertNotSame($asset, $duplicate);
		self::assertSame($source, $duplicate->getAssetOriginalFilePath());
		self::assertSame($this->srcDir . '/js/app-copy.js', $duplicate->getAssetVirtualFilePath());
		self::assertSame($this->srcDir . '/js/app-copy.js', $duplicate->getAssetFilePath());
		self::assertSame(0, static::depth());
	}

	public function testDuplicatesWithTheDuplicateClass(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$source = $this->writeSource('js/app.js');
		$asset = new TFileAsset($source);

		static::duplicator(['MatchFiles' => '/\.js$/', 'MatchReplacement' => '.gen.js', 'DuplicateClass' => TGeneratedAsset::class])->duplicateAsset($asset, static::processParam($asset));

		self::assertCount(1, $manager->recorded);
		self::assertInstanceOf(TGeneratedAsset::class, $manager->recorded[0]);
		self::assertSame($this->srcDir . '/js/app.gen.js', $manager->recorded[0]->getAssetFilePath());
	}

	public function testWithoutAReplacementTheDuplicateHasNoVirtualPath(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$source = $this->writeSource('js/app.js');
		$asset = new TFileAsset($source);

		static::duplicator(['MatchFiles' => '/\.js$/', 'DuplicateClass' => TGeneratedAsset::class])->duplicateAsset($asset, static::processParam($asset));

		self::assertCount(1, $manager->recorded);
		self::assertNull($manager->recorded[0]->getAssetVirtualFilePath());
		self::assertSame($source, $manager->recorded[0]->getAssetFilePath());
	}

	public function testAnUnchangingReplacementGivesNoVirtualPath(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$source = $this->writeSource('js/app.js');
		$asset = new TFileAsset($source);

		static::duplicator(['MatchFiles' => '/(\.js)$/', 'MatchReplacement' => '${1}', 'DuplicateClass' => TGeneratedAsset::class])->duplicateAsset($asset, static::processParam($asset));

		self::assertCount(1, $manager->recorded);
		self::assertNull($manager->recorded[0]->getAssetVirtualFilePath());
	}

	public function testDuplicatesTheVirtualFilePathOfTheAsset(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$source = $this->writeSource('js/app.js');
		$asset = new TFileAsset($source, '/alias/main.js');

		static::duplicator(['MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}'])->duplicateAsset($asset, static::processParam($asset));

		self::assertCount(1, $manager->recorded);
		self::assertSame($source, $manager->recorded[0]->getAssetOriginalFilePath());
		self::assertSame('/alias/main-copy.js', $manager->recorded[0]->getAssetFilePath(), 'The duplicate is matched on the asset file path.');
	}

	public function testANonMatchingAssetIsNotDuplicated(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$asset = new TFileAsset($this->writeSource('css/site.css'));

		static::duplicator(['MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js'])->duplicateAsset($asset, static::processParam($asset));

		self::assertSame([], $manager->recorded);
	}

	public function testWithoutMatchFilesNothingIsDuplicated(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$asset = new TFileAsset($this->writeSource('js/app.js'));

		static::duplicator(['MatchReplacement' => '.x.js'])->duplicateAsset($asset, static::processParam($asset));

		self::assertSame([], $manager->recorded);
	}

	public function testDisabledOrWithoutAnAssetParameterNothingIsDuplicated(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$asset = new TFileAsset($this->writeSource('js/app.js'));
		$behavior = static::duplicator(['MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js']);

		self::assertNull($behavior->duplicateAsset($asset, null));
		self::assertNull($behavior->duplicateAsset($asset, new TEventParameter()));
		$behavior->setEnabled(false);
		self::assertNull($behavior->duplicateAsset($asset, static::processParam($asset)));

		self::assertSame([], $manager->recorded);
	}

	public function testTheDepthResetsWhenPublishingThrows(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$manager->throw = new \RuntimeException('publish failed');
		$asset = new TFileAsset($this->writeSource('js/app.js'));
		$behavior = static::duplicator(['MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js']);

		for ($i = 0; $i < 5; $i++) {
			try {
				$behavior->duplicateAsset($asset, static::processParam($asset));
				self::fail('The publishing exception propagates.');
			} catch (\RuntimeException $e) {
				self::assertSame('publish failed', $e->getMessage(), 'The recursion guard does not trip on repeated failures.');
			}
			self::assertSame(0, static::depth(), 'The depth is reset after the failure.');
		}
		self::assertCount(5, $manager->recorded);
	}

	public function testTheDepthResetsWhenTheDuplicateClassIsInvalid(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$asset = new TFileAsset($this->writeSource('js/app.js'));
		$behavior = static::duplicator(['MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js', 'DuplicateClass' => 'NoSuch\\AssetClass']);

		try {
			$behavior->duplicateAsset($asset, static::processParam($asset));
			self::fail('An unknown duplicate class throws.');
		} catch (\Error $e) {
			self::assertStringContainsString('NoSuch\\AssetClass', $e->getMessage());
		}

		self::assertSame(0, static::depth());
		self::assertSame([], $manager->recorded);
	}

	public function testDuplicatesATarArchiveWithItsChecksum(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$tar = $this->writeSource('bundle/bundle.tar', TarTestHelper::archive([TarTestHelper::entry('style.css', 'body{}')]));
		$md5 = $this->writeSource('bundle/bundle.md5', md5_file($tar) . '  bundle.tar');
		$asset = new TTarAsset($tar, $md5);
		$asset->setVerifyChecksum(true);

		static::duplicator(['MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}'])->duplicateAsset($asset, static::processParam($asset));

		self::assertCount(1, $manager->recorded);
		$duplicate = $manager->recorded[0];
		self::assertInstanceOf(TTarAsset::class, $duplicate);
		self::assertSame($md5, $duplicate->getMd5FilePath(), 'The checksum file is passed, not taken as the virtual path.');
		self::assertSame($tar, $duplicate->getAssetOriginalFilePath());
		self::assertSame($this->srcDir . '/bundle/bundle-copy.tar', $duplicate->getAssetVirtualFilePath());
		self::assertSame(0, static::depth());
	}

	public function testDuplicatesATarArchiveAsAnotherClassWithoutItsChecksum(): void
	{
		$manager = $this->newManager([], TRecordingPublishingManager::class);
		$tar = $this->writeSource('bundle/bundle.tar', TarTestHelper::archive([TarTestHelper::entry('style.css', 'body{}')]));
		$asset = new TTarAsset($tar, $this->writeSource('bundle/bundle.md5', md5_file($tar)));

		static::duplicator(['MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}', 'DuplicateClass' => TFileAsset::class])->duplicateAsset($asset, static::processParam($asset));

		$duplicate = $manager->recorded[0];
		self::assertSame(TFileAsset::class, get_class($duplicate));
		self::assertSame($this->srcDir . '/bundle/bundle-copy.tar', $duplicate->getAssetVirtualFilePath());
	}

	public function testPublishDuplicatesTheAsset(): void
	{
		$this->attachClassBehavior('copier', ['class' => TAssetDuplicate::class, 'MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}'], TAsset::class);
		$source = $this->writeSource('js/app.js', 'let a;');
		$manager = $this->newManager();

		$url = $manager->publish($source);
		$dst = $this->urlToPath($url);

		self::assertStringEndsWith('/app.js', $url);
		self::assertSame('let a;', file_get_contents($dst));
		self::assertSame('let a;', file_get_contents(dirname($dst) . '/app-copy.js'), 'The duplicate publishes beside the original.');
		self::assertArrayHasKey($this->srcDir . '/js/app-copy.js', $manager->getPublishedAssets());
		self::assertSame(dirname($url) . '/app-copy.js', $manager->getPublishedAssets()[$this->srcDir . '/js/app-copy.js']->getPublishedUrl());
		self::assertSame([], glob(dirname($dst) . '/tmp-*'), 'No temporary file is left.');
		self::assertSame(0, static::depth());
	}

	public function testPublishDuplicatesWithTheDuplicateClass(): void
	{
		$this->attachClassBehavior('generator', [
			'class' => TAssetDuplicate::class,
			'MatchFiles' => '/(?<!\.gen)\.js$/',
			'MatchReplacement' => '.gen.js',
			'DuplicateClass' => TGeneratedAsset::class,
		], TAsset::class);
		$source = $this->writeSource('js/app.js', 'let a;');

		$dst = $this->urlToPath($this->newManager()->publish($source));

		self::assertSame('let a;', file_get_contents($dst));
		self::assertSame('generated', file_get_contents(dirname($dst) . '/app.gen.js'));
	}

	public function testPublishDuplicatesEachFileOfADirectory(): void
	{
		$this->attachClassBehavior('copier', ['class' => TAssetDuplicate::class, 'MatchFiles' => self::COPY_MATCH, 'MatchReplacement' => '-copy${1}'], TAsset::class);
		$this->writeSource('bundle/a.txt', 'alpha');
		$this->writeSource('bundle/sub/b.txt', 'beta');
		$manager = $this->newManager();

		$dir = $this->urlToPath($manager->publish($this->srcDir . '/bundle'));

		self::assertSame('alpha', file_get_contents($dir . '/a.txt'));
		self::assertSame('beta', file_get_contents($dir . '/sub/b.txt'));
		self::assertSame('alpha', file_get_contents($dir . '/a-copy.txt'), 'A duplicate publishes where its own directory publishes.');
		self::assertFalse(is_file($dir . '/sub/b-copy.txt'), 'A nested duplicate is published as its own asset, not into the published tree.');
		self::assertSame('beta', file_get_contents(dirname($manager->getPublishedPath($this->srcDir . '/bundle/sub/b.txt')) . '/b-copy.txt'));
	}

	public function testRecursiveDuplicationThrows(): void
	{
		$this->attachClassBehavior('recursive', ['class' => TAssetDuplicate::class, 'MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js'], TAsset::class);
		$source = $this->writeSource('js/app.js', 'let a;');
		$manager = $this->newManager();

		try {
			$manager->publish($source);
			self::fail('Recursive duplication throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetduplicate_recursive', $e->getErrorCode());
		}

		self::assertSame(0, static::depth(), 'The depth is reset after the recursion is stopped.');
		self::assertSame([], glob($this->assetDir . '/*/*'), 'Neither the asset nor its nested duplicates are left published, nor any temporary file.');
	}

	public function testDuplicationSucceedsAfterARecursionFailure(): void
	{
		$this->attachClassBehavior('recursive', ['class' => TAssetDuplicate::class, 'MatchFiles' => '/\.js$/', 'MatchReplacement' => '.x.js'], TAsset::class);
		$this->attachClassBehavior('copier', ['class' => TAssetDuplicate::class, 'MatchFiles' => '/(?<!-copy)\.css$/', 'MatchReplacement' => '-copy.css'], TAsset::class);
		$manager = $this->newManager();
		try {
			$manager->publish($this->writeSource('js/app.js'));
			self::fail('Recursive duplication throws.');
		} catch (TConfigurationException $e) {
			self::assertSame('assetduplicate_recursive', $e->getErrorCode());
		}

		$dst = $this->urlToPath($manager->publish($this->writeSource('css/site.css', 'body{}')));

		self::assertSame('body{}', file_get_contents(dirname($dst) . '/site-copy.css'));
	}
}
