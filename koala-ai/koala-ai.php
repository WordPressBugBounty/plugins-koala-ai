<?php
/**
 * Plugin Name: Koala AI
 * Description: Official integration by Koala AI that makes it easy to publish content from Koala AI Writer to WordPress.
 * Version: 1.0
 * Author: Koala AI
 * Author URI: https://koala.sh
 * Text Domain: koala-ai
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once __DIR__ . '/KoalaAI-API.php';
require_once __DIR__ . '/KoalaAI-ImageImporter.php';

if ( ! class_exists( 'KoalaAI' ) ) {
    class KoalaAI {
		const KOALA_AI_CONNECTION_ENDPOINT = 'https://koala.sh/wordpress-callback?uuid={secret_token}&url={url}';
        const KOALA_AI_CONNECTION_OPTION = 'koala_ai_connection';
        const KOALA_AI_SECRET_TOKEN_OPTION = 'koala_ai_secret_token';
        const KOALA_AI_IMAGE_IMPORT_BATCH_SIZE = 5;
        const KOALA_AI_IMAGE_IMPORT_TRANSIENT = 'koala_ai_image_import_in_progress';
        const KOALA_AI_IMAGE_IMPORT_LOG_OPTION = 'koala_ai_image_import_log';
        const KOALA_AI_AUTO_IMPORT_OPTION = 'koala_ai_image_auto_import';
        const KOALA_AI_POST_TYPES_OPTION = 'koala_ai_image_import_post_types';
        const KOALA_AI_PROCESS_POST_HOOK = 'koala_ai_process_post_images';
        const KOALA_AI_PROCESSING_MODE_OPTION = 'koala_ai_processing_mode';
        const KOALA_AI_FIRST_IMAGE_AS_FEATURED_OPTION = 'koala_ai_first_image_as_featured';

	    /**
         * Initializes the Koala AI plugin by setting up the necessary actions.
         */
        public static function init() {
            add_action( 'admin_menu', array( 'KoalaAI', 'koala_ai_settings_menu' ) );
            
            // Handle form submissions in admin context only
            add_action( 'admin_init', array( 'KoalaAI', 'handle_disconnection' ) );
            add_action( 'admin_init', array( 'KoalaAI', 'handle_image_import_request' ) );
            add_action( 'admin_init', array( 'KoalaAI', 'handle_settings_update' ) );
            
            // Add schedule hook for image import background process
            add_action('koala_ai_process_image_import', array('KoalaAI', 'process_scheduled_image_import'));
            // Add save_post hook to schedule image processing on publish
            add_action('save_post', array('KoalaAI', 'process_images_on_publish'), 10, 3);
            // Add hook for background processing of individual posts
            add_action(self::KOALA_AI_PROCESS_POST_HOOK, array('KoalaAI', 'process_single_post_images'), 10, 1);
        
            // Activation redirect
            add_action('admin_init', array('KoalaAI', 'do_activation_redirect'));
        
            /**
             * Register REST endpoints
             */
            KoalaAI_API::register_api_endpoints();
        }

        /**
         * Handles settings update form submission.
         */
        public static function handle_settings_update() {
            if ( isset( $_POST['update_koala_ai_settings'] ) && $_POST['update_koala_ai_settings'] === '1' ) {
                // Verify nonce for security
                if ( isset( $_POST['koala_ai_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['koala_ai_settings_nonce'] ) ), 'koala_ai_settings_action' ) ) {
                    // Update auto import setting
                    $auto_import = isset( $_POST['koala_ai_auto_import'] ) ? 1 : 0;
                    update_option( self::KOALA_AI_AUTO_IMPORT_OPTION, $auto_import );
                    
                    // Update post types setting
                    $post_types = isset( $_POST['koala_ai_post_types'] ) ? (array) wp_unslash($_POST['koala_ai_post_types']) : array();
                    $post_types = array_map( 'sanitize_text_field', $post_types );
                    update_option( self::KOALA_AI_POST_TYPES_OPTION, $post_types );
                    
                    // Update processing mode setting
                    $processing_mode = isset( $_POST['koala_ai_processing_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['koala_ai_processing_mode'] ) ) : 'background';
                    update_option( self::KOALA_AI_PROCESSING_MODE_OPTION, $processing_mode );
                    
                    // Update first image as featured setting
                    $first_image_as_featured = isset( $_POST['koala_ai_first_image_as_featured'] ) ? 1 : 0;
                    update_option( self::KOALA_AI_FIRST_IMAGE_AS_FEATURED_OPTION, $first_image_as_featured );
                    
                    wp_safe_redirect( admin_url( 'admin.php?page=koala-ai&settings_updated=1' ) );
                    exit;
                }
            }
        }

        /**
         * Handles disconnection request from the user.
         */
        public static function handle_disconnection() {
            if ( isset( $_POST['disconnect'] ) && $_POST['disconnect'] === '1' ) {
                // Verify nonce for security in admin forms
                if ( isset( $_POST['koala_ai_disconnect_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['koala_ai_disconnect_nonce'] ) ), 'koala_ai_disconnect_action' ) ) {
                    update_option( self::KOALA_AI_CONNECTION_OPTION, 0 );
                    update_option( self::KOALA_AI_SECRET_TOKEN_OPTION, self::generate_secret_token() );
                    wp_safe_redirect( admin_url( 'admin.php?page=koala-ai&disconnected=1' ) );
                    exit;
                }
            }
        }

        /**
         * Handles the image import request.
         */
        public static function handle_image_import_request() {
            if (isset($_POST['start_image_import']) && $_POST['start_image_import'] === '1') {
                // Verify nonce for security
                if (isset($_POST['koala_ai_image_import_nonce']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['koala_ai_image_import_nonce'] ) ), 'koala_ai_image_import_action')) {
                    // Check if an import is already in progress
                    if (get_transient(self::KOALA_AI_IMAGE_IMPORT_TRANSIENT)) {
                        wp_safe_redirect(admin_url('admin.php?page=koala-ai&import_already_running=1'));
                        exit;
                    }

                    // Set transient to indicate import is in progress
                    set_transient(self::KOALA_AI_IMAGE_IMPORT_TRANSIENT, true, HOUR_IN_SECONDS);
                    
                    // Clear previous log
                    update_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, array());
                    
                    // Schedule the first batch to run immediately
                    if (!wp_next_scheduled('koala_ai_process_image_import')) {
                        wp_schedule_single_event(time(), 'koala_ai_process_image_import');
                    }
                    
                    wp_safe_redirect(admin_url('admin.php?page=koala-ai&import_started=1'));
                    exit;
                }
            }
        }
        
        /**
         * Process a batch of posts for image import.
         */
        public static function process_scheduled_image_import() {
            // Check if import is in progress
            if (!get_transient(self::KOALA_AI_IMAGE_IMPORT_TRANSIENT)) {
                return;
            }
            
            // Get current log
            $log = get_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, array());
            $processed_post_ids = isset($log['processed_posts']) ? $log['processed_posts'] : array();
            
            // Get selected post types from settings
            $image_import_post_types = get_option(self::KOALA_AI_POST_TYPES_OPTION, array('post', 'page'));
            
            // Query for posts that haven't been processed yet
            $args = array(
                'post_type' => $image_import_post_types,
                'post_status' => 'any',
                'posts_per_page' => self::KOALA_AI_IMAGE_IMPORT_BATCH_SIZE,
                'post__not_in' => $processed_post_ids,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            
            $query = new WP_Query($args);
            
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $processed_post_ids[] = $post_id;
                    
                    // Process this post's content
                    $content = get_post_field('post_content', $post_id);
                    $updated_content = KoalaAI_ImageImporter::process_content($content, $post_id);
                    
                    // If content was changed, update the post
                    if ($content !== $updated_content) {
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_content' => $updated_content
                        ));
                        
                        // Log the post that had images updated
                        if (!isset($log['updated_posts'])) {
                            $log['updated_posts'] = array();
                        }
                        $log['updated_posts'][] = array(
                            'post_id' => $post_id,
                            'title' => get_the_title($post_id),
                            'time' => current_time('mysql')
                        );
                    }
                }
                
                wp_reset_postdata();
                
                // Update log with processed posts
                $log['processed_posts'] = $processed_post_ids;
                $log['last_run'] = current_time('mysql');
                update_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, $log);
                
                // Schedule the next batch
                wp_schedule_single_event(time() + 10, 'koala_ai_process_image_import');
            } else {
                // No more posts to process, mark as completed
                $log['status'] = 'completed';
                $log['completed_time'] = current_time('mysql');
                update_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, $log);
                delete_transient(self::KOALA_AI_IMAGE_IMPORT_TRANSIENT);
            }
        }

        /**
         * Process images in post content when a post or page is published.
         * Either processes directly or schedules processing based on settings.
         *
         * @param int     $post_ID Post ID.
         * @param WP_Post $post    Post object.
         * @param bool    $update  Whether this is an existing post being updated.
         */
        public static function process_images_on_publish($post_ID, $post, $update) {
            // Check if auto-import is enabled
            $image_auto_import_enabled = get_option(self::KOALA_AI_AUTO_IMPORT_OPTION, 1);
            if (!$image_auto_import_enabled) {
                return;
            }
            
            // Get allowed post types
            $image_import_post_types = get_option(self::KOALA_AI_POST_TYPES_OPTION, array('post', 'page'));
            
            // Check if current post type is enabled for image import
            if (!in_array($post->post_type, $image_import_post_types)) {
                return;
            }
            
            // Only process if post is being published
            if ($post->post_status !== 'publish') {
                return;
            }
            
            // Check processing mode setting (default to background if not set)
            $processing_mode = get_option(self::KOALA_AI_PROCESSING_MODE_OPTION, 'background');
            
            if ($processing_mode === 'immediate') {
                // Process immediately
                self::process_single_post_images($post_ID);
            } else {
                // Schedule the background task to process this post
                if (!wp_next_scheduled(self::KOALA_AI_PROCESS_POST_HOOK, array($post_ID))) {
                    wp_schedule_single_event(time(), self::KOALA_AI_PROCESS_POST_HOOK, array($post_ID));
                }
            }
        }

        /**
         * Process images for a single post in the background.
         *
         * @param int $post_ID Post ID to process.
         */
        public static function process_single_post_images($post_ID) {
            // Get the post
            $post = get_post($post_ID);
            if (!$post) {
                return;
            }
            
            $content = $post->post_content;
            $updated_content = KoalaAI_ImageImporter::process_content($content, $post_ID);
            
            if ($content !== $updated_content) {
                // Avoid infinite loops by removing the action temporarily
                remove_action('save_post', array('KoalaAI', 'process_images_on_publish'), 10);
                
                // Update the post with the new content
                wp_update_post(array(
                    'ID' => $post_ID,
                    'post_content' => $updated_content
                ));
                
                // Re-add the action
                add_action('save_post', array('KoalaAI', 'process_images_on_publish'), 10, 3);
                
                // Log the post processing if we're keeping logs
                $log = get_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, array());
                if (isset($log['updated_posts'])) {
                    $log['updated_posts'][] = array(
                        'post_id' => $post_ID,
                        'title' => get_the_title($post_ID),
                        'time' => current_time('mysql')
                    );
                    update_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, $log);
                }
            }
        }

        /**
         * Adds the Koala AI Settings menu page to the WordPress admin menu.
         */
        public static function koala_ai_settings_menu() {
            add_menu_page(
                esc_html__( 'Koala AI Settings', 'koala-ai' ),
                esc_html__( 'Koala AI', 'koala-ai' ),
                'manage_options',
                'koala-ai',
                array( 'KoalaAI', 'koala_ai_settings_page' ),
        		plugin_dir_url( __FILE__ ) . 'assets/logos/logo-small.png?12',
                80
            );
            // Enqueue JS and CSS for our settings page
            add_action('admin_enqueue_scripts', function($hook) {
                if ($hook === 'toplevel_page_koala-ai') {
                    // Enqueue admin CSS
                    wp_enqueue_style(
                        'koala-ai-admin',
                        plugin_dir_url(__FILE__) . 'assets/css/koala-ai-admin.css',
                        array(),
                        '1.0'
                    );
                    
                    // Enqueue image import JS
                    wp_enqueue_script(
                        'koala-ai-image-import',
                        plugin_dir_url(__FILE__) . 'assets/js/koala-ai-image-import.js',
                        array('jquery'),
                        '1.0',
                        true
                    );
                    
                    // Enqueue admin JS
                    wp_enqueue_script(
                        'koala-ai-admin',
                        plugin_dir_url(__FILE__) . 'assets/js/koala-ai-admin.js',
                        array('jquery'),
                        '1.0',
                        true
                    );
                    
                    // Set up data for JS
                    wp_localize_script('koala-ai-image-import', 'KoalaAIImageImport', array(
                        'restUrl' => esc_url_raw(rest_url('koala-ai/v1/')),
                        'nonce' => wp_create_nonce('wp_rest'),
                    ));
                    
                    // Set up data for admin JS
                    wp_localize_script('koala-ai-admin', 'koalaAIData', array(
                        'disconnectText' => esc_html__('Disconnect', 'koala-ai'),
                        'disconnectConfirm' => esc_html__('Do you really want to disconnect the website? Your content synchronization will be stopped.', 'koala-ai'),
                    ));
                }
            });
        }

        /**
         * Renders the Koala AI settings page.
         */
        public static function koala_ai_settings_page() {
            $secret_token = self::get_secret_token();
            $website_url = self::get_website_url();
            $connection = self::is_connected() ? 'Connected' : 'Disconnected';
            $import_log = get_option(self::KOALA_AI_IMAGE_IMPORT_LOG_OPTION, array());
            $import_in_progress = get_transient(self::KOALA_AI_IMAGE_IMPORT_TRANSIENT);
            
            // Get auto import settings
            $image_auto_import_enabled = get_option(self::KOALA_AI_AUTO_IMPORT_OPTION, 1);
            $image_import_post_types = get_option(self::KOALA_AI_POST_TYPES_OPTION, array('post', 'page'));
            $processing_mode = get_option(self::KOALA_AI_PROCESSING_MODE_OPTION, 'background');
            $first_image_as_featured_enabled = get_option(self::KOALA_AI_FIRST_IMAGE_AS_FEATURED_OPTION, 0);
            
            // Get all registered post types
            $post_types = array_filter(
                get_post_types(array('public' => true), 'objects'),
                function($post_type) {
                    return $post_type->name !== 'attachment';
                }
            );

            $connection_url = $connection === 'Disconnected'
                ? str_replace(
                    ['{secret_token}', '{url}'],
                    [esc_attr($secret_token), esc_attr(urlencode($website_url))],
                    self::KOALA_AI_CONNECTION_ENDPOINT
                )
                : '#';
            ?>
            <h2><?php esc_html_e( 'Koala AI Settings', 'koala-ai' ); ?></h2>
            <div class="koala-content-container">
                <form action="<?php echo esc_url( $connection_url ); ?>" method="post">
                    <p style="display: none;">
                        <label for="secret-token"><?php esc_html_e( 'Secret Token', 'koala-ai' ); ?></label> <br/> <input type="text" id="secret-token" name="secret-token" size="32" value="<?php echo esc_attr( $secret_token ); ?>" readonly>
                    </p>
                    <p>
                        <label><?php esc_html_e( 'Connection Status: ', 'koala-ai' ); ?> <span style="color: <?php echo $connection === 'Disconnected' ? 'darkred' : 'darkgreen'; ?>; font-weight: bold"><?php echo esc_html( $connection ); ?></span></label>
                    </p>
                    <?php if ( $connection === 'Connected' ) { 
                    ?>
                        <input type="hidden" name="disconnect" value="1">
                        <?php wp_nonce_field('koala_ai_disconnect_action', 'koala_ai_disconnect_nonce'); ?>
                    <?php }
                    // Output submit button
                    if ( $connection === 'Connected' ) {
                        submit_button( esc_html__( 'Disconnect', 'koala-ai' ), 'primary', 'connection-button', false, array( 'onclick' => 'return confirm_disconnect()' ) );
                    } else {
                        echo '<a href="' . esc_url( $connection_url ) . '" target="_blank" class="button button-primary">' . esc_html__( 'Connect', 'koala-ai' ) . '</a>';
                        echo '<a href="' . esc_url( admin_url( 'admin.php?page=koala-ai' ) ) . '" class="button" style="margin-left: 10px;">' . esc_html__( 'Refresh Status', 'koala-ai' ) . '</a>';
                        echo '<div class="connection-note">' . esc_html__( 'After connecting in Koala AI, please come back and click the Refresh Status button to update your connection status.', 'koala-ai' ) . '</div>';
                    }
                    
                    // Show disconnection message if needed
                    if (isset($_GET['disconnected']) && $_GET['disconnected'] == '1') {
                        echo '<div class="connection-note" style="border-left-color: #d63638;">' . esc_html__( 'Your WordPress site has been disconnected. For complete disconnection, please also remove this site from your integrations in your Koala AI account.', 'koala-ai' ) . '</div>';
                    }
                    ?>
                </form>
            </div>
            
            <h3 class="section-title"><?php esc_html_e('Automatic Image Import Settings', 'koala-ai'); ?></h3>
            <div class="koala-content-container">
            
            <div class="koala-info-banner">
                <p><?php esc_html_e('These settings control how Koala AI handles images in your content. When enabled, external images from Koala AI will be automatically downloaded to your WordPress media library, allowing them to be hosted directly on your server instead of the koala.sh domain.', 'koala-ai'); ?></p>
            </div>
            
            <?php if (isset($_GET['settings_updated']) && $_GET['settings_updated'] == '1') : ?>
                <div class="success-message"><?php esc_html_e('Your settings have been updated successfully.', 'koala-ai'); ?></div>
            <?php endif; ?>
            
            <form method="post" class="settings-form">
                <p>
                    <label>
                        <input type="checkbox" name="koala_ai_auto_import" value="1" <?php checked($image_auto_import_enabled, 1); ?> id="koala_ai_auto_import">
                        <?php esc_html_e('Enable automatic image import when posts are saved (only applies to Published posts)', 'koala-ai'); ?>
                    </label>
                </p>
                
                <div id="koala_ai_auto_import_options" style="margin-left: 24px; <?php echo $image_auto_import_enabled ? '' : 'display: none;'; ?>">
                    <p>
                        <label><?php esc_html_e('Automatic Processing Mode:', 'koala-ai'); ?></label>
                    </p>
                    <div style="margin-left: 24px;">
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="koala_ai_processing_mode" value="background" <?php checked($processing_mode, 'background'); ?>> 
                            <?php esc_html_e('Background Processing (faster saving, processes soon after the post is saved)', 'koala-ai'); ?>
                        </label>
                        <label style="display: block;">
                            <input type="radio" name="koala_ai_processing_mode" value="immediate" <?php checked($processing_mode, 'immediate'); ?>> 
                            <?php esc_html_e('Immediate Processing (slower publishing, but ensures processing before completing save)', 'koala-ai'); ?>
                        </label>
                    </div>
                </div>
                
                <p>
                    <label>
                        <input type="checkbox" name="koala_ai_first_image_as_featured" value="1" <?php checked($first_image_as_featured_enabled, 1); ?>>
                        <?php esc_html_e('Set the first image in content as the featured image for the post (only applies to posts without an existing featured image)', 'koala-ai'); ?>
                    </label>
                </p>
                
                <p>
                    <label><?php esc_html_e('Choose post types for processing:', 'koala-ai'); ?></label>
                </p>
                
                <div class="post-types-container">
                    <?php foreach ($post_types as $post_type) : ?>
                        <label class="post-type-label">
                            <input type="checkbox" class="post-type-checkbox" name="koala_ai_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $image_import_post_types), true); ?>>
                            <?php echo esc_html($post_type->labels->singular_name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <input type="hidden" name="update_koala_ai_settings" value="1">
                <?php wp_nonce_field('koala_ai_settings_action', 'koala_ai_settings_nonce'); ?>
                <?php submit_button(esc_html__('Save Settings', 'koala-ai')); ?>
            </form>
            </div>
            
            <h3 class="section-title"><?php esc_html_e('Bulk Image Import Tool', 'koala-ai'); ?></h3>
            <div class="koala-content-container">
            <div id="koala-ai-image-import-ui">
                <div class="note-banner">
                    <p><?php esc_html_e('We recommend creating a backup of your website before using this tool as a best practice.', 'koala-ai'); ?></p>
                </div>
                <div class="koala-info-banner">
                    <p><?php esc_html_e('This tool allows you to import all external Koala AI images from existing content. Before using this tool:', 'koala-ai'); ?></p>
                    <ol>
                        <li><?php esc_html_e('Make sure to save your settings in the section above first', 'koala-ai'); ?></li>
                        <li><?php esc_html_e('Only posts of the selected post types above will be processed', 'koala-ai'); ?></li>
                        <li><?php esc_html_e('If you enabled the "Set first image as featured image" option, it will only apply to posts that don\'t already have a featured image', 'koala-ai'); ?></li>
                    </ol>
                </div>
                <p><?php esc_html_e('This tool scans your content for images from koala.sh, downloads them to your WordPress media library, and updates your posts to use these local copies. The process runs in your browser and shows real-time progress.', 'koala-ai'); ?></p>
                <button id="koala-ai-start-btn" class="button button-primary"><?php esc_html_e('Start Bulk Image Import', 'koala-ai'); ?></button>
                <div id="koala-ai-progress-container" style="margin-top:20px;display:none;max-width:400px;">
                    <div style="background:#eee;border-radius:4px;overflow:hidden;height:24px;">
                        <div id="koala-ai-progress-bar" style="background:#2271b1;height:24px;width:0%;transition:width 0.3s;"></div>
                    </div>
                    <div id="koala-ai-progress-text" style="margin-top:8px;"></div>
                </div>
                <div id="koala-ai-status" style="margin-top:10px;"></div>
            </div>
            </div>
                
            <div class="koala-content-container" style="margin-top: 45px;">Visit <a href="https://koala.sh/?utm_source=wp_plugin_footer" target="_blank">Koala AI</a></div>
            <?php
        }

        /**
         * Gets the secret token for the current site.
         */
        public static function get_secret_token() {
            return get_option( self::KOALA_AI_SECRET_TOKEN_OPTION );
        }

        /**
         * Gets the website URL for the current site.
         */
        public static function get_website_url() {
            return get_bloginfo('url');
        }

        /**
         * Generate Secret Token
         */
        private static function generate_secret_token() {
            return wp_generate_uuid4();
        }

        /**
         * Handles redirect to settings page after plugin activation.
         */
        public static function do_activation_redirect() {
            if (get_option('koala_ai_do_activation_redirect', false)) {
                delete_option('koala_ai_do_activation_redirect');
                if (!isset($_GET['activate-multi'])) { // Prevent redirect on bulk activation
                    wp_safe_redirect(admin_url('admin.php?page=koala-ai'));
                    exit;
                }
            }
        }

        /**
         * Activates the Koala AI plugin by checking and setting the necessary options.
         */
        public static function activate() {
            // If secret token option is empty, set value.
            if ( ! self::get_secret_token() ) {
                update_option( self::KOALA_AI_SECRET_TOKEN_OPTION, self::generate_secret_token() );
            }
            
            // Set default values for auto import settings
            if (get_option(self::KOALA_AI_AUTO_IMPORT_OPTION) === false) {
                update_option(self::KOALA_AI_AUTO_IMPORT_OPTION, 1); // Enabled by default
            }
            
            // Set default values for post types
            if (get_option(self::KOALA_AI_POST_TYPES_OPTION) === false) {
                update_option(self::KOALA_AI_POST_TYPES_OPTION, array('post', 'page'));
            }
            
            // Set default processing mode
            if (get_option(self::KOALA_AI_PROCESSING_MODE_OPTION) === false) {
                update_option(self::KOALA_AI_PROCESSING_MODE_OPTION, 'background'); // Background processing by default
            }
            
            // Set default first image as featured setting (disabled by default)
            if (get_option(self::KOALA_AI_FIRST_IMAGE_AS_FEATURED_OPTION) === false) {
                update_option(self::KOALA_AI_FIRST_IMAGE_AS_FEATURED_OPTION, 0);
            }
            
            // Set flag for activation redirect
            update_option('koala_ai_do_activation_redirect', true);
        }

        /**
         * Checks if the Koala AI connection is active.
         */
        public static function is_connected() {
            $connection_data = get_option( self::KOALA_AI_CONNECTION_OPTION );
            return !empty($connection_data);
        }

        /**
         * Gets the stored integration ID if connected.
         * @return string|null
         */
        public static function get_integration_id() {
            $connection_data = get_option( self::KOALA_AI_CONNECTION_OPTION );
            if (is_array($connection_data) && isset($connection_data['integration_id']) && !empty($connection_data['integration_id'])) {
                return $connection_data['integration_id'];
            }
            return null;
        }
    }

    KoalaAI::init();
}

/**
 * Set secret_token when plugin is activated
 */
register_activation_hook( __FILE__, array( 'KoalaAI', 'activate' ) );