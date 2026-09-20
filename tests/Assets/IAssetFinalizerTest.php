<?php

/**
 * IAssetFinalizerTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Assets\IAssetFinalizer;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TRecordingAssetFinalizer;
use Prado\Web\Tests\PublishingTestCase;

/**
 * IAssetFinalizerTest class.
 *
 * Tests the asset finalizer contract: its signature, and that a finalizer added during
 * onProcessAsset finalizes the written asset once processing is complete.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class IAssetFinalizerTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TRecordingAssetFinalizer::$journal = [];
	}

	public function testDeclaresFinalize(): void
	{
		$interface = new \ReflectionClass(IAssetFinalizer::class);
		self::assertTrue($interface->isInterface());
		self::assertSame(['finalize'], array_map(fn ($m) => $m->getName(), $interface->getMethods()));

		$parameters = $interface->getMethod('finalize')->getParameters();
		self::assertSame(['dstFile', 'param'], array_map(fn ($p) => $p->getName(), $parameters));
		self::assertSame('string', (string) $parameters[0]->getType());
		self::assertSame(TAssetEventParameter::class, (string) $parameters[1]->getType());
	}

	public function testFinalizerCompletesAPublishedAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$asset->content = 'body';
		$contentAtHandler = null;
		$asset->attachEventHandler('onProcessAsset', function ($sender, $param) use (&$contentAtHandler) {
			$param->addFinalizer(new TRecordingAssetFinalizer('footer', function ($dstFile) {
				file_put_contents($dstFile, "\nfooter", FILE_APPEND);
			}));
			$contentAtHandler = file_get_contents($param->getFilePath());
		});
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'a.txt';

		self::assertTrue($asset->publish($dst));

		self::assertSame('body', $contentAtHandler, 'The finalizer has not run during the handlers.');
		self::assertSame("body\nfooter", file_get_contents($dst));
		self::assertCount(1, TRecordingAssetFinalizer::$journal);
		[, $dstFile, $param] = TRecordingAssetFinalizer::$journal[0];
		self::assertSame($dst, $dstFile);
		self::assertSame($asset, $param->getAsset());
	}
}
