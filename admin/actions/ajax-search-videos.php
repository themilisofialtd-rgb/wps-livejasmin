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
    $searched_data  = array();
    $performer      = isset( $params['performer'] ) ? sanitize_text_field( (string) $params['performer'] ) : '';
    $result_message = '';
    $last_category_label = '';
    $last_category_slug  = '';
    $is_multi  = isset( $params['multi_category_search'] ) && '1' === (string) $params['multi_category_search'];
    $multi_category_meta = array(
        'active'        => false,
        'auto_continue' => false,
        'message'       => '',
    );

    if ( $is_multi ) {
        $straight_categories = lvjm_get_straight_category_slugs(); // slug => original id.
        $cache_group         = 'lvjm_search';
        $category_slugs      = array_keys( $straight_categories );
        $total_categories    = count( $category_slugs );
        $start_index         = isset( $params['multi_category_index'] ) ? max( 0, (int) $params['multi_category_index'] ) : 0;

        if ( $start_index > $total_categories ) {
            $start_index = $total_categories;
        }

        $multi_category_meta = array(
            'active'               => true,
            'current_category'     => '',
            'current_category_slug'=> '',
            'next_index'           => $start_index,
            'has_more'             => false,
            'count'                => 0,
            'completed'            => $start_index >= $total_categories,
            'auto_continue'        => false,
            'message'              => '',
        );

        for ( $index = $start_index; $index < $total_categories; $index++ ) {
            $normalized_slug = $category_slugs[ $index ];
            $category_id     = $straight_categories[ $normalized_slug ];

            $loop_params = $params;

            $raw_category_id   = $category_id;
            $search_category   = $raw_category_id;
            $is_keyword_search = false;

            $last_category_label = $raw_category_id;
            $last_category_slug  = $normalized_slug;

            if ( false !== strpos( $search_category, 'kw::' ) ) {
                $is_keyword_search = true;
                $search_category   = str_replace( 'kw::', '', $search_category );
            }

            $loop_params['cat_s']          = $search_category;
            $loop_params['category']       = $search_category;
            $loop_params['original_cat_s'] = str_replace( '&', '%%', $raw_category_id );
            $loop_params['cat_s_encoded']  = rawurlencode( $search_category );

            if ( $is_keyword_search ) {
                $loop_params['kw']   = 1;
                $loop_params['kw_s'] = $search_category;
            } else {
                $loop_params['kw']   = 0;
                $loop_params['kw_s'] = '';
            }

            if ( isset( $loop_params['feed_id'] ) && '' !== $loop_params['feed_id'] ) {
                $feed_id_parts = explode( '__', (string) $loop_params['feed_id'] );
                if ( count( $feed_id_parts ) >= 3 ) {
                    $feed_id_parts[ count( $feed_id_parts ) - 1 ] = $raw_category_id;
                    $loop_params['feed_id'] = implode( '__', $feed_id_parts );
                } else {
                    $loop_params['feed_id'] = $raw_category_id;
                }
            }

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
                    $errors     = array_merge( $errors, (array) $search_videos->get_errors() );
                    $new_videos = array();
                    wp_cache_set( $cache_key, $new_videos, $cache_group, MINUTE_IN_SECONDS );
                } else {
                    $new_videos   = (array) $search_videos->get_videos();
                    $searched_data = $search_videos->get_searched_data();
                    wp_cache_set( $cache_key, $new_videos, $cache_group, HOUR_IN_SECONDS );
                }
            }

            $log_count = 0;
            if ( is_array( $new_videos ) ) {
                $log_count = count( $new_videos );
            } elseif ( is_object( $new_videos ) ) {
                $log_count = count( (array) $new_videos );
            }
            error_log( sprintf( '[WPS-LiveJasmin] Category: %s — count: %d', $category_id, $log_count ) );

            $videos_for_category = array();
            foreach ( (array) $new_videos as $video_item ) {
                if ( is_array( $video_item ) ) {
                    $video_item['partner_cat'] = $normalized_slug;
                    $videos_for_category[]     = $video_item;
                } else {
                    $video_item->partner_cat = $normalized_slug;
                    $videos_for_category[]   = $video_item;
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[WPS-LiveJasmin] Brutal search → %s (%d videos)', $category_id, count( (array) $new_videos ) ) );
            }

            if ( ! empty( $videos_for_category ) ) {
                $videos = $videos_for_category;
                $multi_category_meta['current_category']      = $raw_category_id;
                $multi_category_meta['current_category_slug'] = $normalized_slug;
                $multi_category_meta['count']                 = count( $videos_for_category );
                $multi_category_meta['next_index']            = $index + 1;
                $multi_category_meta['has_more']              = $multi_category_meta['next_index'] < $total_categories;
                $multi_category_meta['completed']             = ! $multi_category_meta['has_more'];
                $result_message                               = sprintf( '%1$s — showing %2$d video(s).', $raw_category_id, $multi_category_meta['count'] );
                $multi_category_meta['message']               = $result_message;
                break;
            }

            $multi_category_meta['next_index'] = $index + 1;
            $result_message = sprintf( 'No results found in %s.', $raw_category_id );
            $multi_category_meta['message'] = $result_message;
        }

        if ( empty( $videos ) ) {
            $multi_category_meta['next_index'] = max( $multi_category_meta['next_index'], $total_categories );
            $multi_category_meta['has_more']   = false;
            $multi_category_meta['count']      = 0;
            $multi_category_meta['completed']  = true;
            $multi_category_meta['auto_continue'] = false;
            if ( '' !== $last_category_label ) {
                $result_message = sprintf( 'No results found in %s.', $last_category_label );
                $multi_category_meta['current_category']      = $last_category_label;
                $multi_category_meta['current_category_slug'] = $last_category_slug;
                $multi_category_meta['message']               = $result_message;
            }
        }
    } else {
        if ( isset( $params['cat_s'] ) && ! isset( $params['cat_s_encoded'] ) ) {
            $params['cat_s_encoded'] = rawurlencode( (string) $params['cat_s'] );
        }
        $search_videos = new LVJM_Search_Videos( $params );
        if ( $search_videos->has_errors() ) {
            $errors = (array) $search_videos->get_errors();
        } else {
            $videos = (array) $search_videos->get_videos();
            $searched_data = $search_videos->get_searched_data();
        }

        $single_category = '';
        if ( isset( $params['category'] ) && '' !== $params['category'] ) {
            $single_category = (string) $params['category'];
        } elseif ( isset( $params['cat_s'] ) && '' !== $params['cat_s'] ) {
            $single_category = (string) $params['cat_s'];
        }

        if ( '' !== $single_category ) {
            $count_videos   = is_array( $videos ) ? count( $videos ) : 0;
            if ( $count_videos > 0 ) {
                $result_message = sprintf( '%1$s — showing %2$d video(s).', $single_category, $count_videos );
            } else {
                $result_message = sprintf( 'No results found in %s.', $single_category );
            }
            error_log( sprintf( '[WPS-LiveJasmin] Category: %s — count: %d', $single_category, $count_videos ) );
        }
    }

    if ( '' !== $performer ) {
        $filtered = array();
        if ( ! function_exists( 'lvjm_get_embed_and_actors' ) ) {
            $actions_file = dirname( __FILE__ ) . '/ajax-get-embed-and-actors.php';
            if ( file_exists( $actions_file ) ) {
                require_once $actions_file;
            }
        }

        foreach ( (array) $videos as $video_item ) {
            $match  = false;
            $actors = '';
            if ( is_array( $video_item ) ) {
                $actors = isset( $video_item['actors'] ) ? (string) $video_item['actors'] : '';
            } elseif ( is_object( $video_item ) ) {
                $actors = isset( $video_item->actors ) ? (string) $video_item->actors : '';
            }

            if ( '' !== $actors && false !== stripos( $actors, $performer ) ) {
                $match = true;
            } elseif ( function_exists( 'lvjm_get_embed_and_actors' ) ) {
                $video_id = is_array( $video_item ) ? ( $video_item['id'] ?? '' ) : ( isset( $video_item->id ) ? $video_item->id : '' );
                if ( $video_id ) {
                    try {
                        $more = lvjm_get_embed_and_actors( array( 'video_id' => $video_id ) );
                        if ( ! empty( $more['performer_name'] ) && false !== stripos( $more['performer_name'], $performer ) ) {
                            $match = true;
                            if ( is_array( $video_item ) ) {
                                $video_item['actors'] = $more['performer_name'];
                            } else {
                                $video_item->actors = $more['performer_name'];
                            }
                        }
                    } catch ( \Throwable $exception ) {
                        unset( $exception );
                    }
                }
            }

            if ( $match ) {
                $filtered[] = $video_item;
            }
        }
        $videos = $filtered;
    }

    if ( $multi_category_meta['active'] ) {
        $multi_category_meta['count'] = is_array( $videos ) ? count( $videos ) : 0;
        if ( $multi_category_meta['count'] <= 0 && $multi_category_meta['has_more'] && ! $multi_category_meta['completed'] ) {
            $multi_category_meta['auto_continue'] = true;
        } else {
            $multi_category_meta['auto_continue'] = false;
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
            'multi_category'=> $multi_category_meta,
            'result_message'=> $result_message,
        )
    );

    wp_die();
}
add_action( 'wp_ajax_lvjm_search_videos', 'lvjm_search_videos' );
