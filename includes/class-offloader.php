<?php
/**
 * Offloader — pushes new uploads (original + every size) to R2.
 *
 * Mode-aware: CDN keeps local copies as a fallback; Stateless removes them.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Offloader {

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/**
	 * @param R2_Client $client
	 * @param Settings  $settings
	 */
	public function __construct( R2_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook into the media pipeline.
	 */
	public function register() {
		// Fires after WordPress has generated every registered size.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload' ), 10, 2 );
		// Mirror deletions to R2.
		add_action( 'delete_attachment', array( $this, 'delete' ) );
	}

	/**
	 * Offload an attachment's original + all sizes to R2.
	 *
	 * @param array $metadata      Attachment metadata (passes through unchanged).
	 * @param int   $attachment_id
	 * @return array
	 */
	public function offload( $metadata, $attachment_id ) {
		if ( ! $this->settings->is_configured() ) {
			return $metadata;
		}

		$files = $this->collect_files( $metadata, $attachment_id );
		if ( empty( $files ) ) {
			return $metadata;
		}

		$cache_control = $this->settings->get( 'cache_control' );
		$uploaded      = 0;

		foreach ( $files as $local_path => $key ) {
			if ( ! is_readable( $local_path ) ) {
				continue;
			}
			$result = $this->client->upload_file( $local_path, $key, '', array( 'Cache-Control' => $cache_control ) );
			if ( is_wp_error( $result ) ) {
				// Leave local copies in place if any upload fails — never strand media.
				return $metadata;
			}
			++$uploaded;
		}

		if ( $uploaded > 0 ) {
			update_post_meta( $attachment_id, '_r2offload_synced', 1 );
			update_post_meta( $attachment_id, '_r2offload_synced_at', time() );

			// Stateless mode: now that every file is safely in R2, drop local copies.
			if ( 'stateless' === $this->settings->get( 'mode' ) ) {
				foreach ( array_keys( $files ) as $local_path ) {
					if ( file_exists( $local_path ) ) {
						wp_delete_file( $local_path );
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Remove an attachment's original + all sizes from R2.
	 *
	 * @param int $attachment_id
	 */
	public function delete( $attachment_id ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$files    = $this->collect_files( is_array( $metadata ) ? $metadata : array(), $attachment_id );
		foreach ( $files as $key ) {
			$this->client->delete_object( $key );
		}
	}

	/**
	 * Build a map of local-path => R2-key for the original and every size.
	 *
	 * The R2 key is the file's path relative to the uploads dir
	 * (i.e. the `_wp_attached_file` value), so it maps 1:1 to WordPress's
	 * canonical location and the URL rewriter can reconstruct it.
	 *
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array<string,string>  local_path => r2_key
	 */
	private function collect_files( $metadata, $attachment_id ) {
		$relative = isset( $metadata['file'] )
			? $metadata['file']
			: get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! $relative ) {
			return array();
		}

		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );
		$subdir  = dirname( $relative );
		$prefix  = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );

		$files = array();

		// Original.
		$files[ $basedir . $relative ] = $relative;

		// Every registered size (incl. theme/plugin custom sizes).
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$key                     = $prefix . $size['file'];
				$files[ $basedir . $key ] = $key;
			}
		}

		return $files;
	}
}
