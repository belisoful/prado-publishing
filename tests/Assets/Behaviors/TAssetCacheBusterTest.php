<?php

/**
 * TAssetCacheBusterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\TComponent;
use Prado\Web\Assets\Behaviors\IAssetMatching;
use Prado\Web\Assets\Behaviors\TAssetBlocker;
use Prado\Web\Assets\Behaviors\TAssetCacheBuster;
use Prado\Web\Assets\Behaviors\TAssetCompress;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetCacheBusterTest class.
 *
 * Tests the cache buster: its configuration, the content and modification-time
 * fingerprints, token truncation, file matching, the assets it cannot fingerprint, and
 * fingerprinted file names and URLs when publishing.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetCacheBusterTest extends PublishingTestCase
{
	/**
	 * @param string $path the asset file path.
	 * @param array $properties the cache buster properties.
	 * @param string $class the asset class.
	 * @return TAsset the asset with a cache buster attached as "buster".
	 */
	protected function bustedAsset(string $path, array $properties = [], string $class = TFileAsset::class): TAsset
	{
		$asset = new $class($path);
		$asset->attachBehavior('buster', ['class' => TAssetCacheBuster::class] + $properties);
		return $asset;
	}

	/**
	 * @param TAsset $asset the asset.
	 * @return string the asset file path filtered through dyAlterAssetFilePath.
	 */
	protected static function altered(TAsset $asset): string
	{
		return $asset->dyAlterAssetFilePath($asset->getAssetFilePath());
	}

	public function testImplementsIAssetMatching(): void
	{
		self::assertInstanceOf(IAssetMatching::class, new TAssetCacheBuster());
		self::assertSame('mtime', TAssetCacheBuster::MTIME_ALGORITHM);
	}

	public function testDefaults(): void
	{
		$buster = new TAssetCacheBuster();

		self::assertSame('crc32b', $buster->getAlgorithm());
		self::assertSame(0, $buster->getHashLength());
		self::assertNull($buster->getMatchFiles());
	}

	public function testPropertiesFromStrings(): void
	{
		$buster = new TAssetCacheBuster();

		$buster->setAlgorithm('md5');
		$buster->setHashLength('8');
		$buster->setMatchFiles('/\.js$/');

		self::assertSame('md5', $buster->getAlgorithm());
		self::assertSame(8, $buster->getHashLength());
		self::assertSame('/\.js$/', $buster->getMatchFiles());
	}

	public function testNegativeHashLengthIsZero(): void
	{
		$buster = new TAssetCacheBuster();
		$buster->setHashLength(-3);

		self::assertSame(0, $buster->getHashLength());
	}

	public function testEmptyMatchFilesIsNull(): void
	{
		$buster = new TAssetCacheBuster();
		$buster->setMatchFiles('/x/');
		$buster->setMatchFiles('');

		self::assertNull($buster->getMatchFiles());
	}

	public function testFingerprintsWithTheDefaultCrc32b(): void
	{
		$source = $this->writeSource('js/app.js', 'let a = 1;');
		$asset = $this->bustedAsset($source);

		self::assertSame($this->srcDir . '/js/app.' . hash('crc32b', 'let a = 1;') . '.js', static::altered($asset));
		self::assertSame($source, $asset->getAssetFilePath(), 'The asset file path itself is not changed.');
	}

	public function testFingerprintsWithAnotherAlgorithm(): void
	{
		$source = $this->writeSource('css/site.css', 'body{}');

		self::assertSame($this->srcDir . '/css/site.' . sha1('body{}') . '.css', static::altered($this->bustedAsset($source, ['Algorithm' => 'sha1'])));
	}

	public function testHashLengthTruncatesTheToken(): void
	{
		$source = $this->writeSource('css/site.css', 'body{}');

		self::assertSame($this->srcDir . '/css/site.' . substr(md5('body{}'), 0, 6) . '.css', static::altered($this->bustedAsset($source, ['Algorithm' => 'md5', 'HashLength' => 6])));
	}

	public function testHashLengthLongerThanTheToken(): void
	{
		$source = $this->writeSource('css/site.css', 'body{}');

		self::assertSame($this->srcDir . '/css/site.' . hash('crc32b', 'body{}') . '.css', static::altered($this->bustedAsset($source, ['HashLength' => 100])));
	}

	public function testFingerprintsWithTheModificationTime(): void
	{
		$source = $this->writeSource('js/app.js', 'x', 1700000000);

		self::assertSame($this->srcDir . '/js/app.1700000000.js', static::altered($this->bustedAsset($source, ['Algorithm' => 'mtime'])));
		self::assertSame($this->srcDir . '/js/app.17000.js', static::altered($this->bustedAsset($source, ['Algorithm' => 'MTime', 'HashLength' => 5])), 'The mtime algorithm is case-insensitive.');
	}

	public function testTheTokenChangesWithTheContent(): void
	{
		$source = $this->writeSource('js/app.js', 'one');
		$first = static::altered($this->bustedAsset($source));
		file_put_contents($source, 'two');

		self::assertNotSame($first, static::altered($this->bustedAsset($source)));
	}

	public function testFileNameShapes(): void
	{
		$token = hash('crc32b', 'x');

		self::assertSame($this->srcDir . '/LICENSE.' . $token, static::altered($this->bustedAsset($this->writeSource('LICENSE', 'x'))), 'An extension-less name gets a trailing token.');
		self::assertSame($this->srcDir . '/jquery.min.' . $token . '.js', static::altered($this->bustedAsset($this->writeSource('jquery.min.js', 'x'))), 'The token goes before the last extension.');
		self::assertSame($this->srcDir . '/v1.2/app.' . $token . '.js', static::altered($this->bustedAsset($this->writeSource('v1.2/app.js', 'x'))), 'Dotted directories are unchanged.');
	}

	public function testMatchFilesLimitsFingerprinting(): void
	{
		$js = $this->writeSource('app.js', 'x');
		$css = $this->writeSource('site.css', 'x');

		self::assertSame($this->srcDir . '/app.' . hash('crc32b', 'x') . '.js', static::altered($this->bustedAsset($js, ['MatchFiles' => '/\.js$/'])));
		self::assertSame($css, static::altered($this->bustedAsset($css, ['MatchFiles' => '/\.js$/'])));
	}

	public function testMatchFilesMatchesTheAssetFilePathBeforeARename(): void
	{
		$source = $this->writeSource('app.js', 'js');
		$asset = new TFileAsset($source);
		$asset->attachBehavior('compress', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.js$/'], 5);
		$asset->attachBehavior('buster', ['class' => TAssetCacheBuster::class, 'MatchFiles' => '/\.js$/'], 10);
		$token = hash_file('crc32b', $source);

		self::assertSame($this->srcDir . "/app.js.$token.gz", $asset->getAssetPublishFilePath(), 'The ".gz" appended first does not unmatch the ".js" asset.');
		self::assertSame($this->srcDir . "/app.js.$token.gz", $asset->asa('buster')->dyAlterAssetFilePath($source . '.gz'));
	}

	public function testMatchFilesMatchesTheGivenPathWithoutAnAssetFilePath(): void
	{
		$source = $this->writeSource('app.js', 'js');
		$owner = new class () extends TComponent {
			public ?string $source = null;

			public function getAssetOriginalFilePath()
			{
				return $this->source;
			}
		};
		$owner->source = $source;
		$owner->attachBehavior('buster', ['class' => TAssetCacheBuster::class, 'MatchFiles' => '/\.js$/']);
		$token = hash_file('crc32b', $source);

		self::assertSame($this->srcDir . "/app.$token.js", $owner->dyAlterAssetFilePath($source), 'The given path matches.');
		self::assertSame($this->srcDir . '/app.css', $owner->dyAlterAssetFilePath($this->srcDir . '/app.css'), 'The given path does not match.');
	}

	public function testADirectoryIsNotFingerprinted(): void
	{
		$this->writeSource('bundle/a.txt');
		$asset = $this->bustedAsset($this->srcDir . '/bundle');

		self::assertSame($this->srcDir . '/bundle/', static::altered($asset));
	}

	public function testAVirtualAssetIsNotFingerprinted(): void
	{
		$asset = $this->bustedAsset('/virtual/data.json', [], TGeneratedAsset::class);

		self::assertSame('/virtual/data.json', static::altered($asset));
	}

	public function testAnEmptyPathIsNotFingerprinted(): void
	{
		$asset = $this->bustedAsset($this->writeSource('a.txt'));

		self::assertSame('', $asset->asa('buster')->dyAlterAssetFilePath(''));
		self::assertNull($asset->asa('buster')->dyAlterAssetFilePath(null));
	}

	public function testWithoutAnOwnerNothingIsFingerprinted(): void
	{
		$this->writeSource('a.txt');

		self::assertSame($this->srcDir . '/a.txt', (new TAssetCacheBuster())->dyAlterAssetFilePath($this->srcDir . '/a.txt'));
	}

	public function testAnOwnerWithoutASourceIsNotFingerprinted(): void
	{
		$source = $this->writeSource('a.txt');
		$component = new TComponent();
		$component->attachBehavior('buster', TAssetCacheBuster::class);

		self::assertSame($source, $component->dyAlterAssetFilePath($source));
	}

	public function testABlockedAssetIsNotFingerprinted(): void
	{
		$source = $this->writeSource('a.txt');
		$asset = $this->bustedAsset($source);
		$asset->attachBehavior('blocker', ['class' => TAssetBlocker::class, 'BlockedFiles' => '/a\.txt$/']);

		self::assertNull($asset->getAssetOriginalFilePath());
		self::assertSame($source, $asset->dyAlterAssetFilePath($source));
	}

	public function testAnUnknownAlgorithmDoesNotFingerprint(): void
	{
		$source = $this->writeSource('a.txt', 'x');

		self::assertSame($source, static::altered($this->bustedAsset($source, ['Algorithm' => 'no-such-algorithm'])));
	}

	public function testAnUnreadableSourceIsNotFingerprinted(): void
	{
		$source = $this->writeSource('a.txt', 'x');
		chmod($source, 0);
		if (is_readable($source)) {
			chmod($source, 0o644);
			self::markTestSkipped('File permissions are not enforced for this user.');
		}
		try {
			self::assertSame($source, static::altered($this->bustedAsset($source)));
		} finally {
			chmod($source, 0o644);
		}
	}

	public function testADisabledBusterDoesNotFingerprint(): void
	{
		$source = $this->writeSource('a.txt', 'x');
		$asset = $this->bustedAsset($source);
		$buster = $asset->asa('buster');
		$buster->setEnabled(false);

		self::assertSame($source, $buster->dyAlterAssetFilePath($source));
		self::assertSame($source, $asset->dyAlterAssetFilePath($source));
	}

	public function testChainsToTheNextBehavior(): void
	{
		$source = $this->writeSource('a.txt', 'x');
		$asset = $this->bustedAsset($source);
		$asset->attachBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v']);

		self::assertSame($this->srcDir . '/a.' . hash('crc32b', 'x') . '.txt.v', static::altered($asset));
	}

	public function testPublishFingerprintsTheFileName(): void
	{
		$this->attachClassBehavior('buster', ['class' => TAssetCacheBuster::class, 'HashLength' => 8], TAsset::class);
		$source = $this->writeSource('js/app.js', 'let a = 1;');
		$manager = $this->newManager();

		$url = $manager->publish($source);

		self::assertStringEndsWith('/app.' . hash('crc32b', 'let a = 1;') . '.js', $url);
		self::assertStringNotContainsString('?', $url, 'No query string is appended.');
		self::assertSame('let a = 1;', file_get_contents($this->urlToPath($url)));
		self::assertSame($url, $manager->getPublishedUrl($source));
		self::assertSame($this->urlToPath($url), $manager->getPublishedPath($source));
		self::assertSame($url, $manager->getPublishedAssets()[$source]->getPublishedUrl());
		self::assertFalse(is_file(dirname($this->urlToPath($url)) . '/app.js'));
	}

	public function testPublishChangedContentUnderANewUrl(): void
	{
		$this->attachClassBehavior('buster', TAssetCacheBuster::class, TAsset::class);
		$source = $this->writeSource('js/app.js', 'one');
		$first = $this->newManager()->publish($source);

		file_put_contents($source, 'two');
		$second = $this->newManager()->publish($source);

		self::assertNotSame($first, $second);
		self::assertSame(dirname($first), dirname($second));
		self::assertSame('one', file_get_contents($this->urlToPath($first)));
		self::assertSame('two', file_get_contents($this->urlToPath($second)));
	}

	public function testPublishOnlyMatchingFiles(): void
	{
		$this->attachClassBehavior('buster', ['class' => TAssetCacheBuster::class, 'MatchFiles' => '/\.css$/'], TAsset::class);
		$manager = $this->newManager();

		self::assertStringEndsWith('/app.js', $manager->publish($this->writeSource('app.js', 'x')));
		self::assertStringEndsWith('/site.' . hash('crc32b', 'y') . '.css', $manager->publish($this->writeSource('site.css', 'y')));
	}

	public function testPublishDirectoryFingerprintsEachFile(): void
	{
		$this->attachClassBehavior('buster', TAssetCacheBuster::class, TAsset::class);
		$this->writeSource('bundle/a.txt', 'alpha');
		$this->writeSource('bundle/sub/b.txt', 'beta');

		$url = $this->newManager()->publish($this->srcDir . '/bundle');
		$dir = $this->urlToPath($url);

		self::assertStringEndsWith(basename($dir), $url, 'The directory is not fingerprinted.');
		self::assertSame('alpha', file_get_contents($dir . '/a.' . hash('crc32b', 'alpha') . '.txt'));
		self::assertSame('beta', file_get_contents($dir . '/sub/b.' . hash('crc32b', 'beta') . '.txt'));
		self::assertFalse(is_file($dir . '/a.txt'));
	}
}
