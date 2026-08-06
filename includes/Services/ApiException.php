<?php
/**
 * Safe Cinebot API failure.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use RuntimeException;

/**
 * Represents an API failure without retaining upstream response content.
 */
final class ApiException extends RuntimeException {
	/** @var int|null */
	private $status;

	/**
	 * Creates a safe API exception.
	 */
	public function __construct( string $message, ?int $status = null ) {
		parent::__construct( $message );
		$this->status = $status;
	}

	/**
	 * Returns the HTTP status when one is available.
	 */
	public function status(): ?int {
		return $this->status;
	}
}
