<?php
/**
 * Poster URL service unit tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Unit;

use CinebotWp\Services\LocandinaService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies deterministic and safe poster URL construction.
 */
final class LocandinaServiceTest extends TestCase {
	/**
	 * Verifies disabled posters do not validate unrelated input.
	 */
	public function test_non_positive_flag_returns_null_without_other_validation(): void {
		$service = new LocandinaService();

		self::assertNull( $service->build( 'https://invalid/?secret=1', '../invalid', 0, 0 ) );
		self::assertNull( $service->build( '', '', -1, -5 ) );
	}

	/**
	 * Verifies the required canonical sample exactly.
	 */
	public function test_build_returns_exact_sample_url(): void {
		$service = new LocandinaService();

		self::assertSame(
			'https://ticket.cinebot.it/martinovich/titolo/491/locandina',
			$service->build( 'ticket.cinebot.it', 'martinovich', 491, 1 )
		);
	}

	/**
	 * Verifies host casing and surrounding path slashes are normalized.
	 */
	public function test_build_normalizes_host_and_surrounding_path_slashes(): void {
		$service = new LocandinaService();

		self::assertSame(
			'https://ticket.cinebot.it/martinovich/titolo/491/locandina',
			$service->build( 'TICKET.CINEBOT.IT', '///martinovich///', 491, 2 )
		);
	}

	/**
	 * Verifies safe nested path segments are independently URL encoded.
	 */
	public function test_build_encodes_each_safe_nested_path_segment(): void {
		$service = new LocandinaService();

		self::assertSame(
			'https://ticket.cinebot.it/cinema%20uno/sala%2Bdue/titolo/491/locandina',
			$service->build( 'ticket.cinebot.it', 'cinema uno/sala+due', 491, 1 )
		);
	}

	/**
	 * Verifies already encoded text remains literal and cannot become traversal.
	 */
	public function test_build_double_encodes_percent_signs_to_prevent_encoded_traversal(): void {
		$service = new LocandinaService();

		self::assertSame(
			'https://ticket.cinebot.it/%252e%252e/poster/titolo/491/locandina',
			$service->build( 'ticket.cinebot.it', '%2e%2e/poster', 491, 1 )
		);
	}

	/**
	 * Verifies repeated calls produce exactly the same URL.
	 */
	public function test_build_is_deterministic(): void {
		$service = new LocandinaService();
		$first   = $service->build( 'TICKET.CINEBOT.IT', '/cinema uno/poster/', 491, 1 );

		self::assertSame( $first, $service->build( 'TICKET.CINEBOT.IT', '/cinema uno/poster/', 491, 1 ) );
	}

	/**
	 * Verifies the maximum DNS label length is accepted.
	 */
	public function test_build_accepts_63_byte_dns_label(): void {
		$host = str_repeat( 'a', 63 ) . '.example';

		self::assertSame(
			'https://' . $host . '/poster/titolo/491/locandina',
			( new LocandinaService() )->build( $host, 'poster', 491, 1 )
		);
	}

	/**
	 * Verifies the maximum total DNS host length is accepted.
	 */
	public function test_build_accepts_253_byte_dns_host(): void {
		$host = str_repeat( 'a', 63 ) . '.'
			. str_repeat( 'b', 63 ) . '.'
			. str_repeat( 'c', 63 ) . '.'
			. str_repeat( 'd', 61 );

		self::assertSame( 253, strlen( $host ) );
		self::assertSame(
			'https://' . $host . '/poster/titolo/491/locandina',
			( new LocandinaService() )->build( $host, 'poster', 491, 1 )
		);
	}

	/**
	 * Provides invalid positive title identifiers.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function invalid_title_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Verifies enabled posters require a positive title identifier.
	 *
	 * @dataProvider invalid_title_ids
	 */
	public function test_positive_flag_rejects_non_positive_title_id( int $title_id ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unable to build poster URL.' );

		( new LocandinaService() )->build( 'ticket.cinebot.it', 'martinovich', $title_id, 1 );
	}

	/**
	 * Provides invalid poster hosts.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_hosts(): array {
		$host_254 = str_repeat( 'a', 63 ) . '.'
			. str_repeat( 'b', 63 ) . '.'
			. str_repeat( 'c', 63 ) . '.'
			. str_repeat( 'd', 62 );

		return array(
			'empty'              => array( '' ),
			'HTTPS scheme'       => array( 'https://ticket.cinebot.it' ),
			'HTTP scheme'        => array( 'http://ticket.cinebot.it' ),
			'user info'          => array( 'user@ticket.cinebot.it' ),
			'password user info' => array( 'user:pass@ticket.cinebot.it' ),
			'port'               => array( 'ticket.cinebot.it:443' ),
			'path'               => array( 'ticket.cinebot.it/path' ),
			'query'              => array( 'ticket.cinebot.it?secret=1' ),
			'fragment'           => array( 'ticket.cinebot.it#part' ),
			'localhost'          => array( 'localhost' ),
			'IP address'         => array( '127.0.0.1' ),
			'IPv6 address'       => array( '[::1]' ),
			'underscore'         => array( 'ticket_bad.cinebot.it' ),
			'empty label'        => array( 'ticket..cinebot.it' ),
			'leading hyphen'     => array( '-ticket.cinebot.it' ),
			'trailing hyphen'    => array( 'ticket-.cinebot.it' ),
			'trailing dot'       => array( 'ticket.cinebot.it.' ),
			'space'              => array( 'ticket cinebot.it' ),
			'control character'  => array( "ticket.cinebot.it\n" ),
			'64-byte label'      => array( str_repeat( 'a', 64 ) . '.example' ),
			'254-byte host'      => array( $host_254 ),
		);
	}

	/**
	 * Verifies invalid hosts fail without reflecting their content.
	 *
	 * @dataProvider invalid_hosts
	 */
	public function test_positive_flag_rejects_invalid_host( string $host ): void {
		try {
			( new LocandinaService() )->build( $host, 'martinovich', 491, 1 );
			self::fail( 'Expected invalid poster host.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertSame( 'Unable to build poster URL.', $exception->getMessage() );
			if ( '' !== $host ) {
				self::assertStringNotContainsString( $host, $exception->getMessage() );
			}
		}
	}

	/**
	 * Provides invalid poster paths.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_paths(): array {
		return array(
			'empty'             => array( '' ),
			'only slashes'      => array( '///' ),
			'empty segment'     => array( 'cinema//poster' ),
			'current segment'   => array( 'cinema/./poster' ),
			'parent segment'    => array( 'cinema/../poster' ),
			'backslash'         => array( 'cinema\\poster' ),
			'query'             => array( 'cinema/poster?size=large' ),
			'fragment'          => array( 'cinema/poster#large' ),
			'HTTPS marker'      => array( 'https://ticket.cinebot.it/poster' ),
			'HTTP marker'       => array( 'http://ticket.cinebot.it/poster' ),
			'generic scheme'    => array( 'javascript:alert(1)' ),
			'null control'      => array( "cinema/pos\0ter" ),
			'newline control'   => array( "cinema/pos\nter" ),
			'encoded query'     => array( 'cinema%3fsecret/poster' ),
			'encoded fragment'  => array( 'cinema%23secret/poster' ),
			'encoded slash'     => array( 'cinema%2fsecret/poster' ),
			'encoded backslash' => array( 'cinema%5csecret/poster' ),
			'encoded null'      => array( 'cinema%00secret/poster' ),
			'encoded unit sep'  => array( 'cinema%1fsecret/poster' ),
			'encoded delete'    => array( 'cinema%7fsecret/poster' ),
		);
	}

	/**
	 * Verifies path syntax cannot escape or alter the generated URL.
	 *
	 * @dataProvider invalid_paths
	 */
	public function test_positive_flag_rejects_invalid_path( string $path ): void {
		try {
			( new LocandinaService() )->build( 'ticket.cinebot.it', $path, 491, 1 );
			self::fail( 'Expected invalid poster path.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertSame( 'Unable to build poster URL.', $exception->getMessage() );
			if ( '' !== $path ) {
				self::assertStringNotContainsString( $path, $exception->getMessage() );
			}
		}
	}
}
