<?php
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Search for videos in Ajax or PHP call, now supporting multi-category straight searches.
 */
function lvjm_search_videos( $params = '' ) {
    $ajax_call = '' === $params;

    if ( $ajax_call ) {
        check_ajax_referer( 'ajax-nonce', 'nonce' );
        $params = $_POST;
    }

    $errors    = array();
    $videos    = array();
    $seen_ids  = array();
    $last_data = array();

    if ( isset( $params['cat_s'] ) && 'all_straight' === $params['cat_s'] ) {
        $params['multi_category_search'] = '1';
    }

    $performer           = isset( $params['performer'] ) ? sanitize_text_field( (string) $params['performer'] ) : '';
    $params['performer'] = $performer;

    $loop_straight = ( isset( $params['multi_category_search'] ) && '1' === (string) $params['multi_category_search'] ) || '' !== $performer;

    $categories = array();
    if ( $loop_straight ) {
        $categories = lvjm_get_straight_search_categories();
    } elseif ( isset( $params['cat_s'] ) && '' !== $params['cat_s'] && 'all_straight' !== $params['cat_s'] ) {
        $categories = array( $params['cat_s'] );
    }

    if ( empty( $categories ) && isset( $params['cat_s'] ) && '' !== $params['cat_s'] && 'all_straight' !== $params['cat_s'] ) {
        $categories = array( $params['cat_s'] );
    }

    $processed_category = false;

    foreach ( (array) $categories as $category ) {
        $category = trim( (string) $category );
        if ( '' === $category || 'all_straight' === $category ) {
            continue;
        }

        $params['cat_s']   = $category;
        $params['category'] = $category;

        $search_videos = new LVJM_Search_Videos( $params );
        $processed_category = true;

        if ( $search_videos->has_errors() ) {
            $errors = array_merge( $errors, (array) $search_videos->get_errors() );
            if ( $loop_straight ) {
                lvjm_log_search_category_result( $category, $performer, 0 );
            }
            continue;
        }

        $new_videos = $search_videos->get_videos();
        $last_data  = $search_videos->get_searched_data();

        foreach ( (array) $new_videos as $video ) {
            $video_id = null;
            if ( is_array( $video ) ) {
                $video_id = isset( $video['id'] ) ? $video['id'] : null;
            } elseif ( is_object( $video ) && isset( $video->id ) ) {
                $video_id = $video->id;
            }

            if ( $video_id && ! isset( $seen_ids[ $video_id ] ) ) {
                $videos[]              = $video;
                $seen_ids[ $video_id ] = true;
            }
        }

        if ( $loop_straight ) {
            $match_count = method_exists( $search_videos, 'get_last_match_count' ) ? $search_videos->get_last_match_count() : count( (array) $new_videos );
            lvjm_log_search_category_result( $category, $performer, $match_count );
        }
    }

    if ( false === $processed_category ) {
        $search_videos = new LVJM_Search_Videos( $params );

        if ( $search_videos->has_errors() ) {
            $errors = array_merge( $errors, (array) $search_videos->get_errors() );
        } else {
            $videos    = $search_videos->get_videos();
            $last_data = $search_videos->get_searched_data();
        }
    }

    if ( ! $ajax_call ) {
        return $videos;
    }

    wp_send_json(
        array(
            'videos'        => $videos,
            'errors'        => $errors,
            'searched_data' => $last_data,
        )
    );

    wp_die();
}

/**
 * Retrieve the list of straight categories used for performer searches.
 *
 * @return array
 */
function lvjm_get_straight_search_categories() {
    $categories   = array();
    $ordered_cats = LVJM()->get_ordered_categories();

    foreach ( (array) $ordered_cats as $entry ) {
        if ( isset( $entry['id'], $entry['name'] ) && 'optgroup' === $entry['id'] && 'Straight' === $entry['name'] && isset( $entry['sub_cats'] ) ) {
            foreach ( (array) $entry['sub_cats'] as $sub_cat ) {
                if ( isset( $sub_cat['id'] ) ) {
                    $id = trim( (string) $sub_cat['id'] );
                    if ( '' !== $id ) {
                        $categories[] = $id;
                    }
                }
            }
            break;
        }
    }

    return array_values( array_unique( $categories ) );
}

/**
 * Log category performer matching results when debugging is enabled.
 *
 * @param string $category  Category identifier.
 * @param string $performer Performer name.
 * @param int    $count     Number of matched videos.
 *
 * @return void
 */
function lvjm_log_search_category_result( $category, $performer, $count ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $name = '' !== trim( $performer ) ? $performer : 'N/A';
        error_log( sprintf( '[WPS-LiveJasmin] Category: %s | Performer: %s | Videos matched: %d', $category, $name, intval( $count ) ) );
    }
}

add_action( 'wp_ajax_lvjm_search_videos', 'lvjm_search_videos' );
