<?php
/**
 * Price data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one price database row.
 */
final class Prezzo {
	public ?int $id = null;
	public ?int $idprezzo = null;
	public int $settoreId = 0;
	public ?string $nome = null;
	public ?string $tipo = null;
	public ?string $importo = null;
	public ?string $prevendita = null;
	public ?int $stato = null;
	public string $source = 'manual';
	public int $syncActive = 1;
	public ?string $lastSeenSync = null;
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate a price from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                = new self();
		$model->id            = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->idprezzo      = isset( $data['idprezzo'] ) ? (int) $data['idprezzo'] : null;
		$model->settoreId     = isset( $data['settore_id'] ) ? (int) $data['settore_id'] : 0;
		$model->nome          = isset( $data['nome'] ) ? (string) $data['nome'] : null;
		$model->tipo          = isset( $data['tipo'] ) ? (string) $data['tipo'] : null;
		$model->importo       = isset( $data['importo'] ) ? (string) $data['importo'] : null;
		$model->prevendita    = isset( $data['prevendita'] ) ? (string) $data['prevendita'] : null;
		$model->stato         = isset( $data['stato'] ) ? (int) $data['stato'] : null;
		$model->source        = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
		$model->syncActive    = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
		$model->lastSeenSync  = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
		$model->createdAt     = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
		$model->updatedAt     = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;

		return $model;
	}

	/**
	 * Return database-shaped data.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'idprezzo'        => $this->idprezzo,
			'settore_id'      => $this->settoreId,
			'nome'            => $this->nome,
			'tipo'            => $this->tipo,
			'importo'         => $this->importo,
			'prevendita'      => $this->prevendita,
			'stato'           => $this->stato,
			'source'          => $this->source,
			'sync_active'     => $this->syncActive,
			'last_seen_sync'  => $this->lastSeenSync,
			'created_at'      => $this->createdAt,
			'updated_at'      => $this->updatedAt,
		);
	}
}
