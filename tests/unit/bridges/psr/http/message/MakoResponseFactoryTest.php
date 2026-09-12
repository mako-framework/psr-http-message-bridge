<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\tests\unit\bridges\psr\http\message;

use mako\bridges\psr\http\message\MakoResponseFactory;
use mako\bridges\psr\http\message\MakoResponseHydrator;
use mako\http\Request;
use mako\http\Response;
use mako\security\signer\Signer;
use mako\tests\TestCase;
use Mockery;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

#[Group('unit')]
class MakoResponseFactoryTest extends TestCase
{
	/**
	 *
	 */
	public function testCreateReturnsHydratedResponse(): void
	{
		$request = Mockery::mock(Request::class);

		$psrResponse = new PsrResponse;

		$hydrator = Mockery::mock(MakoResponseHydrator::class);

		$hydrator->shouldReceive('hydrate')
			->once()
			->with(Mockery::type(Response::class), $psrResponse, false);

		$response = (new MakoResponseFactory($hydrator))->create($psrResponse, $request);

		$this->assertInstanceOf(Response::class, $response);

		$this->assertSame($request, $response->getRequest());
	}

	/**
	 *
	 */
	public function testCreatePassesStreamFlagToHydrator(): void
	{
		$request = Mockery::mock(Request::class);

		$psrResponse = new PsrResponse;

		$hydrator = Mockery::mock(MakoResponseHydrator::class);

		$hydrator->shouldReceive('hydrate')
			->once()
			->with(Mockery::type(Response::class), $psrResponse, true);

		(new MakoResponseFactory($hydrator))->create($psrResponse, $request, stream: true);
	}

	/**
	 *
	 */
	public function testHydratorReceivesTheReturnedResponse(): void
	{
		$request = Mockery::mock(Request::class);

		$psrResponse = Mockery::mock(ResponseInterface::class);

		$hydratedResponse = null;

		$hydrator = Mockery::mock(MakoResponseHydrator::class);

		$hydrator->shouldReceive('hydrate')
			->once()
			->with(Mockery::capture($hydratedResponse), $psrResponse, false);

		$response = (new MakoResponseFactory($hydrator))->create($psrResponse, $request);

		$this->assertSame($response, $hydratedResponse);
	}

	/**
	 *
	 */
	public function testCreateWithSigner(): void
	{
		$request = Mockery::mock(Request::class);

		$signer = Mockery::mock(Signer::class);

		$psrResponse = new PsrResponse;

		$hydrator = Mockery::mock(MakoResponseHydrator::class);

		$hydrator->shouldReceive('hydrate')->once();

		$response = (new MakoResponseFactory($hydrator))->create($psrResponse, $request, $signer);

		$this->assertInstanceOf(Response::class, $response);
	}
}
