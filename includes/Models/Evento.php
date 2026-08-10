<?php
/**
 * Event data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one event database row.
 */
final class Evento {
	public ?int $id = null;
	public ?int $idevento = null;
	public ?string $urlAcquisto = null;
	public int $titoloId = 0;
	public string $inizio = '';
	public ?int $organizzatoreId = null;
	public ?string $organizzatoreCf = null;
	public int $localeId = 0;
	public ?int $stato = null;
	public ?int $otp = null;
	public ?int $controlloaccessi = null;
	public ?int $mappa = null;
	public string $source = 'manual';
	public int $syncActive = 1;
	public ?string $lastSeenSync = null;
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate an event from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                    = new self();
		$model->id                = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->idevento          = isset( $data['idevento'] ) ? (int) $data['idevento'] : null;
		$model->urlAcquisto       = isset( $data['url_acquisto'] ) ? (string) $data['url_acquisto'] : null;
		$model->titoloId          = isset( $data['titolo_id'] ) ? (int) $data['titolo_id'] : 0;
		$model->inizio            = isset( $data['inizio'] ) ? (string) $data['inizio'] : '';
		$model->organizzatoreId   = isset( $data['organizzatore_id'] ) ? (int) $data['organizzatore_id'] : null;
		$model->organizzatoreCf   = isset( $data['organizzatore_cf'] ) ? (string) $data['organizzatore_cf'] : null;
		$model->localeId          = isset( $data['locale_id'] ) ? (int) $data['locale_id'] : 0;
		$model->stato             = isset( $data['stato'] ) ? (int) $data['stato'] : null;
		$model->otp               = isset( $data['otp'] ) ? (int) $data['otp'] : null;
		$model->controlloaccessi  = isset( $data['controlloaccessi'] ) ? (int) $data['controlloaccessi'] : null;
		$model->mappa             = isset( $data['mappa'] ) ? (int) $data['mappa'] : null;
		$model->source            = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
		$model->syncActive        = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
		$model->lastSeenSync      = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
		$model->createdAt         = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
		$model->updatedAt         = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;

		return $model;
	}

	/**
	 * Return database-shaped data.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                 => $this->id,
			'idevento'           => $this->idevento,
			'url_acquisto'      => $this->urlAcquisto,
			'titolo_id'          => $this->titoloId,
			'inizio'             => $this->inizio,
			'organizzatore_id'   => $this->organizzatoreId,
			'organizzatore_cf'   => $this->organizzatoreCf,
			'locale_id'          => $this->localeId,
			'stato'              => $this->stato,
			'otp'                => $this->otp,
			'controlloaccessi'   => $this->controlloaccessi,
			'mappa'              => $this->mappa,
			'source'             => $this->source,
			'sync_active'        => $this->syncActive,
			'last_seen_sync'     => $this->lastSeenSync,
			'created_at'         => $this->createdAt,
			'updated_at'         => $this->updatedAt,
		);
	}
}
