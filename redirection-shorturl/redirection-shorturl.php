<?php
/*
Plugin Name: Redirection Shortlink Generator
Plugin URI: http://www.njstatelib.org/
Description: Use Redirection to host shortlinks for your site's content
Version: 1.0
Author: David Dean for NJSL
Author URI: http://www.njstatelib.org/
 */

register_activation_hook( __FILE__, array( 'Redirection_Shortlink', 'activate' ) );

add_filter( 'pre_get_shortlink',    array( 'Redirection_Shortlink', 'filter_shortlink' ), 10, 4 );
add_action( 'admin_head-post.php' , array( 'Redirection_Shortlink', 'add_scripts' ) );
add_action( 'save_post',            array( 'Redirection_Shortlink', 'save_shortlink' ) );
add_action( 'before_delete_post',   array( 'Redirection_Shortlink', 'delete_post_shortlink' ) );
add_action( 'wp_ajax_redirection_shortlink_check_path', array( 'Redirection_Shortlink', 'ajax_check_path' ) );

class Redirection_Shortlink {
	
	public static function activate() {
		
		if( ! self::is_redirection_active() ) {
			wp_die( __( 'Redirection must be installed and activated to use this plugin.', 'njsl-redirection-shortlink' ) );
		}
		
		// Check for existing shortlink group
		if( $result = get_option( 'redirection_shortlink_groupID', false ) ) {
			return;
		}
		
		// Create Redirection group to hold shortlinks
		
		// Get all modules
		$mods = Red_Module::get_all();

		foreach( $mods as $mod ) {
			
			// Create the group in the first WP module
			if( is_a( $mod, 'WordPress_Module' ) ) {
				
				$group_ID = Red_Group::create(array(
					'name'      => __( 'Shortlinks', 'njsl-redirection-shortlink' ),
					'module_id' => (int)$mod->get_id()
				));
				
				break;
				
			}
			
		}
		
		if( ! empty( $group_ID ) ) {
			update_option( 'redirection_shortlink_groupID', $group_ID );
			return;
		}
		
		wp_die( __( 'Unable to create shortlink group!', 'njsl-redirection-shortlink' ) );
		
	}
	
	/**
	 * Filter shortlink on pre_get_shortlink
	 */
	public static function filter_shortlink( $return_val, $id, $context, $allow_slugs ) {
		
		if( ! self::is_redirection_active() ) return $return_val;
		
		$link = self::get_shortlink( $id );
		if( $link ) {
			return home_url( $link->url );
		}
		return false;
		
	}
	
	/**
	 * Delete associated shortlink when deleting a post
	 */
	public static function delete_post_shortlink( $post_ID ) {
		
		self::delete_shortlink( $post_ID );
		
	}
	
	/**
	 * These functions enhance the "Get Shortlink" box to allow editors to set the shortlink inline
	 */
	
	/**
	 * Add JS to the admin footer 
	 */
	public static function add_scripts() {
		?>
		<script type='text/javascript'>
			jQuery(document).ready( function( $ ) {
				$( '#shortlink' ).next( 'a' ).attr( 'id', 'get-shortlink' ); // Tag the "Get Shortlink" button
				$( '#get-shortlink' ).attr( 'onclick', 'window.user_shortlink = ' + "prompt('URL:', jQuery('#shortlink').val()); return false;" );
				$( '#get-shortlink' ).html( 'Edit Shortlink' );
				$( '#get-shortlink' ).on( 'click', function( e ) {
					
					if( '' == window.user_shortlink || null == window.user_shortlink )	return;
					
					// Fire AJAX request to check requested path
					$.post(
						ajaxurl,
						{
							action : 'redirection_shortlink_check_path',
							path   : window.user_shortlink
						},
						function( response ) {
							
							if( 'success' == response.status ) {
								// TODO: Notify user that shortlink is OK
								jQuery( '#shortlink' ).val( response.path );
								jQuery( '#shortlink' ).attr( 'name', 'shortlink' );  // Set name attribute to pass Shortlink via POST
							} else {
								alert( 'Error with your shortlink: ' + response.error );
								jQuery( '#shortlink' ).removeAttr( 'name' );         // Clear the name attribute to prevent POSTing
							}
						}
					);
					
				});
			});
		</script>
		<?php
	}
	
	public static function save_shortlink( $post_ID ) {
		
		if( empty( $_POST['shortlink'] ) )
			return;
		
		if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;
		
		$path = self::sanitize_path( $_POST['shortlink'] );
		
		// Do not update if shortlink is default or unchanged
		if( wp_get_shortlink( $post_ID ) == trim( $_POST['shortlink'] ) )
			return;
		
		// Check whether a shortlink exists for this post
		if( ! self::has_shortlink( $post_ID ) ) {
			
			// If not, create new shortlink
			self::create_shortlink( $path, $post_ID );
			return;
			
		}
		
		// If so, check whether selected path changes that shortlink
		if( $path != self::get_shortlink( $post_ID ) ) {
			
			// If so, delete old shortlink and create new one
			self::delete_shortlink( $post_ID );
			self::create_shortlink( $path, $post_ID );
			return;
		}
		
		// If not, we are done here
		return;
		
	}
	
	public static function ajax_check_path() {
		
		$path = $_POST['path'];
		
		header( 'Content-Type: application/json' );
		
		if( self::is_path_in_use( self::sanitize_path( $path ) ) ) {
			echo json_encode(array(
				'status' => 'error',
				'error'  => __( 'Path in use. Please try another path.', 'njsl-redirection-shortlink' )
			));
		} else {
			
			$path = self::sanitize_path( $path );
			
			// This adds support for subdir installs
			$home_url = parse_url( home_url( '/' ) );
			$path = str_replace( $home_url['path'], '/', $path );

			$path = home_url( '/' ) . ltrim( $path, '/' );
			
			echo json_encode(array(
				'status' => 'success',
				'path'   => $path
			));
		}
		
		die();
	}
	
	/**
	 * These functions are needed for any implementation
	 */
	
	// Verify that Redirection is installed and active
	private static function is_redirection_active() {
		return class_exists( 'Red_Item' );
	}
	
	/**
	 * Check for a shortlink corresponding to this post or page
	 */
	private static function has_shortlink( $post_ID ) {
		$result = self::get_shortlink( $post_ID );
		if( $result )
			return $result->url;
		return false;
	}
	
	/**
	 * Retrieve shortlink for a post or page
	 */
	private static function get_shortlink( $post_ID ) {
		
		global $wpdb;
		
		if( $result = wp_cache_get( (int)$post_ID, 'redirection_shortlink' ) ) {
			return $result;
		}
		
		$shortlinks = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'redirection_items WHERE match_type=%s AND action_type=%s AND action_data=%s AND group_id=%d',
				'url',
				'url',
				self::get_permalink( $post_ID ),
				self::get_shortlink_group_id()
			)
		);
		
		if( ! empty( $shortlinks ) ) {
			$shortlink = new Red_Item( $shortlinks );
			wp_cache_set( (int)$post_ID, $shortlink, 'redirection_shortlink' );
			return $shortlink;
		}
		return false;
	}
	
	/**
	 * Create shortlink for a post or page
	 */
	private static function create_shortlink( $path, $post_ID ) {
		
		$result = Red_Item::create(array(
			'source'      => $path,
			'target'      => self::get_permalink( $post_ID ),
			'red_action'  => 'url',
			'match'       => 'url',
			'group'       => (int)self::get_shortlink_group_id(),
			'match_type'  => 'url', // These two are not needed, but including them suppresses a warning
			'action_type' => 'url'
		));
		
		return $result;
		
	}
	
	/**
	 * Delete shortlink for a post or page
	 */
	private static function delete_shortlink( $post_ID ) {
		
		$shortlink = self::get_shortlink( $post_ID );
		if( $shortlink ) {
			$shortlink->delete();
		}
		return;
		
	}
	
	/**
	 * Clean up user's short URL
	 * Shortlink should be stored as root-relative for Redirection compatibilty, but served as absolute URLs.
	 */
	private static function sanitize_path( $path ) {
		
		$path = urldecode( $path );
		
		if( 0 === stripos( $path, home_url( '/' ) ) ) {
			
			$path = str_replace( home_url( '/' ), '/', $path );
			$path = ltrim( $path, '/' );
			
			// This adds support for subdir installs
			$home_url = parse_url( home_url( '/' ) );
			$path = $home_url['path'] . $path;
		}
		
		if( 0 === stripos( $path, 'http' ) ) {
			$path = preg_replace( '|http(s?)\:\/\/([^\/]+)(\/.+)$|', '$3', $path );
		}
		
		$path = '/' . ltrim( $path, '/' );
		return $path;
		
	}
	
	private static function get_permalink( $post_ID ) {
		
		$post = get_post( $post_ID );
		if( ! $post || is_wp_error( $post ) )
			return false;
		
		$post_type = ( $post->post_type == 'page' ? 'page_id' : ( $post->post_type == 'post' ? 'p' : $post->post_type ) );
		$permalink = home_url( '/' ) . '?' . $post_type . '=' . $post_ID;
		return $permalink;
		
	}
	
	/**
	 * Make sure the selected URL is not in use by another Redirection entry or WP page
	 */
	private static function is_path_in_use( $path ) {
		
		// Stop processing if Redirection can't be found
		if( ! self::is_redirection_active() ) return true;
		
		// Mark in use if any redirect uses this path
		if( Red_Item::exists( $path ) ) return true;
		
		// Mark in use if any page uses this path
		if( get_page_by_path( $path ) ) return true;
		
		return false;
		
	}
	
	/**
	 * Return the id of the Redirection shortlink group 
	 */
	private static function get_shortlink_group_id() {
		return (int)get_option( 'redirection_shortlink_groupID' );
	}
	
	
}