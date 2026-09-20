<?php

/**
 * TImageAssetTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Assets\TFileAsset;
use Prado\Web\Assets\TImageAsset;
use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TImageAssetTest class.
 *
 * Tests the image file asset: it is a file asset, it publishes image files unchanged,
 * and it is the attachment point for image-only class behaviors.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImageAssetTest extends PublishingTestCase
{
	public function testIsARealFileAsset(): void
	{
		$asset = new TImageAsset();
		self::assertInstanceOf(TFileAsset::class, $asset);
		self::assertFalse($asset->getIsVirtual());
	}

	public function testResolvesTheImagePath(): void
	{
		$source = $this->writeImage('img/photo.png');
		$asset = new TImageAsset($this->srcDir . '/img/../img/photo.png');

		self::assertSame($source, $asset->getAssetOriginalFilePath());
		self::assertSame($source, $asset->getAssetFilePath());
		self::assertSame(filemtime($source), $asset->getAssetModificationDate());
	}

	public static function formatProvider(): array
	{
		return [
			'png' => ['png', IMAGETYPE_PNG],
			'jpeg' => ['jpg', IMAGETYPE_JPEG],
			'gif' => ['gif', IMAGETYPE_GIF],
		];
	}

	/**
	 * @dataProvider formatProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('formatProvider')]
	public function testPublishesTheImageUnchanged(string $extension, int $type): void
	{
		$source = $this->writeImage("photo.$extension", 32, 16);
		$dst = $this->tempDir . DIRECTORY_SEPARATOR . "published.$extension";

		self::assertTrue((new TImageAsset($source))->publish($dst));

		self::assertSame(sha1_file($source), sha1_file($dst));
		$info = getimagesize($dst);
		self::assertSame([32, 16, $type], [$info[0], $info[1], $info[2]]);
	}

	public function testImageClassBehaviorsDoNotApplyToOtherFileAssets(): void
	{
		$this->attachClassBehavior('imageOnly', ['class' => TAssetPathFilterBehavior::class, 'rewriteFilter' => fn ($path) => $path . '.webp'], TImageAsset::class);
		$image = $this->writeImage('photo.png');
		$text = $this->writeSource('notes.txt');

		self::assertSame($image . '.webp', (new TImageAsset($image))->getAssetFilePath());
		self::assertSame($text, (new TFileAsset($text))->getAssetFilePath());
	}

	public function testFileClassBehaviorsApplyToImageAssets(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.v1'], TFileAsset::class);
		$image = $this->writeImage('photo.png');

		self::assertSame($image . '.v1', (new TImageAsset($image))->dyAlterAssetFilePath($image));
	}

	public function testManagerPublishesImagesThroughImageBehaviors(): void
	{
		$this->attachClassBehavior('suffix', ['class' => TSuffixAssetBehavior::class, 'Suffix' => '.img'], TImageAsset::class);
		$image = $this->writeImage('photo.png');
		$text = $this->writeSource('notes.txt', 'text');
		$manager = $this->newManager();

		$imageUrl = $manager->publish($image);
		$textUrl = $manager->publish($text);

		self::assertStringEndsWith('/photo.png.img', $imageUrl);
		self::assertSame(sha1_file($image), sha1_file($this->urlToPath($imageUrl)));
		self::assertStringEndsWith('/notes.txt', $textUrl);
		self::assertInstanceOf(TImageAsset::class, $manager->getPublishedAssets()[$image]);
	}
}
