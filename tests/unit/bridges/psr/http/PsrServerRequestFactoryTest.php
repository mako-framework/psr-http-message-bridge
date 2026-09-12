<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\tests\unit\bridges\psr\http;

use ArrayIterator;
use mako\bridges\psr\http\PsrServerRequestFactory;
use mako\http\Request;
use mako\http\request\UploadedFile;
use mako\tests\TestCase;
use Mockery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('unit')]
class PsrServerRequestFactoryTest extends TestCase
{
	/**
	 *
	 */
	protected function factory(): PsrServerRequestFactory
	{
		$factory = new Psr17Factory;

		return new PsrServerRequestFactory($factory, $factory, $factory, $factory);
	}

	/**
	 *
	 */
	protected function request(
		array $server = [],
		string $method = 'GET',
		string $contentType = 'application/json',
		array $query = [],
		array $post = [],
		array $body = [],
		array $headers = [],
		array $cookies = [],
		array $files = [],
		array $attributes = [],
		string $rawBody = ''
	): Request {
		$request = Mockery::mock(Request::class);

		$request->shouldReceive('getMethod')->andReturn($method);
		$request->shouldReceive('getBaseURL')
			->andReturn('https://example.com/base?old=1#fragment');
		$request->shouldReceive('getContentType')->andReturn($contentType);
		$request->shouldReceive('getRawBody')->andReturn($rawBody);
		$request->shouldReceive('getAttributes')->andReturn($attributes);

		$serverBag = $this->mockProperty($request, 'server');
		$serverBag->shouldReceive('all')->andReturn($server);
		$serverBag->shouldReceive('get')
			->andReturnUsing(static fn (string $key, mixed $default = null) => $server[$key] ?? $default
			);

		foreach (['query' => $query, 'post' => $post, 'files' => $files] as $name => $values) {
			$this->mockProperty($request, $name)
				->shouldReceive('all')->andReturn($values);
		}

		foreach (['headers' => $headers, 'cookies' => $cookies] as $name => $values) {
			$this->mockProperty($request, $name)
				->shouldReceive('getIterator')
				->andReturnUsing(static fn () => new ArrayIterator($values));
		}

		$this->mockMethodResult($request, 'getBody')
			->shouldReceive('all')->andReturn($body);

		return $request;
	}

	/**
	 *
	 */
	public function testCopiesRequestData(): void
	{
		$server = [
			'REQUEST_METHOD' => 'PATCH',
			'REQUEST_URI' => '/items%2F42?tag=a&tag=b',
			'SERVER_PROTOCOL' => 'HTTP/2',
			'REMOTE_ADDR' => '127.0.0.1',
		];

		$attribute = new \stdClass;

		$result = $this->factory()->create($this->request(
			server: $server,
			method: 'PATCH',
			query: ['tag' => ['a', 'b']],
			body: ['enabled' => true],
			headers: [
				'Content_Type' => ['application/json'],
				'X_Custom' => ['first', 'second'],
			],
			cookies: ['session' => 'abc'],
			attributes: ['context' => $attribute],
			rawBody: '{"enabled":true}'
		));

		$this->assertSame('PATCH', $result->getMethod());
		$this->assertSame('2', $result->getProtocolVersion());
		$this->assertSame($server, $result->getServerParams());
		$this->assertSame($server['REQUEST_URI'], $result->getRequestTarget());
		$this->assertSame(
			'https://example.com/items%2F42?tag=a&tag=b',
			(string) $result->getUri()
		);
		$this->assertSame(['application/json'], $result->getHeader('Content-Type'));
		$this->assertSame(['first', 'second'], $result->getHeader('X-Custom'));
		$this->assertSame('{"enabled":true}', (string) $result->getBody());
		$this->assertSame(['tag' => ['a', 'b']], $result->getQueryParams());
		$this->assertSame(['session' => 'abc'], $result->getCookieParams());
		$this->assertSame(['enabled' => true], $result->getParsedBody());
		$this->assertSame($attribute, $result->getAttribute('context'));
		$this->assertSame([], $result->getUploadedFiles());
	}

	/**
	 *
	 */
	public static function requestTargets(): iterable
	{
		yield 'encoded path' => [
			'GET', '/a%2Fb?x=a%20b', 'example.com', '/a%2Fb', 'x=a%20b',
		];

		yield 'leading double slash remains a path' => [
			'GET', '//other.example/path?q=1',
			'example.com', '/other.example/path', 'q=1',
		];

		yield 'absolute form' => [
			'GET', 'https://proxy.example:8443/a%2Fb?q=1',
			'proxy.example', '/a%2Fb', 'q=1',
		];

		yield 'asterisk form' => [
			'OPTIONS', '*', 'example.com', '', '',
		];

		yield 'CONNECT authority form' => [
			'CONNECT', 'upstream.example:443', 'example.com', '', '',
		];

		yield 'empty query' => [
			'GET', '/path?', 'example.com', '/path', '',
		];
	}

	/**
	 *
	 */
	#[DataProvider('requestTargets')]
	public function testPreservesRequestTarget(
		string $method,
		string $target,
		string $host,
		string $path,
		string $query
	): void {
		$result = $this->factory()->create($this->request(
			server: ['REQUEST_URI' => $target],
			method: $method
		));

		$this->assertSame($target, $result->getRequestTarget());
		$this->assertSame($host, $result->getUri()->getHost());
		$this->assertSame($path, $result->getUri()->getPath());
		$this->assertSame($query, $result->getUri()->getQuery());
		$this->assertSame('', $result->getUri()->getFragment());
	}

	/**
	 *
	 */
	public function testUsesDefaultsWhenServerValuesAreMissing(): void
	{
		$result = $this->factory()->create($this->request());

		$this->assertSame('/', $result->getRequestTarget());
		$this->assertSame('https://example.com/', (string) $result->getUri());
		$this->assertSame('1.1', $result->getProtocolVersion());
	}

	/**
	 *
	 */
	public function testAcceptsProtocolWithoutHttpPrefix(): void
	{
		$result = $this->factory()->create($this->request(
			server: ['SERVER_PROTOCOL' => '2']
		));

		$this->assertSame('2', $result->getProtocolVersion());
	}

	/**
	 *
	 */
	public static function parsedBodies(): iterable
	{
		yield 'urlencoded POST' => [
			'POST', 'POST', 'application/x-www-form-urlencoded', ['post' => true],
		];

		yield 'multipart POST' => [
			'POST', 'POST', 'multipart/form-data', ['post' => true],
		];

		yield 'overridden POST' => [
			'PATCH', 'post', 'application/x-www-form-urlencoded', ['post' => true],
		];

		yield 'JSON POST' => [
			'POST', 'POST', 'application/json', ['body' => true],
		];

		yield 'urlencoded PUT' => [
			'PUT', 'PUT', 'application/x-www-form-urlencoded', ['body' => true],
		];

		yield 'GET does not use query parameters' => [
			'GET', 'GET', 'application/json', ['body' => true],
		];

		yield 'missing real method falls back to effective method' => [
			'POST', null, 'application/x-www-form-urlencoded', ['post' => true],
		];
	}

	/**
	 *
	 */
	#[DataProvider('parsedBodies')]
	public function testSelectsParsedBody(
		string $method,
		?string $realMethod,
		string $contentType,
		array $expected
	): void {
		$result = $this->factory()->create($this->request(
			server: $realMethod === null ? [] : ['REQUEST_METHOD' => $realMethod],
			method: $method,
			contentType: $contentType,
			query: ['query' => true],
			post: ['post' => true],
			body: ['body' => true]
		));

		$this->assertSame($expected, $result->getParsedBody());
	}

	/**
	 *
	 */
	public function testPreservesNestedUploadsAndHandlesFailedUploads(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'psr-bridge-');

		$this->assertNotFalse($path);

		try {
			file_put_contents($path, 'contents');

			$successful = Mockery::mock(UploadedFile::class);
			$successful->shouldReceive('getErrorCode')->andReturn(UPLOAD_ERR_OK);
			$successful->shouldReceive('getPathname')->once()->andReturn($path);
			$successful->shouldReceive('getReportedSize')->andReturn(8);
			$successful->shouldReceive('getReportedFilename')->andReturn('file.txt');
			$successful->shouldReceive('getReportedMimeType')->andReturn('text/plain');

			$failed = Mockery::mock(UploadedFile::class);
			$failed->shouldReceive('getErrorCode')->andReturn(UPLOAD_ERR_NO_FILE);
			$failed->shouldNotReceive('getPathname');
			$failed->shouldReceive('getReportedSize')->andReturn(0);
			$failed->shouldReceive('getReportedFilename')->andReturn('');
			$failed->shouldReceive('getReportedMimeType')->andReturn('');

			$result = $this->factory()->create($this->request(
				files: [
					'documents' => [3 => ['attachment' => $successful]],
					'missing' => $failed,
					'empty' => [],
				]
			));

			$uploads = $result->getUploadedFiles();

			$this->assertSame(['documents', 'missing', 'empty'], array_keys($uploads));
			$this->assertSame([3], array_keys($uploads['documents']));
			$this->assertSame([], $uploads['empty']);

			$file = $uploads['documents'][3]['attachment'];

			$this->assertSame(UPLOAD_ERR_OK, $file->getError());
			$this->assertSame(8, $file->getSize());
			$this->assertSame('file.txt', $file->getClientFilename());
			$this->assertSame('text/plain', $file->getClientMediaType());
			$this->assertSame('contents', (string) $file->getStream());

			$this->assertSame(UPLOAD_ERR_NO_FILE, $uploads['missing']->getError());
			$this->assertSame(0, $uploads['missing']->getSize());

			$file->getStream()->close();
		}
		finally {
			unlink($path);
		}
	}
}
