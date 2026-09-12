<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\bridges\psr\http\message;

use Generator;
use mako\http\Response;
use mako\http\response\CustomStatus;
use mako\http\response\senders\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

use function in_array;

/**
 * Hydrates Mako responses from PSR-7 responses.
 */
final readonly class MakoResponseHydrator
{
	/**
	 * Constructor.
	 */
	public function __construct(
		private int $chunkSize = 8192
	) {
	}

	/**
	 * Reads the body, preserving the cursor when seeking is supported.
	 *
	 * Non-seekable streams are read from their current position.
	 */
	private function readBody(StreamInterface $body): string
	{
		if (!$body->isSeekable()) {
			return $body->getContents();
		}

		$position = $body->tell();

		try {
			$body->rewind();

			return $body->getContents();
		}
		finally {
			$body->seek($position);
		}
	}

	/**
	 * Hydrates an existing Mako response.
	 *
	 * Headers are appended to the existing response. Call Response::reset()
	 * before passing the response if you want a clean slate.
	 */
	public function hydrate(
		Response $response,
		ResponseInterface $psrResponse,
		bool $stream = false
	): void {
		$response->setProtocolVersion($psrResponse->getProtocolVersion());

		$response->setStatus(new CustomStatus(
			$psrResponse->getStatusCode(),
			$psrResponse->getReasonPhrase()
		));

		foreach ($psrResponse->getHeaders() as $name => $values) {
			foreach ($values as $value) {
				$response->headers->add($name, $value, false);
			}
		}

		$body = $psrResponse->getBody();
		$statusCode = $psrResponse->getStatusCode();

		$hasBody = $response->getRequest()->getMethod() !== 'HEAD'
			&& $statusCode >= 200
			&& !in_array($statusCode, [204, 205, 304], true);

		if (!$hasBody) {
			$response->setBody('');
		}
		elseif (!$stream) {
			$response->setBody($this->readBody($body));
		}
		else {
			$chunkSize = $this->chunkSize;

			$response->setBody(new Stream(static function () use ($body, $chunkSize): Generator {
				if ($body->isSeekable()) {
					$body->rewind();
				}

				while (!$body->eof()) {
					yield $body->read($chunkSize);
				}
			}));
		}
	}
}
