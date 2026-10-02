<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Migrations;

use Arkid\CatalogueLink\Migrations\Migrator;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class MigratorTest extends TestCase {

	public function tear_down(): void {
		delete_option( Migrator::OPTION_KEY );
		delete_option( 'arkid_catalogue_link_migrating' );
		delete_option( 'arkid_catalogue_link_known_embed_ids' );
		parent::tear_down();
	}

	/**
	 * The regression guard for the original bug: register() added a
	 * plugins_loaded callback at priority 9 while plugins_loaded:10 was already
	 * running, so it landed behind the pointer and never fired. Registering on a
	 * hook that has already run is invisible — nothing errors, the migration
	 * simply never happens.
	 */
	public function test_registers_on_a_hook_that_has_not_run_yet(): void {
		$migrator = new Migrator();
		$migrator->register();

		$this->assertNotFalse(
			has_action( 'init', array( $migrator, 'maybe_migrate' ) ),
			'Migrator must register on init; plugins_loaded has already fired by the time register() is called.'
		);
	}

	public function test_writes_the_version_marker(): void {
		delete_option( Migrator::OPTION_KEY );

		( new Migrator() )->maybe_migrate();

		$this->assertSame( ARKID_CATALOGUE_LINK_VERSION, get_option( Migrator::OPTION_KEY ) );
	}

	public function test_is_idempotent(): void {
		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( ARKID_CATALOGUE_LINK_VERSION, get_option( Migrator::OPTION_KEY ) );
		$this->assertFalse(
			get_option( 'arkid_catalogue_link_migrating', false ),
			'The migration lock must always be released.'
		);
	}

	public function test_upgrading_from_0_2_0_removes_the_legacy_transient_cache(): void {
		update_option( Migrator::OPTION_KEY, '0.2.0' );
		update_option( 'arkid_catalogue_link_known_embed_ids', array( 'abc-123' ) );
		set_transient( 'arkid_embed_abc-123', array( 'embed_id' => 'abc-123' ), HOUR_IN_SECONDS );
		set_transient( 'arkid_embed_lock_abc-123', '1', 60 );

		( new Migrator() )->maybe_migrate();

		$this->assertFalse( get_transient( 'arkid_embed_abc-123' ) );
		$this->assertFalse( get_transient( 'arkid_embed_lock_abc-123' ) );
		$this->assertFalse( get_option( 'arkid_catalogue_link_known_embed_ids', false ) );
		$this->assertSame( ARKID_CATALOGUE_LINK_VERSION, get_option( Migrator::OPTION_KEY ) );
	}

	public function test_orphaned_transients_outside_the_index_are_swept(): void {
		update_option( Migrator::OPTION_KEY, '0.2.0' );
		// No index entry — this is the row the old flush() would have orphaned.
		set_transient( 'arkid_embed_orphan', array( 'embed_id' => 'orphan' ), HOUR_IN_SECONDS );

		( new Migrator() )->maybe_migrate();
		wp_cache_flush();

		$this->assertFalse( get_transient( 'arkid_embed_orphan' ) );
	}

	public function test_no_rewrite_when_the_version_already_matches(): void {
		update_option( Migrator::OPTION_KEY, ARKID_CATALOGUE_LINK_VERSION );

		$writes = 0;
		add_action(
			'update_option_' . Migrator::OPTION_KEY,
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		( new Migrator() )->maybe_migrate();

		$this->assertSame( 0, $writes );
	}
}
