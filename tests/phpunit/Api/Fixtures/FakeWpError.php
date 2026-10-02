<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api\Fixtures;

final class FakeWpError {

	public function __construct( private readonly string $message ) {}

	public function get_error_message(): string {
		return $this->message;
	}
}
