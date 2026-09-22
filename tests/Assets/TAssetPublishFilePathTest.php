<?php

/**
 * TAssetPublishFilePathTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets;

use Prado\Web\Tests\Fixtures\TAssetPathFilterBehavior;
use Prado\Web\Tests\Fixtures\TGeneratedAsset;
use Prado\Web\Tests\Fixtures\TSuffixAssetBehavior;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAssetPublishFilePathTest class.
 *
 * Tests TAsset::getAssetPublishFilePath: a file path is filtered through
 * dyAlterAssetFilePath, while an unset (false), cancelled (null), or empty asset file
 * path is returned as it is.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAssetPublishFilePathTest extends PublishingTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TAssetPathFilterBehavior::$journal = [];
	}

	/**
	 * @param ?string $path the asset file path.
	 * @param array<string, mixed> $filter the path filter behavior properties.
	 * @return TGeneratedAsset the asset, with a ".renamed" suffix behavior and a path filter.
	 */
	protected static function asset(?string $path, array $filter = []): TGeneratedAsset
	{
		$asset = new TGeneratedAsset();
		$behavior = new TAssetPathFilterBehavior();
		foreach ($filter as $name => $value) {
			$behavior->$name = $value;
		}
		$asset->attachBehavior('filter', $behavior);
		$asset->attachBehavior('suffix', TSuffixAssetBehavior::class);
		if ($path !== null) {
			$asset->setAssetFilePath($path);
		}
		return $asset;
	}

	public function testAFilePathIsAltered(): void
	{
		$asset = static::asset('/virtual/data.json');

		self::assertSame('/virtual/data.json', $asset->getAssetFilePath());
		self::assertSame('/virtual/data.json.renamed', $asset->getAssetPublishFilePath());
	}

	public function testAnUnsetFilePathIsFalse(): void
	{
		$asset = static::asset(null);

		self::assertFalse($asset->getAssetFilePath());
		self::assertFalse($asset->getAssetPublishFilePath());
	}

	public function testACancelledFilePathIsNull(): void
	{
		$asset = static::asset('/virtual/data.json', ['postFilter' => fn ($path) => null]);

		self::assertNull($asset->getAssetFilePath());
		self::assertNull($asset->getAssetPublishFilePath());
	}

	public function testAnEmptyFilePathIsNotAltered(): void
	{
		$asset = static::asset('/virtual/data.json', ['rewriteFilter' => fn ($path) => '']);

		self::assertSame('', $asset->getAssetFilePath());
		self::assertSame('', $asset->getAssetPublishFilePath());
	}

	public function testARewrittenFalseFilePathIsNotAltered(): void
	{
		$asset = static::asset('/virtual/data.json', ['rewriteFilter' => fn ($path) => false]);

		self::assertFalse($asset->getAssetPublishFilePath());
	}

	public function testThePublishPathIsNotCachedByDefault(): void
	{
		$asset = static::asset('/virtual/data.json');
		$suffix = $asset->asa('suffix');
		self::assertFalse($asset->getCachePublishFilePath());

		self::assertSame('/virtual/data.json.renamed', $asset->getAssetPublishFilePath());
		$suffix->suffix = '.again';
		self::assertSame('/virtual/data.json.again', $asset->getAssetPublishFilePath(), 'The behaviors rename on every read.');
		self::assertSame(2, $suffix->alterCount);
	}

	public function testACachedPublishPathIsComputedOnce(): void
	{
		$asset = static::asset('/virtual/data.json');
		$suffix = $asset->asa('suffix');
		$asset->setCachePublishFilePath('true');
		self::assertTrue($asset->getCachePublishFilePath());

		self::assertSame('/virtual/data.json.renamed', $asset->getAssetPublishFilePath());
		$suffix->suffix = '.again';
		self::assertSame('/virtual/data.json.renamed', $asset->getAssetPublishFilePath(), 'The cached path is reused.');
		self::assertSame(1, $suffix->alterCount);

		$asset->resetFilePathCache();
		self::assertSame('/virtual/data.json.again', $asset->getAssetPublishFilePath(), 'Resetting the cache renames again.');
		self::assertSame(2, $suffix->alterCount);

		$asset->setAssetFilePath('/virtual/other.json');
		self::assertSame('/virtual/other.json.again', $asset->getAssetPublishFilePath(), 'A new file path renames again.');

		$asset->attachBehavior('another', new TSuffixAssetBehavior());
		self::assertSame('/virtual/other.json.again.renamed', $asset->getAssetPublishFilePath(), 'Attaching a behavior renames again.');

		$asset->setCachePublishFilePath(false);
		$suffix->suffix = '.third';
		self::assertSame('/virtual/other.json.third.renamed', $asset->getAssetPublishFilePath(), 'Turning the cache off drops it.');
	}

	public function testAnInvalidCachedPublishPathIsComputedOnce(): void
	{
		$asset = static::asset('/virtual/data.json');
		$suffix = $asset->asa('suffix');
		$suffix->invalid = true;
		$asset->setCachePublishFilePath(true);

		self::assertFalse($asset->getAssetPublishFilePath());
		self::assertFalse($asset->getAssetPublishFilePath(), 'The cached invalid path is reused.');
		self::assertSame(1, $suffix->alterCount, 'An invalid publish path is cached as well as a valid one.');

		$asset->resetFilePathCache();
		$suffix->invalid = false;
		self::assertSame('/virtual/data.json.renamed', $asset->getAssetPublishFilePath(), 'Resetting the cache renames again.');
		self::assertSame(2, $suffix->alterCount);
	}

	public function testACancelledPublishPathIsNotCached(): void
	{
		$asset = static::asset('/virtual/data.json', ['rewriteFilter' => fn ($path) => null]);
		$asset->setCachePublishFilePath(true);
		$suffix = $asset->asa('suffix');

		self::assertNull($asset->getAssetPublishFilePath());
		self::assertNull($asset->getAssetPublishFilePath());
		self::assertSame(0, $suffix->alterCount, 'A cancelled path is never renamed, so there is no publish path to cache.');
		self::assertSame(1, count(array_filter(TAssetPathFilterBehavior::$journal, fn ($entry) => $entry[0] === 'rewrite:filter')), 'The cancelled file path is held by the file path cache.');
	}
}
