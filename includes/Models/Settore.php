<?php
/**
 * Sector data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one sector database row.
 */
final class Settore {
	public ?int $id = null;
	public ?int $idsettore = null;
	public int $eventoId = 0;
	public ?string $nome = null;
	public string $source = 'manual';
	public int $syncActive = 1;
	public ?string $lastSeenSync = null;
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate a sector from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                = new self();
		$model->id            = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->idsettore     = isset( $data['idsettore'] ) ? (int) $data['idsettore'] : null;
		$model->eventoId      = isset( $data['evento_id'] ) ? (int) $data['evento_id'] : 0;
		$model->nome          = isset( $data['nome'] ) ? (string) $data['nome'] : null;
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
			'idsettore'       => $this->idsettore,
			'evento_id'       => $this->eventoId,
			'nome'            => $this->nome,
			'source'          => $this->source,
			'sync_active'     => $this->syncActive,
			'last_seen_sync'  => $this->lastSeenSync,
			'created_at'      => $this->createdAt,
			'updated_at'      => $this->updatedAt,
		);
	}
}
