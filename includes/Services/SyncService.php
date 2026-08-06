<?php
/**
 * Transactional Cinebot schedule synchronization.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use CinebotWp\Models\Evento;
use CinebotWp\Models\Prezzo;
use CinebotWp\Models\Settore;
use CinebotWp\Models\Titolo;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TitoloRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use wpdb;

/** Imports the full API hierarchy and reconciles inactive API-owned rows. */
final class SyncService {
	/** @var wpdb */
	private $db;
	/** @var ApiClient|null */
	private $api;
	/** @var TitoloRepository */
	private $titles;
	/** @var EventoRepository */
	private $events;
	/** @var SettoreRepository */
	private $sectors;
	/** @var PrezzoRepository */
	private $prices;
	/** @var LocaleRepository */
	private $venues;
	/** @var SyncLogRepository */
	private $logs;
	/** @var SyncLock */
	private $lock;
	/** @var LocandinaService */
	private $posters;

	/** Create the service with concrete defaults and injectable test collaborators. */
	public function __construct(
		wpdb $db,
		?ApiClient $api = null,
		?TitoloRepository $titles = null,
		?EventoRepository $events = null,
		?SettoreRepository $sectors = null,
		?PrezzoRepository $prices = null,
		?LocaleRepository $venues = null,
		?SyncLogRepository $logs = null,
		?SyncLock $lock = null,
		?LocandinaService $posters = null
	) {
		$this->db      = $db;
		$this->api     = $api;
		$this->titles  = $titles ?? new TitoloRepository( $db );
		$this->events  = $events ?? new EventoRepository( $db );
		$this->sectors = $sectors ?? new SettoreRepository( $db );
		$this->prices  = $prices ?? new PrezzoRepository( $db );
		$this->venues  = $venues ?? new LocaleRepository( $db );
		$this->logs    = $logs ?? new SyncLogRepository( $db );
		$this->lock    = $lock ?? new SyncLock( $db );
		$this->posters = $posters ?? new LocandinaService();
	}

	/** Fetch and synchronize the configured remote programming payload. */
	public function sync(): SyncResult {
		$owner = $this->lock->acquire();
		if ( null === $owner ) {
			return new SyncResult( 'locked', array(), 'A schedule synchronization is already running.' );
		}
		try {
			if ( null === $this->api ) {
				throw new RuntimeException( 'Synchronization API client is unavailable.' );
			}
			return $this->synchronize( $this->api->fetchProgrammazione() );
		} catch ( Throwable $exception ) {
			$log_id = null;
			try {
				$log_id = $this->logs->start();
			} catch ( Throwable $ignored ) {
				// Return the safe API outcome even when history is unavailable.
			}
			return $this->record_failure( $log_id, array() );
		} finally {
			$this->lock->release( $owner );
		}
	}

	/** Synchronize an already decoded API payload. */
	public function syncPayload( array $payload ): SyncResult {
		$owner = $this->lock->acquire();
		if ( null === $owner ) {
			return new SyncResult( 'locked', array(), 'A schedule synchronization is already running.' );
		}
		try {
			return $this->synchronize( $payload );
		} finally {
			$this->lock->release( $owner );
		}
	}

	/** Validate, persist, reconcile, commit, and record one owned payload. */
	private function synchronize( array $payload ): SyncResult {
		$stats = array( 'titoli_added' => 0, 'titoli_updated' => 0, 'eventi_added' => 0, 'eventi_updated' => 0 );
		$log_id = null;
		$started = false;
		try {
			$this->validate_payload( $payload );
			$log_id = $this->logs->start( $this->canonical_hash( $payload ) );
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Unable to start synchronization transaction.' );
			}
			$started = true;
			$token = $this->sync_token();
			foreach ( $payload['programmazione'] as $envelope ) {
				$this->sync_frontend( $envelope, $token, $stats );
			}
			if ( false === $this->db->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Unable to commit synchronization transaction.' );
			}
			$started = false;
			$this->clear_cache();
			$this->logs->finish( $log_id, 'success', $stats );
			return new SyncResult( 'success', $stats, 'Schedule synchronization completed.' );
		} catch ( Throwable $exception ) {
			if ( $started ) {
				try {
					$this->db->query( 'ROLLBACK' );
				} catch ( Throwable $ignored ) {
					// Preserve the safe synchronization outcome if rollback reporting fails.
				}
			}
			return $this->record_failure( $log_id, $stats );
		}
	}

	/** Persist one frontend and deactivate API rows no longer represented by it. */
	private function sync_frontend( array $envelope, string $token, array &$stats ): void {
		$frontend = $this->positive_int( $envelope['frontend'] ?? null, 'frontend' );
		foreach ( $this->child_array( $envelope, 'titoli' ) as $data ) {
			if ( ! is_array( $data ) ) {
				throw new InvalidArgumentException( 'Invalid title data.' );
			}
			$this->sync_title( $data, $envelope, $frontend, $token, $stats );
		}
		$gone_titles = $this->titles->deactivateUnseenApi( $frontend, $token );
		$gone_events = $this->events->deactivateByTitoloIds( $gone_titles );
		$gone_sectors = $this->sectors->deactivateByEventoIds( $gone_events );
		$this->prices->deactivateBySettoreIds( $gone_sectors );
	}

	/** Persist one API title and its entire descendant hierarchy. */
	private function sync_title( array $data, array $envelope, int $frontend, string $token, array &$stats ): void {
		$remote = $this->positive_int( $data['idtitolo'] ?? null, 'title' );
		$existing = $this->titles->findByRemoteId( $remote );
		if ( null !== $existing && 'manual' === $existing->source ) {
			return;
		}
		$title = null !== $existing ? $existing : new Titolo();
		$this->map_title( $title, $data, $envelope, $frontend, $token );
		$changed = null === $existing || ! hash_equals( (string) $existing->syncHash, (string) $title->syncHash ) || 1 !== $existing->syncActive;
		$title_id = $this->titles->save( $title );
		if ( null === $existing ) {
			++$stats['titoli_added'];
		} elseif ( $changed ) {
			++$stats['titoli_updated'];
		}
		foreach ( $this->child_array( $data, 'eventi' ) as $event_data ) {
			if ( ! is_array( $event_data ) ) {
				throw new InvalidArgumentException( 'Invalid event data.' );
			}
			$this->sync_event( $event_data, $title_id, $token, $stats );
		}
		$gone_events = $this->events->deactivateUnseenApi( $title_id, $token );
		$gone_sectors = $this->sectors->deactivateByEventoIds( $gone_events );
		$this->prices->deactivateBySettoreIds( $gone_sectors );
	}

	/** Persist one event, its venue, sectors, and prices. */
	private function sync_event( array $data, int $title_id, string $token, array &$stats ): void {
		$remote = $this->positive_int( $data['idevento'] ?? null, 'event' );
		$existing = $this->events->findByRemoteId( $remote );
		if ( null !== $existing && 'manual' === $existing->source ) {
			return;
		}
		$event = null !== $existing ? $existing : new Evento();
		$event->idevento = $remote;
		$event->titoloId = $title_id;
		$event->inizio = $this->required_string( $data, 'inizio' );
		$event->organizzatoreId = $this->nullable_int( $data, 'organizzatoreId' );
		$event->organizzatoreCf = $this->nullable_string( $data, 'organizzatoreCf' );
		$event->localeId = $this->venues->upsertApi( $data );
		$event->stato = $this->nullable_int( $data, 'stato' );
		$event->otp = $this->nullable_int( $data, 'otp' );
		$event->controlloaccessi = $this->nullable_int( $data, 'controlloaccessi' );
		$event->mappa = $this->nullable_int( $data, 'mappa' );
		$event->source = 'api';
		$event->syncActive = 1;
		$event->lastSeenSync = $token;
		$changed = null === $existing || $this->event_changed( $existing, $event );
		$event_id = $this->events->save( $event );
		if ( null === $existing ) {
			++$stats['eventi_added'];
		} elseif ( $changed ) {
			++$stats['eventi_updated'];
		}
		foreach ( $this->child_array( $data, 'settori' ) as $sector_data ) {
			if ( ! is_array( $sector_data ) ) {
				throw new InvalidArgumentException( 'Invalid sector data.' );
			}
			$this->sync_sector( $sector_data, $event_id, $token );
		}
		$gone_sectors = $this->sectors->deactivateUnseenApi( $event_id, $token );
		$this->prices->deactivateBySettoreIds( $gone_sectors );
	}

	/** Persist one sector and reconcile its prices. */
	private function sync_sector( array $data, int $event_id, string $token ): void {
		$remote = $this->positive_int( $data['idsettore'] ?? null, 'sector' );
		$existing = $this->sectors->findByRemoteId( $event_id, $remote );
		if ( null !== $existing && 'manual' === $existing->source ) {
			return;
		}
		$sector = null !== $existing ? $existing : new Settore();
		$sector->idsettore = $remote;
		$sector->eventoId = $event_id;
		$sector->nome = $this->nullable_string( $data, 'nome' );
		$sector->source = 'api';
		$sector->syncActive = 1;
		$sector->lastSeenSync = $token;
		$sector_id = $this->sectors->save( $sector );
		foreach ( $this->child_array( $data, 'prezzi' ) as $price_data ) {
			if ( ! is_array( $price_data ) ) {
				throw new InvalidArgumentException( 'Invalid price data.' );
			}
			$this->sync_price( $price_data, $sector_id, $token );
		}
		$this->prices->deactivateUnseenApi( $sector_id, $token );
	}

	/** Persist one API price. */
	private function sync_price( array $data, int $sector_id, string $token ): void {
		$remote = $this->positive_int( $data['idprezzo'] ?? null, 'price' );
		$existing = $this->prices->findByRemoteId( $sector_id, $remote );
		if ( null !== $existing && 'manual' === $existing->source ) {
			return;
		}
		$price = null !== $existing ? $existing : new Prezzo();
		$price->idprezzo = $remote;
		$price->settoreId = $sector_id;
		$price->nome = $this->nullable_string( $data, 'nome' );
		$price->tipo = $this->nullable_string( $data, 'tipo' );
		$price->importo = $this->nullable_string( $data, 'importo' );
		$price->prevendita = $this->nullable_string( $data, 'prevendita' );
		$price->stato = $this->nullable_int( $data, 'stato' );
		$price->source = 'api';
		$price->syncActive = 1;
		$price->lastSeenSync = $token;
		$this->prices->save( $price );
	}

	/** Map all title DTO fields from its API representation. */
	private function map_title( Titolo $title, array $data, array $envelope, int $frontend, string $token ): void {
		$title->idtitolo = $this->positive_int( $data['idtitolo'] ?? null, 'title' );
		$title->frontendId = $frontend;
		$title->titolo = $this->required_string( $data, 'titolo' );
		$title->autore = $this->nullable_string( $data, 'autore' );
		$title->esecutore = $this->nullable_string( $data, 'esecutore' );
		$title->durata = $this->nullable_int( $data, 'durata' );
		$title->scadenza = $this->nullable_int( $data, 'scadenza' );
		$title->descrizione = $this->nullable_string( $data, 'descrizione' );
		$title->tipoeventoCodice = $this->nullable_string( $data, 'tipoevento' );
		$title->locandinaFlag = $this->nullable_int( $data, 'locandina' );
		$title->locandinaUrl = $this->posters->build( $this->required_string( $envelope, 'host' ), $this->required_string( $envelope, 'path' ), $title->idtitolo, (int) $title->locandinaFlag );
		$title->cinetel = $this->nullable_string( $data, 'cinetel' );
		$title->tmdb = $this->nullable_string( $data, 'tmdb' );
		$title->trailer = $this->nullable_string( $data, 'trailer' );
		$title->cast = $this->nullable_string( $data, 'cast' );
		$title->tag = $this->child_array( $data, 'tag' );
		$title->source = 'api';
		$title->syncHash = $this->canonical_hash( $this->title_hash_data( $data ) );
		$title->syncActive = 1;
		$title->lastSeenSync = $token;
	}

	/** Reject an invalid top-level payload before any persistence is attempted. */
	private function validate_payload( array $payload ): void {
		if ( ! isset( $payload['programmazione'] ) || ! is_array( $payload['programmazione'] ) ) {
			throw new InvalidArgumentException( 'Invalid programming payload.' );
		}
		foreach ( $payload['programmazione'] as $envelope ) {
			if ( ! is_array( $envelope ) ) {
				throw new InvalidArgumentException( 'Invalid programming payload.' );
			}
			$this->positive_int( $envelope['frontend'] ?? null, 'frontend' );
			$this->child_array( $envelope, 'titoli' );
		}
	}

	/** Return an optional child array or reject a malformed present child. */
	private function child_array( array $data, string $key ): array {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return array();
		}
		if ( ! is_array( $data[ $key ] ) ) {
			throw new InvalidArgumentException( 'Invalid programming payload.' );
		}
		return $data[ $key ];
	}

	/** Return a positive integer ID without accepting lossy coercion. */
	private function positive_int( $value, string $field ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Invalid ' . $field . ' identifier.' );
	}

	/** Return a required non-empty scalar string. */
	private function required_string( array $data, string $key ): string {
		$value = $this->nullable_string( $data, $key );
		if ( null === $value || '' === $value ) {
			throw new InvalidArgumentException( 'Invalid programming payload.' );
		}
		return $value;
	}

	/** Return a nullable scalar string. */
	private function nullable_string( array $data, string $key ): ?string {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return null;
		}
		if ( ! is_scalar( $data[ $key ] ) ) {
			throw new InvalidArgumentException( 'Invalid programming payload.' );
		}
		return (string) $data[ $key ];
	}

	/** Return a nullable integer from a native integer or decimal digit string. */
	private function nullable_int( array $data, string $key ): ?int {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return null;
		}
		$value = $data[ $key ];
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/D', $value ) ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Invalid programming payload.' );
	}

	/** Compare stored and mapped event values before counting an update. */
	private function event_changed( Evento $old, Evento $new ): bool {
		return $old->titoloId !== $new->titoloId || $old->inizio !== $new->inizio || $old->organizzatoreId !== $new->organizzatoreId || $old->organizzatoreCf !== $new->organizzatoreCf || $old->localeId !== $new->localeId || $old->stato !== $new->stato || $old->otp !== $new->otp || $old->controlloaccessi !== $new->controlloaccessi || $old->mappa !== $new->mappa || 1 !== $old->syncActive;
	}

	/** Produce a key-order-independent SHA-256 payload hash. */
	private function canonical_hash( array $data ): string {
		$this->sort_recursive( $data );
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new RuntimeException( 'Unable to encode synchronization payload.' );
		}
		return hash( 'sha256', $json );
	}

	/** Sort associative keys recursively without changing JSON list order. */
	private function sort_recursive( array &$data ): void {
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				$this->sort_recursive( $value );
			}
		}
		unset( $value );
		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
			ksort( $data );
		}
	}

	/** Exclude children so child-only changes do not count as title updates. */
	private function title_hash_data( array $data ): array {
		unset( $data['eventi'] );
		return $data;
	}

	/** Generate a 36-character reconciliation token. */
	private function sync_token(): string {
		$hex = bin2hex( random_bytes( 16 ) );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}

	/** Delete matching normal and timeout public cache options after a committed sync. */
	private function clear_cache(): void {
		// The table is trusted and the two wildcard patterns are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $this->db->query( $this->db->prepare( "DELETE FROM {$this->db->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_cinebot_prog_%', '_transient_timeout_cinebot_prog_%' ) );
		if ( false === $result ) {
			throw new RuntimeException( 'Unable to clear schedule cache.' );
		}
	}

	/** Finish an existing log if possible and return an intentionally generic error. */
	private function record_failure( ?int $log_id, array $stats ): SyncResult {
		if ( null !== $log_id ) {
			try {
				$this->logs->finish( $log_id, 'error', $stats, 'Schedule synchronization failed.' );
			} catch ( Throwable $ignored ) {
				// Returning a safe error is more important than a secondary log failure.
			}
		}
		return new SyncResult( 'error', $stats, 'Schedule synchronization failed.' );
	}
}
