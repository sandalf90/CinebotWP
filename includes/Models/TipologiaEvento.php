<?php
/**
 * Event type data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one event-type database row.
 */
final class TipologiaEvento {
	public ?int $id = null;
	public string $codice = '';
	public string $descrizione = '';
	public int $predefinito = 0;
	public int $attivo = 1;
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate an event type from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model               = new self();
		$model->id           = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->codice       = isset( $data['codice'] ) ? (string) $data['codice'] : '';
		$model->descrizione  = isset( $data['descrizione'] ) ? (string) $data['descrizione'] : '';
		$model->predefinito  = isset( $data['predefinito'] ) ? (int) $data['predefinito'] : 0;
		$model->attivo       = isset( $data['attivo'] ) ? (int) $data['attivo'] : 1;
		$model->createdAt    = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
		$model->updatedAt    = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;

		return $model;
	}

	/**
	 * Return database-shaped data.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'            => $this->id,
			'codice'        => $this->codice,
			'descrizione'   => $this->descrizione,
			'predefinito'   => $this->predefinito,
			'attivo'        => $this->attivo,
			'created_at'    => $this->createdAt,
			'updated_at'    => $this->updatedAt,
		);
	}
}
