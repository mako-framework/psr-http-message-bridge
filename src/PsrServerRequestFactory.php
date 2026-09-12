<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\bridges\psr\http;

use mako\http\Request;
use mako\http\request\UploadedFile;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

use function explode;
use function in_array;
use function is_array;
use function iterator_to_array;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function substr;

use const UPLOAD_ERR_OK;

/**
 * Converts Mako requests to PSR-7 server requests.
 */
final class PsrServerRequestFactory
{
	/**
	 * Constructor.
	 */
	public function __construct(
		private readonly ServerRequestFactoryInterface $serverRequestFactory,
		private readonly UriFactoryInterface $uriFactory,
		private readonly StreamFactoryInterface $streamFactory,
		private readonly UploadedFileFactoryInterface $uploadedFileFactory
	) {
	}

	/**
	 * Creates a URI without using Mako's decoded routing path.
	 */
	private function createUri(Request $request, string $target): UriInterface
	{
		// Absolute-form request target.

		if (preg_match('~\Ahttps?://~i', $target) === 1) {
			return $this->uriFactory->createUri($target);
		}

		$uri = $this->uriFactory->createUri($request->getBaseURL())
			->withPath('')
			->withQuery('')
			->withFragment('');

		// Asterisk-form and CONNECT authority-form have no URI path.

		if ($target === '*' || $request->getMethod() === 'CONNECT') {
			return $uri;
		}

		// Split manually so leading "//" remains a path, not an authority.

		[$path, $query] = explode('?', $target, 2) + [1 => ''];

		return $uri
			->withPath($path)
			->withQuery($query);
	}

	/**
	 * Returns parsed input without substituting GET query parameters.
	 */
	private function getParsedBody(Request $request): array
	{
		$realMethod = strtoupper(
			$request->server->get('REQUEST_METHOD', $request->getMethod())
		);

		if ($realMethod === 'POST' && in_array($request->getContentType(), [
			'application/x-www-form-urlencoded',
			'multipart/form-data',
		], true)) {
			return $request->post->all();
		}

		return $request->getBody()->all();
	}

	/**
	 * Converts an uploaded file.
	 */
	private function createUploadedFile(UploadedFile $file): UploadedFileInterface
	{
		$error = $file->getErrorCode();

		// Failed uploads might not have a readable temporary file.

		$stream = $error === UPLOAD_ERR_OK
			? $this->streamFactory->createStreamFromFile($file->getPathname(), 'rb')
			: $this->streamFactory->createStream();

		return $this->uploadedFileFactory->createUploadedFile(
			$stream,
			$file->getReportedSize(),
			$error,
			$file->getReportedFilename(),
			$file->getReportedMimeType()
		);
	}

	/**
	 * Converts uploaded files while preserving their nested structure.
	 */
	private function createUploadedFiles(array $files): array
	{
		$uploads = [];

		foreach ($files as $name => $file) {
			$uploads[$name] = is_array($file)
				? $this->createUploadedFiles($file)
				: $this->createUploadedFile($file);
		}

		return $uploads;
	}

	/**
	 * Creates a PSR-7 server request.
	 */
	public function create(Request $request): ServerRequestInterface
	{
		$target = $request->server->get('REQUEST_URI', '/');

		$psrRequest = $this->serverRequestFactory->createServerRequest(
			$request->getMethod(),
			$this->createUri($request, $target),
			$request->server->all()
		);

		$protocol = $request->server->get('SERVER_PROTOCOL', 'HTTP/1.1');

		$psrRequest = $psrRequest
			->withProtocolVersion(str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : $protocol)
			->withRequestTarget($target);

		foreach ($request->headers as $name => $values) {
			$psrRequest = $psrRequest->withHeader(
				str_replace('_', '-', $name),
				$values
			);
		}

		$psrRequest = $psrRequest
			->withBody($this->streamFactory->createStream($request->getRawBody()))
			->withQueryParams($request->query->all())
			->withCookieParams(iterator_to_array($request->cookies))
			->withParsedBody($this->getParsedBody($request))
			->withUploadedFiles($this->createUploadedFiles($request->files->all()));

		foreach ($request->getAttributes() as $name => $value) {
			$psrRequest = $psrRequest->withAttribute($name, $value);
		}

		return $psrRequest;
	}
}
