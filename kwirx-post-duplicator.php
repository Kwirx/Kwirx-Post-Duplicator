<?php
/**
 * Plugin Name: Kwirx Post Duplicator
 * Plugin URI: https://kwirx.com
 * Description: Allows duplicating posts for selected custom post types.
 * Version: 1.0.0
 * Author: Kwirx Creative
 * Author URI: https://kwirx.com
 * License: GPL2
 * Text Domain: kwirx-post-duplicator
 * Requires PHP: 7.2
 * Requires at least: 4.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Kwirx_Post_Duplicator
 */
class Kwirx_Post_Duplicator {

	/**
	 * Kwirx_Post_Duplicator constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_action_kwirx_duplicate_post_as_draft', array( $this, 'duplicate_post_as_draft' ) );
		add_filter( 'post_row_actions', array( $this, 'duplicate_post_link' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'duplicate_post_link' ), 10, 2 );
	}

	/**
	 * Adds settings page to the WordPress admin menu.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'options-general.php',
			__( 'Duplicate Post Settings', 'kwirx-post-duplicator' ),
			__( 'Duplicate Post Settings', 'kwirx-post-duplicator' ),
			'manage_options',
			'kwirx-duplicate-settings',
			array( $this, 'settings_page_callback' )
		);
	}

	/**
	 * Callback function to render the settings page.
	 */
	public function settings_page_callback() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Duplicate Post Settings', 'kwirx-post-duplicator' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'kwirx_duplicate_settings_group' );
				do_settings_sections( 'kwirx_duplicate_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Registers settings for the plugin.
	 */
	public function register_settings() {
		register_setting( 'kwirx_duplicate_settings_group', 'kwirx_duplicate_cpt_settings' );

		add_settings_section(
			'kwirx_duplicate_settings_section',
			esc_html__( 'Duplicate Post Settings', 'kwirx-post-duplicator' ),
			array( $this, 'settings_section_callback' ),
			'kwirx_duplicate_settings'
		);

		add_settings_field(
			'kwirx_duplicate_cpt_field',
			esc_html__( 'Select Custom Post Types', 'kwirx-post-duplicator' ),
			array( $this, 'cpt_field_callback' ),
			'kwirx_duplicate_settings',
			'kwirx_duplicate_settings_section'
		);
	}

	/**
	 * Callback function for settings section.
	 */
	public function settings_section_callback() {
		esc_html_e( 'Select the custom post types you want to enable the duplicate feature for.', 'kwirx-post-duplicator' );
	}

	/**
	 * Callback function for custom post types field.
	 */
	public function cpt_field_callback() {
		$cpts    = get_post_types( array( 'public' => true ), 'objects' );
		$options = get_option( 'kwirx_duplicate_cpt_settings' );

		foreach ( $cpts as $cpt ) {
			if ( $cpt->name === 'attachment' ) {
				continue;
			}
			?>
			<label>
				<input type="checkbox" name="kwirx_duplicate_cpt_settings[]" value="<?php echo esc_attr( $cpt->name ); ?>" <?php if ( is_array( $options ) && in_array( $cpt->name, $options ) ) { echo 'checked'; } ?>>
				<?php echo esc_html( $cpt->label ); ?>
			</label><br>
			<?php
		}
	}

	/**
	 * Handles the duplication of a post as a draft.
	 */
	public function duplicate_post_as_draft() {
		global $wpdb;

		if ( ! ( isset( $_GET['post'] ) || isset( $_POST['post'] ) || ( isset( $_REQUEST['action'] ) && 'kwirx_duplicate_post_as_draft' === $_REQUEST['action'] ) ) ) {
			wp_die( 'No post to duplicate has been supplied!' );
		}

		// Nonce verification
		if ( ! isset( $_GET['duplicate_nonce'] ) || ! wp_verify_nonce( $_GET['duplicate_nonce'], basename( __FILE__ ) ) ) {
			wp_die( 'Invalid nonce' );
		}

		// Get the original post ID
		$post_id = absint( isset( $_GET['post'] ) ? $_GET['post'] : $_POST['post'] );
		$post    = get_post( $post_id );

		// If post data exists, create the post duplicate
		if ( isset( $post ) && null !== $post ) {

			// New post data array
			$args = array(
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_author'    => get_current_user_id(),
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_name'      => $post->post_name,
				'post_parent'    => $post->post_parent,
				'post_password'  => $post->post_password,
				'post_status'    => 'draft',
				'post_title'     => $post->post_title,
				'post_type'      => $post->post_type,
				'to_ping'        => $post->to_ping,
				'menu_order'     => $post->menu_order,
			);

			// Insert the post by wp_insert_post() function
			$new_post_id = wp_insert_post( $args );

			// Get all current post terms and set them to the new post draft
			$taxonomies = get_object_taxonomies( $post->post_type );
			foreach ( $taxonomies as $taxonomy ) {
				$post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
				wp_set_object_terms( $new_post_id, $post_terms, $taxonomy, false );
			}

			// Duplicate all post meta
			$post_meta_infos = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );
			if ( count( $post_meta_infos ) !== 0 ) {
				$sql_query     = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) ";
				$sql_query_sel = array();
				foreach ( $post_meta_infos as $meta_info ) {
					$meta_key = $meta_info->meta_key;
					if ( '_wp_old_slug' === $meta_key ) {
						continue;
					}
					$meta_value      = addslashes( $meta_info->meta_value );
					$sql_query_sel[] = $wpdb->prepare( "SELECT %d, %s, %s", $new_post_id, $meta_key, $meta_value );
				}
				if ( $sql_query_sel ) {
					$sql_query .= implode( ' UNION ALL ', $sql_query_sel );
					$wpdb->query( $sql_query ); // db call ok. no cache ok. non user interaction ok.
				}
			}

			// Finally, redirect to the edit post screen for the new draft
			wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
			exit;
		} else {
			wp_die( 'Post creation failed, could not find original post: ' . $post_id );
		}
	}

	/**
	 * Adds the duplicate link to action list for post_row_actions.
	 *
	 * @param array   $actions An array of row action links.
	 * @param WP_Post $post    The post object.
	 *
	 * @return array
	 */
	public function duplicate_post_link( $actions, $post ) {
		if ( current_user_can( 'edit_posts' ) ) {
			$allowed_cpts = get_option( 'kwirx_duplicate_cpt_settings' );
			if ( is_array( $allowed_cpts ) && in_array( $post->post_type, $allowed_cpts, true ) ) {
				$actions['duplicate'] = sprintf(
					'<a href="%1$s" title="%2$s" rel="permalink">%3$s</a>',
					wp_nonce_url( 'admin.php?action=kwirx_duplicate_post_as_draft&post=' . $post->ID, basename( __FILE__ ), 'duplicate_nonce' ),
					esc_attr__( 'Duplicate this item', 'kwirx-post-duplicator' ),
					esc_html__( 'Duplicate', 'kwirx-post-duplicator' )
				);
			}
		}
		return $actions;
	}
}

new Kwirx_Post_Duplicator();

/**
 * Deletes plugin settings when the plugin is deleted.
 */
function kwirx_post_duplicator_delete_plugin_settings() {
	delete_option( 'kwirx_duplicate_cpt_settings' );
}

register_uninstall_hook( __FILE__, 'kwirx_post_duplicator_delete_plugin_settings' );
?>
