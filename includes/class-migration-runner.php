<?php
/**
 * Migration runner — background, resumable media migration (SWR-331).
 *
 * Drives Migrator::migrate_batch() in the background via WP-Cron so the job
 * survives the admin closing the tab. Progress lives in a single option; the
 * admin UI polls it and may also advance a batch for responsiveness. Same
 * batch engine as the WP-CLI command, so results match exactly.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Migration_Runner {

	const STATE_OPTION = 'r2offload_migration';
	const CRON_HOOK    = 'r2offload_migrate_tick';
	const BATCH        = 100;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the cron callback that advances the migration in the background.
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'tick' ) );
	}

	/**
	 * Default (idle) state shape.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'running'     => false,
			'mode'        => 'upload', // upload | dry-run | verify
			'cursor'      => '',
			'processed'   => 0,
			'uploaded'    => 0,
			'skipped'     => 0,
			'errors'      => 0,
			'bytes'       => 0,
			'total'       => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'last_error'  => '',
		);
	}

	/**
	 * Current migration state (merged onto defaults).
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return array_merge( self::default_state(), is_array( $state ) ? $state : array() );
	}

	/**
	 * Start (or restart) a migration in the given mode.
	 *
	 * @param string $mode upload | dry-run | verify
	 * @return array New state.
	 */
	public function start( $mode = 'upload' ) {
		$mode  = in_array( $mode, array( 'upload', 'dry-run', 'verify' ), true ) ? $mode : 'upload';
		$state = self::default_state();
		$state['running']    = true;
		$state['mode']       = $mode;
		$state['total']      = $this->count_attachments();
		$state['started_at'] = time();
		update_option( self::STATE_OPTION, $state, false );

		$this->schedule_next();
		return $state;
	}

	/**
	 * Stop a running migration. Progress is preserved so it can be resumed.
	 *
	 * @return array New state.
	 */
	public function stop() {
		$state            = $this->state();
		$state['running'] = false;
		update_option( self::STATE_OPTION, $state, false );
		$this->clear_scheduled();
		return $state;
	}

	/**
	 * Process exactly one batch and persist progress. Safe to call from the
	 * cron tick or the status poll. Re-schedules itself until done.
	 *
	 * @return array Current state after the batch.
	 */
	public function run_one_batch() {
		$state = $this->state();
		if ( empty( $state['running'] ) ) {
			return $state;
		}

		// Mutex so the cron tick and the status poll can't process the same
		// cursor concurrently. If another worker holds it, just report state.
		if ( get_transient( 'r2offload_migration_lock' ) ) {
			return $state;
		}
		set_transient( 'r2offload_migration_lock', 1, 2 * MINUTE_IN_SECONDS );

		$migrator = new Migrator( null, $this->settings );
		$migrator->set_dry_run( 'dry-run' === $state['mode'] )
			->set_verify( 'verify' === $state['mode'] );

		$result = $migrator->migrate_batch( self::BATCH, (string) $state['cursor'] );

		$state['processed'] += (int) $result['processed'];
		$state['uploaded']  += (int) $result['uploaded'];
		$state['skipped']   += (int) $result['skipped'];
		$state['errors']    += count( $result['errors'] );
		$state['bytes']     += (int) $result['bytes'];
		$state['cursor']     = (string) $result['next_cursor'];
		if ( ! empty( $result['errors'] ) ) {
			$state['last_error'] = (string) end( $result['errors'] );
		}

		if ( ! empty( $result['done'] ) ) {
			$state['running']     = false;
			$state['finished_at'] = time();
			update_option( self::STATE_OPTION, $state, false );
			$this->clear_scheduled();
			delete_transient( 'r2offload_migration_lock' );
			return $state;
		}

		update_option( self::STATE_OPTION, $state, false );
		$this->schedule_next();
		delete_transient( 'r2offload_migration_lock' );
		return $state;
	}

	/**
	 * Cron callback — advance one batch in the background.
	 */
	public function tick() {
		$this->run_one_batch();
	}

	/**
	 * Schedule the next background tick (a few seconds out) if not already due.
	 */
	private function schedule_next() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Cancel any pending tick.
	 */
	private function clear_scheduled() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Count non-trashed attachments (the migration total).
	 *
	 * @return int
	 */
	private function count_attachments() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
		);
	}
}
