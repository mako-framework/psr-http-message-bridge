<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\tests\unit\bridges\psr\http\message;

use mako\bridges\psr\http\message\MakoResponseHydrator;
use mako\http\Request;
use mako\http\Response;
use mako\http\response\CustomStatus;
use mako\http\response\senders\Stream;
use mako\tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\Stream as PsrStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[Group('unit')]
class MakoResponseHydratorTest extends TestCase
{
	/**
	 *
	 */
	protected function request(string $method = 'GET'): Request
	{
		$request = Mockery::mock(Request::class);
		$request->shouldReceive('getMethod')->andReturn($method);

		return $request;
	}

	/**
	 *
	 */
	protected function response(
		int $status = 200,
		string $reason = 'OK',
		string $protocol = '1.1',
		array $headers = [],
		string $method = 'GET'
	): MockInterface&Response {
		$response = Mockery::mock(Response::class);

		$response->shouldReceive('getRequest')->andReturn($this->request($method));

		$response->shouldReceive('setProtocolVersion')
			->once()->with($protocol)->andReturnSelf();

		$response->shouldReceive('setStatus')
			->once()
			->with(Mockery::on(
				static fn ($value) => $value == new CustomStatus($status, $reason)
			))
			->andReturnSelf();

		$headerBag = $this->mockProperty($response, 'headers');

		if ($headers === []) {
			$headerBag->shouldNotReceive('add');
		}
		else {
			foreach ($headers as $name => $values) {
				foreach ($values as $value) {
					$headerBag->shouldReceive('add')
						->once()->with($name, $value, false);
				}
			}
		}

		return $response;
	}

	/**
	 *
	 */
	public function testCopiesMetadataAndReadsEntireSeekableBody(): void
	{
		$headers = [
			'X-Test' => ['first', 'second'],
			'Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/'],
		];

		$body = PsrStream::create('complete body');
		$body->seek(5);

		$psrResponse = new PsrResponse(201, $headers, $body, '2', 'Made');
		$response = $this->response(201, 'Made', '2', $headers);
		$response->shouldReceive('setBody')
			->once()->with('complete body')->andReturnSelf();

		(new MakoResponseHydrator)->hydrate($response, $psrResponse);

		$this->assertSame(5, $body->tell());
	}

	/**
	 *
	 */
	public function testReadsNonSeekableBodyWithoutRewinding(): void
	{
		$body = Mockery::mock(StreamInterface::class);
		$body->shouldReceive('isSeekable')->once()->andReturnFalse();
		$body->shouldReceive('getContents')->once()->andReturn('remaining');
		$body->shouldNotReceive('tell');
		$body->shouldNotReceive('rewind');
		$body->shouldNotReceive('seek');

		$response = $this->response();
		$response->shouldReceive('setBody')
			->once()->with('remaining')->andReturnSelf();

		(new MakoResponseHydrator)->hydrate(
			$response,
			new PsrResponse(200, [], $body)
		);
	}

	/**
	 *
	 */
	public function testRestoresCursorWhenReadingThrows(): void
	{
		$exception = new RuntimeException('Read failed');

		$body = Mockery::mock(StreamInterface::class);
		$body->shouldReceive('isSeekable')->once()->andReturnTrue();
		$body->shouldReceive('tell')->once()->andReturn(7);
		$body->shouldReceive('rewind')->once()->ordered();
		$body->shouldReceive('getContents')->once()->ordered()->andThrow($exception);
		$body->shouldReceive('seek')->once()->with(7)->ordered();

		$response = $this->response();
		$response->shouldNotReceive('setBody');

		$this->expectExceptionObject($exception);

		(new MakoResponseHydrator)->hydrate(
			$response,
			new PsrResponse(200, [], $body)
		);
	}

	/**
	 *
	 */
	public static function bodylessResponses(): iterable
	{
		foreach ([false, true] as $stream) {
			foreach ([
				['HEAD', 200],
				['GET', 100],
				['GET', 101],
				['GET', 199],
				['GET', 204],
				['GET', 205],
				['GET', 304],
			] as [$method, $status]) {
				yield "{$method} {$status} stream=" . (int) $stream => [
					$method, $status, $stream,
				];
			}
		}
	}

	/**
	 *
	 */
	#[DataProvider('bodylessResponses')]
	public function testSuppressesBody(
		string $method,
		int $status,
		bool $stream
	): void {
		$body = Mockery::mock(StreamInterface::class);
		$body->shouldNotReceive('isSeekable');
		$body->shouldNotReceive('getContents');
		$body->shouldNotReceive('rewind');
		$body->shouldNotReceive('read');

		$psrResponse = new PsrResponse($status, [], $body);
		$response = $this->response($status, $psrResponse->getReasonPhrase(), method: $method);
		$response->shouldReceive('setBody')->once()->with('')->andReturnSelf();

		(new MakoResponseHydrator)->hydrate(
			$response,
			$psrResponse,
			$stream
		);
	}

	/**
	 *
	 */
	public function testStreamingDefersReadingTheBody(): void
	{
		$body = Mockery::mock(StreamInterface::class);
		$body->shouldNotReceive('isSeekable');
		$body->shouldNotReceive('rewind');
		$body->shouldNotReceive('getContents');
		$body->shouldNotReceive('eof');
		$body->shouldNotReceive('read');

		$response = $this->response();
		$response->shouldReceive('setBody')
			->once()->with(Mockery::type(Stream::class))->andReturnSelf();

		(new MakoResponseHydrator)->hydrate(
			$response,
			new PsrResponse(200, [], $body),
			stream: true
		);
	}
}
