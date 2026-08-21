<?php
/**
 * Event row read model for the detail page table.
 *
 * @package CinebotWp
 */

namespace CinebotWp\ReadModels;

/**
 * Represents one joined event row used by the TitoloDetail projection.
 */
final class EventoRiga {
	public int $eventoId;
	public string $inizio;
	public string $localeNome;
	public ?string $urlAcquisto;

	/**
	 * Hydrate the event row from its joined database row.
	 *
	 * @param array<string,mixed> $row Joined database row.
	 */
	public static function fromRow( array $row ): self {
		$model             = new self();
		$model->eventoId   = isset( $row['evento_id'] ) ? (int) $row['evento_id'] : 0;
		$model->inizio     = isset( $row['inizio'] ) ? (string) $row['inizio'] : '';
		$model->localeNome = isset( $row['locale_nome'] ) ? (string) $row['locale_nome'] : '';
		$model->urlAcquisto = isset( $row['url_acquisto'] ) ? (string) $row['url_acquisto'] : null;

		return $model;
	}
}
