<?php
/**
 * Aggregated title detail read model for the detail page shortcodes.
 *
 * @package CinebotWp
 */

namespace CinebotWp\ReadModels;

use CinebotWp\Models\Titolo;

/**
 * Immutable-by-convention projection that bundles a title, its visible events,
 * and pre-computed aggregates used by the detail-page shortcodes.
 */
final class TitoloDetail {
	public ?Titolo $title = null;

	/** @var array<int,EventoRiga> */
	public array $eventi = array();

	public ?string $prezzoDa = null;
	public ?string $prezzoA = null;
	public int $eventiCount = 0;
	public int $giorniCount = 0;
	public ?string $primoGiorno = null;
	public ?string $ultimoGiorno = null;
	public ?string $localeNomi = null;
}
