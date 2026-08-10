<?php
/**
 * Domain model unit tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Unit;

use CinebotWp\Models\Evento;
use CinebotWp\Models\Locale;
use CinebotWp\Models\Prezzo;
use CinebotWp\Models\Settore;
use CinebotWp\Models\SyncLog;
use CinebotWp\Models\TipologiaEvento;
use CinebotWp\Models\Titolo;
use CinebotWp\ReadModels\ProgrammazioneCard;
use PHPUnit\Framework\TestCase;

/**
 * Verifies typed hydration at database row boundaries.
 */
final class ModelsTest extends TestCase {
	/**
	 * Provides complete database rows for every writable model.
	 *
	 * @return array<string,array{class:class-string,row:array<string,mixed>}>
	 */
	public function model_rows(): array {
		return array(
			'titolo'           => array(
				'class' => Titolo::class,
				'row'   => array(
					'id'                  => 9,
					'idtitolo'            => 491,
					'frontend_id'         => 12,
					'titolo'              => 'DONNE & UOMINI',
					'autore'              => 'Autore',
					'esecutore'           => 'Compagnia',
					'durata'              => 95,
					'scadenza'            => true,
					'descrizione'         => 'Descrizione',
					'tipoevento_codice'   => '01',
					'locandina_flag'      => false,
					'locandina_url'       => 'https://example.test/poster.jpg',
					'cinetel'             => 'C-1',
					'tmdb'                => 'T-1',
					'trailer'             => 'https://example.test/trailer',
					'cast'                => 'Interprete',
					'tag'                 => array( 'teatro', 'prosa' ),
					'source'              => 'api',
					'sync_hash'           => 'payload-hash',
					'sync_active'         => true,
					'last_seen_sync'      => 'sync-token',
					'created_at'          => '2026-08-01 10:00:00',
					'updated_at'          => '2026-08-02 10:00:00',
				),
			),
			'evento'           => array(
				'class' => Evento::class,
				'row'   => array(
					'id'                => 10,
					'idevento'          => 501,
					'url_acquisto'      => 'https://ticket.cinebot.it/martinovich/evento/501/acquista',
					'titolo_id'         => 9,
					'inizio'            => '2026-08-10 21:00:00',
					'organizzatore_id'  => 44,
					'organizzatore_cf'  => 'RSSMRA80A01H501U',
					'locale_id'         => 3,
					'stato'             => 3,
					'otp'               => true,
					'controlloaccessi'  => false,
					'mappa'             => 7,
					'source'            => 'api',
					'sync_active'       => true,
					'last_seen_sync'    => 'sync-token',
					'created_at'        => '2026-08-01 10:00:00',
					'updated_at'        => '2026-08-02 10:00:00',
				),
			),
			'settore'           => array(
				'class' => Settore::class,
				'row'   => array(
					'id'             => 11,
					'idsettore'      => 601,
					'evento_id'      => 10,
					'nome'           => 'Platea',
					'source'         => 'api',
					'sync_active'    => false,
					'last_seen_sync' => 'sync-token',
					'created_at'     => '2026-08-01 10:00:00',
					'updated_at'     => '2026-08-02 10:00:00',
				),
			),
			'prezzo'            => array(
				'class' => Prezzo::class,
				'row'   => array(
					'id'             => 12,
					'idprezzo'       => 701,
					'settore_id'     => 11,
					'nome'           => 'Intero',
					'tipo'           => 'I',
					'importo'        => '19.90',
					'prevendita'     => '0.10',
					'stato'          => true,
					'source'         => 'api',
					'sync_active'    => true,
					'last_seen_sync' => 'sync-token',
					'created_at'     => '2026-08-01 10:00:00',
					'updated_at'     => '2026-08-02 10:00:00',
				),
			),
			'locale'            => array(
				'class' => Locale::class,
				'row'   => array(
					'id'               => 3,
					'locale_id_remoto' => 801,
					'nome'             => 'Cinema Martinovich',
					'codice'           => '0250120220822',
					'indirizzo'        => 'Via Roma 1',
					'cap'              => '00100',
					'comune'           => 'Roma',
					'provincia'        => 'RM',
					'mappa'            => 7,
					'source'           => 'api',
					'created_at'       => '2026-08-01 10:00:00',
					'updated_at'       => '2026-08-02 10:00:00',
				),
			),
			'tipologia evento'  => array(
				'class' => TipologiaEvento::class,
				'row'   => array(
					'id'           => 4,
					'codice'       => '01',
					'descrizione'  => 'CINEMA',
					'predefinito'  => true,
					'attivo'       => false,
					'created_at'   => '2026-08-01 10:00:00',
					'updated_at'   => '2026-08-02 10:00:00',
				),
			),
			'sync log'          => array(
				'class' => SyncLog::class,
				'row'   => array(
					'id'              => 5,
					'started_at'      => '2026-08-01 10:00:00',
					'finished_at'     => '2026-08-01 10:01:00',
					'status'          => 'success',
					'titoli_added'    => 2,
					'titoli_updated'  => 3,
					'eventi_added'    => 4,
					'eventi_updated'  => 5,
					'error_message'   => null,
					'payload_hash'    => 'payload-hash',
				),
			),
		);
	}

	/**
	 * Verifies complete round trips, exact database keys, and immutable input.
	 *
	 * @param class-string        $class Model class.
	 * @param array<string,mixed> $row   Database row.
	 *
	 * @dataProvider model_rows
	 */
	public function test_model_round_trip_uses_exact_database_keys_without_mutating_input( string $class, array $row ): void {
		$original = $row;
		$model    = $class::fromArray( $row );
		$output   = $model->toArray();

		self::assertSame( $original, $row );
		self::assertSame( array_keys( $original ), array_keys( $output ) );
		self::assertSame( $this->normalized_row( $original ), $output );
		foreach ( array_keys( $output ) as $key ) {
			self::assertSame( strtolower( $key ), $key );
			self::assertStringNotContainsString( 'Id', $key );
			self::assertStringNotContainsString( 'At', $key );
		}
	}

	/**
	 * Verifies DTO-owned defaults and nullable-value preservation.
	 */
	public function test_defaults_preserve_nulls_and_own_manual_reconciliation_state(): void {
		$titolo = Titolo::fromArray( array( 'titolo' => 'Manuale' ) );

		self::assertNull( $titolo->id );
		self::assertNull( $titolo->idtitolo );
		self::assertNull( $titolo->frontendId );
		self::assertNull( $titolo->durata );
		self::assertNull( $titolo->locandinaUrl );
		self::assertSame( array(), $titolo->tag );
		self::assertSame( 'manual', $titolo->source );
		self::assertSame( 1, $titolo->syncActive );
		self::assertNull( $titolo->lastSeenSync );

		$evento = Evento::fromArray(
			array(
				'titolo_id' => 9,
				'inizio'    => '2026-08-10 21:00:00',
				'locale_id' => 3,
			)
		);
		self::assertNull( $evento->idevento );
		self::assertNull( $evento->urlAcquisto );
		self::assertNull( $evento->stato );
		self::assertSame( 'manual', $evento->source );
		self::assertSame( 1, $evento->syncActive );
		self::assertNull( $evento->lastSeenSync );

		$prezzo = Prezzo::fromArray( array( 'settore_id' => 11 ) );
		self::assertNull( $prezzo->importo );
		self::assertNull( $prezzo->prevendita );
		self::assertSame( 'manual', $prezzo->source );

		$settore = Settore::fromArray( array( 'evento_id' => 10 ) );
		self::assertSame( 'manual', $settore->source );
		self::assertSame( 1, $settore->syncActive );

		$locale = Locale::fromArray( array( 'nome' => 'Manuale' ) );
		self::assertNull( $locale->localeIdRemoto );
		self::assertSame( 'manual', $locale->source );

		$tipologia = TipologiaEvento::fromArray( array( 'codice' => '01' ) );
		self::assertSame( '01', $tipologia->codice );
		self::assertSame( 0, $tipologia->predefinito );
		self::assertSame( 1, $tipologia->attivo );

		$log = SyncLog::fromArray( array( 'started_at' => '2026-08-01 10:00:00' ) );
		self::assertSame( 0, $log->titoliAdded );
		self::assertSame( 0, $log->eventiUpdated );
		self::assertNull( $log->finishedAt );
	}

	/**
	 * Verifies the complete public joined-row projection.
	 */
	public function test_programmazione_card_projects_every_key_and_nullable_prices(): void {
		$row = array(
			'evento_id'       => 10,
			'inizio'          => '2026-08-10 21:00:00',
			'titolo_id'       => 9,
			'titolo'          => 'DONNE & UOMINI',
			'descrizione'     => 'Descrizione',
			'locandina_url'   => null,
			'tipo_codice'     => '01',
			'tipo_descrizione' => 'CINEMA',
			'locale_id'       => 3,
			'locale_nome'     => 'Cinema Martinovich',
			'comune'          => null,
			'prezzo_min'      => null,
			'prezzo_max'      => null,
		);
		$original = $row;

		$card = ProgrammazioneCard::fromRow( $row );

		self::assertSame( $original, $row );
		self::assertSame( 10, $card->eventoId );
		self::assertSame( '2026-08-10 21:00:00', $card->inizio );
		self::assertSame( 9, $card->titoloId );
		self::assertSame( 'DONNE & UOMINI', $card->titolo );
		self::assertSame( 'Descrizione', $card->descrizione );
		self::assertNull( $card->locandinaUrl );
		self::assertSame( '01', $card->tipoCodice );
		self::assertSame( 'CINEMA', $card->tipoDescrizione );
		self::assertSame( 3, $card->localeId );
		self::assertSame( 'Cinema Martinovich', $card->localeNome );
		self::assertNull( $card->comune );
		self::assertNull( $card->prezzoMin );
		self::assertNull( $card->prezzoMax );
	}

	/**
	 * Converts database booleans to the integer representation owned by DTOs.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function normalized_row( array $row ): array {
		foreach ( $row as $key => $value ) {
			if ( is_bool( $value ) ) {
				$row[ $key ] = (int) $value;
			}
		}

		return $row;
	}
}
