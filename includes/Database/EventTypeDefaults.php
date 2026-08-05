<?php
/**
 * Built-in event type definitions.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Database;

/**
 * Provides the event types approved for initial installation.
 */
final class EventTypeDefaults {
	/**
	 * Return all built-in event types.
	 *
	 * @return array<int,array{codice:string,descrizione:string}>
	 */
	public static function all(): array {
		// Keeping each immutable code/description pair on one line makes the approved catalog auditable.
		// phpcs:disable Generic.Files.LineLength.TooLong
		return array(
			array( 'codice' => '01', 'descrizione' => 'CINEMA' ),
			array( 'codice' => '04', 'descrizione' => 'PROIEZIONI IN LOCALI CINEMA DIVERSE DA SPETTACOLO' ),
			array( 'codice' => '05', 'descrizione' => 'CALCIO (SERIE A/B ED INTERNAZIONALI)' ),
			array( 'codice' => '06', 'descrizione' => 'CALCIO (SERIE C ED INFERIORI)' ),
			array( 'codice' => '07', 'descrizione' => 'TELEDIFFUSIONE IN FORMA CODIFICATA NEI LOCALI APERTI AL PUBBLICO' ),
			array( 'codice' => '08', 'descrizione' => 'DIFFUSIONE RADIO/TV CON ACCESSO CONDIZIONATO' ),
			array( 'codice' => '10', 'descrizione' => 'PUGILATO' ),
			array( 'codice' => '11', 'descrizione' => 'CICLISMO' ),
			array( 'codice' => '12', 'descrizione' => 'ATLETICA LEGGERA' ),
			array( 'codice' => '13', 'descrizione' => 'NUOTO E PALLANUOTO' ),
			array( 'codice' => '14', 'descrizione' => 'PALLACANESTRO' ),
			array( 'codice' => '15', 'descrizione' => 'PALLAVOLO' ),
			array( 'codice' => '16', 'descrizione' => 'RUGBY' ),
			array( 'codice' => '17', 'descrizione' => 'BASEBALL' ),
			array( 'codice' => '18', 'descrizione' => 'TENNIS' ),
			array( 'codice' => '19', 'descrizione' => 'CONCORSI IPPICI' ),
			array( 'codice' => '20', 'descrizione' => 'SPORT INVERNALI' ),
			array( 'codice' => '21', 'descrizione' => 'AUTOMOBILISMO' ),
			array( 'codice' => '22', 'descrizione' => 'MOTOCICLISMO' ),
			array( 'codice' => '23', 'descrizione' => 'MOTONAUTICA' ),
			array( 'codice' => '24', 'descrizione' => 'CORSE CAVALLI (INGRESSI)' ),
			array( 'codice' => '25', 'descrizione' => 'SPORT CON SCOMMESSE (INGRESSI)' ),
			array( 'codice' => '26', 'descrizione' => 'ALTRI SPORT (INGRESSI)' ),
			array( 'codice' => '30', 'descrizione' => 'CASINÒ (INGRESSI)' ),
			array( 'codice' => '33', 'descrizione' => 'CASINÒ (PROVENTI DEL GIOCO)' ),
			array( 'codice' => '41', 'descrizione' => 'MUSEI' ),
			array( 'codice' => '42', 'descrizione' => 'EVENTI DIVERSI DA SPETTACOLO O INTRATTENIMENTO' ),
			array( 'codice' => '45', 'descrizione' => 'TEATRO PROSA' ),
			array( 'codice' => '46', 'descrizione' => 'TEATRO PROSA DIALETTALE' ),
			array( 'codice' => '47', 'descrizione' => 'TEATRO REPERTORIO NAPOLETANO' ),
			array( 'codice' => '48', 'descrizione' => 'TEATRO LIRICO' ),
			array( 'codice' => '49', 'descrizione' => 'BALLETTO CLASSICO E MODERNO' ),
			array( 'codice' => '50', 'descrizione' => 'OPERETTA' ),
			array( 'codice' => '51', 'descrizione' => 'RIVISTE-COMMEDIE MUSICALI' ),
			array( 'codice' => '52', 'descrizione' => 'CONCERTI CLASSICI' ),
			array( 'codice' => '53', 'descrizione' => 'CONCERTI MUSICA LEGGERA' ),
			array( 'codice' => '54', 'descrizione' => 'ARTE VARIA (IVA 10%)' ),
			array( 'codice' => '55', 'descrizione' => 'BURATTINI-MARIONETTE' ),
			array( 'codice' => '56', 'descrizione' => 'RECITALS LETTERARI' ),
			array( 'codice' => '57', 'descrizione' => 'CONCERTI BANDISTICI-CORALI' ),
			array( 'codice' => '58', 'descrizione' => 'CONCERTI JAZZ' ),
			array( 'codice' => '59', 'descrizione' => 'CONCERTI DI DANZA' ),
			array( 'codice' => '60', 'descrizione' => 'BALLO CON MUSICA DAL VIVO' ),
			array( 'codice' => '61', 'descrizione' => 'BALLO CON MUSICA PREREGISTRATA' ),
			array( 'codice' => '64', 'descrizione' => 'CONCERTINI CON MUSICA PREREGISTRATA' ),
			array( 'codice' => '65', 'descrizione' => 'CONCERTINI CON MUSICA DAL VIVO' ),
			array( 'codice' => '67', 'descrizione' => 'CONCERTI CORALI' ),
			array( 'codice' => '68', 'descrizione' => 'CONCERTI FOLKLORISTICI' ),
			array( 'codice' => '70', 'descrizione' => 'FIERE' ),
			array( 'codice' => '71', 'descrizione' => 'MOSTRE' ),
			array( 'codice' => '74', 'descrizione' => 'ARTE VARIA (IVA 22%)' ),
			array( 'codice' => '75', 'descrizione' => 'CIRCO' ),
			array( 'codice' => '76', 'descrizione' => 'SPETTACOLI VIAGGIANTI' ),
			array( 'codice' => '77', 'descrizione' => 'PARCHI DIVERTIMENTO E ACQUATICI (con prevalenza attività dello spettacolo viaggiante)' ),
			array( 'codice' => '78', 'descrizione' => 'PARCHI DIVERTIMENTO E ACQUATICI (senza prevalenza attività dello spettacolo viaggiante)' ),
			array( 'codice' => '84', 'descrizione' => 'BOWLING' ),
			array( 'codice' => '85', 'descrizione' => 'NOLEGGIO GO-KARTS' ),
			array( 'codice' => '90', 'descrizione' => 'MANIFESTAZIONI MISTE (all\'aperto)' ),
			array( 'codice' => '91', 'descrizione' => 'MULTIMEDIALITÀ' ),
			array( 'codice' => '97', 'descrizione' => 'ALTRE ATTIVITÀ DI SPETTACOLO CONGIUNTE CON ALTRE NON DI SPETTACOLO' ),
			array( 'codice' => '98', 'descrizione' => 'ALTRI SPETTACOLI O INTRATTENIMENTI (in alberghi e villaggi turistici)' ),
			array( 'codice' => '99', 'descrizione' => 'ALBERGHI E VILLAGGI TURISTICI (attività di spettacolo)' ),
		);
		// phpcs:enable Generic.Files.LineLength.TooLong
	}
}
