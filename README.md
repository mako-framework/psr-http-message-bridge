# PSR HTTP Message Bridge

[![Tests](https://github.com/mako-framework/psr-http-message-bridge/actions/workflows/tests.yml/badge.svg)](https://github.com/mako-framework/psr-http-message-bridge/actions/workflows/tests.yml)
[![Static analysis](https://github.com/mako-framework/psr-http-message-bridge/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/mako-framework/psr-http-message-bridge/actions/workflows/static-analysis.yml)

Converts between [Mako](https://makoframework.com) HTTP requests/responses and [PSR-7](https://www.php-fig.org/psr/psr-7/) messages using any [PSR-17](https://www.php-fig.org/psr/psr-17/) factory implementation.

## Requirements

* Mako 13.0+
* A PSR-7/PSR-17 implementation (e.g. [nyholm/psr7](https://github.com/Nyholm/psr7))

## Installation

```
composer require mako/psr-http-message-bridge nyholm/psr7
```

## Usage

### Converting a Mako request to a PSR-7 server request

```php
use mako\bridges\psr\http\message\PsrServerRequestFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17Factory = new Psr17Factory;

$factory = new PsrServerRequestFactory(
	serverRequestFactory: $psr17Factory,
	uriFactory: $psr17Factory,
	streamFactory: $psr17Factory,
	uploadedFileFactory: $psr17Factory
);

$psrRequest = $factory->create($request);
```

### Converting a PSR-7 response to a Mako response

```php
use mako\bridges\psr\http\message\MakoResponseFactory;
use mako\bridges\psr\http\message\MakoResponseHydrator;

// Create a new Mako response from a PSR-7 response

$response = new MakoResponseFactory()->create($psrResponse, $request);

// Or hydrate an existing Mako response
// (call $response->reset() first if you want a clean slate)

new MakoResponseHydrator()->hydrate($response, $psrResponse);
```

### Streaming responses

Set the `$stream` argument to `true` to stream the response body in chunks instead of buffering it in memory. This is useful for large responses or responses of indeterminate size.

```php
$hydrator = new MakoResponseHydrator;

$hydrator->hydrate($response, $psrResponse, stream: true);
```

The default chunk size is 8192 bytes and can be configured through the constructor:

```php
$hydrator = new MakoResponseHydrator(chunkSize: 65536);
```

### Example: running a PSR-15 handler inside a Mako controller

```php
use mako\bridges\psr\http\message\MakoResponseHydrator;
use mako\bridges\psr\http\message\PsrServerRequestFactory;
use mako\http\Request;
use mako\http\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;

class Controller
{
	public function __invoke(
		Request $request,
		Response $response,
		RequestHandlerInterface $handler
	): void {
		$psr17Factory = new Psr17Factory;

		$psrRequest = new PsrServerRequestFactory(
			$psr17Factory,
			$psr17Factory,
			$psr17Factory,
			$psr17Factory
		)->create($request);

		$psrResponse = $handler->handle($psrRequest);

		new MakoResponseHydrator()->hydrate($response, $psrResponse);
	}
}
```
