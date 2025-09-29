<?php
/**
 * Admin Action plugin file.
 *
 * @package LIVEJASMIN\Admin\Actions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Import videos in Ajax or PHP call.
 *
 * @param mixed $params       Array of parameters if this function is called in PHP.
 * @return void|array $output New post ID if success, -1 if not. Returned only if this function is called in PHP.
 */
function lvjm_import_video( $params = '' ) {
	$ajax_call = '' === $params;

	if ( $ajax_call ) {
		check_ajax_referer( 'ajax-nonce', 'nonce' );
		$params = $_POST;
	}

	if ( ! isset( $params['partner_id'], $params['video_infos'], $params['status'], $params['feed_id'], $params['cat_s'], $params['cat_wp'] ) ) {
		wp_die( 'Some parameters are missing!' );
	}

	// get custom post type.
	$custom_post_type = xbox_get_field_value( 'lvjm-options', 'custom-video-post-type' );

	// prepare post data.
	$post_args = array(
		'post_author'  => '1',
		'post_status'  => '' !== $params['status'] ? $params['status'] : xbox_get_field_value( 'lvjm-options', 'default-status' ),
		'post_type'    => '' !== $custom_post_type ? $custom_post_type : 'post',
		'post_title'   => isset( $params['video_infos']['title'] ) ? $params['video_infos']['title'] : 'Untitled',
		'post_content' => isset( $params['video_infos']['desc'] ) ? $params['video_infos']['desc'] : '',
	);

	// insert post.
	$post_id = wp_insert_post( $post_args, true );

	// add post metas & taxonomies.
	if ( is_wp_error( $post_id ) ) {
		$output = -1;
	} else {
		// add embed and actors.
		$more_data                       = lvjm_get_embed_and_actors( array( 'video_id' => $params['video_infos']['id'] ) );
		$params['video_infos']['embed']  = $more_data['embed'];
		$params['video_infos']['actors'] = empty( $params['video_infos']['actors'] ) ? $more_data['performer_name'] : $params['video_infos']['actors'];

		// add partner id.
		update_post_meta( $post_id, 'partner', (string) $params['partner_id'] );
		// add video id.
		update_post_meta( $post_id, 'video_id', (string) $params['video_infos']['id'] );
		// add main thumb.
		update_post_meta( $post_id, 'thumb', (string) $params['video_infos']['thumb_url'] );
		// add partner_cat.
		update_post_meta( $post_id, 'partner_cat', (string) $params['cat_s'] );
		// add feed.
		update_post_meta( $post_id, 'feed', (string) $params['feed_id'] );
		// add video length.
		$custom_duration = xbox_get_field_value( 'lvjm-options', 'custom-duration' );
		update_post_meta( $post_id, '' !== $custom_duration ? $custom_duration : 'duration', (string) $params['video_infos']['duration'] );
		// add embed player.
		$custom_embed_player = xbox_get_field_value( 'lvjm-options', 'custom-embed-player' );
		update_post_meta( $post_id, '' !== $custom_embed_player ? $custom_embed_player : 'embed', (string) $params['video_infos']['embed'] );
		// add video url.
		$custom_video_url = xbox_get_field_value( 'lvjm-options', 'custom-video-url' );
		update_post_meta( $post_id, '' !== $custom_video_url ? $custom_video_url : 'video_url', (string) $params['video_infos']['video_url'] );
		// add tracking url.
		$custom_tracking_url = xbox_get_field_value( 'lvjm-options', 'custom-tracking-url' );
		update_post_meta( $post_id, '' !== $custom_tracking_url ? $custom_tracking_url : 'tracking_url', (string) $params['video_infos']['tracking_url'] );
		// add quality.
		$custom_quality = xbox_get_field_value( 'lvjm-options', 'custom-quality' );
		update_post_meta( $post_id, '' !== $custom_quality ? $custom_quality : 'quality', (string) $params['video_infos']['quality'] );
		// add isHd.
		$custom_is_hd = xbox_get_field_value( 'lvjm-options', 'custom-isHd' );
		$is_hd_data   = (string) $params['video_infos']['isHd'];
		if ( '1' === $is_hd_data ) {
			$is_hd_data = 'yes';
		} else {
			$is_hd_data = 'no';
		}
		update_post_meta( $post_id, '' !== $custom_is_hd ? $custom_is_hd : 'isHd', $is_hd_data );
		// add uploader.
		$custom_uploader = xbox_get_field_value( 'lvjm-options', 'custom-uploader' );
		update_post_meta( $post_id, '' !== $custom_uploader ? $custom_uploader : 'uploader', (string) $params['video_infos']['uploader'] );
		// add category.
		$custom_taxonomy = xbox_get_field_value( 'lvjm-options', 'custom-video-categories' );
		wp_set_object_terms( $post_id, intval( $params['cat_wp'] ), '' !== $custom_taxonomy ? $custom_taxonomy : 'category', false );
		// add tags.
		$custom_tags = xbox_get_field_value( 'lvjm-options', 'custom-video-tags' );
		if ( '' === $custom_tags ) {
			$custom_tags = 'post_tag';
		}
		wp_set_object_terms( $post_id, explode( ',', str_replace( ';', ',', (string) $params['video_infos']['tags'] ) ), LVJM()->call_by_ref( $custom_tags ), false );
		// add actors.
		$custom_actors = xbox_get_field_value( 'lvjm-options', 'custom-video-actors' );
                if ( '' === $custom_actors ) { $custom_actors = 'models'; }
                if ( 'actors' === $custom_actors ) { $custom_actors = 'models'; }

                if ( '' === $custom_actors ) {
                        $custom_actors = 'actors';
                }

                $models_taxonomy     = LVJM()->call_by_ref( $custom_actors );
                $assigned_model_terms = array();
                if ( ! empty( $params['video_infos']['actors'] ) ) {
                        $assigned_model_terms = wp_set_object_terms( $post_id, explode( ',', str_replace( ';', ',', (string) $params['video_infos']['actors'] ) ), $models_taxonomy, false );
                }

                if ( ! is_wp_error( $assigned_model_terms ) ) {
                        lvjm_ensure_model_profiles_from_terms( (array) $assigned_model_terms, $models_taxonomy, $post_id );
                }
		// add thumbs.
		foreach ( (array) $params['video_infos']['thumbs_urls'] as $thumb ) {
			if ( ! empty( $thumb ) ) {
				add_post_meta( $post_id, 'thumbs', $thumb, false );
			}
		}
		// add trailer.
		update_post_meta( $post_id, 'trailer_url', (string) $params['video_infos']['trailer_url'] );

		// downloading main thumb.
		if ( 'on' === xbox_get_field_value( 'lvjm-options', 'import-thumb' ) ) {

			$default_thumb = (string) $params['video_infos']['thumb_url'];

			if ( strpos( $default_thumb, 'http' ) === false ) {
				$default_thumb = 'http:' . $default_thumb;
			}

			// magic sideload image returns an HTML image, not an ID.
			$media = LVJM()->media_sideload_image( $default_thumb, $post_id, null, $params['partner_id'] );

			// therefore we must find it so we can set it as featured ID.
			if ( ! empty( $media ) && ! is_wp_error( $media ) ) {
				$args = array(
					'post_type'      => 'attachment',
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'post_parent'    => $post_id,
				);

				// reference new image to set as featured.
				$attachments = get_posts( $args );
				if ( isset( $attachments ) && is_array( $attachments ) ) {
					foreach ( $attachments as $attachment ) {
						// grab partner_id of full size images (so no 300x150 nonsense in path).
						$default_thumb = wp_get_attachment_image_src( $attachment->ID, 'full' );
						// determine if in the $media image we created, the string of the URL exists.
						if ( strpos( $media, $default_thumb[0] ) !== false ) {
							// if so, we found our image. set it as thumbnail.
							set_post_thumbnail( $post_id, $attachment->ID );
							// only want one image.
							break;
						}
					}
				}
			}
		}

		// post format video.
		set_post_format( $post_id, 'video' );
		$output = $params['video_infos']['id'];
	}

	if ( ! $ajax_call ) {
		return $output;
	}

	wp_send_json( $output );

	wp_die();
}
add_action( 'wp_ajax_lvjm_import_video', 'lvjm_import_video' );

if ( ! function_exists( 'lvjm_get_model_placeholder_image_url' ) ) {
        /**
         * Retrieve the default placeholder image URL for auto-created model profiles.
         *
         * @return string
         */
        function lvjm_get_model_placeholder_image_url() {
                $default = trailingslashit( LVJM_URL ) . 'admin/assets/img/loading-thumb.gif';

                return apply_filters( 'lvjm_model_placeholder_image_url', $default );
        }
}

if ( ! function_exists( 'lvjm_get_default_model_bio' ) ) {
        /**
         * Build the default bio for auto-created model profiles.
         *
         * @param string $name Model name.
         * @return string
         */
        function lvjm_get_default_model_bio( $name ) {
                $clean_name = wp_strip_all_tags( $name );
                $bio        = sprintf( esc_html__( 'This profile was automatically created for %s from LiveJasmin imports. Update it with custom information and media.', 'lvjm_lang' ), $clean_name );

                return apply_filters( 'lvjm_default_model_bio', $bio, $clean_name );
        }
}

if ( ! function_exists( 'lvjm_attach_video_to_model_profile' ) ) {
        /**
         * Attach an imported video to a model profile.
         *
         * @param int $model_post_id Model post ID.
         * @param int $video_post_id Video post ID.
         * @return void
         */
        function lvjm_attach_video_to_model_profile( $model_post_id, $video_post_id ) {
                $model_post_id = (int) $model_post_id;
                $video_post_id = (int) $video_post_id;

                if ( $model_post_id <= 0 || $video_post_id <= 0 ) {
                        return;
                }

                $existing = get_post_meta( $model_post_id, 'lvjm_related_videos', true );

                if ( ! is_array( $existing ) ) {
                        $existing = array();
                }

                if ( in_array( $video_post_id, $existing, true ) ) {
                        return;
                }

                $existing[] = $video_post_id;
                update_post_meta( $model_post_id, 'lvjm_related_videos', $existing );
        }
}

if ( ! function_exists( 'lvjm_find_or_create_model_post' ) ) {
        /**
         * Locate an existing model profile or create a new one on demand.
         *
         * @param string $name Model name.
         * @return int Model post ID or 0 on failure.
         */
        function lvjm_find_or_create_model_post( $name ) {
                if ( ! post_type_exists( 'model' ) ) {
                        return 0;
                }

                $existing = get_page_by_title( $name, OBJECT, 'model' );
                if ( $existing instanceof WP_Post ) {
                        return (int) $existing->ID;
                }

                $query = new WP_Query(
                        array(
                                'post_type'      => 'model',
                                'post_status'    => 'any',
                                'name'           => sanitize_title( $name ),
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                        )
                );

                if ( $query->have_posts() ) {
                        $found = (int) $query->posts[0];
                        wp_reset_postdata();
                        return $found;
                }

                wp_reset_postdata();

                $author_id = get_current_user_id();
                if ( ! $author_id ) {
                        $author_id = 1;
                }

                $post_id = wp_insert_post(
                        array(
                                'post_title'   => $name,
                                'post_name'    => sanitize_title( $name ),
                                'post_content' => lvjm_get_default_model_bio( $name ),
                                'post_status'  => 'publish',
                                'post_type'    => 'model',
                                'post_author'  => $author_id,
                        ),
                        true
                );

                if ( is_wp_error( $post_id ) ) {
                        return 0;
                }

                if ( ! get_post_meta( $post_id, 'lvjm_model_placeholder_image', true ) ) {
                        update_post_meta( $post_id, 'lvjm_model_placeholder_image', lvjm_get_model_placeholder_image_url() );
                }

                return (int) $post_id;
        }
}

if ( ! function_exists( 'lvjm_ensure_model_profiles_from_terms' ) ) {
        /**
         * Ensure CPT model profiles exist for the provided taxonomy terms.
         *
         * @param array  $term_ids        Term IDs assigned to the video.
         * @param string $taxonomy        Taxonomy slug associated with the model terms.
         * @param int    $video_post_id   Imported video post ID.
         * @return void
         */
        function lvjm_ensure_model_profiles_from_terms( $term_ids, $taxonomy, $video_post_id ) {
                if ( empty( $term_ids ) || ! post_type_exists( 'model' ) ) {
                        return;
                }

                $taxonomy_exists = $taxonomy && taxonomy_exists( $taxonomy );

                foreach ( (array) $term_ids as $term_id ) {
                        $term = null;

                        if ( $taxonomy_exists ) {
                                $term = get_term( (int) $term_id, $taxonomy );

                                if ( ! $term || is_wp_error( $term ) ) {
                                        continue;
                                }

                                $name = $term->name;
                        } else {
                                $term = null;
                                $name = ''; // Will fallback to taxonomy assignment.
                        }

                        if ( '' === $name ) {
                                continue;
                        }

                        $model_post_id = lvjm_find_or_create_model_post( $name );

                        if ( ! $model_post_id ) {
                                continue;
                        }

                        if ( $taxonomy_exists ) {
                                wp_set_post_terms( $model_post_id, array( (int) $term_id ), $taxonomy, true );
                        }

                        lvjm_attach_video_to_model_profile( $model_post_id, $video_post_id );
                }
        }
}
