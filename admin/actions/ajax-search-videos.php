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
        $category_summaries  = array();

        $log_performer_name = '';
        if ( '' !== $performer ) {
            $log_performer_name = '' !== $performer_label ? $performer_label : $performer_raw;
            if ( '' === $log_performer_name ) {
                $log_performer_name = $performer;
            }
        }

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

            $cache_payload       = wp_cache_get( $cache_key, $cache_group );
            if ( false !== $cache_payload && ! isset( $cache_payload['videos'] ) && is_array( $cache_payload ) ) {
                $cache_payload = array(
                    'videos'        => (array) $cache_payload,
                    'searched_data' => array(),
                );
            }
            $loop_searched_data = array();

            if ( false === $cache_payload ) {
                $search_videos = new LVJM_Search_Videos( $loop_params );
                if ( $search_videos->has_errors() ) {
                    $search_errors = (array) $search_videos->get_errors();
                    if ( isset( $search_errors['code'] ) || isset( $search_errors['message'] ) || isset( $search_errors['solution'] ) ) {
                        $search_errors = array( $search_errors );
                    }
                    $errors        = array_merge( $errors, $search_errors );
                    $cache_payload = array(
                        'videos'        => array(),
                        'searched_data' => array(),
                    );
                    wp_cache_set( $cache_key, $cache_payload, $cache_group, MINUTE_IN_SECONDS );
                } else {
                    $cache_payload = array(
                        'videos'        => (array) $search_videos->get_videos(),
                        'searched_data' => (array) $search_videos->get_searched_data(),
                    );
                    wp_cache_set( $cache_key, $cache_payload, $cache_group, HOUR_IN_SECONDS );
                }
            }

            $new_videos         = isset( $cache_payload['videos'] ) ? (array) $cache_payload['videos'] : array();
            $loop_searched_data = isset( $cache_payload['searched_data'] ) ? (array) $cache_payload['searched_data'] : array();
            if ( ! empty( $loop_searched_data ) ) {
                $searched_data = $loop_searched_data;
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

            $log_category_name = $category_id;
            if ( '' === $log_category_name ) {
                $log_category_name = $normalized_slug;
            }
            $log_category_name = ucwords( str_replace( array( '-', '_' ), ' ', (string) $log_category_name ) );

            $log_results_count = count( (array) $new_videos );
            if ( isset( $loop_searched_data['pagination']['totalCount'] ) ) {
                $log_results_count = (int) $loop_searched_data['pagination']['totalCount'];
            } elseif ( isset( $loop_searched_data['pagination']['count'] ) ) {
                $log_results_count = (int) $loop_searched_data['pagination']['count'];
            }

            if ( '' !== $performer ) {
                $category_summaries[] = array(
                    'id'      => $category_id,
                    'slug'    => $normalized_slug,
                    'name'    => $log_category_name,
                    'results' => $log_results_count,
                );

                if ( '' !== $log_performer_name && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( '[WPS-LiveJasmin] Performer %s — Category %s → %d results', $log_performer_name, $log_category_name, $log_results_count ) );
                }
            }
        }

        if ( ! empty( $category_summaries ) ) {
            $searched_data['category_summaries'] = $category_summaries;

            if ( '' !== $log_performer_name && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $summary_lines = array();
                foreach ( $category_summaries as $summary_entry ) {
                    $summary_lines[] = sprintf(
                        '%s → %d',
                        isset( $summary_entry['name'] ) ? $summary_entry['name'] : ( isset( $summary_entry['slug'] ) ? $summary_entry['slug'] : 'Category' ),
                        isset( $summary_entry['results'] ) ? (int) $summary_entry['results'] : 0
                    );
                }

                if ( ! empty( $summary_lines ) ) {
                    error_log( sprintf( '[WPS-LiveJasmin] Performer %s — Final Summary: %s', $log_performer_name, implode( ' | ', $summary_lines ) ) );
                }
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

            $log_results_count = count( (array) $videos );
            if ( isset( $searched_data['pagination']['count'] ) ) {
                $log_results_count = (int) $searched_data['pagination']['count'];
            }

            error_log( sprintf( '[WPS-LiveJasmin] Performer %s — Category %s → %d results', $log_performer_name, $log_category_name, $log_results_count ) );
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
