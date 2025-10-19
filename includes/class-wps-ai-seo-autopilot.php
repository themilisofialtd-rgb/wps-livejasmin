<?php
/**
 * WPS LiveJasmin AI SEO Autopilot.
 *
 * Provides Smart Hybrid Mode SEO generation that can delegate to TMW SEO Autopilot 100
 * when available, otherwise falls back to a lightweight internal heuristic engine.
 *
 * @package WPS-LiveJasmin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPS_AI_SEO_Autopilot' ) ) {

	/**
	 * Handles AI-based keyword, title and meta generation for LiveJasmin videos.
	 */
	class WPS_AI_SEO_Autopilot {

		/**
		 * Bootstrap hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wps_livejasmin_video_imported', array( __CLASS__, 'generate_seo_meta' ), 15, 2 );
			add_action( 'wps_livejasmin_after_video_import', array( __CLASS__, 'generate_seo_meta' ), 15, 2 );
			add_action( 'admin_menu', array( __CLASS__, 'add_settings_tab' ) );
			add_action( 'wp_ajax_wps_run_ai_seo_autopilot', array( __CLASS__, 'ajax_run_batch' ) );
		}

		/**
		 * Generate Rank Math SEO fields for a video post.
		 *
		 * @param int   $post_id    Post ID.
		 * @param array $video_data Optional contextual data.
		 * @return void
		 */
		public static function generate_seo_meta( $post_id, $video_data = array() ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return;
			}

			$existing_keyword = get_post_meta( $post_id, '_rank_math_focus_keyword', true );
			if ( ! $existing_keyword ) {
				$existing_keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			}

			if ( $existing_keyword ) {
				self::log( sprintf( 'Skipped (already optimized) Post ID %d', $post_id ) );
				return;
			}

			$title = isset( $video_data['title'] ) ? sanitize_text_field( $video_data['title'] ) : get_the_title( $post_id );
			$tags  = isset( $video_data['tags'] ) ? (array) $video_data['tags'] : array();
			$model = isset( $video_data['model'] ) ? sanitize_text_field( $video_data['model'] ) : '';

			if ( empty( $tags ) ) {
				$terms = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					$tags = $terms;
				}
			}

			$seo = array();

			if ( function_exists( 'tmw_seo_autopilot_generate' ) ) {
				$seo = tmw_seo_autopilot_generate( $title, implode( ', ', $tags ), $model, 'video' );
				self::log( sprintf( 'TMW SEO Autopilot used for Post ID %d', $post_id ) );
			} else {
				$seo = self::internal_ai_generate( $title, $tags, $model );
				self::log( sprintf( 'Internal AI fallback used for Post ID %d', $post_id ) );
			}

			if ( ! empty( $seo['focus_keyword'] ) ) {
				update_post_meta( $post_id, '_rank_math_focus_keyword', $seo['focus_keyword'] );
				update_post_meta( $post_id, 'rank_math_focus_keyword', $seo['focus_keyword'] );
			}

			if ( ! empty( $seo['seo_title'] ) ) {
				update_post_meta( $post_id, '_rank_math_title', $seo['seo_title'] );
				update_post_meta( $post_id, 'rank_math_title', $seo['seo_title'] );
			}

			if ( ! empty( $seo['seo_description'] ) ) {
				update_post_meta( $post_id, '_rank_math_description', $seo['seo_description'] );
				update_post_meta( $post_id, 'rank_math_description', $seo['seo_description'] );
			}

			self::log( sprintf( 'AI_SEO: Created keywords for Post ID %d', $post_id ) );
		}

		/**
		 * Simple internal fallback generator.
		 *
		 * @param string $title Post title.
		 * @param array  $tags  Tags array.
		 * @param string $model Model name.
		 * @return array
		 */
		private static function internal_ai_generate( $title, $tags, $model ) {
			$title = (string) $title;
			$model = (string) $model;

			$tags_str = implode( ', ', array_filter( array_map( 'sanitize_text_field', (array) $tags ) ) );
			$focus_keyword = trim( $model . ' ' . $tags_str );
			$focus_keyword = mb_substr( $focus_keyword, 0, 60 );

			$seo_title = trim( $model ) ? sprintf( '%s – %s', $title, $model ) : $title;
			$seo_description = trim( $model )
				? sprintf( '%s stars in %s. Watch her now live!', $model, mb_strtolower( $title ) )
				: sprintf( 'Watch %s live now!', $title );
			$seo_description = mb_substr( $seo_description, 0, 160 );

			return array(
				'focus_keyword'   => $focus_keyword,
				'seo_title'       => $seo_title,
				'seo_description' => $seo_description,
			);
		}

		/**
		 * Register submenu page under the LiveJasmin menu.
		 *
		 * @return void
		 */
		public static function add_settings_tab() {
			add_submenu_page(
				'wps-livejasmin',
				__( 'SEO Automation', 'lvjm_lang' ),
				__( 'SEO Automation', 'lvjm_lang' ),
				'manage_options',
				'wps-livejasmin-seo-autopilot',
				array( __CLASS__, 'render_settings_page' )
			);
		}

		/**
		 * Render admin settings page.
		 *
		 * @return void
		 */
		public static function render_settings_page() {
			$tmw_detected = function_exists( 'tmw_seo_autopilot_generate' );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'WPS-LiveJasmin – SEO Automation', 'lvjm_lang' ); ?></h1>

				<div style="margin: 15px 0; padding: 10px; border-radius:8px; background:<?php echo $tmw_detected ? '#e5ffe5' : '#f3f3f3'; ?>;">
					<strong><?php esc_html_e( '🧠 TMW SEO Autopilot 100:', 'lvjm_lang' ); ?></strong>
					<?php if ( $tmw_detected ) : ?>
						✅ <span style="color:green;font-weight:bold;">
							<?php esc_html_e( 'Detected & Connected', 'lvjm_lang' ); ?>
						</span> — <?php esc_html_e( 'Using master AI engine.', 'lvjm_lang' ); ?>
					<?php else : ?>
						⚪ <span style="color:gray;font-weight:bold;">
							<?php esc_html_e( 'Not Detected', 'lvjm_lang' ); ?>
						</span> — <?php esc_html_e( 'Using internal AI engine.', 'lvjm_lang' ); ?>
					<?php endif; ?>
				</div>

				<form id="wps-ai-seo-form" method="post" action="options.php">
					<p><label><input type="checkbox" checked="checked" disabled="disabled" /> <?php esc_html_e( 'Enable AI-Based RankMath Auto-Generation', 'lvjm_lang' ); ?></label></p>
					<p><label><input type="checkbox" checked="checked" disabled="disabled" /> <?php esc_html_e( 'Only fill empty RankMath fields', 'lvjm_lang' ); ?></label></p>
					<p><label><input type="checkbox" checked="checked" disabled="disabled" /> <?php esc_html_e( 'Log AI SEO actions to debug.log', 'lvjm_lang' ); ?></label></p>
					<p><button type="button" class="button button-primary" id="run-ai-seo-now"><?php esc_html_e( 'Run SEO Autopilot Now', 'lvjm_lang' ); ?></button></p>
				</form>

				<script type="text/javascript">
				jQuery('#run-ai-seo-now').on('click', function() {
					var $btn = jQuery(this);
					$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Running…', 'lvjm_lang' ) ); ?>');
					jQuery.post(ajaxurl, { action: 'wps_run_ai_seo_autopilot' }).done(function(response) {
						var message = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'SEO Autopilot run complete.', 'lvjm_lang' ) ); ?>';
						alert(message);
					}).always(function() {
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run SEO Autopilot Now', 'lvjm_lang' ) ); ?>');
					});
				});
				</script>
			</div>
			<?php
		}

		/**
		 * AJAX handler to batch re-optimise all videos.
		 *
		 * @return void
		 */
		public static function ajax_run_batch() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized', 'lvjm_lang' ) ) );
			}

			$args = array(
				'post_type'      => 'video',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			);

			$posts = get_posts( $args );

			foreach ( $posts as $pid ) {
				self::generate_seo_meta( $pid );
			}

			wp_send_json_success( array( 'message' => __( 'SEO Autopilot completed for all videos.', 'lvjm_lang' ) ) );
		}

		/**
		 * Basic logger helper.
		 *
		 * @param string $message Message to write.
		 * @return void
		 */
		private static function log( $message ) {
			if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
				error_log( '[AI_SEO] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}

add_action( 'plugins_loaded', array( 'WPS_AI_SEO_Autopilot', 'init' ) );
