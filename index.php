<?php
// blackmonk88@gmail.com
// copyright 2022
// Script to mass import products based from images uploaded to certain directory.
// please message me when you need commercial support, the code provided as is.

// ================================================================================
// config is here
// ================================================================================
// color from the product name
$arr_color = array('white', 'lime-green', 'military-green', 'limegreen', 'militarygreen', 'olive', 'silver', 'lapis', 'black', 'hivis', 'orange', 'pink');
// subdirectory where the image should be located
$config_path = './process_this';
$config_path_web = 'process_this';

// ================================================================================
// logic start from below
// ================================================================================
require_once('../wp-load.php');

$arr_images_bak = array();
$arr_images = array();

function better_scandir($dir, $sorting_order = SCANDIR_SORT_DESCENDING) {
	$files = array();
	foreach (scandir($dir, $sorting_order) as $file) {
		if ($file[0] === '.' || $file == 'index.php' || $file == 'php.ini') {
			continue;
		}
		$files[$file] = filemtime($dir . '/' . $file);
	} // foreach
	if ($sorting_order == SCANDIR_SORT_ASCENDING) {
		asort($files, SORT_NUMERIC);
	} else {
		arsort($files, SORT_NUMERIC);
	}
	$ret = array_keys($files);
	return ($ret) ? $ret : false;
}

function check_color($arrs) {
	global $arr_color;
	$found_color = false;
	foreach($arrs as $arr) {
		foreach($arr_color as $color) {
			if(strstr(strtolower($arr), $color)) {
				$found_color = true;
			}
		}
	}
	return $found_color;
}

function wp_insert_attachment_from_url( $url, $parent_post_id = null ) {
	if ( ! class_exists( 'WP_Http' ) ) {
		require_once ABSPATH . WPINC . '/class-http.php';
	}

	$http     = new WP_Http();
	$response = $http->request( $url );
	if ( 200 !== $response['response']['code'] ) {
		return false;
	}

	$upload = wp_upload_bits( basename( $url ), null, $response['body'] );
	if ( ! empty( $upload['error'] ) ) {
		return false;
	}

	$file_path        = $upload['file'];
	$file_name        = basename( $file_path );
	$file_type        = wp_check_filetype( $file_name, null );
	$attachment_title = sanitize_file_name( pathinfo( $file_name, PATHINFO_FILENAME ) );
	$wp_upload_dir    = wp_upload_dir();

	$post_info = array(
		'guid'           => $wp_upload_dir['url'] . '/' . $file_name,
		'post_mime_type' => $file_type['type'],
		'post_title'     => $attachment_title,
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	// Create the attachment.
	$attach_id = wp_insert_attachment( $post_info, $file_path, $parent_post_id );

	// Include image.php.
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Generate the attachment metadata.
	$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );

	// Assign metadata to attachment.
	wp_update_attachment_metadata( $attach_id, $attach_data );

	return $attach_id;
}

function insert_product ($product_data) {
	global $arr_images;
	$post = array( 
		'post_author'  => 1,
		'post_content' => $product_data['description'],
		'post_status'  => 'publish',
		'post_title'   => $product_data['name'],
		'post_parent'  => '',
		'post_type'    => 'product'
	);
	
	$post_id = wp_insert_post($post); 
	if (!$post_id) {
		return false;
	}

	update_post_meta($post_id, '_sku', $product_data['sku']); // Set its SKU
	update_post_meta( $post_id,'_visibility','visible'); // Set the product to visible, if not it won't show on the front end
	
	update_post_meta($post_id, '_thumbnail_id', $product_data['image']);
	update_post_meta($post_id, '_default_attributes', $product_data['default_attributes']);
	
	wp_set_object_terms($post_id, $product_data['categories'], 'product_cat'); // Set up its categories
	wp_set_object_terms($post_id, 'variable', 'product_type'); // Set it to a variable product type

	insert_product_attributes($post_id, $product_data['available_attributes'], $product_data['variations']); // Add attributes passing the new post id, attributes & variations
	insert_product_variations($post_id, $product_data['variations']); // Insert variations passing the new post id & variations
	
	if(count($product_data['variations'])>6) {
		update_post_meta( $post_id,'_product_image_gallery', $product_data['gallery']);	
	}
}

function insert_product_attributes ($post_id, $available_attributes, $variations) {
	foreach ($available_attributes as $attribute) {
		$values = array(); // Set up an array to store the current attributes values.
		// Loop each variation in the file
		foreach ($variations as $variation) {
			$attribute_keys = array_keys($variation['attributes']); // Get the keys for the current variations attributes
			// Loop through each key
			foreach ($attribute_keys as $key) {
				// If this attributes key is the top level attribute add the value to the $values array
				if ($key === $attribute) {
					$values[] = $variation['attributes'][$key];
				}
			}
		}
		$values = array_unique($values); // Filter out duplicate values
		wp_set_object_terms($post_id, $values, 'pa_' . $attribute);
	}

	$product_attributes_data = array(); // Setup array to hold our product attributes data
	
	// Loop round each attribute
	foreach ($available_attributes as $attribute) {
		// Set this attributes array to a key to using the prefix 'pa'
		$product_attributes_data['pa_'.$attribute] = array( 
			'name'         => 'pa_'.$attribute,
			'value'        => '',
			'is_visible'   => '1',
			'is_variation' => '1',
			'is_taxonomy'  => '1'
		);
	}
	update_post_meta($post_id, '_product_attributes', $product_attributes_data); // Attach the above array to the new posts meta data key '_product_attributes'
}

function insert_product_variations ($post_id, $variations) {
	foreach ($variations as $index => $variation) {
		$variation_post = array( // Setup the post data for the variation
			'post_title'  => 'Variation #'.$index.' of '.count($variations).' for product#'. $post_id,
			'post_name'   => 'product-'.$post_id.'-variation-'.$index,
			'post_status' => 'publish',
			'post_parent' => $post_id,
			'post_type'   => 'product_variation',
			'guid'        => home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index
		);

		$variation_post_id = wp_insert_post($variation_post); // Insert the variation

		// Loop through the variations attributes
		foreach ($variation['attributes'] as $attribute => $value) {
			// We need to insert the slug not the name into the variation post meta
			$attribute_term = get_term_by('name', $value, 'pa_'.$attribute); 
			update_post_meta($variation_post_id, 'attribute_pa_'.$attribute, $attribute_term->slug);
		}
		update_post_meta($variation_post_id, '_sku', $variation['sku']);
		update_post_meta($variation_post_id, '_price', $variation['price']);
		update_post_meta($variation_post_id, '_regular_price', $variation['price']);
		update_post_meta($variation_post_id, 'yith_cog_cost', $variation['yith_cog_cost']);
		update_post_meta($variation_post_id, '_thumbnail_id', $variation['image']);
		update_post_meta($post_id, '_thumbnail_id', $variation['image']);
	}
}

function insert_products ($products) {
	// No point proceeding if there are no products
	if (!empty($products)) {
		array_map('insert_product', $products); // Run 'insert_product' function from above for each product
	}
}

$files1 = better_scandir($config_path);

$var_products = array();
$product_arr = array();
$full_web_uri = str_replace('index.php','',$_SERVER['SCRIPT_URI']);
foreach($files1 as $file1) {
	echo 'category '.$file1.'<br />';
	$path1 = $config_path.'/'.$file1;
	$files2 = better_scandir($path1);
	foreach($files2 as $file2) {
		$product_name = str_replace('-', ' ',str_replace($file1.'-', '', $file2));
		echo '- product_name <strong>"'.$product_name.'"</strong><br />';
		$product = get_page_by_title( $product_name, OBJECT, 'product' );
		if(is_null($product)) {
			echo 'creating new product <br />';
			$product_arr[$file1][$product_name];
			$path2 = $path1.'/'.$file2;
			$files3 = better_scandir($path2);
			$product_count = count($files3);
			if(check_color($files3)) {
				echo '--- Found color here<br />';
			} else {
				if($product_count==1) {
					echo '-- Generate <span style="color:red;"><strong>single</strong></span> product '.$product_name.'<br />';
				} else {
					die('<span style="color:red;">-- ERROR, please advise to coder</span><br />');
				}
			}
			
			foreach($files3 as $file3) {
				echo '-- product image '.$file3.'<br />';
				$path3 = $path2.'/'.$file3;
				foreach($arr_color as $color) {
					if(strstr(strtolower($file3), $color)) {
						echo '--- Generate <strong>"'.$product_name.'"</strong> variation color '.$color.'<br />';
						$product_arr[$file1][$product_name]['color'][$color];
						$product_arr[$file1][$product_name]['color'][$color]['path'] = $file3;
						$product_arr[$file1][$product_name]['color'][$color]['pathx'] = $full_web_uri.''.str_replace($config_path,$config_path_web,$path3);
						$arr_images_bak[$file3]['path']=$full_web_uri.''.str_replace($config_path,$config_path_web,$path3);
					}
				}
			}
		} else {
			echo '<span style="color:red;">ERR: product already exist, skipping.</span><br />';
		}
	}
}

// process images, upload, and convert as image_id
if(isset($_REQUEST['start'])) {
	foreach($arr_images_bak as $key => $val) {
		$arr_images[$key]['id']=wp_insert_attachment_from_url($val['path']);
		$arr_images[$key]['path']=$val['path'];
	}
}	
//var_dump($arr_images);

$i=0;
foreach($product_arr as $key1 => $val1) {
	global $arr_images;
	$cat=$key1;
	foreach($val1 as $key2 => $val2) {
		$product_name = $key2;
		// create var_products
		$var_products[$i] = array(
			'name' => $product_name,
			'sku' => sanitize_title($product_name),
			'categories' => array('Tshirt', $cat),
			'available_attributes' => array("color", "size")
		);
		
		$last_image = '';
		$last_color = '';
		// start iterate color size
		$cnt_color = 0;
		foreach($val2 as $key3 => $val3) {
			foreach($val3 as $key4 => $val4) {
				$cnt_color++;
				$last_color=$key4;
				$var_products[$i]['galleryx'][$val4['path']]=$arr_images[$val4['path']]['id'];
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'S'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-S'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 20,
					'yith_cog_cost' => 14.75
				);
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'M'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-M'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 20,
					'yith_cog_cost' => 14.75
				);
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'L'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-L'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 20,
					'yith_cog_cost' => 14.75
				);
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'XL'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-XL'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 20,
					'yith_cog_cost' => 14.75
				);
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'XXL (Double)'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-XXL'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 25,
					'yith_cog_cost' => 16.75
				);
				$var_products[$i]['variations'][] = array(
					'attributes' => array(
						'color' => $key4,
						'size'	=> 'XXXL (Triple)'),
					'sku' => sanitize_title($product_name.'-'.$key4.'-XXXL'),
					'image' => $arr_images[$val4['path']]['id'],
					'price' => 25,
					'yith_cog_cost' => 16.75
				);
			}
		}
		
		echo $cat.' '.$product_name.' color:'.$cnt_color.'<br />';
		$str_gallery = '';
		foreach($var_products[$i]['galleryx'] as $key => $val) {
			$str_gallery.=$arr_images[$key]['id'].',';
			$last_image = $arr_images[$key]['id'];
		}
		$var_products[$i]['gallery']=rtrim($str_gallery,',');
		$var_products[$i]['image']=$last_image;
		$var_products[$i]['default_attributes']=array(
			'pa_size' => 'l',
			'pa_color' => $last_color
		);
		$i++;
	}
}

echo '<br />===========================================================<br />';
if(isset($_REQUEST['sandbox'])) {

} elseif(isset($_REQUEST['start'])) {
	insert_products($var_products);
}
