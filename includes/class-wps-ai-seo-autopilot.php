<?php
/**
 * AI SEO Autopilot handler.
 *
 * @package LIVEJASMIN\Includes
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPS_LiveJasmin_AI_SEO_Autopilot' ) ) {

        /**
         * Generate RankMath metadata for imported videos.
         */
        class WPS_LiveJasmin_AI_SEO_Autopilot {

                /**
                 * Singleton instance.
                 *
                 * @var self|null
                 */
                protected static $instance = null;

                /**
                 * Get singleton instance.
                 *
                 * @return self
                 */
                public static function instance() {
                        if ( null === self::$instance ) {
                                self::$instance = new self();
                        }

                        return self::$instance;
                }

                /**
                 * Constructor.
                 */
                protected function __construct() {
                        add_action( 'wps_livejasmin_after_video_import', array( $this, 'maybe_generate_for_import' ), 50, 2 );
                        add_action( 'wp_ajax_wps_run_ai_seo_autopilot', array( $this, 'handle_ajax_batch' ) );
                        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
                }

                /**
                 * Maybe run generator after import.
                 *
                 * @param int   $post_id    Post ID.
                 * @param array $video_data Video data.
                 * @return void
                 */
                public function maybe_generate_for_import( $post_id, $video_data = array() ) {
                        $status = $this->process_post( $post_id, $video_data );

                        $this->log_status( $status, $post_id );
                }

                /**
                 * Handle manual AJAX batch processing.
                 *
                 * @return void
                 */
                public function handle_ajax_batch() {
                        check_ajax_referer( 'wps-ai-seo-autopilot', 'nonce' );

                        if ( ! current_user_can( 'manage_options' ) ) {
                                wp_send_json_error(
                                        array(
                                                'message' => esc_html__( 'You are not allowed to run the SEO autopilot.', 'lvjm_lang' ),
                                        ),
                                        403
                                );
                        }

                        $page  = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
                        $batch = isset( $_POST['batch'] ) ? max( 1, min( 50, absint( $_POST['batch'] ) ) ) : 5;

                        $post_type = $this->get_video_post_type();

                        $query = new WP_Query(
                                array(
                                        'post_type'      => $post_type,
                                        'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                                        'posts_per_page' => $batch,
                                        'paged'          => $page,
                                        'fields'         => 'ids',
                                        'orderby'        => 'ID',
                                        'order'          => 'ASC',
                                )
                        );

                        $optimized = 0;
                        $processed = 0;

                        if ( $query->have_posts() ) {
                                foreach ( (array) $query->posts as $post_id ) {
                                        $processed++;
                                        $result = $this->process_post( $post_id );
                                        if ( 'created' === $result ) {
                                                $optimized++;
                                        }

                                        $this->log_status( $result, $post_id );
                                }
                                wp_reset_postdata();
                        }

                        $has_more = ( $page < max( 1, (int) $query->max_num_pages ) );

                        wp_send_json_success(
                                array(
                                        'page'      => $page,
                                        'processed' => $processed,
                                        'optimized' => $optimized,
                                        'has_more'  => $has_more,
                                )
                        );
                }

                /**
                 * Enqueue admin assets.
                 *
                 * @param string $hook_suffix Hook suffix.
                 * @return void
                 */
                public function enqueue_admin_assets( $hook_suffix ) {
                        if ( empty( $_GET['page'] ) || 'lvjm-options' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                return;
                        }

                        wp_register_script(
                                'wps-ai-seo-autopilot',
                                plugins_url( 'admin/assets/js/ai-seo-autopilot.js', LVJM_FILE ),
                                array( 'jquery' ),
                                defined( 'LVJM_VERSION' ) ? LVJM_VERSION : '1.0.0',
                                true
                        );

                        wp_localize_script(
                                'wps-ai-seo-autopilot',
                                'wpsAiSeoAutopilotL10n',
                                array(
                                        'start'    => esc_html__( 'Starting AI SEO optimization…', 'lvjm_lang' ),
                                        'progress' => esc_html__( 'Optimized %1$s posts (processed %2$s in current batch)…', 'lvjm_lang' ),
                                        'complete' => esc_html__( 'SEO Autopilot completed for %s posts.', 'lvjm_lang' ),
                                        'error'    => esc_html__( 'The SEO autopilot request failed. Please try again.', 'lvjm_lang' ),
                                )
                        );

                        wp_enqueue_script( 'wps-ai-seo-autopilot' );
                }

                /**
                 * Process a post.
                 *
                 * @param int   $post_id    Post ID.
                 * @param array $video_data Optional video data.
                 * @return string|WP_Error Either created/skipped or WP_Error on failure.
                 */
                public function process_post( $post_id, $video_data = array() ) {
                        $post_id = absint( $post_id );

                        if ( $post_id <= 0 ) {
                                return new WP_Error( 'wps-ai-seo-invalid-id', __( 'Invalid post ID supplied to the SEO autopilot.', 'lvjm_lang' ) );
                        }

                        if ( ! $this->is_enabled() ) {
                                return 'skipped';
                        }

                        $post = get_post( $post_id );
                        if ( ! $post || 'auto-draft' === $post->post_status ) {
                                return 'skipped';
                        }

                        $existing_focus = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
                        if ( $existing_focus && $this->only_fill_empty() ) {
                                return 'skipped';
                        }

                        $context = $this->build_context( $post, $video_data );

                        $metadata = $this->generate_metadata( $context );

                        if ( empty( $metadata['focus_keyword'] ) && empty( $metadata['seo_title'] ) && empty( $metadata['meta_description'] ) ) {
                                return new WP_Error( 'wps-ai-seo-empty', __( 'AI SEO autopilot was unable to build metadata.', 'lvjm_lang' ) );
                        }

                        $metadata = $this->sanitize_metadata( $metadata );

                        $updated = $this->update_post_metadata( $post_id, $metadata );

                        if ( ! $updated ) {
                                return 'skipped';
                        }

                        return 'created';
                }

                /**
                 * Check whether the generator is enabled.
                 *
                 * @return bool
                 */
                protected function is_enabled() {
                        return 'on' === $this->get_option( 'lvjm-ai-seo-enable', 'on' );
                }

                /**
                 * Check whether only empty fields should be updated.
                 *
                 * @return bool
                 */
                protected function only_fill_empty() {
                        return 'on' === $this->get_option( 'lvjm-ai-seo-only-empty', 'on' );
                }

                /**
                 * Check whether debug logging is enabled.
                 *
                 * @return bool
                 */
                protected function logging_enabled() {
                        return 'on' === $this->get_option( 'lvjm-ai-seo-logging', 'on' );
                }

                /**
                 * Retrieve an option from Xbox.
                 *
                 * @param string $key     Option key.
                 * @param mixed  $default Default value.
                 * @return mixed
                 */
                protected function get_option( $key, $default = '' ) {
                        if ( function_exists( 'xbox_get_field_value' ) ) {
                                $value = xbox_get_field_value( 'lvjm-options', $key );
                                if ( null !== $value && '' !== $value ) {
                                        return $value;
                                }
                        }

                        return $default;
                }

                /**
                 * Build context data for generation.
                 *
                 * @param WP_Post $post       Post object.
                 * @param array   $video_data Video data.
                 * @return array
                 */
                protected function build_context( $post, $video_data = array() ) {
                        $title       = $post->post_title;
                        $description = wp_strip_all_tags( $post->post_content );

                        $model_name = '';
                        if ( ! empty( $video_data['model_name'] ) ) {
                                $model_name = (string) $video_data['model_name'];
                        } elseif ( metadata_exists( 'post', $post->ID, 'model_name' ) ) {
                                $model_name = get_post_meta( $post->ID, 'model_name', true );
                        }

                        if ( ! $model_name ) {
                                $model_terms = wp_get_post_terms( $post->ID, 'models', array( 'fields' => 'names' ) );
                                if ( ! is_wp_error( $model_terms ) && ! empty( $model_terms ) ) {
                                        $model_name = (string) $model_terms[0];
                                }
                        }

                        $tags_taxonomy = $this->get_tags_taxonomy();
                        $tags          = array();
                        if ( $tags_taxonomy ) {
                                $terms = wp_get_post_terms( $post->ID, $tags_taxonomy, array( 'fields' => 'names' ) );
                                if ( ! is_wp_error( $terms ) ) {
                                        $tags = array_values( array_filter( array_map( 'trim', $terms ) ) );
                                }
                        }

                        return array(
                                'title'       => (string) $title,
                                'description' => (string) $description,
                                'model_name'  => (string) $model_name,
                                'tags'        => $tags,
                                'site_name'   => get_bloginfo( 'name', 'display' ),
                                'locale'      => get_locale(),
                        );
                }

                /**
                 * Generate metadata via AI/fallback.
                 *
                 * @param array $context Context data.
                 * @return array
                 */
                protected function generate_metadata( $context ) {
                        $metadata = array();

                        if ( function_exists( 'tmw_seo_autopilot_generate' ) ) {
                                $metadata = tmw_seo_autopilot_generate( $context );
                        } else {
                                $api_key = apply_filters( 'wps_livejasmin_ai_seo_api_key', '' );
                                if ( empty( $api_key ) && defined( 'WPS_LIVEJASMIN_OPENAI_KEY' ) ) {
                                        $api_key = WPS_LIVEJASMIN_OPENAI_KEY;
                                }

                                if ( ! empty( $api_key ) ) {
                                        $metadata = $this->request_openai_metadata( $context, $api_key );
                                }
                        }

                        if ( is_string( $metadata ) ) {
                                $decoded_metadata = json_decode( $metadata, true );
                                if ( json_last_error() === JSON_ERROR_NONE ) {
                                        $metadata = $decoded_metadata;
                                }
                        }

                        if ( is_wp_error( $metadata ) || empty( $metadata ) ) {
                                $metadata = $this->generate_fallback_metadata( $context );
                        }

                        return $metadata;
                }

                /**
                 * Request metadata from OpenAI.
                 *
                 * @param array  $context Context data.
                 * @param string $api_key API key.
                 * @return array|WP_Error
                 */
                protected function request_openai_metadata( $context, $api_key ) {
                        if ( empty( $api_key ) ) {
                                return array();
                        }

                        $prompt = sprintf(
                                'Create natural RankMath metadata for a WordPress video post. Return valid JSON with keys focus_keyword, seo_title, meta_description. Avoid keyword stuffing. Context: %s',
                                wp_json_encode( $context )
                        );

                        $request = wp_remote_post(
                                'https://api.openai.com/v1/chat/completions',
                                array(
                                        'headers' => array(
                                                'Content-Type'  => 'application/json',
                                                'Authorization' => 'Bearer ' . $api_key,
                                        ),
                                        'body'    => wp_json_encode(
                                                array(
                                                        'model'       => 'gpt-3.5-turbo',
                                                        'temperature' => 0.6,
                                                        'messages'    => array(
                                                                array(
                                                                        'role'    => 'system',
                                                                        'content' => 'You are an expert adult SEO copywriter who crafts concise, human descriptions.',
                                                                ),
                                                                array(
                                                                        'role'    => 'user',
                                                                        'content' => $prompt,
                                                                ),
                                                        ),
                                                )
                                        ),
                                        'timeout' => 20,
                                )
                        );

                        if ( is_wp_error( $request ) ) {
                                return $request;
                        }

                        $body = wp_remote_retrieve_body( $request );
                        if ( empty( $body ) ) {
                                return new WP_Error( 'wps-ai-seo-empty-response', __( 'Empty response from AI provider.', 'lvjm_lang' ) );
                        }

                        $decoded = json_decode( $body, true );
                        if ( json_last_error() !== JSON_ERROR_NONE ) {
                                return new WP_Error( 'wps-ai-seo-invalid-json', __( 'Invalid AI response received.', 'lvjm_lang' ) );
                        }

                        if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
                                return new WP_Error( 'wps-ai-seo-missing-choice', __( 'AI response did not contain any content.', 'lvjm_lang' ) );
                        }

                        $content = trim( $decoded['choices'][0]['message']['content'] );
                        $content = trim( $content, "\xEF\xBB\xBF" );

                        $metadata = json_decode( $content, true );
                        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $metadata ) ) {
                                // Attempt to extract JSON from text.
                                if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
                                        $metadata = json_decode( $matches[0], true );
                                }
                        }

                        if ( ! is_array( $metadata ) ) {
                                return new WP_Error( 'wps-ai-seo-unreadable', __( 'Unable to parse AI response.', 'lvjm_lang' ) );
                        }

                        return $metadata;
                }

                /**
                 * Generate fallback metadata without AI.
                 *
                 * @param array $context Context data.
                 * @return array
                 */
                protected function generate_fallback_metadata( $context ) {
                        $model      = trim( (string) $context['model_name'] );
                        $title      = trim( (string) $context['title'] );
                        $site_name  = trim( (string) $context['site_name'] );
                        $tags       = isset( $context['tags'] ) ? (array) $context['tags'] : array();
                        $primary_kw = '';

                        if ( ! empty( $tags ) ) {
                                $primary_kw = sanitize_text_field( $tags[0] );
                        } elseif ( ! empty( $title ) ) {
                                $words      = preg_split( '/\s+/u', $title );
                                $primary_kw = implode( ' ', array_slice( (array) $words, 0, 3 ) );
                        }

                        $primary_kw = trim( $primary_kw );
                        if ( $model && $primary_kw ) {
                                $focus_keyword = $model . ' ' . $primary_kw;
                        } elseif ( $model ) {
                                $focus_keyword = $model . ' live cam';
                        } else {
                                $focus_keyword = $primary_kw ? $primary_kw : $title;
                        }

                        $seo_title = $title;
                        if ( $model ) {
                                $seo_title = sprintf( '%s – %s Live Cam Video', $title, $model );
                        } elseif ( $site_name ) {
                                $seo_title = sprintf( '%s – %s', $title, $site_name );
                        }

                        $description_source = ! empty( $context['description'] ) ? $context['description'] : $title;
                        $meta_description   = sprintf(
                                '%s Watch %s for exclusive shows on %s.',
                                $model ? sprintf( '%s brings you a sensual performance.', $model ) : 'Enjoy a sensual cam show.',
                                $title,
                                $site_name ? $site_name : __( 'our cam portal', 'lvjm_lang' )
                        );

                        if ( ! empty( $description_source ) ) {
                                $meta_description = wp_strip_all_tags( $description_source );
                                $meta_description = wp_trim_words( $meta_description, 28, '' );
                                if ( $model ) {
                                        $meta_description = sprintf( '%s — featuring %s.', $meta_description, $model );
                                }
                        }

                        return array(
                                'focus_keyword'    => $focus_keyword,
                                'seo_title'        => $seo_title,
                                'meta_description' => $meta_description,
                        );
                }

                /**
                 * Sanitize metadata values.
                 *
                 * @param array $metadata Metadata.
                 * @return array
                 */
                protected function sanitize_metadata( $metadata ) {
                        $focus_keyword    = isset( $metadata['focus_keyword'] ) ? $this->clean_text_field( $metadata['focus_keyword'], 60 ) : '';
                        $seo_title        = isset( $metadata['seo_title'] ) ? $this->clean_text_field( $metadata['seo_title'], 60 ) : '';
                        $meta_description = isset( $metadata['meta_description'] ) ? $this->clean_text_field( $metadata['meta_description'], 160 ) : '';

                        return array(
                                'focus_keyword'    => $focus_keyword,
                                'seo_title'        => $seo_title,
                                'meta_description' => $meta_description,
                        );
                }

                /**
                 * Clean a text field and trim length.
                 *
                 * @param string $value  Value.
                 * @param int    $length Max length.
                 * @return string
                 */
                protected function clean_text_field( $value, $length ) {
                        $value = sanitize_text_field( (string) $value );
                        if ( function_exists( 'mb_substr' ) ) {
                                $value = mb_substr( $value, 0, $length );
                        } else {
                                $value = substr( $value, 0, $length );
                        }

                        return trim( $value );
                }

                /**
                 * Update post RankMath metadata.
                 *
                 * @param int   $post_id  Post ID.
                 * @param array $metadata Metadata array.
                 * @return bool
                 */
                protected function update_post_metadata( $post_id, $metadata ) {
                        $updated = false;

                        if ( ! empty( $metadata['focus_keyword'] ) && ( ! $this->only_fill_empty() || '' === get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ) ) {
                                update_post_meta( $post_id, 'rank_math_focus_keyword', $metadata['focus_keyword'] );
                                $updated = true;
                        }

                        if ( ! empty( $metadata['seo_title'] ) ) {
                                if ( ! $this->only_fill_empty() || '' === get_post_meta( $post_id, 'rank_math_title', true ) ) {
                                        update_post_meta( $post_id, 'rank_math_title', $metadata['seo_title'] );
                                        update_post_meta( $post_id, 'rank_math_og_title', $metadata['seo_title'] );
                                        update_post_meta( $post_id, 'rank_math_twitter_title', $metadata['seo_title'] );
                                        $updated = true;
                                }
                        }

                        if ( ! empty( $metadata['meta_description'] ) ) {
                                if ( ! $this->only_fill_empty() || '' === get_post_meta( $post_id, 'rank_math_description', true ) ) {
                                        update_post_meta( $post_id, 'rank_math_description', $metadata['meta_description'] );
                                        update_post_meta( $post_id, 'rank_math_og_description', $metadata['meta_description'] );
                                        update_post_meta( $post_id, 'rank_math_twitter_description', $metadata['meta_description'] );
                                        $updated = true;
                                }
                        }

                        return $updated;
                }

                /**
                 * Determine tag taxonomy.
                 *
                 * @return string
                 */
                protected function get_tags_taxonomy() {
                        $custom_tags = $this->get_option( 'custom-video-tags', '' );
                        if ( empty( $custom_tags ) ) {
                                $custom_tags = 'post_tag';
                        }

                        return $custom_tags;
                }

                /**
                 * Determine imported post type.
                 *
                 * @return string|string[]
                 */
                protected function get_video_post_type() {
                        $post_type = $this->get_option( 'custom-video-post-type', 'post' );
                        if ( empty( $post_type ) ) {
                                $post_type = 'post';
                        }

                        return $post_type;
                }

                /**
                 * Log helper.
                 *
                 * @param string $message Log message.
                 * @return void
                 */
                protected function log( $message ) {
                        if ( ! $this->logging_enabled() ) {
                                return;
                        }

                        if ( function_exists( 'error_log' ) ) {
                                error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        }
                }

                /**
                 * Log helper for action status.
                 *
                 * @param string|WP_Error $status  Action status.
                 * @param int             $post_id Post ID.
                 * @return void
                 */
                protected function log_status( $status, $post_id ) {
                        if ( ! $this->logging_enabled() ) {
                                return;
                        }

                        $post_id = absint( $post_id );
                        $title   = get_the_title( $post_id );

                        if ( is_wp_error( $status ) ) {
                                $this->log( sprintf( '[AI_SEO] Failed: %1$d – %2$s (%3$s)', $post_id, $title, $status->get_error_message() ) );
                                return;
                        }

                        if ( 'created' === $status ) {
                                $this->log( sprintf( '[AI_SEO] Created: %1$d – %2$s', $post_id, $title ) );
                                return;
                        }

                        if ( 'skipped' === $status ) {
                                $this->log( sprintf( '[AI_SEO] Skipped: %1$d – %2$s', $post_id, $title ) );
                        }
                }
        }
}

