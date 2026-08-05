<?php
/**
 * Venue data transfer object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Models;

/**
 * Represents one venue database row.
 */
final class Locale {
	public ?int $id = null;
	public ?int $localeIdRemoto = null;
	public string $nome = '';
	public ?string $codice = null;
	public ?string $indirizzo = null;
	public ?string $cap = null;
	public ?string $comune = null;
	public ?string $provincia = null;
	public ?int $mappa = null;
	public string $source = 'manual';
	public ?string $createdAt = null;
	public ?string $updatedAt = null;

	/**
	 * Hydrate a venue from a database-shaped array.
	 *
	 * @param array<string,mixed> $data Database data.
	 */
	public static function fromArray( array $data ): self {
		$model                 = new self();
		$model->id             = isset( $data['id'] ) ? (int) $data['id'] : null;
		$model->localeIdRemoto = isset( $data['locale_id_remoto'] ) ? (int) $data['locale_id_remoto'] : null;
		$model->nome           = isset( $data['nome'] ) ? (string) $data['nome'] : '';
		$model->codice         = isset( $data['codice'] ) ? (string) $data['codice'] : null;
		$model->indirizzo      = isset( $data['indirizzo'] ) ? (string) $data['indirizzo'] : null;
		$model->cap            = isset( $data['cap'] ) ? (string) $data['cap'] : null;
		$model->comune         = isset( $data['comune'] ) ? (string) $data['comune'] : null;
		$model->provincia      = isset( $data['provincia'] ) ? (string) $data['provincia'] : null;
		$model->mappa          = isset( $data['mappa'] ) ? (int) $data['mappa'] : null;
		$model->source         = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
		$model->createdAt      = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
		$model->updatedAt      = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;

		return $model;
	}

	/**
	 * Return database-shaped data.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                => $this->id,
			'locale_id_remoto'  => $this->localeIdRemoto,
			'nome'              => $this->nome,
			'codice'            => $this->codice,
			'indirizzo'         => $this->indirizzo,
			'cap'               => $this->cap,
			'comune'            => $this->comune,
			'provincia'         => $this->provincia,
			'mappa'             => $this->mappa,
			'source'            => $this->source,
			'created_at'        => $this->createdAt,
			'updated_at'        => $this->updatedAt,
		);
	}
}
