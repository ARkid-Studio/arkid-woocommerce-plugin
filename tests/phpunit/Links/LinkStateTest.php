<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Links;

use Arkid\CatalogueLink\Links\LinkState;
use PHPUnit\Framework\TestCase;

final class LinkStateTest extends TestCase {

	/**
	 * @dataProvider links
	 */
	public function test_a_link_is_classified_from_what_is_stored( string $id, string $url, string $status, LinkState $expected ): void {
		$this->assertSame( $expected, LinkState::classify( $id, $url, $status ) );
	}

	/**
	 * @return array<string, array{string, string, string, LinkState}>
	 */
	public static function links(): array {
		$url = 'https://catalogue.arkid.app/embed/x';

		return array(
			'nothing selected'                  => array( '', '', '', LinkState::None ),
			'nothing selected, stale url'       => array( '', $url, '', LinkState::None ),
			'nothing selected, stale status'    => array( '', '', 'missing', LinkState::None ),
			'synced'                            => array( 'abc', $url, '', LinkState::Linked ),
			'selected, never synced'            => array( 'abc', '', '', LinkState::NotSynced ),
			'deleted upstream'                  => array( 'abc', '', 'missing', LinkState::Missing ),
			'deleted upstream, url still there' => array( 'abc', $url, 'missing', LinkState::Missing ),
			'unknown status is no status'       => array( 'abc', $url, 'broken', LinkState::Linked ),
			'unknown status, no url'            => array( 'abc', '', 'broken', LinkState::NotSynced ),
		);
	}

	public function test_only_the_two_states_where_nothing_shows_need_attention(): void {
		$this->assertFalse( LinkState::None->needs_attention() );
		$this->assertFalse( LinkState::Linked->needs_attention() );
		$this->assertTrue( LinkState::NotSynced->needs_attention() );
		$this->assertTrue( LinkState::Missing->needs_attention() );
	}

	public function test_the_values_are_stable_strings_a_filter_can_use(): void {
		$this->assertSame(
			array( 'none', 'linked', 'not_synced', 'missing' ),
			array_map( static fn( LinkState $state ): string => $state->value, LinkState::cases() )
		);
	}
}
