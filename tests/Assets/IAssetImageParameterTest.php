<?php

/**
 * IAssetImageParameterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Assets\IAssetImageParameter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TRecordingAssetFinalizer;
use Prado\Web\Tests\PublishingTestCase;

/**
 * IAssetImageParameterTest class.
 *
 * Tests the image parameter contract: its accessors, and that the image it carries is
 * shared by the handlers and finalizers of a single publish.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class IAssetImageParameterTest extends PublishingTestCase
{
	public function testDeclaresTheImageAccessors(): void
	{
		$interface = new \ReflectionClass(IAssetImageParameter::class);

		self::assertTrue($interface->isInterface());
		$methods = array_map(fn ($m) => $m->getName(), $interface->getMethods());
		sort($methods);
		self::assertSame(['getImage', 'setImage'], $methods);
		self::assertSame(0, $interface->getMethod('getImage')->getNumberOfParameters());
		self::assertSame(1, $interface->getMethod('setImage')->getNumberOfRequiredParameters());
		self::assertTrue(is_a(TAssetEventParameter::class, IAssetImageParameter::class, true));
	}

	public function testTheImageIsSharedThroughoutAPublish(): void
	{
		$source = $this->writeImage('photo.png', 40, 20);
		$asset = new TImageAsset($source);
		$asset->attachEventHandler('onProcessAsset', function ($sender, IAssetImageParameter $param) {
			$param->setImage(imagecreatefrompng($param->getFilePath()));
			$param->addFinalizer(new TRecordingAssetFinalizer('save', function ($dstFile, IAssetImageParameter $param) {
				imagepng($param->getImage(), $dstFile);
			}));
		});
		$asset->attachEventHandler('onProcessAsset', function ($sender, IAssetImageParameter $param) {
			$image = $param->getImage();
			imagefilledrectangle($image, 0, 0, 39, 19, 0x00FF00);
		});
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . 'photo.png';

		self::assertTrue($asset->publish($dst));

		$published = imagecreatefrompng($dst);
		self::assertSame(0x00FF00, static::rgbAt($published, 0, 0), 'The second handler changed the image the finalizer saved.');
		self::assertSame(0xFF0000, static::rgbAt(imagecreatefrompng($source), 0, 0), 'The source is unchanged.');
	}
}
