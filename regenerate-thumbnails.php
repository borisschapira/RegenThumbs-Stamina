<?php
/***************************************************************************
Plugin Name:  RegenThumbs Stamina
Plugin URI:   
Description:  Allows you to regenerate all thumbnails after changing the thumbnail sizes.
Version:      0.7
Author:       Boris Schapira
Author URI:   http://www.borisschapira.com
**************************************************************************
This program is free software: you can redistribute it and/or modifyit under the terms of the GNU General Public License as published bythe Free Software Foundation, either version 3 of the License, or(at your option) any later version.
This program is distributed in the hope that it will be useful,but WITHOUT ANY WARRANTY; without even the implied warranty ofMERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See theGNU General Public License for more details.
You should have received a copy of the GNU General Public Licensealong with this program.  If not, see <http://www.gnu.org/licenses/>.**************************************************************************/
class RegenerateThumbnails {
	// Plugin initialization
	function RegenerateThumbnails() {
	if ( !function_exists('admin_url') )
			return false;
		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "localization" folder and name it "regenthumbs-stamina-[value in wp-config].mo"
		load_plugin_textdomain( 'regenthumbs-stamina', false, '/regenthumbs-stamina/localization' );
		add_action( 'admin_menu', array(&$this, 'add_admin_menu') );
		add_action( 'admin_enqueue_scripts', array(&$this, 'admin_enqueues') );
		add_action( 'wp_ajax_regeneratethumbnail', array(&$this, 'ajax_process_image') );
	}

	// Register the management page
	function add_admin_menu() {
		add_management_page( __( 'RegenThumbs Stamina', 'regenthumbs-stamina' ), __( 'RegenThumbs Stamina', 'regenthumbs-stamina' ), 'manage_options', 'regenthumbs-stamina', array(&$this, 'regenerate_interface') );
	}

	// Enqueue the needed Javascript and CSS
	function admin_enqueues( $hook_suffix ) {
		if ( 'tools_page_regenthumbs-stamina' != $hook_suffix )
			return;

		wp_enqueue_script( 'jquery-ui-custom', plugins_url( 'jquery-ui-js/jquery-ui-1.8.5.custom.min.js', __FILE__ ), array('jquery'), '1.8.5' );
		wp_enqueue_style( 'jquery-ui-regenthumbs', plugins_url( 'jquery-ui-css/start/jquery-ui-1.8.5.custom.css', __FILE__ ), array(), '1.8.5' );
	}

	// The user interface plus thumbnail regenerator
	function regenerate_interface() {
		global $wpdb;

		?>

<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap regenthumbs">
	<h2><?php _e('RegenThumbs Stamina', 'regenthumbs-stamina'); ?></h2>

<?php
		// If the button was clicked
		if ( !empty($_POST['regenthumbs-stamina']) ) {
			// Capability check
			if ( !current_user_can('manage_options') )
				wp_die( __('Cheatin&#8217; uh?') );

			// Form nonce check
			check_admin_referer( 'regenthumbs-stamina' );
			
			// Just query for the IDs only to reduce memory usage
			// Distinguish two cases : images IDs are given or not
			if ( !empty($_POST['regenthumbs-stamina-media-id']) ) {
				$images = $wpdb->get_results( sprintf("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID IN (%s)", mysql_real_escape_string($_POST['regenthumbs-stamina-media-id'])) );
			}
			else {
				$images = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
			}

			// Make sure there are images to process
			if ( empty($images) ) {
				echo '	<p>' . sprintf( __( "Unable to find any images. Are you sure <a href='%s'>some exist</a>?", 'regenthumbs-stamina' ), admin_url('upload.php?post_mime_type=image') ) . "</p>\n\n";
			}

			// Valid results
			else {
				echo '	<p>' . __( "Please be patient while all thumbnails are regenerated. This can take a while if your server is slow (cheap hosting) or if you have many images. Do not navigate away from this page until this script is done or all thumbnails won't be resized. You will be notified via this page when all regenerating is completed.", 'regenthumbs-stamina' ) . '</p>';

				// Generate the list of IDs
				$ids = array();
				foreach ( $images as $image )
					$ids[] = $image->ID;
				$ids = implode( ',', $ids );
				$count = count( $images );
?>
	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'regenthumbs-stamina' ) ?></em></p></noscript>
	<div id="regenthumbsbar" style="position:relative;height:25px;">
		<div id="regenthumbsbar-percent" style="position:absolute;left:20%;top:50%;width:500px;margin-left:-25px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>
	<p><input type="button" class="button hide-if-no-js" name="regenthumbs-stamina-stop" id="regenthumbs-stamina-stop" value="<?php _e( 'Stop the regeneration', 'regenthumbs-stamina' ) ?>" /></p>
	<h3><?php _e( 'Informations', 'regenthumbs-stamina' )?></h3>
	<p><?php _e( 'Number of images to resize : ', 'regenthumbs-stamina' )?><span id="imagecount"></span></p>
	<p><?php _e( 'Success rate : ', 'regenthumbs-stamina' )?><span id="successratevalue"></span> %</p>
	</div>
	<div id="regenthumbslog">
	<h3><?php _e( 'Full log', 'regenthumbs-stamina' )?> </h3>
	<p>
	<ul id="log_regenthumb"></ul>
	</p>
	</div>
	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_images = [<?php echo $ids; ?>];
			var rt_total = rt_images.length;
			var rt_count = 1;
			var rt_success = 0;
			var rt_errors = 0;
			var rt_percent = 0;
			var rt_continue = true;
			$("#regenthumbsbar").progressbar();
			$("#regenthumbsbar-percent").html( "0%" );
			$("#imagecount").html(rt_total);
			$("#successratevalue").html("100");
			
			$('#regenthumbs-stamina-stop').click(function() {
				rt_continue = false;				
				$('#regenthumbs-stamina-stop').val('<?php echo esc_js( __( 'Stopping...', 'regenthumbs-stamina' ))?>');
			});
			
			// Updates log info
			function UpdateRegenInfos(id, success) {
				var SUCCESSTEXT = "<?php echo esc_js( __( 'Success !', 'regenthumbs-stamina' ))?>";
				var ERRORTEXT = "<?php echo esc_js( __(  'Error :(', 'regenthumbs-stamina' ))?>";
				
				if(success){
					rt_success = rt_success + 1;
					$("#log_regenthumb").append('<li><?php echo esc_js( __( 'Id : ', 'regenthumbs-stamina' ))?><a title="<?php echo esc_js( __( 'Edit the media', 'regenthumbs-stamina' ))?>" target="_blank" class="regenthumbs-success-id" href="media.php?action=edit&attachment_id=' + id + '">' + id + '</a>... ' + SUCCESSTEXT + '</li>');
				}
				else{
					rt_errors = rt_errors + 1;
					$("#log_regenthumb").append('<li><?php echo esc_js( __( 'Id : ', 'regenthumbs-stamina' ))?><a title="<?php echo esc_js( __( 'Edit the media', 'regenthumbs-stamina' ))?>" target="_blank" class="regenthumbs-error-id" href="media.php?action=edit&attachment_id=' + id + '">' + id + '</a>... ' + ERRORTEXT + '</li>');
				}
				rt_percent = Math.round(( rt_count / rt_total ) * 1000)/10;
				$("#regenthumbsbar").progressbar( "value", rt_percent );
				$("#regenthumbsbar-percent").html( rt_percent + "%");
				if((rt_errors + rt_success) > 0){
					$("#successratevalue").html(Math.round((rt_success / (rt_errors + rt_success)) * 1000)/10);
				}
				rt_count = rt_count + 1;
			}
			// When the process is finished, shows end messages and proproses to
			// relaunch on errors
			function FinishProcess() {
				$('#regenthumbs-stamina-stop').css('display', 'none');
				var finishedMessage = '<?php echo esc_js( __( 'All done! Number of images processed : ', 'regenthumbs-stamina' )) ?>' + (rt_success + rt_errors) + '. ';
				var $errors = $("#log_regenthumb .regenthumbs-error-id");
				if ($errors.length) {
					var media_ids;
					$errors.each(function(index) {
						if(index) {
							media_ids = media_ids + ',' + $(this).text();
						}
						else {
							media_ids = $(this).text();
						}
					});
					finishedMessage = finishedMessage + '<?php echo esc_js( __( 'There are some errors. ', 'regenthumbs-stamina' ) ); ?>' + '<a href="tools.php?page=regenthumbs-stamina&amp;media_ids=' + media_ids + '" ><?php echo esc_js( __( 'Relaunch RegenThumbs Stamina on these specific medias. ', 'regenthumbs-stamina' ) ); ?></a>' ;
				}
				else {
					finishedMessage = finishedMessage + '<?php echo esc_js( __( 'No errors (not even a tiny little one, how wonderful) ! ', 'regenthumbs-stamina' ) ); ?>';
				}
				$("#message").html("<p><strong>" + finishedMessage + "</strong></p>");
				$("#message").show();
			}

			// Regenerate thumbnails for a specific media id
			function RegenThumbs( id ) {
				$.ajax({
					type: 'POST',
					url: "admin-ajax.php",
					data: { action: "regeneratethumbnail", id: id },
					success: function() 
					{
						UpdateRegenInfos(id, "true");
						if ( rt_images.length && rt_continue ) {
							RegenThumbs( rt_images.shift() );
						} 
						else {
							FinishProcess();
						}
					},
					error:function ()
					{
						UpdateRegenInfos(id, "false");
						if ( rt_images.length && rt_continue ) {
							RegenThumbs( rt_images.shift() );
						} 
						else {
							FinishProcess();
						}
					}
				});
			}

			RegenThumbs( rt_images.shift() );
		});
	// ]]>
	</script>
<?php
			}
		}
		// No button click? Display the form.
		else {
?>
	<?php if ( !empty($_GET['media_ids']) ) {?>
	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var regenUrlVars;
	
			function getUrlVars() {
				var vars = [], hash;
				var hashes = window.location.href.slice(window.location.href.indexOf('?') + 1).split('&');
				for(var i = 0; i < hashes.length; i++)
				{
					hash = hashes[i].split('=');
					vars.push(hash[0]);
					vars[hash[0]] = hash[1];
				}
				regenUrlVars = vars;
			}
			
			getUrlVars();
			$("#regenthumbs-stamina-media-id").val(regenUrlVars['media_ids']);
		});
	// ]]>
	<?php } ?>
	</script>
	<p><?php printf( __( "Use this tool to regenerate thumbnails for all images that you have uploaded to your blog. This is useful if you've changed any of the thumbnail dimensions on the <a href='%s'>media settings page</a>. Old thumbnails will be kept to avoid any broken images due to hard-coded URLs.", 'regenthumbs-stamina'), admin_url('options-media.php') ); ?></p>
	<p><?php _e( "This process is not reversible, although you can just change your thumbnail dimensions back to the old values and click the button again if you don't like the results.", 'regenthumbs-stamina'); ?></p>
	<p><?php _e( "To begin, just press the button below.", 'regenthumbs-stamina'); ?></p>
	<form method="post" action="">
	
	<?php wp_nonce_field('regenthumbs-stamina') ?>
	<p><?php _e( "Id(s) of image(s) to regenerate, comma-separated (optional)", 'regenthumbs-stamina'); ?> <input type="text" class="hide-if-no-js" name="regenthumbs-stamina-media-id" id="regenthumbs-stamina-media-id" /></p>
	<p><input type="submit" class="button hide-if-no-js" name="regenthumbs-stamina" id="regenthumbs-stamina" value="<?php _e( 'Regenerate All Thumbnails', 'regenthumbs-stamina' ) ?>" /></p>
	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'regenthumbs-stamina' ) ?></em></p></noscript>
	</form>
<?php
		} // End if button
?>
</div>
<?php
	}

	// Process a single image ID (this is an AJAX handler)
	function ajax_process_image() {
		if ( !current_user_can( 'manage_options' ) )
			die('-1');
		$id = (int) $_REQUEST['id'];
		if ( empty($id) )
			die('-1');
		$fullsizepath = get_attached_file( $id );
		if ( false === $fullsizepath || !file_exists($fullsizepath) )
			die('-1');
		set_time_limit( 60 );
		if ( wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $fullsizepath ) ) )
			die('1');
		else
			die('-1');
	}
}

// Start up this plugin
add_action( 'init', 'RegenerateThumbnails' );
function RegenerateThumbnails() {
	global $RegenerateThumbnails;
	$RegenerateThumbnails = new RegenerateThumbnails();
}

/**
 * Hooks
 */
add_filter('wp_generate_attachment_metadata', 'wp_smushit_resize_from_meta_data');
add_filter('manage_media_columns', 'wp_regenthumbs_stamina_columns');
add_action('manage_media_custom_column', 'wp_regenthumbs_stamina_custom_column', 10, 2);

/**
 * Print column header for RegenThumbs Stamina results in the media library using
 * the `manage_media_columns` hook.
 */
function wp_regenthumbs_stamina_columns($defaults) {
	$defaults['regenthumbs-stamina'] = 'RegenThumbs Stamina';
	return $defaults;
}

/**
 * Print column data for RegenThumbs Stamina action in the media library using
 * the `manage_media_custom_column` hook.
 */
function wp_regenthumbs_stamina_custom_column($column_name, $id) {
    if( $column_name == 'regenthumbs-stamina' ) {
   		printf("<br><a href=\"tools.php?page=regenthumbs-stamina&amp;media_ids=%d\">%s</a>",$id, __('Regen. Thumbnail', 'regenthumbs-stamina'));
    }
}

?>