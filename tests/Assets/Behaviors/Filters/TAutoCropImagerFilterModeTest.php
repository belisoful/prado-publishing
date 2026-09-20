<?php

/**
 * TAutoCropImagerFilterModeTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TEnumerable;
use Prado\TPropertyValue;
use Prado\Web\Assets\Behaviors\Filters\TAutoCropImagerFilterMode;
use Prado\Web\Tests\PublishingTestCase;

/**
 * TAutoCropImagerFilterModeTest class.
 *
 * Tests the auto-crop mode enumerable: its modes and their case-insensitive
 * resolution as a property value.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TAutoCropImagerFilterModeTest extends PublishingTestCase
{
	public function testModes(): void
	{
		self::assertTrue(is_subclass_of(TAutoCropImagerFilterMode::class, TEnumerable::class));
		self::assertSame(
			['Default' => 'Default', 'Transparent' => 'Transparent', 'Black' => 'Black', 'White' => 'White', 'Sides' => 'Sides', 'Threshold' => 'Threshold'],
			(new \ReflectionClass(TAutoCropImagerFilterMode::class))->getConstants()
		);
	}

	public function testModesResolveCaseInsensitively(): void
	{
		self::assertSame(TAutoCropImagerFilterMode::Sides, TPropertyValue::ensureEnum('sides', TAutoCropImagerFilterMode::class));
		self::assertSame(TAutoCropImagerFilterMode::Threshold, TPropertyValue::ensureEnum('THRESHOLD', TAutoCropImagerFilterMode::class));

		$this->expectException(TInvalidDataValueException::class);
		TPropertyValue::ensureEnum('Diagonal', TAutoCropImagerFilterMode::class);
	}
}
