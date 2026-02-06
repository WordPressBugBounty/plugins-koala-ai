<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'KoalaAI_API' ) ) {
	class KoalaAI_API {
		/**
		 * Map of command names to static methods.
		 *
		 * @var array
		 */
		private static $method_map = [
			'connect'                => 'connect',
			'publish_posts'          => 'publish_posts',
			'disconnect'             => 'disconnect',
			'get_authors'            => 'get_authors',
			'get_post_types'         => 'get_post_types',
			'get_categories'         => 'get_categories',
			'get_tags'               => 'get_tags',
			'upload_media'           => 'upload_media',
			'check_connection_status'=> 'check_connection_status',
		];
	
		/**
		 * @return void
		 */
		public static function register_api_endpoints() {
			// Add endpoints for both logged-in and non-logged-in users
			add_action( 'wp_ajax_koala-ai', array( 'KoalaAI_API', 'execute' ) );
			add_action( 'wp_ajax_nopriv_koala-ai', array( 'KoalaAI_API', 'execute' ) );

			// REST API endpoints for JS-driven image import
			add_action( 'rest_api_init', function () {
				register_rest_route( 'koala-ai/v1', '/all_post_ids', array(
					'methods' => 'GET',
					'callback' => array( 'KoalaAI_API', 'rest_get_all_post_ids' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				) );
				register_rest_route( 'koala-ai/v1', '/process_image_import_batch', array(
					'methods' => 'POST',
					'callback' => array( 'KoalaAI_API', 'rest_process_image_import_batch' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args' => array(
						'post_ids' => array(
							'required' => true,
							'type' => 'array',
						),
					),
				) );
			});
		}

		/**
		 * Execute the request. This structure is very easy to extend - just add new methods in the class, nothing more.
		 *
		 * @return void
		 */
		public static function execute() {
			// Check if the request is POST
			if ( !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
				wp_send_json_error( [
					'message' => 'Invalid request method. Only POST allowed.'
				], 405 );
				wp_die();
			}

			// Decode the input from JSON
			$json    = file_get_contents( 'php://input' );
			$request = json_decode( $json, true );

			// Validate JSON and check required fields
			if (json_last_error() !== JSON_ERROR_NONE) {
				wp_send_json_error([
					'message' => 'Invalid JSON format.'
				], 400);
				wp_die();
			}

			// Ensure the 'command' element is set and 'data' is an array.
			if ( isset( $request['command'] ) && is_array( $request['data'] ) ) {
				// Sanitize command
				$command = sanitize_text_field($request['command']);
				
				if ( ! self::check_uuid( $request['data'], $command === 'connect' ) ) {
					wp_send_json_error( [
						'message' => 'Invalid secret token or site is disconnected'
					], 403 );
					wp_die();
				}

				$data = $request['data'];
				
				// Check if the action is in the method map
				if (array_key_exists($command, self::$method_map)) {
					$method = self::$method_map[$command];
		
					// Call the static method if it exists
					if (method_exists(__CLASS__, $method)) {
						call_user_func(array(__CLASS__, $method), $data);
					} else {
						wp_send_json_error([
							'message' => 'Method not found.'
						], 500);
					}
				} else {
					wp_send_json_error([
						'message' => 'Invalid command.'
					], 400);
				}
			} else {
				wp_send_json_error([
					'message' => 'Invalid request format. Command and data required.'
				], 400);
			}
			
			wp_die();
		}

		/**
		 * Check every request for correct secret token - enhanced validation
		 *
		 * @param $request_data
		 *
		 * @return bool
		 */
		public static function check_uuid( $request_data, $connect = false ) {
			// Verify the site is connected
			if (!KoalaAI::is_connected() && !$connect) {
				return false;
			}
			
			// Get the secret token for validation
			$secret_token = KoalaAI::get_secret_token();
			
			// Verify secret token exists in request and matches stored token
			if (!isset($request_data['uuid']) || 
				empty($request_data['uuid']) || 
				$request_data['uuid'] !== $secret_token) {
				return false;
			}
			
			return true;
		}

		/**
		 * Connecting with secret token
		 * */
		public static function connect( $request_data ) {
			$integration_id = isset($request_data['integration_id']) ? sanitize_text_field($request_data['integration_id']) : '';
			
			// Save the integration ID
			$connection_data = array(
				'integration_id' => $integration_id
			);
			
			update_option(KoalaAI::KOALA_AI_CONNECTION_OPTION, $connection_data);
		
			wp_send_json_success([
				'message' => 'Connected successfully',
				'integration_id' => $integration_id
			]);
			
			wp_die();
		}

		/**
		 * Status that connection
		 * @return void
		 * */
		public static function check_connection_status() {
			$connection_data = get_option( KoalaAI::KOALA_AI_CONNECTION_OPTION );
			$status = $connection_data ? 'Connected' : 'Disconnected';

			wp_send_json_success( [
				'message' => $status,
				'integration_id' => $connection_data && is_array($connection_data) ? $connection_data['integration_id'] : null
			] );
		
			wp_die();
		}

		/**
		 * Handles disconnection request from the user.
		 *
		 * @return void
		 */
		public static function disconnect() {
			update_option( KoalaAI::KOALA_AI_CONNECTION_OPTION, 0 );
			wp_send_json_success( [
				'message' => 'successfully disconnected'
			] );

			wp_die();
		}

		/**
		 * Publishes multiple posts.
		 *
		 * @param array $request_data The data containing the posts to be published.
		 *
		 * @return void
		 */
		public static function publish_posts( $request_data ) {
			if ( ! is_array( $request_data['posts'] ) || ! $request_data['posts'] ) {
				wp_send_json_error( [ 'message' => 'No posts in request' ] );
				wp_die();
			}
			$posts_published  = 0;
			$posts_permalinks = [];

			// Default author setup - now used only as fallback
			$default_author_id = 1; // Default to user ID 1
			$admins = get_users(['role' => 'administrator', 'number' => 1]);
			if (!empty($admins)) {
				$default_author_id = $admins[0]->ID;
			}

			foreach ( $request_data['posts'] as $post_data ) {
				// Check if we're scheduling a post for the future
				$is_future_post = false;
				$post_date = current_time('mysql');
				
				// Process the post date if provided
				if (isset($post_data['date']) && !empty($post_data['date'])) {
					$timestamp = strtotime($post_data['date']);
					if ($timestamp) {
						$post_date = gmdate('Y-m-d H:i:s', $timestamp);
						
						// If the date is in the future and post status is set to publish, 
						// we'll need to set status to future for WordPress scheduling
						if ($timestamp > current_time('timestamp') && 
							(isset($post_data['state']) && $post_data['state'] === 'publish')) {
							$is_future_post = true;
						}
					}
				}
				
				// Determine post status
				$post_status = 'draft'; // Default status
				if (isset($post_data['state']) && in_array($post_data['state'], array('publish', 'draft', 'pending', 'private'))) {
					$post_status = $post_data['state'];
					
					// If publishing and date is in future, set to 'future'
					if ($post_status === 'publish' && $is_future_post) {
						$post_status = 'future';
					}
				}
				
				// Determine post author (per-post basis)
				$post_author_id = $default_author_id;
				if (isset($post_data['author']) && (int)$post_data['author'] > 0) {
					$post_author_id = (int)$post_data['author'];
				}
				
				// Prepare post data with proper sanitization
				$new_post = array(
					'post_title'    => sanitize_text_field( $post_data['title'] ),
					'post_name'     => sanitize_title_with_dashes( $post_data['slug'] ),
					'tags_input'    => isset($post_data['tags']) ? array_map('sanitize_text_field', (array)$post_data['tags']) : array(),
					'post_status'   => $post_status,
					'post_author'   => $post_author_id,
					'post_date'     => $post_date,
					'post_excerpt'  => isset($post_data['excerpt']) ? sanitize_text_field( $post_data['excerpt'] ) : '',
					'post_type'     => isset($post_data['post_type']) && post_type_exists($post_data['post_type']) ? sanitize_text_field( $post_data['post_type'] ) : 'post',
					'post_category' => isset($post_data['categories']) ? self::setup_categories($post_data['categories']) : array(),
					'post_content'  => wp_kses_post( $post_data['content'] ),
				);
				
				// Set featured image in meta_input if provided
				if (isset($post_data['featured_media']) && (int)$post_data['featured_media'] > 0) {
					$attachment_id = (int)$post_data['featured_media'];
					// Check if attachment exists and is an image
					$attachment = get_post($attachment_id);
					if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
						$new_post['meta_input'] = array(
							'_thumbnail_id' => $attachment_id
						);
					}
				}

				// Insert post into database
				$post_id = wp_insert_post( $new_post );

				if ( $post_id ) {
					// Add permalink for $post_id to $posts_permalinks[]
					$posts_permalinks[ $post_id ] = get_permalink( $post_id );
					$posts_published ++;
				}
			}

			if ( $posts_published ) {
				wp_send_json_success( [
					'message'          => 'Posts published: ' . $posts_published,
					'posts_permalinks' => $posts_permalinks,
				] );
			} else {
				wp_send_json_error( [
					'message' => 'No posts updated'
				] );
			}

			wp_die();
		}

		/**
		 * Set categories and handle category IDs or names
		 *
		 * @param string|array $categories_input The string containing comma-separated category names/IDs or array of category names/IDs.
		 *
		 * @return array The array of category IDs.
		 */
		private static function setup_categories( $categories_input ) {
			$categories_array = [];
			
			// Handle both string and array inputs
			if (is_string($categories_input)) {
				$categories_array = explode(',', $categories_input);
			} elseif (is_array($categories_input)) {
				$categories_array = $categories_input;
			}
			
			$categories_ids = [];

			foreach ( $categories_array as $category_item ) {
				$category_item = trim($category_item);
				if (empty($category_item)) continue;
				
				// Check if this is already a valid category ID
				if (is_numeric($category_item) && term_exists((int)$category_item, 'category')) {
					$categories_ids[] = (int)$category_item;
				} else {
					// Handle as category name
					$category_id = get_cat_ID($category_item);
					if ($category_id == 0) {
						$new_category_id = wp_create_category($category_item);
						$categories_ids[] = $new_category_id;
					} else {
						$categories_ids[] = $category_id;
					}
				}
			}

			return $categories_ids;
		}

		/**
		 * Get authors
		 *
		 * This method retrieves a list of authors from the database and returns them in a JSON response.
		 *
		 * @return void
		 */
		public static function get_authors( $request_data ) {
			$search = isset($request_data['search']) ? sanitize_text_field($request_data['search']) : '';

			// Setup arguments
			$args = array(
				'orderby' => 'display_name',
			);
		
			// Add search parameter if provided
			if ( ! empty( $search ) ) {
				$args['search'] = '*' . $search . '*';
				$args['search_columns'] = array( 'display_name' );
			}

			// Create the WP_User_Query object
			$user_query = new WP_User_Query( $args );

			// Get the list of authors
			$authors = $user_query->get_results();

			// Initialize empty array
			$authordata = array();

			// Check if authors were found
			if ( ! empty( $authors ) ) {
				// Loop through each author
				foreach ( $authors as $author ) {
					// Add author data to array
					$authordata[] = array(
						'id'   => $author->ID,
						'name' => "{$author->data->display_name}",
					);
				}
			}

			if ( $authordata ) {
				wp_send_json_success( [
					'message' => 'Authors found: ' . count( $authordata ),
					'authors' => $authordata
				] );
			} else {
				wp_send_json_error( [
					'message' => 'No authors found'
				] );
			}

			wp_die();
		}

		/**
		 * Get all categories and filter based on search word
		 *
		 * @param $request_data
		 * @return void
		 */
		public static function get_categories( $request_data ) {
			$search = isset($request_data['search']) ? sanitize_text_field($request_data['search']) : '';
			$hide_empty = isset($request_data['hide_empty']) && $request_data['hide_empty'] === 'true';
	
			$args = array(
				'hide_empty' => $hide_empty,
			);
	
			if (!empty($search)) {
				$args['search'] = $search;
			}
	
			$categories = get_categories($args);
			$categories_array = array();
	
			foreach ($categories as $category) {
				$categories_array[] = array(
					'id' => $category->term_id,
					'name' => $category->name,
					'slug' => $category->slug,
					'description' => $category->description,
					'count' => $category->count,
					'parent' => $category->parent,
				);
			}
	
			if (empty($categories_array)) {
				wp_send_json_error(['message' => 'No categories found']);
			} else {
				wp_send_json_success([
					'message'    => 'Categories retrieved successfully',
					'categories' => $categories_array,
				]);
			}
	
			wp_die();
		}
	
		/**
		 * Get all tags and filter based on search word
		 *
		 * @param $request_data
		 * @return void
		 */
		public static function get_tags( $request_data ) {
			$search = isset($request_data['search']) ? sanitize_text_field($request_data['search']) : '';
			$hide_empty = isset($request_data['hide_empty']) && $request_data['hide_empty'] === 'true';

			$args = array(
				'hide_empty' => $hide_empty,
			);

			if (!empty($search)) {
				$args['search'] = $search;
			}

			$tags = get_tags($args);
			$tags_array = array();

			foreach ($tags as $tag) {
				$tags_array[] = array(
					'id' => $tag->term_id,
					'name' => $tag->name,
					'slug' => $tag->slug,
					'description' => $tag->description,
					'count' => $tag->count,
				);
			}

			if (empty($tags_array)) {
				wp_send_json_error(['message' => 'No tags found']);
			} else {
				wp_send_json_success([
					'message'    => 'Tags retrieved successfully',
					'tags' => $tags_array,
				]);
			}

			wp_die();
		}

		/**
		 * Get all available post types
		 *
		 * @param $request_data
		 * @return void
		 */
		public static function get_post_types( $request_data ) {
			// Get all post types that are publicly queryable
			$post_types = get_post_types( array( 'public' => true ), 'objects' );
			$post_types_array = array();

			// Check if post types were found
			if ( ! empty( $post_types ) ) {
				// Loop through each post type
				foreach ( $post_types as $post_type ) {
					// Skip attachments and revisions
					if ( in_array( $post_type->name, array( 'attachment', 'revision' ) ) ) {
						continue;
					}
					
					// Add post type data to array
					$post_types_array[] = array(
						'name' => $post_type->name,
						'label' => $post_type->labels->singular_name,
						'description' => $post_type->description,
					);
				}
			}

			if ( ! empty( $post_types_array ) ) {
				wp_send_json_success( [
					'message' => 'Post types retrieved successfully',
					'post_types' => $post_types_array
				] );
			} else {
				wp_send_json_error( [
					'message' => 'No post types found'
				] );
			}

			wp_die();
		}

		/**
		 * REST: Get all post IDs
		 */
		public static function rest_get_all_post_ids( $request ) {
			// Get selected post types from settings
			$image_import_post_types = get_option(KoalaAI::KOALA_AI_POST_TYPES_OPTION, array('post', 'page'));
			
			$args = array(
				'post_type' => $image_import_post_types,
				'post_status' => 'any',
				'fields' => 'ids',
				'posts_per_page' => -1,
			);
			$query = new WP_Query($args);
			return rest_ensure_response(array('post_ids' => $query->posts));
		}

		/**
		 * REST: Process a batch of post IDs for image import
		 */
		public static function rest_process_image_import_batch( $request ) {
			$post_ids = $request->get_param('post_ids');
			$updated_count = 0;
			$results = array();
			
			foreach ($post_ids as $post_id) {
				$content = get_post_field('post_content', $post_id);
				$updated_content = KoalaAI_ImageImporter::process_content($content, $post_id);
				if ($content !== $updated_content) {
					wp_update_post(array(
						'ID' => $post_id,
						'post_content' => $updated_content
					));
					$results[] = array(
						'post_id' => $post_id,
						'updated' => true,
					);
					$updated_count++;
				} else {
					$results[] = array(
						'post_id' => $post_id,
						'updated' => false,
					);
				}
			}
			return rest_ensure_response(array(
				'results' => $results,
				'updated_count' => $updated_count,
				'batch_size' => count($post_ids),
			));
		}

		/**
		 * Upload media file and return the attachment ID
		 *
		 * @param array $request_data The data containing the image to upload
		 * @return void
		 */
		public static function upload_media($request_data) {
			// Check if image data is provided
			if (!isset($request_data['image_data']) || empty($request_data['image_data'])) {
				wp_send_json_error([
					'message' => 'No image data provided'
				], 400);
				wp_die();
			}

			// Prepare image data
			$image_data = $request_data['image_data'];
			$image_url = isset($image_data['url']) ? esc_url_raw($image_data['url']) : '';
			$image_name = isset($image_data['filename']) ? sanitize_file_name($image_data['filename']) : 'koala-image-' . time() . '.jpg';

			// Validate URL
			if (empty($image_url)) {
				wp_send_json_error([
					'message' => 'Invalid image URL'
				], 400);
				wp_die();
			}

			// Download image from URL
			$temp_file = download_url($image_url);

			// Check for download errors
			if (is_wp_error($temp_file)) {
				wp_send_json_error([
					'message' => 'Failed to download image: ' . $temp_file->get_error_message()
				], 400);
				wp_die();
			}

			// Prepare file array for media_handle_sideload
			$file_array = [
				'name'     => $image_name,
				'tmp_name' => $temp_file
			];

			// Determine MIME type
			$filetype = wp_check_filetype($image_name, null);
			if (!empty($filetype['type'])) {
				$file_array['type'] = $filetype['type'];
			} else {
				// Default to JPEG if type can't be determined
				$file_array['type'] = 'image/jpeg';
			}

			// Add image to media library
			$attachment_id = media_handle_sideload($file_array, 0);

			// Check for errors
			if (is_wp_error($attachment_id)) {
				// Clean up temp file
				wp_delete_file($temp_file);
				wp_send_json_error([
					'message' => 'Failed to add image to media library: ' . $attachment_id->get_error_message()
				], 500);
				wp_die();
			}

			// Get attachment details
			$attachment = get_post($attachment_id);
			$attachment_url = wp_get_attachment_url($attachment_id);
			$attachment_metadata = wp_get_attachment_metadata($attachment_id);
			
			// Get attachment metadata for sizes
			$sizes = [];
			if (!empty($attachment_metadata['sizes'])) {
				foreach ($attachment_metadata['sizes'] as $size_name => $size_data) {
					$size_url = wp_get_attachment_image_src($attachment_id, $size_name);
					$sizes[$size_name] = [
						'url' => $size_url[0],
						'width' => $size_data['width'],
						'height' => $size_data['height']
					];
				}
			}

			// Return success with attachment details
			wp_send_json_success([
				'message' => 'Image uploaded successfully',
				'attachment' => [
					'id' => $attachment_id,
					'url' => $attachment_url,
					'title' => $attachment->post_title,
					'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
					'sizes' => $sizes,
					'media_details' => [
						'sizes' => [
							'full' => [
								'source_url' => $attachment_url
							]
						]
					]
				]
			]);
			
			wp_die();
		}
	}
}