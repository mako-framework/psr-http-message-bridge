<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\tests;

use IteratorAggregate;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

/**
 * Base test case.
 */
abstract class TestCase extends PHPUnitTestCase
{
	use MockeryPHPUnitIntegration;

	/**
	 *
	 */
	private function mockDeclaredType(?ReflectionType $type): MockInterface
	{
		return Mockery::mock(
			$type instanceof ReflectionNamedType && !$type->isBuiltin()
				? $type->getName()
				: IteratorAggregate::class
		);
	}

	/**
	 *
	 */
	protected function mockProperty(object $object, string $name): MockInterface
	{
		$property = new ReflectionProperty($object, $name);

		$mock = $this->mockDeclaredType($property->getType());

		$property->setValue($object, $mock);

		return $mock;
	}

	/**
	 *
	 */
	protected function mockMethodResult(MockInterface $object, string $method): MockInterface
	{
		$reflection = new ReflectionMethod($object, $method);

		$mock = $this->mockDeclaredType($reflection->getReturnType());

		$object->shouldReceive($method)->andReturn($mock)->byDefault();

		return $mock;
	}
}
