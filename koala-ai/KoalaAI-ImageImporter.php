<?php
/**
 * KoalaAI Image Importer
 * 
 * This class handles the importing of external images from a specific domain
 * into the WordPress media library and updates post content to use the local copies.
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'KoalaAI_ImageImporter' ) ) {
    class KoalaAI_ImageImporter {
        
        /**
         * Process post content to find and replace external images
         * 
         * @param string $content The post content to process
         * @param int $post_id The ID of the post being processed
         * @return string The updated content with local image URLs
         */
        public static function process_content($content, $post_id) {
            // Find all image tags in the content
            $updated_content = $content;
            $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
            
            // Track the first imported attachment ID for featured image
            $first_attachment_id = 0;
            
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $index => $img_tag) {
                    $img_url = $matches[1][$index];
                    
                    // Check if this image is hosted on koala.sh
                    if (strpos($img_url, 'https://koala.sh/api/image/') === 0) {
                        // Extract alt text from image tag if available
                        $alt_text = '';
                        if (preg_match('/alt=[\'"]([^\'"]*)[\'"]/', $img_tag, $alt_matches)) {
                            $alt_text = $alt_matches[1];
                        }
                        
                        // Download the image and add to media library
                        $result = self::import_external_image($img_url, $post_id, $alt_text);
                        
                        // $result is either false or an array with 'url' and 'id' keys
                        if ($result && isset($result['url'])) {
                            // Replace the URL in the img tag
                            $new_img_tag = str_replace($img_url, $result['url'], $img_tag);
                            $updated_content = str_replace($img_tag, $new_img_tag, $updated_content);
                            
                            // If this is the first image and we have an attachment ID, store it
                            if ($index === 0 && !$first_attachment_id && isset($result['id'])) {
                                $first_attachment_id = $result['id'];
                            }
                        }
                    }
                }
            }
            
            // Check if we should set the first image as featured
            if ($first_attachment_id) {
                // Get the setting value
                $first_image_as_featured = get_option('koala_ai_first_image_as_featured', 0);
                
                if ($first_image_as_featured) {
                    // Only set if the post doesn't already have a featured image
                    if (!has_post_thumbnail($post_id)) {
                        set_post_thumbnail($post_id, $first_attachment_id);
                    }
                }
            }
            
            return $updated_content;
        }
        
        /**
         * Import an external image to the media library
         * 
         * @param string $url URL of the external image
         * @param int $post_id The post ID to attach the image to
         * @param string $alt_text Optional alt text for the image
         * @return array|bool Array with 'url' and 'id' keys, or false on failure
         */
        public static function import_external_image($url, $post_id, $alt_text = '') {
            // Strip query parameters from URL for filename purposes
            $url_no_query = self::strip_query_parameters($url);

            // Check if we already imported this image (avoid duplicates)
            $existing_attachment = self::get_attachment_by_url($url_no_query);
            if ($existing_attachment) {
                return array(
                    'url' => wp_get_attachment_url($existing_attachment),
                    'id' => $existing_attachment
                );
            }
            
            // Get file info for the filename
            $file_info = pathinfo($url_no_query);
            $file_name = sanitize_file_name(wp_basename($url_no_query));
            
            // If no file extension, try to determine from content type
            if (empty($file_info['extension'])) {
                $file_info = self::get_file_info_from_url($url);
                if (!empty($file_info['extension'])) {
                    $file_name .= '.' . $file_info['extension'];
                } else {
                    // Default to jpg if we can't determine extension
                    $file_name .= '.jpg';
                }
            }

            // Make sure we have the required files for media handling
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            // Add fast=1 parameter to the URL to avoid waiting
            $url_with_fast = add_query_arg('fast', '1', $url);
            
            // Download the file to a temp location
            $tmp = download_url($url_with_fast);
            
            if (is_wp_error($tmp)) {
                return false;
            }
            
            $file_array = array(
                'name'     => $file_name,
                'tmp_name' => $tmp
            );
            
            // Use WordPress function to handle the sideload
            $attachment_id = media_handle_sideload($file_array, $post_id);
            
            if (is_wp_error($attachment_id)) {
                // If there was an error uploading, remove the temporary file
                wp_delete_file($tmp);
                return false;
            }
            
            // Store original URL in meta for future reference
            update_post_meta($attachment_id, '_koala_ai_original_url', $url);
            
            // Set alt text if provided
            if (!empty($alt_text)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
            }
            
            // Return the new URL and attachment ID
            return array(
                'url' => wp_get_attachment_url($attachment_id),
                'id' => $attachment_id
            );
        }
        
        /**
         * Check if an attachment already exists by external URL
         * 
         * @param string $url The external URL to check
         * @return int|null Attachment ID if found, null otherwise
         */
        private static function get_attachment_by_url($url) {
            // Query attachments by meta value
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'meta_key' => '_koala_ai_original_url',
                'meta_value' => $url,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ));
            
            if (!empty($attachments)) {
                return $attachments[0];
            }
            
            return null;
        }
        
        /**
         * Get file info from URL by making a HEAD request
         * 
         * @param string $url The URL to check
         * @return array File info including extension if available
         */
        private static function get_file_info_from_url($url) {
            $file_info = array(
                'extension' => ''
            );
            
            // Make a HEAD request to get the content type
            $response = wp_safe_remote_head($url, array(
                'timeout' => 10,
                'httpversion' => '1.1',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            ));
            
            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $content_type = wp_remote_retrieve_header($response, 'content-type');
                
                // Map content type to extension
                $mime_to_ext = array(
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'image/svg+xml' => 'svg'
                );
                
                if (!empty($content_type)) {
                    // Extract the MIME type without parameters
                    $mime_parts = explode(';', $content_type);
                    $mime_type = trim($mime_parts[0]);
                    
                    if (isset($mime_to_ext[$mime_type])) {
                        $file_info['extension'] = $mime_to_ext[$mime_type];
                    }
                }
            }
            
            return $file_info;
        }
        
        /**
         * Strip query parameters from a URL
         *
         * @param string $url The URL to process
         * @return string URL without query parameters
         */
        private static function strip_query_parameters($url) {
            $parts = wp_parse_url($url);
            $clean_url = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $clean_url .= ':' . $parts['port'];
            }
            if (isset($parts['path'])) {
                $clean_url .= $parts['path'];
            }
            return $clean_url;
        }
    }
} 