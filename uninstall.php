<?php
/**
 * Uninstall cleanup for R2 Stateless Media Offload.
 *
 * Runs only when the plugin is DELETED (not on deactivate). Removes the plugin's
 * own options and post-meta so nothing — including the encrypted Secret Access
 * Key — is left behind in the database.
 *
 * IMPORTANT: this NEVER deletes media. It touches only plugin bookkeeping
 * (options + `_r2offload_*` post-meta). The objects already in R2 and any local
 * files are left exactly as they are — uninstalling the plugin must not destroy
 * the user's media library. (If the site was running in Stateless mode, switch
 * back to CDN mode and pull media local again BEFORE deleting the plugin, since
 * without the plugin nothing rewrites URLs to R2.)
 *
 * @package R2Offload
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's options and post-meta for the current site.
 */
function r2offload_uninstall_cleanup_site() {
	// Options: settings (incl. the encrypted secret), migration state, batch lock.
	delete_option( 'r2offload_settings' );
	delete_option( 'r2offload_migration' );
	delete_option( 'r2offload_migration_lock' );

	// Per-attachment bookkeeping. delete_post_meta_by_key removes the key from
	// every post in one query each. This only forgets which R2 object an
	// attachment maps to — it does not delete the object or any file.
	delete_post_meta_by_key( '_r2offload_synced' );
	delete_post_meta_by_key( '_r2offload_synced_at' );
	delete_post_meta_by_key( '_r2offload_key' );
}

// Options and post-meta are per-site, so on a network clean every site — mirrors
// Migration_Runner::on_deactivate(). number => 0 means "no limit".
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $r2offload_site_id ) {
		switch_to_blog( (int) $r2offload_site_id );
		r2offload_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	r2offload_uninstall_cleanup_site();
}
