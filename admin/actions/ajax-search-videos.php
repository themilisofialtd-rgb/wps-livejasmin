<?php
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Search for videos in Ajax or PHP call, supporting multi-category straight searches.
 *
 * @param array|string $params Optional parameters when called directly.
 * @return array|void
 */
function lvjm_search_videos( $params = '' ) {
    $ajax_call = '' === $params;

    if ( $ajax_call ) {
        check_ajax_referer( 'ajax-nonce', 'nonce' );
        $params = isset( $_POST ) ? lvjm_recursive_sanitize_text_field( wp_unslash( $_POST ) ) : array();
    } else {
        $params = lvjm_recursive_sanitize_text_field( $params );
    }

    if ( isset( $params['cat_s'] ) && 'all_straight' === $params['cat_s'] ) {
        $params['multi_category_search'] = '1';
    }

    $errors         = array();
    $videos         = array();
    $seen_ids       = array();
    $searched_data  = array();
    $performer_raw  = isset( $params['performer'] ) ? sanitize_text_field( (string) $params['performer'] ) : '';
    $performer      = lvjm_normalize_performer_query( $performer_raw );
    $params['performer'] = $performer;
    $performer_label = isset( $params['performer_label'] ) ? sanitize_text_field( (string) $params['performer_label'] ) : $performer_raw;
    $category_label  = isset( $params['category_label'] ) ? sanitize_text_field( (string) $params['category_label'] ) : '';
    $is_multi  = isset( $params['multi_category_search'] ) && '1' === (string) $params['multi_category_search'];

    if ( $is_multi ) {
        $straight_categories = lvjm_get_straight_category_slugs(); // slug => original id.
        $cache_group         = 'lvjm_search';

        foreach ( $straight_categories as $normalized_slug => $category_id ) {
            $loop_params          = $params;
            $loop_params['cat_s'] = $category_id;
            $loop_params['category'] = $category_id;

            $cache_key = 'straight_' . md5( serialize( array(
                'partner'   => isset( $loop_params['partner']['id'] ) ? $loop_params['partner']['id'] : '',
                'cat'       => $normalized_slug,
                'limit'     => isset( $loop_params['limit'] ) ? $loop_params['limit'] : '',
                'performer' => $performer,
            ) ) );

            $new_videos = wp_cache_get( $cache_key, $cache_group );
            if ( false === $new_videos ) {
                $search_videos = new LVJM_Search_Videos( $loop_params );
                if ( $search_videos->has_errors() ) {
                    $search_errors = (array) $search_videos->get_errors();
                    if ( isset( $search_errors['code'] ) || isset( $search_errors['message'] ) || isset( $search_errors['solution'] ) ) {
                        $search_errors = array( $search_errors );
                    }
                    $errors     = array_merge( $errors, $search_errors );
                    $new_videos = array();
                    wp_cache_set( $cache_key, $new_videos, $cache_group, MINUTE_IN_SECONDS );
                } else {
                    $new_videos = (array) $search_videos->get_videos();
                    $searched_data = $search_videos->get_searched_data();
                    wp_cache_set( $cache_key, $new_videos, $cache_group, HOUR_IN_SECONDS );
                }
            }

            foreach ( (array) $new_videos as $video_item ) {
                $video_id = null;
                if ( is_array( $video_item ) ) {
                    $video_id = isset( $video_item['id'] ) ? $video_item['id'] : null;
                } elseif ( is_object( $video_item ) ) {
                    $video_id = isset( $video_item->id ) ? $video_item->id : null;
                }

                if ( $video_id && ! isset( $seen_ids[ $video_id ] ) ) {
                    $seen_ids[ $video_id ] = true;
                    if ( is_array( $video_item ) ) {
                        $video_item['partner_cat'] = $normalized_slug;
                        $videos[]                  = $video_item;
                    } else {
                        $video_item->partner_cat = $normalized_slug;
                        $videos[]                = $video_item;
                    }
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $performer ) {
                $log_performer_name = '' !== $performer_label ? $performer_label : $performer_raw;
                if ( '' === $log_performer_name ) {
                    $log_performer_name = $performer;
                }

                $log_category_name = $category_id;
                if ( '' === $log_category_name ) {
                    $log_category_name = $normalized_slug;
                }
                $log_category_name = ucwords( str_replace( array( '-', '_' ), ' ', (string) $log_category_name ) );

                error_log( sprintf( '[WPS-LiveJasmin] Performer %s — Category %s → %d results', $log_performer_name, $log_category_name, count( (array) $new_videos ) ) );
            }
        }
    } else {
        $search_videos = new LVJM_Search_Videos( $params );
        if ( $search_videos->has_errors() ) {
            $search_errors = (array) $search_videos->get_errors();
            if ( isset( $search_errors['code'] ) || isset( $search_errors['message'] ) || isset( $search_errors['solution'] ) ) {
                $search_errors = array( $search_errors );
            }
            $errors = $search_errors;
        } else {
            $videos = (array) $search_videos->get_videos();
            $searched_data = $search_videos->get_searched_data();
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $performer ) {
            $log_performer_name = '' !== $performer_label ? $performer_label : $performer_raw;
            if ( '' === $log_performer_name ) {
                $log_performer_name = $performer;
            }

            $log_category_name = $category_label;
            if ( '' === $log_category_name ) {
                if ( isset( $params['cat_s'] ) && '' !== (string) $params['cat_s'] ) {
                    $log_category_name = (string) $params['cat_s'];
                } else {
                    $log_category_name = 'All Categories';
                }
            }

            $log_category_name = trim( $log_category_name );
            if ( '' === $log_category_name ) {
                $log_category_name = 'All Categories';
            }

            error_log( sprintf( '[WPS-LiveJasmin] Performer %s — Category %s → %d results', $log_performer_name, $log_category_name, count( (array) $videos ) ) );
        }
    }

    if ( '' !== $performer ) {
        if ( ! function_exists( 'lvjm_get_embed_and_actors' ) ) {
            $actions_file = dirname( __FILE__ ) . '/ajax-get-embed-and-actors.php';
            if ( file_exists( $actions_file ) ) {
                require_once $actions_file;
            }
        }

        foreach ( (array) $videos as $index => $video_item ) {
            $actors = '';
            if ( is_array( $video_item ) ) {
                $actors = isset( $video_item['actors'] ) ? (string) $video_item['actors'] : '';
            } elseif ( is_object( $video_item ) ) {
                $actors = isset( $video_item->actors ) ? (string) $video_item->actors : '';
            }

            if ( '' === $actors && function_exists( 'lvjm_get_embed_and_actors' ) ) {
                $video_id = is_array( $video_item ) ? ( $video_item['id'] ?? '' ) : ( isset( $video_item->id ) ? $video_item->id : '' );
                if ( $video_id ) {
                    try {
                        $more = lvjm_get_embed_and_actors( array( 'video_id' => $video_id ) );
                        if ( ! empty( $more['performer_name'] ) ) {
                            if ( is_array( $video_item ) ) {
                                $videos[ $index ]['actors'] = $more['performer_name'];
                            } else {
                                $videos[ $index ]->actors = $more['performer_name'];
                            }
                        }
                    } catch ( \Throwable $exception ) {
                        unset( $exception );
                    }
                }
            }
        }
    }

    if ( ! $ajax_call ) {
        return $videos;
    }

    wp_send_json(
        array(
            'videos'        => $videos,
            'errors'        => $errors,
            'searched_data' => $searched_data,
        )
    );

    wp_die();
}
add_action( 'wp_ajax_lvjm_search_videos', 'lvjm_search_videos' );
