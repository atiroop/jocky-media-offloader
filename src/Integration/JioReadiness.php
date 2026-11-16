<?php
/**
 * Conservative readiness checks for Jocky Image Optimizer.
 *
 * @package Jocky\MediaOffloader\Integration
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Integration;

use Closure;

/** Validates JIO readiness before JMO snapshots supported images. */
final class JioReadiness {
	public const MINIMUM_CONTRACT_VERSION = 1;
	/** Create readiness checker with an injectable public-metadata detector.
	 *
	 * @param JioDetector  $detector Public plugin metadata detector.
	 * @param Closure|null $contract_state_reader Optional public-contract reader used by isolated tests.
	 */
	public function __construct(
		private readonly JioDetector $detector = new JioDetector(),
		private readonly ?Closure $contract_state_reader = null
	) {}

	/** Whether JIO is active and manages this image family.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 */
	public function requires_confirmation( int $attachment_id ): bool {
		return $this->manages_attachment( $attachment_id ) && ! self::is_terminal_eligible( $this->state( $attachment_id ) );
	}

	/**
	 * Read JIO's versioned completion contract, or null for unavailable/old/invalid JIO.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null Valid contract state.
	 */
	public function state( int $attachment_id ): ?array {
		if ( ! $this->has_compatible_contract( $attachment_id ) ) {
			return null;
		}
		$class = '\\Jocky\\ImageOptimizer\\Integration\\AttachmentReadiness';
		try {
			$state = null !== $this->contract_state_reader ? ( $this->contract_state_reader )( $attachment_id ) : $class::state( $attachment_id );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! self::is_valid_contract_state( $state, $attachment_id ) ) {
			return null;
		}
		return $state;
	}

	/** Whether the active JIO installation exposes the supported completion contract.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether a supported contract is available for this image.
	 */
	public function has_compatible_contract( int $attachment_id ): bool {
		if ( ! $this->manages_attachment( $attachment_id ) ) {
			return false;
		}
		$detection = $this->detector->detect();
		if ( ! $detection['active'] || version_compare( $detection['version'], '0.6.2', '<' ) ) {
			return false;
		}
		if ( null !== $this->contract_state_reader ) {
			return true;
		}
		$class = '\\Jocky\\ImageOptimizer\\Integration\\AttachmentReadiness';
		return class_exists( $class ) && defined( 'JIO_VERSION' ) && version_compare( (string) constant( 'JIO_VERSION' ), '0.6.2', '>=' );
	}

	/**
	 * Whether a compatible JIO state allows a current manifest snapshot.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether snapshotting is safe.
	 */
	public function can_snapshot( int $attachment_id ): bool {
		if ( ! $this->requires_confirmation( $attachment_id ) ) {
			return true;
		}
		// Preserve the manual confirmation fallback only when no compatible JIO
		// contract exists. A present but incomplete or invalid contract stays held.
		return ! $this->has_compatible_contract( $attachment_id );
	}

	/**
	 * Ensure a compatible JIO generation stayed completed across manifest hashing.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $before State captured before hashing.
	 * @return bool Whether the state remained unchanged and terminal-successful.
	 */
	public function snapshot_still_current( int $attachment_id, ?array $before ): bool {
		if ( null === $before ) {
			return ! $this->has_compatible_contract( $attachment_id );
		}
		$after = $this->state( $attachment_id );
		return self::is_terminal_eligible( $before )
			&& is_array( $after )
			&& self::is_terminal_eligible( $after )
			&& (int) $before['contract_version'] === (int) $after['contract_version']
			&& hash_equals( (string) $before['generation'], (string) $after['generation'] )
			&& hash_equals( (string) $before['revision'], (string) $after['revision'] )
			&& hash_equals( (string) $before['outcome'], (string) $after['outcome'] );
	}

	/** Whether a public JIO state proves terminal success or a genuine terminal skip.
	 *
	 * Contract v1 reports `skipped` only after every expected item is terminal and
	 * none is completed, failed, pending, or running. Mixed completed/skipped work
	 * is `partial` and intentionally remains ineligible.
	 *
	 * @param array|null $state JIO completion state.
	 * @return bool Whether the state is eligible for manifest creation.
	 */
	public static function is_terminal_eligible( ?array $state ): bool {
		if ( ! is_array( $state ) || ! isset( $state['attachment_id'] ) || ! is_numeric( $state['attachment_id'] ) || ! self::is_valid_contract_state( $state, (int) $state['attachment_id'] ) ) {
			return false;
		}
		return in_array( (string) $state['outcome'], array( 'completed', 'skipped' ), true ) && true === $state['terminal'];
	}

	/** Whether a manifest contains the exact validated JIO snapshot it claims to represent.
	 *
	 * Legacy manifests without a contract version remain invalid for compatible JIO images.
	 *
	 * @param array $manifest Persisted or candidate manifest.
	 * @param array $state    Current validated JIO contract state.
	 * @return bool Whether contract version and snapshot identity match.
	 */
	public static function manifest_matches_state( array $manifest, array $state ): bool {
		if ( ! isset( $state['attachment_id'] ) || ! is_numeric( $state['attachment_id'] ) || ! self::is_terminal_eligible( $state ) ) {
			return false;
		}
		return isset( $manifest['jio_contract_version'], $manifest['jio_generation'], $manifest['jio_revision'], $manifest['jio_outcome'] )
			&& is_int( $manifest['jio_contract_version'] )
			&& $manifest['jio_contract_version'] === (int) $state['contract_version']
			&& is_string( $manifest['jio_generation'] )
			&& is_string( $manifest['jio_revision'] )
			&& is_string( $manifest['jio_outcome'] )
			&& hash_equals( (string) $state['generation'], $manifest['jio_generation'] )
			&& hash_equals( (string) $state['revision'], $manifest['jio_revision'] )
			&& hash_equals( (string) $state['outcome'], $manifest['jio_outcome'] );
	}

	/** Validate the versioned public contract's structural and terminal invariants.
	 *
	 * @param mixed $state Contract result.
	 * @param int   $attachment_id Expected attachment ID.
	 * @return bool Whether the result is internally consistent.
	 */
	private static function is_valid_contract_state( $state, int $attachment_id ): bool {
		if ( ! is_array( $state )
			|| $attachment_id < 1
			|| ! isset( $state['contract_version'], $state['attachment_id'] )
			|| ! is_numeric( $state['contract_version'] )
			|| ! is_numeric( $state['attachment_id'] )
			|| (int) ( $state['contract_version'] ?? 0 ) < self::MINIMUM_CONTRACT_VERSION
			|| (int) ( $state['attachment_id'] ?? 0 ) !== $attachment_id
			|| ! isset( $state['outcome'], $state['terminal'], $state['generation'], $state['revision'], $state['expected'], $state['completed'] )
			|| ! is_string( $state['outcome'] )
			|| ! is_bool( $state['terminal'] )
			|| ! is_string( $state['generation'] )
			|| ! is_string( $state['revision'] )
			|| ! is_array( $state['expected'] )
			|| ! is_array( $state['completed'] )
			|| 1 !== preg_match( '/\\A[a-f0-9]{64}\\z/', $state['generation'] )
			|| 1 !== preg_match( '/\\A[a-f0-9]{64}\\z/', $state['revision'] )
		) {
			return false;
		}

		$terminal_outcomes = array( 'completed', 'partial', 'failed', 'skipped', 'invalidated' );
		if ( ! in_array( $state['outcome'], array_merge( $terminal_outcomes, array( 'pending', 'running' ) ), true )
		|| in_array( $state['outcome'], $terminal_outcomes, true ) !== $state['terminal']
			|| ! self::is_unique_string_list( $state['expected'] )
			|| ! self::is_unique_string_list( $state['completed'] )
			|| array_diff( $state['completed'], $state['expected'] ) !== array()
		) {
			return false;
		}

		if ( 'completed' === $state['outcome'] && ( array() === $state['expected'] || count( $state['completed'] ) !== count( $state['expected'] ) ) ) {
			return false;
		}
		if ( 'skipped' === $state['outcome'] && array() !== $state['completed'] ) {
			return false;
		}
		return true;
	}

	/** Whether a contract value is a unique list of string work keys.
	 *
	 * @param array<mixed> $values Contract key list.
	 * @return bool Whether the list is structurally valid.
	 */
	private static function is_unique_string_list( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return false;
			}
		}
		return count( $values ) === count( array_unique( $values ) );
	}

	/** Whether this active JIO installation manages the attachment's image family.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether JIO readiness confirmation applies.
	 */
	private function manages_attachment( int $attachment_id ): bool {
		return $this->detector->detect()['active'] && in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true );
	}
}
