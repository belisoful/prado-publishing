<?php

/**
 * TAssetMinifyTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/belisoful/prado-publishing/blob/main/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors;

use Prado\Exceptions\TIOException;
use Prado\TComponent;
use Prado\Web\Assets\Behaviors\IAssetMatching;
use Prado\Web\Assets\Behaviors\TAssetCompress;
use Prado\Web\Assets\Behaviors\TAssetMinify;
use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAsset;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TFileAsset;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\Javascripts\TJavaScriptMinifier;
use Prado\Web\Javascripts\TStyleSheetHelper;
use Prado\Web\Javascripts\TStyleSheetMinifier;
use Prado\Web\Tests\Fixtures\TRecordingMinifier;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetMinifyTest class.
 *
 * Tests the minifier: the matching and its default, skipping minified files and files of
 * another language, the finalizer and its priority before TAssetCompress, minifying
 * JavaScript and CSS in place with the framework minifiers and with minifiers set through
 * setMinifier(), and publishing files, directories, and compressed files end to end.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetMinifyTest extends PublishingTestCase
{
	/** JavaScript with comments and whitespace to remove. */
	public const JS = "/* The application. */\nfunction app(name) {\n\t// Greets.\n\treturn 'Hello, ' + name;\n}\n";

	/** CSS with comments and whitespace to remove. */
	public const CSS = "/* The page. */\nbody {\n\tmargin: 0;\n\tcolor: #ffffff;\n}\n";

	protected function tearDown(): void
	{
		TJavaScript::setMinifier(null);
		TStyleSheetHelper::setMinifier(null);
		parent::tearDown();
	}

	/**
	 * Skips the test when file permissions are not enforced, as for root.
	 * @param string $path the file whose permissions were changed.
	 * @param callable $check whether the permission change took effect.
	 */
	protected static function requirePermissions(string $path, callable $check): void
	{
		clearstatcache();
		if (!$check($path)) {
			chmod($path, 0o644);
			self::markTestSkipped('File permissions are not enforced for this user.');
		}
	}

	/**
	 * @param string $path the asset file path.
	 * @param array $properties the minifier properties.
	 * @return TAsset the asset with a minifier attached as "minify".
	 */
	protected function minifyAsset(string $path, array $properties = []): TAsset
	{
		$asset = new TFileAsset($path);
		$asset->attachBehavior('minify', ['class' => TAssetMinify::class] + $properties);
		$asset->getAssetFilePath();
		return $asset;
	}

	/**
	 * @param TAsset $asset the asset.
	 * @return array the finalizers the asset's behaviors add.
	 */
	protected static function finalizersOf(TAsset $asset): array
	{
		$param = new TAssetEventParameter('onProcessAsset', '/x', $asset);
		foreach ($asset->getBehaviors() as $behavior) {
			if (method_exists($behavior, 'addFinalizer')) {
				$behavior->addFinalizer($asset, $param);
			}
		}
		return $param->getFinalizers()->toArray();
	}

	public function testInterfacesAndConstants(): void
	{
		$minify = new TAssetMinify();

		self::assertInstanceOf(IAssetMatching::class, $minify);
		self::assertInstanceOf(IAssetFinalizer::class, $minify);
		self::assertSame(['onProcessAsset' => 'addFinalizer'], $minify->events());
		self::assertSame(['js' => 'js', 'mjs' => 'js', 'cjs' => 'js', 'css' => 'css'], TAssetMinify::LANGUAGES);
		self::assertLessThan(TAssetCompress::FINALIZER_PRIORITY, TAssetMinify::FINALIZER_PRIORITY, 'Minifying runs before compression.');
	}

	public function testMatchFiles(): void
	{
		$minify = new TAssetMinify();
		self::assertSame(TAssetMinify::DEFAULT_MATCH_FILES, $minify->getMatchFiles());

		$minify->setMatchFiles('/\.js$/');
		self::assertSame('/\.js$/', $minify->getMatchFiles());
		$minify->setMatchFiles('');
		self::assertNull($minify->getMatchFiles());
		$minify->setMatchFiles(null);
		self::assertNull($minify->getMatchFiles());
	}

	public function testLanguage(): void
	{
		self::assertSame('js', TAssetMinify::getLanguage('/a/app.js'));
		self::assertSame('js', TAssetMinify::getLanguage('/a/APP.JS'));
		self::assertSame('js', TAssetMinify::getLanguage('/a/module.mjs'));
		self::assertSame('js', TAssetMinify::getLanguage('/a/common.cjs'));
		self::assertSame('css', TAssetMinify::getLanguage('/a/site.Css'));
		self::assertNull(TAssetMinify::getLanguage('/a/notes.txt'));
		self::assertNull(TAssetMinify::getLanguage('/a/js'));
		self::assertNull(TAssetMinify::getLanguage(''));
		self::assertNull(TAssetMinify::getLanguage(null));
	}

	public function testMinifyUsesTheFrameworkMinifiers(): void
	{
		self::assertSame(TJavaScript::minify(self::JS), TAssetMinify::minify(self::JS, 'js'));
		self::assertSame(TStyleSheetHelper::minify(self::CSS), TAssetMinify::minify(self::CSS, 'css'));
		self::assertInstanceOf(TJavaScriptMinifier::class, TJavaScript::getMinifier());
		self::assertInstanceOf(TStyleSheetMinifier::class, TStyleSheetHelper::getMinifier());
	}

	public function testAddFinalizerMatchesScriptsAndStyleSheets(): void
	{
		$expected = [
			'app.js' => true,
			'site.CSS' => true,
			'app.min.js' => false,
			'site.MIN.css' => false,
			'notes.txt' => false,
			'app.json' => false,
		];
		foreach ($expected as $name => $applies) {
			$asset = $this->minifyAsset($this->writeSource($name));
			$finalizers = static::finalizersOf($asset);
			self::assertSame($applies ? [$asset->asa('minify')] : [], $finalizers, "$name is " . ($applies ? '' : 'not ') . 'minified.');
		}
	}

	public function testAddFinalizer(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		$minify = $asset->asa('minify');

		$param = new TAssetEventParameter('onProcessAsset', '/x', $asset);
		$minify->addFinalizer(null, $param);
		$minify->addFinalizer(new TComponent(), $param);
		$minify->addFinalizer($asset, null);
		self::assertCount(0, $param->getFinalizers(), 'A sender without an asset file path is not finalized.');

		$minify->addFinalizer($asset, $param);
		self::assertSame([$minify], $param->getFinalizers()->toArray());
		self::assertSame(TAssetMinify::FINALIZER_PRIORITY, $param->getFinalizers()->priorityOf($minify));
	}

	public function testAddFinalizerSkipsUnmatchedAndDisabled(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'), ['MatchFiles' => '/\.css$/']);
		self::assertSame([], static::finalizersOf($asset), 'An unmatched path is not minified.');

		$asset->asa('minify')->setMatchFiles('');
		self::assertSame([], static::finalizersOf($asset), 'No MatchFiles matches nothing.');

		$asset->asa('minify')->setMatchFiles('/\.js$/');
		$asset->asa('minify')->setEnabled(false);
		self::assertSame([], static::finalizersOf($asset), 'A disabled behavior is not a finalizer.');
	}

	public function testMatchingAFileOfAnotherLanguageDoesNotMinify(): void
	{
		$asset = $this->minifyAsset($source = $this->writeSource('notes.txt', self::JS), ['MatchFiles' => '/\.txt$/']);
		self::assertSame([], static::finalizersOf($asset));

		$asset->asa('minify')->finalize($source, new TAssetEventParameter());
		self::assertSame(self::JS, file_get_contents($source));
	}

	public function testMinifiedFilesAreSkippedWhateverMatchFilesMatches(): void
	{
		$asset = $this->minifyAsset($source = $this->writeSource('lib.min.js', self::JS), ['MatchFiles' => '/\.min\.js$/']);
		self::assertSame([], static::finalizersOf($asset));

		$asset->asa('minify')->finalize($source, new TAssetEventParameter());
		self::assertSame(self::JS, file_get_contents($source));
	}

	public function testMatchFilesMatchesTheVirtualFilePath(): void
	{
		$source = $this->writeSource('app.js', self::JS);
		$virtual = new TFileAsset($source, $this->srcDir . '/app.min.js');
		$virtual->attachBehavior('minify', TAssetMinify::class);
		$styled = new TFileAsset($source, $this->srcDir . '/app.css');
		$styled->attachBehavior('minify', TAssetMinify::class);

		self::assertSame([], static::finalizersOf($virtual), 'The virtual path is matched, not the source path.');

		file_put_contents($dst = $this->assetDir . '/out', self::CSS);
		$styled->asa('minify')->finalize($dst, new TAssetEventParameter());
		self::assertSame(TStyleSheetHelper::minify(self::CSS), file_get_contents($dst), 'The virtual path gives the language.');
	}

	public function testFinalizeJavaScript(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		file_put_contents($dst = $this->assetDir . '/out.js', self::JS);

		$asset->asa('minify')->finalize($dst, new TAssetEventParameter());

		$minified = file_get_contents($dst);
		self::assertSame(TJavaScript::minify(self::JS), $minified);
		self::assertLessThan(strlen(self::JS), strlen($minified));
		self::assertStringNotContainsString('Greets', $minified);
		self::assertStringContainsString("'Hello, '", $minified);
	}

	public function testFinalizeStyleSheet(): void
	{
		$asset = $this->minifyAsset($this->writeSource('site.css'));
		file_put_contents($dst = $this->assetDir . '/out.css', self::CSS);

		$asset->asa('minify')->finalize($dst, new TAssetEventParameter());

		$minified = file_get_contents($dst);
		self::assertSame(TStyleSheetHelper::minify(self::CSS), $minified);
		self::assertLessThan(strlen(self::CSS), strlen($minified));
		self::assertStringNotContainsString('The page', $minified);
	}

	public function testFinalizeSkipsMissingFilesAndDisabled(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		$minify = $asset->asa('minify');

		$minify->finalize('', new TAssetEventParameter());
		$minify->finalize($this->assetDir . '/missing.js', new TAssetEventParameter());
		$minify->finalize($this->assetDir, new TAssetEventParameter());
		self::assertFalse(is_file($this->assetDir . '/missing.js'));

		file_put_contents($dst = $this->assetDir . '/out.js', self::JS);
		$minify->setEnabled(false);
		$minify->finalize($dst, new TAssetEventParameter());
		self::assertSame(self::JS, file_get_contents($dst), 'A disabled behavior does not minify.');
	}

	public function testFinalizeWithoutAnOwner(): void
	{
		$minify = new TAssetMinify();
		file_put_contents($js = $this->assetDir . '/out.js', self::JS);
		file_put_contents($tmp = $this->assetDir . '/tmp-123', self::CSS);

		$minify->finalize($js, new TAssetEventParameter());
		self::assertSame(TJavaScript::minify(self::JS), file_get_contents($js), 'The written file gives the path without an asset.');

		$asset = new TFileAsset($this->writeSource('site.css'));
		$minify->finalize($tmp, new TAssetEventParameter('onProcessAsset', $tmp, $asset));
		self::assertSame(TStyleSheetHelper::minify(self::CSS), file_get_contents($tmp), 'The parameter asset gives the path.');
	}

	public function testFinalizeLeavesCodeThatDoesNotChange(): void
	{
		$minified = TJavaScript::minify(self::JS);
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		file_put_contents($dst = $this->assetDir . '/out.js', $minified);
		chmod($dst, 0o444);
		static::requirePermissions($dst, fn ($path) => !is_writable($path));

		try {
			$asset->asa('minify')->finalize($dst, new TAssetEventParameter());
		} finally {
			chmod($dst, 0o644);
		}
		self::assertSame($minified, file_get_contents($dst), 'Unchanged code is not written.');
	}

	public function testFinalizeThrowsWhenTheFileCannotBeRead(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		file_put_contents($dst = $this->assetDir . '/out.js', self::JS);
		chmod($dst, 0);
		static::requirePermissions($dst, fn ($path) => !is_readable($path));

		try {
			$asset->asa('minify')->finalize($dst, new TAssetEventParameter());
			self::fail('An unreadable file throws.');
		} catch (TIOException $e) {
			self::assertSame('assetminify_read_failed', $e->getErrorCode());
		} finally {
			chmod($dst, 0o644);
		}
	}

	public function testFinalizeThrowsWhenTheFileCannotBeWritten(): void
	{
		$asset = $this->minifyAsset($this->writeSource('app.js'));
		file_put_contents($dst = $this->assetDir . '/out.js', self::JS);
		chmod($dst, 0o444);
		static::requirePermissions($dst, fn ($path) => !is_writable($path));

		try {
			$asset->asa('minify')->finalize($dst, new TAssetEventParameter());
			self::fail('An unwritable file throws.');
		} catch (TIOException $e) {
			self::assertSame('assetminify_write_failed', $e->getErrorCode());
		} finally {
			chmod($dst, 0o644);
		}
		self::assertSame(self::JS, file_get_contents($dst));
	}

	public function testFinalizeUsesTheMinifiersSet(): void
	{
		TJavaScript::setMinifier($js = new TRecordingMinifier('/*js*/'));
		TStyleSheetHelper::setMinifier($css = new TRecordingMinifier('/*css*/'));
		$jsAsset = $this->minifyAsset($this->writeSource('app.js'));
		$cssAsset = $this->minifyAsset($this->writeSource('site.css'));
		file_put_contents($jsDst = $this->assetDir . '/out.js', self::JS);
		file_put_contents($cssDst = $this->assetDir . '/out.css', self::CSS);

		$jsAsset->asa('minify')->finalize($jsDst, new TAssetEventParameter());
		$cssAsset->asa('minify')->finalize($cssDst, new TAssetEventParameter());

		self::assertSame('/*js*/' . self::JS, file_get_contents($jsDst));
		self::assertSame('/*css*/' . self::CSS, file_get_contents($cssDst));
		self::assertSame([self::JS], $js->calls);
		self::assertSame([self::CSS], $css->calls);
	}

	public function testPublishMinifiesUnderTheSameName(): void
	{
		$this->attachClassBehavior('minify', TAssetMinify::class, TAsset::class);
		$manager = $this->newManager();

		$jsUrl = $manager->publish($this->writeSource('js/app.js', self::JS));
		$cssUrl = $manager->publish($this->writeSource('js/site.css', self::CSS));
		$minUrl = $manager->publish($this->writeSource('js/lib.min.js', self::JS));
		$txtUrl = $manager->publish($this->writeSource('js/notes.txt', self::JS));

		self::assertStringEndsWith('/app.js', $jsUrl);
		self::assertSame(TJavaScript::minify(self::JS), file_get_contents($this->urlToPath($jsUrl)));
		self::assertStringEndsWith('/site.css', $cssUrl);
		self::assertSame(TStyleSheetHelper::minify(self::CSS), file_get_contents($this->urlToPath($cssUrl)));
		self::assertStringEndsWith('/lib.min.js', $minUrl);
		self::assertSame(self::JS, file_get_contents($this->urlToPath($minUrl)), 'A minified file publishes as it is.');
		self::assertStringEndsWith('/notes.txt', $txtUrl);
		self::assertSame(self::JS, file_get_contents($this->urlToPath($txtUrl)));
		self::assertSame([], glob(dirname($this->urlToPath($jsUrl)) . '/tmp-*'), 'No temporary file is left.');
	}

	public function testPublishDirectoryKeepsTheNames(): void
	{
		$this->attachClassBehavior('minify', TAssetMinify::class, TAsset::class);
		$this->writeSource('bundle/app.js', self::JS);
		$this->writeSource('bundle/sub/site.css', self::CSS);
		$this->writeSource('bundle/vendor.min.js', self::JS);

		$dir = $this->urlToPath($this->newManager()->publish($this->srcDir . '/bundle'));

		self::assertSame(TJavaScript::minify(self::JS), file_get_contents($dir . '/app.js'));
		self::assertSame(TStyleSheetHelper::minify(self::CSS), file_get_contents($dir . '/sub/site.css'));
		self::assertSame(self::JS, file_get_contents($dir . '/vendor.min.js'));
	}

	public function testPublishWithACustomMinifier(): void
	{
		TJavaScript::setMinifier($js = new TRecordingMinifier());
		$this->attachClassBehavior('minify', TAssetMinify::class, TAsset::class);

		$url = $this->newManager()->publish($this->writeSource('app.js', self::JS));

		self::assertSame('/*min*/' . self::JS, file_get_contents($this->urlToPath($url)));
		self::assertSame([self::JS], $js->calls);
	}

	public function testPublishCompressesTheMinifiedCode(): void
	{
		if (!function_exists('gzdecode')) {
			self::markTestSkipped('The zlib extension is required.');
		}
		// Compression is attached first, so the finalizer priority, not the behavior order,
		// runs minifying first.
		$this->attachClassBehavior('gzip', ['class' => TAssetCompress::class, 'MatchFiles' => '/\.(js|css)$/i'], TAsset::class);
		$this->attachClassBehavior('minify', TAssetMinify::class, TAsset::class);
		$manager = $this->newManager();

		$jsUrl = $manager->publish($this->writeSource('app.js', self::JS));
		$cssUrl = $manager->publish($this->writeSource('site.css', self::CSS));

		self::assertStringEndsWith('/app.js.gz', $jsUrl);
		self::assertSame(TJavaScript::minify(self::JS), gzdecode(file_get_contents($this->urlToPath($jsUrl))));
		self::assertStringEndsWith('/site.css.gz', $cssUrl);
		self::assertSame(TStyleSheetHelper::minify(self::CSS), gzdecode(file_get_contents($this->urlToPath($cssUrl))));
	}
}
