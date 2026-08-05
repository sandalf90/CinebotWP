<?php
/**
 * Public schedule card read model.
 *
 * @package CinebotWp
 */

namespace CinebotWp\ReadModels;

/**
 * Represents one immutable-by-convention public joined-row projection.
 */
final class ProgrammazioneCard {
	public int $eventoId;
	public string $inizio;
	public int $titoloId;
	public string $titolo;
	public string $descrizione;
	public ?string $locandinaUrl;
	public ?string $tipoCodice;
	public ?string $tipoDescrizione;
	public int $localeId;
	public string $localeNome;
	public ?string $comune;
	public ?string $prezzoMin;
	public ?string $prezzoMax;

	/**
	 * Hydrate the public projection from its joined database row.
	 *
	 * @param array<string,mixed> $row Joined database row.
	 */
	public static function fromRow( array $row ): self {
		$model                   = new self();
		$model->eventoId         = isset( $row['evento_id'] ) ? (int) $row['evento_id'] : 0;
		$model->inizio           = isset( $row['inizio'] ) ? (string) $row['inizio'] : '';
		$model->titoloId         = isset( $row['titolo_id'] ) ? (int) $row['titolo_id'] : 0;
		$model->titolo           = isset( $row['titolo'] ) ? (string) $row['titolo'] : '';
		$model->descrizione      = isset( $row['descrizione'] ) ? (string) $row['descrizione'] : '';
		$model->locandinaUrl     = isset( $row['locandina_url'] ) ? (string) $row['locandina_url'] : null;
		$model->tipoCodice       = isset( $row['tipo_codice'] ) ? (string) $row['tipo_codice'] : null;
		$model->tipoDescrizione  = isset( $row['tipo_descrizione'] ) ? (string) $row['tipo_descrizione'] : null;
		$model->localeId         = isset( $row['locale_id'] ) ? (int) $row['locale_id'] : 0;
		$model->localeNome       = isset( $row['locale_nome'] ) ? (string) $row['locale_nome'] : '';
		$model->comune           = isset( $row['comune'] ) ? (string) $row['comune'] : null;
		$model->prezzoMin        = isset( $row['prezzo_min'] ) ? (string) $row['prezzo_min'] : null;
		$model->prezzoMax        = isset( $row['prezzo_max'] ) ? (string) $row['prezzo_max'] : null;

		return $model;
	}
}
