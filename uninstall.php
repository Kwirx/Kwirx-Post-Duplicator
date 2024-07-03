<?php
// If uninstall is not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete the plugin settings if the plugin is deleted.
delete_option( 'kwirx_duplicate_cpt_settings' );
?>
