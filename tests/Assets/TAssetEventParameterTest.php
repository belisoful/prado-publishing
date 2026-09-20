<?php

/**
 * TAssetEventParameterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Collections\TPriorityList;
use Prado\TEventParameter;
use Prado\Web\Assets\IAssetImageParameter;
use Prado\Web\Assets\TAssetEventParameter;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TRecordingAssetFinalizer;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetEventParameterTest class.
 *
 * Tests the onProcessAsset event parameter: its properties, the shared image and image
 * save flags, and the finalizers run in priority order.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetEventParameterTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TRecordingAssetFinalizer::$journal = [];
	}

	public function testIsAnImageEventParameter(): void
	{
		$param = new TAssetEventParameter();
		self::assertInstanceOf(TEventParameter::class, $param);
		self::assertInstanceOf(IAssetImageParameter::class, $param);
	}

	public function testDefaults(): void
	{
		$param = new TAssetEventParameter();

		self::assertSame('', $param->getName());
		self::assertSame('', $param->getFilePath());
		self::assertNull($param->getAsset());
		self::assertNull($param->getImage());
		self::assertSame(0, $param->getOriginalImageType());
		self::assertSame(0, $param->getImageType());
		self::assertSame(0, $param->getPaletteColors());
		self::assertFalse($param->getSaveImage());
		self::assertInstanceOf(TPriorityList::class, $param->getFinalizers());
		self::assertSame(0, $param->getFinalizers()->getCount());
	}

	public function testConstructorSetsTheNameFilePathAndAsset(): void
	{
		$asset = new TGeneratedAsset('/virtual/a.txt');
		$param = new TAssetEventParameter('onProcessAsset', '/dst/a.txt', $asset);

		self::assertSame('onProcessAsset', $param->getName());
		self::assertSame('/dst/a.txt', $param->getFilePath());
		self::assertSame($asset, $param->getAsset());
	}

	public function testPropertiesRoundTrip(): void
	{
		$param = new TAssetEventParameter();
		$asset = new TGeneratedAsset();
		$image = static::createImage(4, 4);

		$param->setName('custom');
		$param->setFilePath($file = $this->tempDir . '/file.png');
		$param->setAsset($asset);
		$param->setImage($image);
		$param->setOriginalImageType(IMAGETYPE_JPEG);
		$param->setImageType(IMAGETYPE_PNG);
		$param->setPaletteColors(256);
		$param->setSaveImage(true);

		self::assertSame('custom', $param->getName());
		self::assertSame($file, $param->getFilePath());
		self::assertSame($asset, $param->getAsset());
		self::assertSame($image, $param->getImage());
		self::assertSame(IMAGETYPE_JPEG, $param->getOriginalImageType());
		self::assertSame(IMAGETYPE_PNG, $param->getImageType());
		self::assertSame(256, $param->getPaletteColors());
		self::assertTrue($param->getSaveImage());

		$param->setAsset(null);
		$param->setImage(null);
		$param->setSaveImage(false);
		self::assertNull($param->getAsset());
		self::assertNull($param->getImage());
		self::assertFalse($param->getSaveImage());
	}

	public static function appendSaveImageProvider(): array
	{
		return [
			'false, false' => [false, false, false],
			'false, true' => [false, true, true],
			'true, false' => [true, false, true],
			'true, true' => [true, true, true],
		];
	}

	/**
	 * @dataProvider appendSaveImageProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('appendSaveImageProvider')]
	public function testAppendSaveImageOnlyTurnsSavingOn(bool $initial, bool $append, bool $expected): void
	{
		$param = new TAssetEventParameter();
		$param->setSaveImage($initial);

		$param->appendSaveImage($append);

		self::assertSame($expected, $param->getSaveImage());
	}

	public function testFinalizersAreCreatedOnce(): void
	{
		$param = new TAssetEventParameter();
		self::assertSame($param->getFinalizers(), $param->getFinalizers());
	}

	public function testAddFinalizerUsesThePriority(): void
	{
		$param = new TAssetEventParameter();
		$default = new TRecordingAssetFinalizer('default');
		$early = new TRecordingAssetFinalizer('early');

		$param->addFinalizer($default);
		$param->addFinalizer($early, 1.5);

		$finalizers = $param->getFinalizers();
		self::assertSame([$early, $default], $finalizers->toArray());
		self::assertEquals($finalizers->getDefaultPriority(), $finalizers->priorityOf($default));
		self::assertEquals(1.5, $finalizers->priorityOf($early));
	}

	public function testAddFinalizerRequiresAnIAssetFinalizer(): void
	{
		$this->expectException(\TypeError::class);
		(new TAssetEventParameter())->addFinalizer(new \stdClass());
	}

	public function testFinalizeCallsEachFinalizerInPriorityThenInsertionOrder(): void
	{
		$param = new TAssetEventParameter('onProcessAsset', '/dst/file.png');
		$param->addFinalizer(new TRecordingAssetFinalizer('b10'));
		$param->addFinalizer(new TRecordingAssetFinalizer('c20'), 20);
		$param->addFinalizer(new TRecordingAssetFinalizer('a0'), 0);
		$param->addFinalizer(new TRecordingAssetFinalizer('d10'), 10);

		$param->finalize();

		self::assertSame(['a0', 'b10', 'd10', 'c20'], array_column(TRecordingAssetFinalizer::$journal, 0));
		foreach (TRecordingAssetFinalizer::$journal as [$label, $dstFile, $received]) {
			self::assertSame('/dst/file.png', $dstFile);
			self::assertSame($param, $received);
		}
	}

	public function testFinalizeUsesTheCurrentFilePath(): void
	{
		$param = new TAssetEventParameter('onProcessAsset', '/dst/original.png');
		$param->addFinalizer(new TRecordingAssetFinalizer());
		$param->setFilePath('/dst/renamed.png');

		$param->finalize();

		self::assertSame('/dst/renamed.png', TRecordingAssetFinalizer::$journal[0][1]);
	}

	public function testFinalizeWithoutFinalizersDoesNothing(): void
	{
		$param = new TAssetEventParameter();
		$param->finalize();
		self::assertSame([], TRecordingAssetFinalizer::$journal);
	}

	public function testFinalizersSeeTheSharedImageState(): void
	{
		$param = new TAssetEventParameter('onProcessAsset', $dst = $this->tempDir . DIRECTORY_SEPARATOR . 'out.png');
		$param->setImage(static::createImage(8, 6));
		$param->setImageType(IMAGETYPE_PNG);
		$param->appendSaveImage(true);
		$param->addFinalizer(new TRecordingAssetFinalizer('saver', function ($dstFile, $param) {
			if ($param->getSaveImage() && $param->getImageType() === IMAGETYPE_PNG) {
				imagepng($param->getImage(), $dstFile);
			}
		}));

		$param->finalize();

		$info = getimagesize($dst);
		self::assertSame([8, 6, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
	}

	public function testFinalizersAddedDuringFinalizeAreNotRun(): void
	{
		$param = new TAssetEventParameter();
		$param->addFinalizer(new TRecordingAssetFinalizer('first', function ($dstFile, $param) {
			$param->addFinalizer(new TRecordingAssetFinalizer('added'));
		}));

		$param->finalize();

		self::assertSame(['first'], array_column(TRecordingAssetFinalizer::$journal, 0));
		self::assertSame(2, $param->getFinalizers()->getCount());
	}

	public function testFinalizeCanRunAgain(): void
	{
		$param = new TAssetEventParameter();
		$param->addFinalizer(new TRecordingAssetFinalizer());

		$param->finalize();
		$param->finalize();

		self::assertCount(2, TRecordingAssetFinalizer::$journal);
	}
}
