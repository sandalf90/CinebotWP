<?php
/**
 * Synchronization outcome value object.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

/** Represents the safe public outcome of one synchronization attempt. */
final class SyncResult {
	/** @var string */
	private $status;

	/** @var array<string,int> */
	private $stats;

	/** @var string */
	private $message;

	/** Create a synchronization outcome. */
	public function __construct( string $status, array $stats = array(), string $message = '' ) {
		$this->status  = $status;
		$this->stats   = array(
			'titoli_added'   => max( 0, (int) ( $stats['titoli_added'] ?? 0 ) ),
			'titoli_updated' => max( 0, (int) ( $stats['titoli_updated'] ?? 0 ) ),
			'eventi_added'   => max( 0, (int) ( $stats['eventi_added'] ?? 0 ) ),
			'eventi_updated' => max( 0, (int) ( $stats['eventi_updated'] ?? 0 ) ),
		);
		$this->message = $message;
	}

	/** Return whether this synchronization completed successfully. */
	public function isSuccess(): bool {
		return 'success' === $this->status;
	}

	/** Return the machine-readable outcome status. */
	public function status(): string {
		return $this->status;
	}

	/** Return the documented synchronization counters. */
	public function stats(): array {
		return $this->stats;
	}

	/** Return a safe human-readable outcome message. */
	public function message(): string {
		return $this->message;
	}
}
