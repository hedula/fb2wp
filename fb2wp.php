<?php
/**
 * Plugin Name: Facebook to WordPress
 * Plugin URI: 
 * Description: This plugin import facebook post data(json) as wordpress post.
 * Version: 1.0.0
 * Author: Sumit Malik
 * Author URI: 
 * License: GPL2
 */
add_action( 'admin_init','style_call');

/**
 * Add admin page styling for upload form.
*/
function style_call() {
    wp_register_style('stylesheet', plugins_url('style.css',__FILE__ ));
    wp_enqueue_style('stylesheet');
} 

/**
 * Add admin menu for uploading JSON data.
*/
add_action('admin_menu', 'fb2wp_plugin_menu');
function fb2wp_plugin_menu() {
	add_menu_page('Import Facebook Post', 'Import Facebook Post', 'administrator', 'import-facebook-post', 'fb2wp_import_facebook_post');

}

/**
 * Query posts to check if a post already exists with the facebook id from JSON data.
 * This will avoid duplicate posts if admin is uploading same file again.
*/
function fb2wp_post_exists ($fb_id) {
  $post_args = array(
  	'post_type' => 'post',
	'posts_per_page' => -1,
	'meta_query' => array(
		array(
			'key' => 'facebook_id', // Checking against saved fb id with last uploaded posts.
			'value' => $fb_id
		)
	)
  );
 
  $post_query = new WP_Query( $post_args );

  if($post_query->have_posts()) { // If query returns any data
  	return true;
  }
  else {
  	return false;
  }
}

/**
 * Check if the file url in FB data is a valid image or not.
*/
function fb2wp_is_image($path) {
  $a = getimagesize($path);
  $image_type = $a[2];

  if(in_array($image_type , array(IMAGETYPE_GIF , IMAGETYPE_JPEG ,IMAGETYPE_PNG , IMAGETYPE_BMP))) {
    return true;
  }
  
  return false;
}

/**
 * Importing data from JSON as WP posts.
*/
function fb2wp_import_posts ($data) {
  $arr = json_decode($data); // json decode 
  $count = 0;

  require_once(ABSPATH . "wp-admin" . '/includes/image.php');
  require_once(ABSPATH . "wp-admin" . '/includes/file.php');
  require_once(ABSPATH . "wp-admin" . '/includes/media.php');

  foreach($arr->data as $final_arr){
    /* Assigning data to post */
    $facebook_id = $final_arr->id;   
    $description = $final_arr->message;
    $title = $final_arr->name;
    $image_url = $final_arr->picture;
    $link = $final_arr->link;
    $create_time = $final_arr->created_time;


	if(!fb2wp_post_exists($facebook_id)){  // already imported data will not import again
	  error_reporting(E_ERROR | E_PARSE);
	  $url_decode = urldecode( $image_url ); 
	  parse_str($url_decode); //get image link out of picture data
	  $b64image = base64_encode(file_get_contents($url)); //converting to base 64 for uploading
	  $valid_image = fb2wp_is_image ($url);
	  $file_path_array = explode('.', $url);
	  $file_ext = end($file_path_array);
	  $upload_file_ext = (in_array(strtolower($file_ext), array('jpg', 'jpeg', 'png'))) ? $file_ext : 'jpg';
		
	  $args = array(
		'post_title' => $title,
		'post_content' => $description,
		'post_status' => 'publish', 
	 	'post_date' => $create_time
	  );
	  $post_id = wp_insert_post($args); //import data to posts
	  /* uploading base 64 image in wordpress and then attach post id to specific post */

	  if ($valid_image) {// Only upload image if the data has a valid image
		  $directory = "/".date(Y)."/".date(m)."/";
		  $wp_upload_dir = wp_upload_dir();
		  $image_data = base64_decode($b64image);
		  $filename = "IMG_".$final_arr->id.".".$upload_file_ext;
		  $fileurl = "../wp-content/uploads".$directory.$filename;

		  $filetype = wp_check_filetype( basename( $fileurl), null );

		  file_put_contents($fileurl, $image_data);

		  $attachment = array(
			'guid' => $wp_upload_dir['url'] . '/' . basename( $fileurl ),
			'post_mime_type' => $filetype['type'],
			'post_title' => preg_replace('/\.[^.]+$/', '', basename($fileurl)),
			'post_content' => '',
			'post_status' => 'inherit'
		  );
		  $attach_id = wp_insert_attachment( $attachment, $fileurl);

		  // Generate the metadata for the attachment, and update the database record.
		  $attach_data = wp_generate_attachment_metadata( $attach_id, $fileurl );
		  wp_update_attachment_metadata( $attach_id, $attach_data );
		
		  set_post_thumbnail($post_id,$attach_id);  //save to specific post 
	  }
	  add_post_meta($post_id, 'facebook_id',$facebook_id); //add facebook id in wp_postmeta for duplicate checks
	  add_post_meta($post_id, 'read_more',$link);  //add  link in wp_postmeta for Read more link in template
	
	  $count++;
    }
  }

  return $count;
}

/**
 * Form handler for JSON upload.
*/
function fb2wp_import_facebook_post() { 
if (isset($_FILES['json_file'])){
	$allowed =  array('json');
	$filename = $_FILES['json_file']['name'];
	$ext = pathinfo($filename, PATHINFO_EXTENSION);
	
	if (!in_array($ext,$allowed)) {
		echo "<div class='msg_div notice notice-error is-dismissible'><p class='failed_msg'>Please upload json format.</p></div>";
	} else {
		$data = file_get_contents($_FILES['json_file']['tmp_name']);
		$count = fb2wp_import_posts ($data); 
		if($count > 0) { 
		  echo "<div class='msg_div notice updated is-dismissible'><p class=''>Successfully uploaded ".$count." posts.</p></div>"; 
		}
		else {
		  echo "<div class='msg_div notice updated is-dismissible'><p class='' >Already up to date.</p></div>"; 
		}
	}
}

/* Form to uplaod json file */
?>
<div class="up_form" >
	<h2>Choose a json file to upload: </h2>
	<form enctype="multipart/form-data" method="post" action="" >
		<input type="file" name="json_file" accept="application/json" />
		<br />
		<input class="btn-upload" type="submit" name="submit" value="Upload" />
	</form>
</div>

<?php 
}