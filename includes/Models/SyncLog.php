<?php
/**
 * Synchronization log data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one synchronization log database row.
 */
final class SyncLog {
	public ?int $id = null;
	public string $startedAt = '';
	public ?string $finishedAt = null;
	public ?string $status = null;
	public int $titoliAdded = 0;
	public int $titoliUpdated = 0;
	public int $eventiAdded = 0;
	public int $eventiUpdated = 0;
	public ?string $errorMessage = null;
	public ?string $payloadHash = null;

	/**
	 * Hydrate a synchronization log from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                 = new self();
		$model->id             = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->startedAt      = isset( $data['started_at'] ) ? (string) $data['started_at'] : '';
		$model->finishedAt     = isset( $data['finished_at'] ) ? (string) $data['finished_at'] : null;
		$model->status         = isset( $data['status'] ) ? (string) $data['status'] : null;
		$model->titoliAdded    = isset( $data['titoli_added'] ) ? (int) $data['titoli_added'] : 0;
		$model->titoliUpdated  = isset( $data['titoli_updated'] ) ? (int) $data['titoli_updated'] : 0;
		$model->eventiAdded    = isset( $data['eventi_added'] ) ? (int) $data['eventi_added'] : 0;
		$model->eventiUpdated  = isset( $data['eventi_updated'] ) ? (int) $data['eventi_updated'] : 0;
		$model->errorMessage   = isset( $data['error_message'] ) ? (string) $data['error_message'] : null;
		$model->payloadHash    = isset( $data['payload_hash'] ) ? (string) $data['payload_hash'] : null;

		return $model;
	}

	/**
	 * Return database-shaped data.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'started_at'       => $this->startedAt,
			'finished_at'      => $this->finishedAt,
			'status'           => $this->status,
			'titoli_added'     => $this->titoliAdded,
			'titoli_updated'   => $this->titoliUpdated,
			'eventi_added'     => $this->eventiAdded,
			'eventi_updated'   => $this->eventiUpdated,
			'error_message'    => $this->errorMessage,
			'payload_hash'     => $this->payloadHash,
		);
	}
}
