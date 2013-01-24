<?php
/*
Plugin Name: Sugar Calendar - Maps
Plugin URL: http://pippinsplugins.com/sugar-calendar-maps
Description: Adds easy Google maps to Sugar Event Calendar
Version: 1.1
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/


/**
 * SLoad plugin text domain
 *
 * @access      private
 * @since       1.1
 * @return      void
*/

function sc_map_load_textdomain() {

	// Set filter for plugin's languages directory
	$sc_maps_lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';

	// Traditional WordPress plugin locale filter
	$locale        = apply_filters( 'plugin_locale',  get_locale(), 'pippin_sc_maps' );
	$mofile        = sprintf( '%1$s-%2$s.mo', 'pippin_sc_maps', $locale );

	// Setup paths to current locale file
	$mofile_local  = $sc_maps_lang_dir . $mofile;
	$mofile_global = WP_LANG_DIR . '/sugar-calendar-maps/' . $mofile;

	if ( file_exists( $mofile_global ) ) {
		// Look in global /wp-content/languages/sugar-calendar-maps folder
		load_textdomain( 'pippin_sc_maps', $mofile_global );
	} elseif ( file_exists( $mofile_local ) ) {
		// Look in local /wp-content/plugins/sugar-event-calendar-google-maps/languages/ folder
		load_textdomain( 'pippin_sc_maps', $mofile_local );
	} else {
		// Load the default language files
		load_plugin_textdomain( 'pippin_sc_maps', false, $sc_maps_lang_dir );
	}

}
add_action( 'init', 'sc_map_load_textdomain' );


/**
 * Show admin address field
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function sc_maps_add_forms_meta_box() {

	global $post;

	$address = get_post_meta( $post->ID, 'sc_map_address', true );

	echo '<tr class="sc_meta_box_row">';

		echo '<td class="sc_meta_box_td" colspan="2" valign="top">' . __('Event Location', 'pippin_sc_maps') . '</td>';

		echo '<td class="sc_meta_box_td" colspan="4">';

			echo '<input type="text" class="regular-text" name="sc_map_address" value="' . $address . '"/>&nbsp;';

			echo '<span class="description">' . __('Enter the event address.', 'pippin_sc_maps') . '</span><br/>';

			echo '<input type="hidden" name="sc_maps_meta_box_nonce" value="' . wp_create_nonce(basename(__FILE__)) . '" />';

		echo '</td>';

	echo '</tr>';

}
add_action('sc_event_meta_box_after', 'sc_maps_add_forms_meta_box');



/**
 * Save Address field
 *
 * Save data from meta box.
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function sc_maps_meta_box_save( $event_id ) {
	global $post;

	// verify nonce
	if (!isset($_POST['sc_maps_meta_box_nonce']) || !wp_verify_nonce($_POST['sc_maps_meta_box_nonce'], basename(__FILE__))) {
		return $event_id;
	}

	// check autosave
	if ( (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ( defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit']) ) return $event_id;

	//don't save if only a revision
	if ( isset($post->post_type) && $post->post_type == 'revision' ) return $event_id;

	// check permissions
	if (!current_user_can('edit_post', $event_id)) {
		return $event_id;
	}

	$address = sanitize_text_field( $_POST['sc_map_address'] );
	update_post_meta($event_id, 'sc_map_address', $address);

}
add_action('save_post', 'sc_maps_meta_box_save');


/**
 * Displays the event map
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function sc_maps_show_map( $event_id ) {

	$address = get_post_meta( $event_id, 'sc_map_address', true );
	if( $address ) :
		$coordinates = sc_maps_get_coordinates( $address );

		if( !is_array( $coordinates ) )
			return;

	    $out = '<div class="sc-map-canvas" id="sc_map_' . $event_id . '" style="height: 450px;"></div>' . "\n\n";
	    $out .= '<script type="text/javascript">
var map_' . $event_id . ';
function sc_run_map_' . $event_id . '(){
var myLatlng = new google.maps.LatLng(' . $coordinates['lat'] . ', ' . $coordinates['lng'] . ');
var map_options = {
zoom: 15,
center: myLatlng,
mapTypeId: google.maps.MapTypeId.ROADMAP
}
map_' . $event_id . ' = new google.maps.Map(document.getElementById("sc_map_' . $event_id . '"), map_options);
var marker = new google.maps.Marker({
position: myLatlng,
map: map_' . $event_id . '
});}</script>';
    	$out .= '<script type="text/javascript">sc_run_map_' . $event_id . '();</script>';
		echo $out;
	endif;
}
add_action( 'sc_after_event_content', 'sc_maps_show_map' );


/**
 * Loads Google Map API on single event pages
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function sc_maps_load_scripts() {
	if( is_singular( 'sc_event' ) )
		wp_enqueue_script( 'google-maps-api', 'http://maps.google.com/maps/api/js?sensor=false' );
}
add_action( 'wp_enqueue_scripts', 'sc_maps_load_scripts' );



/**
 * Retrieve coordinates for an address
 *
 * Coordinates are cached using transients and a hash of the address
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function sc_maps_get_coordinates($address, $force_refresh = false) {
    $address_hash = md5($address);

    if ($force_refresh || ($coordinates = get_transient($address_hash)) === false) {
    	$url = 'http://maps.google.com/maps/geo?q=' . urlencode($address) . '&output=xml';

     	$response = wp_remote_get( $url );

     	if( is_wp_error( $response ) )
     		return;

     	$xml = wp_remote_retrieve_body( $response );

     	if( is_wp_error( $xml ) )
     		return;

		if ($response['response']['code'] == 200) {

			$data = new SimpleXMLElement($xml);

			if ($data->Response->Status->code == 200) {
			  	$coordinates = $data->Response->Placemark->Point->coordinates;

			  	//Placemark->Point->coordinates;
			  	$coordinates = explode(',', $coordinates[0]);
			  	$cache_value['lat'] = $coordinates[1];
			  	$cache_value['lng'] = $coordinates[0];
			  	$cache_value['address'] = (string) $data->Response->Placemark->address[0];

			  	// cache coordinates for 3 months
			  	set_transient($address_hash, $cache_value, 3600*24*30*3);
			  	$data = $cache_value;
			} elseif ($data->Response->Status->code == 602) {
			  	return 'Unable to parse entered address. API response code: ' . @$data->Response->Status->code;
			} else {
			   	return 'XML parsing error. Please try again later. API response code: ' . @$data->Response->Status->code;
			}

		} else {
		 	return 'Unable to contact Google API service.';
		}
    } else {
       // Cache the results
       $data = get_transient($address_hash);
    }

    return $data;
}