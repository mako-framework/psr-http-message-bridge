<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\bridges\psr\http\message;

use mako\http\Request;
use mako\http\Response;
use mako\security\signer\Signer;
use Psr\Http\Message\ResponseInterface;

/**
 * Converts PSR-7 responses to Mako responses.
 */
final readonly class MakoResponseFactory
{
	/**
	 * Constructor.
	 */
	public function __construct(
		private MakoResponseHydrator $hydrator = new MakoResponseHydrator
	) {
	}

	/**
	 * Creates a Mako response.
	 */
	public function create(
		ResponseInterface $psrResponse,
		Request $request,
		?Signer $signer = null,
		bool $stream = false
	): Response {
		$response = new Response($request, signer: $signer);

		$this->hydrator->hydrate(
			$response,
			$psrResponse,
			$stream
		);

		return $response;
	}
}
