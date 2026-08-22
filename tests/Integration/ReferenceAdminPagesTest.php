<?php
/**
 * Venue and event-type CRUD integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Admin\Pages\LocaliListPage;
use CinebotWp\Admin\Pages\LocaleEditPage;
use CinebotWp\Admin\Pages\TipologieListPage;
use CinebotWp\Admin\Pages\TipologiaEditPage;
use CinebotWp\Models\Locale;
use CinebotWp\Models\TipologiaEvento;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use WP_UnitTestCase;

/**
 * Tests venue and event-type CRUD admin pages.
 */
final class ReferenceAdminPagesTest extends WP_UnitTestCase {
	/** @var LocaleRepository */
	private $venues;

	/** @var TipologiaRepository */
	private $types;

	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** Set up repositories. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->venues = new LocaleRepository( $wpdb );
		$this->types  = new TipologiaRepository( $wpdb );
		$this->titles = new TitoloRepository( $wpdb );
		$this->events = new EventoRepository( $wpdb );
	}

	/** Venue list page renders without errors. */
	public function test_locali_list_renders(): void {
		wp_set_current_user( 1 );

		$page = new LocaliListPage( $this->venues, $this->events );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Locali', $output );
		self::assertStringContainsString( 'Nuovo locale', $output );
	}

	/** Locale edit page renders form for new venue. */
	public function test_locale_edit_renders_new_form(): void {
		wp_set_current_user( 1 );

		$page = new LocaleEditPage( $this->venues );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'New locale', $output );
		self::assertStringContainsString( 'name="nome"', $output );
	}

	/** Venue create sets source=manual. */
	public function test_locale_create_sets_manual_source(): void {
		$locale = new Locale();
		$locale->nome  = 'Test Venue';
		$locale->comune = 'Test City';
		$locale->source = 'manual';

		$id = $this->venues->save( $locale );
		$saved = $this->venues->find( $id );

		self::assertNotNull( $saved );
		self::assertSame( 'manual', $saved->source );
		self::assertSame( 'Test Venue', $saved->nome );
	}

	/** Tipologie list page renders with toggle. */
	public function test_tipologie_list_renders(): void {
		wp_set_current_user( 1 );

		$page = new TipologieListPage( $this->types );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Tipologie evento', $output );
		self::assertStringContainsString( 'Nuova tipologia', $output );
	}

	/** Tipologia edit page renders with codice field. */
	public function test_tipologia_edit_renders(): void {
		wp_set_current_user( 1 );

		$page = new TipologiaEditPage( $this->types );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'name="codice"', $output );
		self::assertStringContainsString( 'name="descrizione"', $output );
	}

	/** Predefined event type code is read-only in edit form. */
	public function test_predefined_code_is_readonly(): void {
		wp_set_current_user( 1 );

		$types = $this->types->findAll();
		self::assertNotEmpty( $types );
		$first = $types[0];

		$page = new TipologiaEditPage( $this->types );

		$_GET['id'] = (int) $first->id;

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'readonly', $output );
	}

	/** Active toggle changes attivo state. */
	public function test_toggle_changes_attivo(): void {
		$types = $this->types->findAll();
		$first = $types[0];
		$original = (int) $first->attivo;

		$this->types->setActive( (int) $first->id, ! $original );
		$updated = $this->types->find( (int) $first->id );

		self::assertNotEquals( $original, (int) $updated->attivo );
	}

	/** Custom event type can be deleted, predefined cannot. */
	public function test_predefined_delete_rejected(): void {
		$types = $this->types->findAll();
		$first = $types[0];

		$result = $this->types->deleteCustom( (int) $first->id );
		self::assertFalse( $result );
	}
}
