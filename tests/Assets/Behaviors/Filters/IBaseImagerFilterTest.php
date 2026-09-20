<?php

/**
 * IBaseImagerFilterTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Assets\Behaviors\Filters;

use Prado\Web\Assets\Behaviors\Filters\IBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TBaseImagerFilter;
use Prado\Web\Assets\Behaviors\Filters\TImagerFilterFactory;
use Prado\Web\Tests\PublishingTestCase;

/**
 * IBaseImagerFilterTest class.
 *
 * Tests the interface of imager filters: it declares Enabled, the graphics mode, and
 * filterImage; the base filter implements it, and so does every built in filter type's class.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class IBaseImagerFilterTest extends PublishingTestCase
{
	public function testDeclaresEnabledGraphicsModeAndFilterImage(): void
	{
		$reflection = new \ReflectionClass(IBaseImagerFilter::class);

		self::assertTrue($reflection->isInterface());
		self::assertSame(['getEnabled', 'getGraphicsMode', 'filterImage'], array_map(fn ($m) => $m->getName(), $reflection->getMethods()));
		self::assertSame([], $reflection->getConstants());
	}

	public function testEveryImagerFilterImplementsTheInterface(): void
	{
		self::assertTrue(is_subclass_of(TBaseImagerFilter::class, IBaseImagerFilter::class));
		foreach (TImagerFilterFactory::TYPES as $type => $class) {
			self::assertTrue(is_subclass_of($class, IBaseImagerFilter::class), "The '$type' filter class $class implements IBaseImagerFilter.");
			self::assertTrue(is_subclass_of($class, TBaseImagerFilter::class), "The '$type' filter class $class extends TBaseImagerFilter.");
		}
	}
}
