<?php
/**
 * Title data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one title database row.
 */
final class Titolo {
	public ?int $id = null;
	public ?int $idtitolo = null;
	public ?int $frontendId = null;
	public string $titolo = '';
	public ?string $autore = null;
	public ?string $esecutore = null;
	public ?int $durata = null;
	public ?int $scadenza = null;
	public ?string $descrizione = null;
	public ?string $tipoeventoCodice = null;
	public ?int $locandinaFlag = null;
	public ?string $locandinaUrl = null;
	public ?string $cinetel = null;
	public ?string $tmdb = null;
	public ?string $trailer = null;
	public ?string $cast = null;
	/** @var array<int,mixed> */
	public array $tag = array();
	public string $source = 'manual';
	public ?string $syncHash = null;
	public int $syncActive = 1;
	public ?string $lastSeenSync = null;
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate a title from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                     = new self();
		$model->id                 = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->idtitolo           = isset( $data['idtitolo'] ) ? (int) $data['idtitolo'] : null;
		$model->frontendId         = isset( $data['frontend_id'] ) ? (int) $data['frontend_id'] : null;
		$model->titolo             = isset( $data['titolo'] ) ? (string) $data['titolo'] : '';
		$model->autore             = isset( $data['autore'] ) ? (string) $data['autore'] : null;
		$model->esecutore          = isset( $data['esecutore'] ) ? (string) $data['esecutore'] : null;
		$model->durata             = isset( $data['durata'] ) ? (int) $data['durata'] : null;
		$model->scadenza           = isset( $data['scadenza'] ) ? (int) $data['scadenza'] : null;
		$model->descrizione        = isset( $data['descrizione'] ) ? (string) $data['descrizione'] : null;
		$model->tipoeventoCodice   = isset( $data['tipoevento_codice'] ) ? (string) $data['tipoevento_codice'] : null;
		$model->locandinaFlag      = isset( $data['locandina_flag'] ) ? (int) $data['locandina_flag'] : null;
		$model->locandinaUrl       = isset( $data['locandina_url'] ) ? (string) $data['locandina_url'] : null;
		$model->cinetel            = isset( $data['cinetel'] ) ? (string) $data['cinetel'] : null;
		$model->tmdb               = isset( $data['tmdb'] ) ? (string) $data['tmdb'] : null;
		$model->trailer            = isset( $data['trailer'] ) ? (string) $data['trailer'] : null;
		$model->cast               = isset( $data['cast'] ) ? (string) $data['cast'] : null;
		$model->tag                = isset( $data['tag'] ) && is_array( $data['tag'] ) ? $data['tag'] : array();
		$model->source             = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
		$model->syncHash           = isset( $data['sync_hash'] ) ? (string) $data['sync_hash'] : null;
		$model->syncActive         = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
		$model->lastSeenSync       = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
		$model->createdAt          = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
		$model->updatedAt          = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;

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
			'idtitolo'           => $this->idtitolo,
			'frontend_id'        => $this->frontendId,
			'titolo'             => $this->titolo,
			'autore'             => $this->autore,
			'esecutore'          => $this->esecutore,
			'durata'             => $this->durata,
			'scadenza'           => $this->scadenza,
			'descrizione'        => $this->descrizione,
			'tipoevento_codice'  => $this->tipoeventoCodice,
			'locandina_flag'     => $this->locandinaFlag,
			'locandina_url'      => $this->locandinaUrl,
			'cinetel'            => $this->cinetel,
			'tmdb'               => $this->tmdb,
			'trailer'            => $this->trailer,
			'cast'               => $this->cast,
			'tag'                => $this->tag,
			'source'             => $this->source,
			'sync_hash'          => $this->syncHash,
			'sync_active'        => $this->syncActive,
			'last_seen_sync'     => $this->lastSeenSync,
			'created_at'         => $this->createdAt,
			'updated_at'         => $this->updatedAt,
		);
	}
}
