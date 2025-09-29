<?php
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

if ( ! function_exists( 'lvjm_debug_log' ) ) {
    /**
     * Write debug information into WordPress' debug.log when enabled.
     *
     * @param string $message The message to persist.
     */
    function lvjm_debug_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $log_file = trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
            error_log( $message, 3, $log_file );
        }
    }
}

if ( ! function_exists( 'lvjm_parse_performer_input' ) ) {
    /**
     * Parse a raw performer input string into sanitized performer names.
     *
     * @param string $raw_input Raw performer input from the UI.
     * @return array List of sanitized performer names.
     */
    function lvjm_parse_performer_input( $raw_input ) {
        $raw_input = (string) $raw_input;

        if ( '' === $raw_input ) {
            return array();
        }

        $raw_input  = str_replace( array( "\r\n", "\r" ), "\n", $raw_input );
        $chunks     = preg_split( '/[\n,;]+/', $raw_input );
        $performers = array();

        foreach ( (array) $chunks as $chunk ) {
            $chunk = trim( $chunk );
            if ( '' === $chunk ) {
                continue;
            }
            $performers[] = sanitize_text_field( $chunk );
        }

        return array_values( array_unique( $performers ) );
    }
}

if ( ! function_exists( 'lvjm_get_mainstream_categories_for_search' ) ) {
    /**
     * Retrieve all mainstream (straight) categories in alphabetical order.
     *
     * @return array
     */
    function lvjm_get_mainstream_categories_for_search() {
        $ordered_categories = LVJM()->get_ordered_categories();
        $mainstream         = array();

        foreach ( (array) $ordered_categories as $category ) {
            if ( ! isset( $category['id'] ) ) {
                continue;
            }

            if ( 'optgroup' === $category['id'] ) {
                if ( isset( $category['name'] ) && 0 === strcasecmp( $category['name'], 'Straight' ) && ! empty( $category['sub_cats'] ) ) {
                    foreach ( (array) $category['sub_cats'] as $sub_category ) {
                        if ( empty( $sub_category['id'] ) ) {
                            continue;
                        }
                        $key                 = strtolower( $sub_category['id'] );
                        $mainstream[ $key ] = array(
                            'id'   => $sub_category['id'],
                            'name' => $sub_category['name'],
                        );
                    }
                }
                continue;
            }

            $candidate_id = strtolower( (string) $category['id'] );
            if ( false !== strpos( $candidate_id, 'gay' ) || false !== strpos( $candidate_id, 'shemale' ) || false !== strpos( $candidate_id, 'trans' ) ) {
                continue;
            }

            $mainstream[ $candidate_id ] = array(
                'id'   => $category['id'],
                'name' => isset( $category['name'] ) ? $category['name'] : $category['id'],
            );
        }

        uasort(
            $mainstream,
            function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );

        return array_values( $mainstream );
    }
}

if ( ! function_exists( 'lvjm_extract_video_id' ) ) {
    /**
     * Extract a video ID from either an array or an object representation.
     *
     * @param array|object $video The video payload.
     * @return string|null The video ID if available.
     */
    function lvjm_extract_video_id( $video ) {
        if ( is_array( $video ) && isset( $video['id'] ) ) {
            return $video['id'];
        }

        if ( is_object( $video ) && isset( $video->id ) ) {
            return $video->id;
        }

        return null;
    }
}

if ( ! function_exists( 'lvjm_normalize_performer_search_term' ) ) {
    /**
     * Normalize a performer name for comparisons.
     *
     * @param string $name Performer name to normalize.
     * @return string
     */
    function lvjm_normalize_performer_search_term( $name ) {
        return strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $name ) );
    }
}

if ( ! function_exists( 'lvjm_search_performer_videos_in_category' ) ) {
    /**
     * Search promo videos for a performer within a specific category.
     *
     * @param string $category_id   Category identifier used in the API request.
     * @param string $category_name Human readable category name for logging.
     * @param array  $performers    List of performer names to match.
     * @param array  $params        Additional search parameters.
     *
     * @return array{videos: array, matches: array, errors: array}
     */
    function lvjm_search_performer_videos_in_category( $category_id, $category_name, $performers, $params = array() ) {
        $performers = array_values( array_filter( (array) $performers, 'strlen' ) );

        $matches = array();
        foreach ( $performers as $performer_name ) {
            $matches[ $performer_name ] = 0;
        }

        if ( empty( $performers ) ) {
            return array(
                'videos'  => array(),
                'matches' => $matches,
                'errors'  => array(),
            );
        }

        $psid       = get_option( 'wps_lj_psid', '' );
        $access_key = get_option( 'wps_lj_accesskey', '' );

        if ( '' === $psid || '' === $access_key ) {
            return array(
                'videos'  => array(),
                'matches' => $matches,
                'errors'  => array(
                    array(
                        'code'     => 'missing_credentials',
                        'message'  => esc_html__( 'LiveJasmin credentials are missing.', 'lvjm_lang' ),
                        'solution' => esc_html__( 'Please configure your AWEmpire PSID and Access Key.', 'lvjm_lang' ),
                    ),
                ),
            );
        }

        $normalized_lookup = array();
        foreach ( $performers as $performer_name ) {
            $normalized = lvjm_normalize_performer_search_term( $performer_name );
            if ( '' === $normalized ) {
                continue;
            }
            $normalized_lookup[ $normalized ] = $performer_name;
        }

        if ( empty( $normalized_lookup ) ) {
            return array(
                'videos'  => array(),
                'matches' => $matches,
                'errors'  => array(),
            );
        }

        $endpoint   = trailingslashit( 'https://pt.ptawe.com/api' ) . 'video-promotion/v1/list';
        $page_index = 1;
        $per_page   = 12;
        $max_results = isset( $params['limit'] ) ? max( 0, (int) $params['limit'] ) : 0;

        $videos    = array();
        $errors    = array();
        $seen_ids  = array();
        $continue  = true;

        while ( $continue ) {
            $query_args = array(
                'psid'              => $psid,
                'accessKey'         => $access_key,
                'sexualOrientation' => 'straight',
                'limit'             => $per_page,
                'pageIndex'         => $page_index,
            );

            if ( '' !== $category_id ) {
                $query_args['tags'] = $category_id;
            }

            $request_url = add_query_arg( $query_args, $endpoint );
            $response    = wp_remote_get( $request_url, array( 'timeout' => 30 ) );

            if ( is_wp_error( $response ) ) {
                $errors[] = array(
                    'code'     => 'http_request_failed',
                    'message'  => $response->get_error_message(),
                    'solution' => esc_html__( 'Try the search again later.', 'lvjm_lang' ),
                );
                break;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status_code ) {
                $errors[] = array(
                    'code'     => 'http_request_failed',
                    'message'  => sprintf( esc_html__( 'LiveJasmin API returned HTTP %d.', 'lvjm_lang' ), (int) $status_code ),
                    'solution' => esc_html__( 'Try the search again later.', 'lvjm_lang' ),
                );
                break;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) ) {
                break;
            }

            $payload = $body;
            if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
                $payload = $body['data'];
            }

            $videos_chunk = array();
            if ( isset( $payload['videos'] ) && is_array( $payload['videos'] ) ) {
                $videos_chunk = $payload['videos'];
            } elseif ( isset( $payload['data']['videos'] ) && is_array( $payload['data']['videos'] ) ) {
                $videos_chunk = $payload['data']['videos'];
            } elseif ( isset( $body['videos'] ) && is_array( $body['videos'] ) ) {
                $videos_chunk = $body['videos'];
            }

            if ( empty( $videos_chunk ) ) {
                break;
            }

            $stop_iteration = false;

            foreach ( $videos_chunk as $video ) {
                if ( ! is_array( $video ) ) {
                    continue;
                }

                $video_id = isset( $video['id'] ) ? (string) $video['id'] : '';

                $candidates = array();
                if ( isset( $video['performers'] ) && is_array( $video['performers'] ) ) {
                    $candidates = $video['performers'];
                } elseif ( isset( $video['models'] ) && is_array( $video['models'] ) ) {
                    $candidates = $video['models'];
                } elseif ( isset( $video['performerNames'] ) && is_array( $video['performerNames'] ) ) {
                    $candidates = $video['performerNames'];
                } elseif ( isset( $video['actors'] ) ) {
                    $actors = is_array( $video['actors'] ) ? $video['actors'] : preg_split( '/[,;]+/', (string) $video['actors'] );
                    $candidates = array_map( 'trim', (array) $actors );
                }

                $matched_names = array();

                foreach ( (array) $candidates as $candidate ) {
                    $normalized_candidate = lvjm_normalize_performer_search_term( $candidate );
                    if ( '' === $normalized_candidate ) {
                        continue;
                    }

                    if ( isset( $normalized_lookup[ $normalized_candidate ] ) ) {
                        $matched_names[ $normalized_lookup[ $normalized_candidate ] ] = true;
                    }
                }

                if ( empty( $matched_names ) ) {
                    continue;
                }

                if ( '' !== $video_id ) {
                    if ( isset( $seen_ids[ $video_id ] ) ) {
                        continue;
                    }

                    $seen_ids[ $video_id ] = true;
                }

                $matched_labels = array_keys( $matched_names );

                foreach ( $matched_labels as $matched_name ) {
                    if ( ! isset( $matches[ $matched_name ] ) ) {
                        $matches[ $matched_name ] = 0;
                    }
                    ++$matches[ $matched_name ];
                }

                if ( empty( $video['actors'] ) && ! empty( $matched_labels ) ) {
                    $video['actors'] = implode( ', ', $matched_labels );
                }

                $videos[] = $video;

                if ( $max_results > 0 && count( $videos ) >= $max_results ) {
                    $stop_iteration = true;
                    break;
                }
            }

            if ( $stop_iteration ) {
                break;
            }

            $page_count = 0;
            if ( isset( $payload['pageCount'] ) ) {
                $page_count = (int) $payload['pageCount'];
            } elseif ( isset( $payload['pagination']['totalPages'] ) ) {
                $page_count = (int) $payload['pagination']['totalPages'];
            } elseif ( isset( $body['pageCount'] ) ) {
                $page_count = (int) $body['pageCount'];
            } elseif ( isset( $body['data']['pageCount'] ) ) {
                $page_count = (int) $body['data']['pageCount'];
            }

            ++$page_index;

            if ( $page_count > 0 && $page_index > $page_count ) {
                break;
            }
        }

        foreach ( $matches as $name => $count ) {
            $matches[ $name ] = (int) $count;
        }

        return array(
            'videos'  => array_values( $videos ),
            'matches' => $matches,
            'errors'  => $errors,
        );
    }
}

if ( ! function_exists( 'lvjm_filter_videos_by_performers' ) ) {
    /**
     * Filter a set of videos by a list of performer names.
     *
     * @param array $videos     Videos to filter.
     * @param array $performers Performer names to match.
     * @return array{videos: array, matches: array}
     */
    function lvjm_filter_videos_by_performers( $videos, $performers ) {
        $results = array(
            'videos'  => array(),
            'matches' => array(),
        );

        $performers = array_values(
            array_filter(
                (array) $performers,
                function ( $performer ) {
                    return '' !== $performer;
                }
            )
        );

        if ( empty( $performers ) ) {
            $results['videos'] = array_values( (array) $videos );
            return $results;
        }

        if ( ! function_exists( 'lvjm_get_embed_and_actors' ) ) {
            $actions_file = dirname( __FILE__ ) . '/ajax-get-embed-and-actors.php';
            if ( file_exists( $actions_file ) ) {
                require_once $actions_file;
            }
        }

        foreach ( $performers as $performer ) {
            $results['matches'][ $performer ] = array(
                'count'     => 0,
                'video_ids' => array(),
            );
        }

        foreach ( (array) $videos as $video ) {
            $video_id = lvjm_extract_video_id( $video );
            $actors   = '';

            if ( is_array( $video ) && isset( $video['actors'] ) ) {
                $actors = (string) $video['actors'];
            } elseif ( is_object( $video ) && isset( $video->actors ) ) {
                $actors = (string) $video->actors;
            }

            $matched_performers = array();

            foreach ( $performers as $performer ) {
                if ( '' !== $actors && false !== stripos( $actors, $performer ) ) {
                    $matched_performers[] = $performer;
                }
            }

            if ( empty( $matched_performers ) && function_exists( 'lvjm_get_embed_and_actors' ) && $video_id ) {
                try {
                    $more = lvjm_get_embed_and_actors( array( 'video_id' => $video_id ) );
                    if ( ! empty( $more['performer_name'] ) ) {
                        foreach ( $performers as $performer ) {
                            if ( false !== stripos( $more['performer_name'], $performer ) ) {
                                $matched_performers[] = $performer;
                                if ( is_array( $video ) ) {
                                    $video['actors'] = $more['performer_name'];
                                } elseif ( is_object( $video ) ) {
                                    $video->actors = $more['performer_name'];
                                }
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Keep fallback silent if the API call fails.
                }
            }

            if ( empty( $matched_performers ) ) {
                continue;
            }

            $results['videos'][] = $video;

            foreach ( $matched_performers as $performer ) {
                ++$results['matches'][ $performer ]['count'];
                if ( $video_id ) {
                    $results['matches'][ $performer ]['video_ids'][ $video_id ] = true;
                }
            }
        }

        $results['videos'] = array_values( $results['videos'] );

        return $results;
    }
}

/**
 * Search for videos (Ajax or PHP call).
 *
 * The search can iterate through all mainstream categories when requested,
 * ensuring performer filters are applied in every query.
 *
 * @param array $params Optional search parameters when called directly.
 */
function lvjm_search_videos( $params = '' ) {
    $ajax_call = '' === $params;

    if ( $ajax_call ) {
        check_ajax_referer( 'ajax-nonce', 'nonce' );
        $params = wp_unslash( $_POST );
    } else {
        $params = (array) $params;
    }

    $errors        = array();
    $videos        = array();
    $searched_data = array();

    $cat_s_value = isset( $params['cat_s'] ) ? sanitize_text_field( (string) $params['cat_s'] ) : '';

    if ( in_array( $cat_s_value, array( 'all_categories', 'all_straight' ), true ) ) {
        $params['multi_category_search'] = '1';
        $cat_s_value                     = 'all_categories';
    }

    $is_multi_category = isset( $params['multi_category_search'] ) && '1' === (string) $params['multi_category_search'];

    $performer_raw  = isset( $params['performer'] ) ? $params['performer'] : '';
    $performer_list = lvjm_parse_performer_input( $performer_raw );

    $primary_performer = '';
    if ( ! empty( $performer_list ) ) {
        $primary_performer = $performer_list[0];
    } elseif ( '' !== $performer_raw ) {
        $primary_performer = sanitize_text_field( (string) $performer_raw );
        $performer_list[]  = $primary_performer;
    }

    $params['performer']      = $primary_performer;
    $params['performer_list'] = $performer_list;

    $progressive_mode = isset( $params['progressive'] ) && '1' === (string) $params['progressive'] && ! empty( $performer_list );

    if ( $progressive_mode ) {
        $category_id   = isset( $params['category_id'] ) ? sanitize_text_field( (string) $params['category_id'] ) : '';
        $category_name = isset( $params['category_name'] ) ? sanitize_text_field( (string) $params['category_name'] ) : $category_id;

        if ( '' === $category_id ) {
            $errors[] = array(
                'code'    => 'missing_category',
                'message' => esc_html__( 'No category provided for the performer search.', 'lvjm_lang' ),
            );

            $response = array(
                'errors'   => $errors,
                'videos'   => array(),
                'category' => array(
                    'id'   => $category_id,
                    'name' => $category_name,
                ),
                'performer' => $primary_performer,
                'matches'   => array(),
            );

            if ( $ajax_call ) {
                wp_send_json( $response );
                wp_die();
            }

            return $response;
        }

        $log_performer = isset( $params['log_performer'] ) ? sanitize_text_field( (string) $params['log_performer'] ) : $primary_performer;

        $category_params                       = $params;
        $category_params['cat_s']              = $category_id;
        $category_params['category']           = $category_id;
        $category_params['performer']          = $primary_performer;
        $category_params['multi_category_search'] = '1';

        $category_results = lvjm_search_performer_videos_in_category( $category_id, $category_name, $performer_list, $category_params );
        $category_videos  = isset( $category_results['videos'] ) ? (array) $category_results['videos'] : array();
        $matches          = isset( $category_results['matches'] ) ? (array) $category_results['matches'] : array();

        if ( ! empty( $category_results['errors'] ) ) {
            $errors = array_merge( $errors, (array) $category_results['errors'] );
        }

        foreach ( $performer_list as $performer_name ) {
            $log_name = '' !== $performer_name ? $performer_name : '(no performer)';
            $count    = isset( $matches[ $performer_name ] ) ? (int) $matches[ $performer_name ] : 0;
            lvjm_debug_log( sprintf( '[WPS-LiveJasmin] Category: %s | Performer: %s | Videos found: %d', $category_name, $log_name, $count ) );
        }

        $response = array(
            'errors'   => $errors,
            'videos'   => array_values( $category_videos ),
            'category' => array(
                'id'   => $category_id,
                'name' => $category_name,
            ),
            'performer'    => $log_performer,
            'matches'      => array_map( 'intval', $matches ),
        );

        if ( $ajax_call ) {
            wp_send_json( $response );
            wp_die();
        }

        return $response;
    }

    if ( $is_multi_category ) {
        if ( empty( $performer_list ) ) {
            $performer_list = array( '' );
        }

        $categories = lvjm_get_mainstream_categories_for_search();

        if ( empty( $categories ) ) {
            $errors[] = array(
                'code'     => 'no_categories',
                'message'  => esc_html__( 'No mainstream categories were found for the search.', 'lvjm_lang' ),
                'solution' => esc_html__( 'Refresh the partner categories and try again.', 'lvjm_lang' ),
            );
        } else {
            $performer_reports = array();
            $unique_videos     = array();
            $unique_ids        = array();

            foreach ( $performer_list as $performer_name ) {
                $category_reports     = array();
                $performer_videos     = array();
                $performer_unique_ids = array();

                foreach ( $categories as $category ) {
                    $category_params = $params;
                    $category_params['cat_s']                 = $category['id'];
                    $category_params['category']              = $category['id'];
                    $category_params['multi_category_search'] = '1';
                    $category_params['performer']             = $performer_name;

                    $search_videos   = new LVJM_Search_Videos( $category_params );
                    $category_videos = array();
                    $category_count  = 0;

                    if ( $search_videos->has_errors() ) {
                        $errors = array_merge( $errors, (array) $search_videos->get_errors() );
                    } else {
                        $category_videos = (array) $search_videos->get_videos();
                        $category_count  = count( $category_videos );

                        foreach ( $category_videos as $video ) {
                            $video_id = lvjm_extract_video_id( $video );

                            if ( $video_id && isset( $performer_unique_ids[ $video_id ] ) ) {
                                continue;
                            }

                            if ( $video_id ) {
                                $performer_unique_ids[ $video_id ] = true;
                            }

                            $performer_videos[] = $video;

                            if ( $video_id && isset( $unique_ids[ $video_id ] ) ) {
                                continue;
                            }

                            if ( $video_id ) {
                                $unique_ids[ $video_id ] = true;
                            }

                            $unique_videos[] = $video;
                        }
                    }

                    $feed_url = method_exists( $search_videos, 'get_last_root_feed_url' ) ? $search_videos->get_last_root_feed_url() : '';
                    $log_name = '' !== $performer_name ? $performer_name : '(no performer)';

                    if ( $feed_url ) {
                        error_log( sprintf( '[WPS-LiveJasmin] Category "%s" performer "%s" feed URL: %s', $category['name'], $log_name, $feed_url ) );
                    }

                    error_log( sprintf( '[WPS-LiveJasmin] Category "%s" performer "%s" results: %d', $category['name'], $log_name, $category_count ) );
                    lvjm_debug_log( sprintf( '[WPS-LiveJasmin] Category checked: %s | Performer: %s | Videos found: %d', $category['name'], $log_name, $category_count ) );

                    $category_reports[] = array(
                        'id'           => $category['id'],
                        'name'         => $category['name'],
                        'videos_found' => $category_count,
                    );
                }

                $performer_reports[] = array(
                    'name'             => $performer_name,
                    'display_name'     => '' !== $performer_name ? $performer_name : esc_html__( 'No performer filter', 'lvjm_lang' ),
                    'video_count'      => count( $performer_videos ),
                    'status'           => count( $performer_videos ) > 0,
                    'category_reports' => $category_reports,
                );
            }

            $videos = $unique_videos;

            $filter_results = lvjm_filter_videos_by_performers( $videos, $performer_list );

            if ( ! empty( $filter_results['videos'] ) || ! empty( $performer_list ) ) {
                $videos = $filter_results['videos'];

                if ( ! empty( $filter_results['matches'] ) ) {
                    foreach ( $performer_reports as &$report ) {
                        $name = $report['name'];
                        if ( '' === $name ) {
                            continue;
                        }

                        if ( isset( $filter_results['matches'][ $name ] ) ) {
                            $report['video_count'] = $filter_results['matches'][ $name ]['count'];
                            $report['status']      = $filter_results['matches'][ $name ]['count'] > 0;
                        } else {
                            $report['video_count'] = 0;
                            $report['status']      = false;
                        }
                    }
                    unset( $report );
                }
            }

            $searched_data = array(
                'videos_details' => array(),
                'counters'       => array(
                    'valid_videos' => count( $videos ),
                ),
                'videos'         => $videos,
                'multi_category' => array(
                    'performer_reports' => $performer_reports,
                ),
            );
        }
    } else {
        $search_videos = new LVJM_Search_Videos( $params );

        if ( $search_videos->has_errors() ) {
            $errors = array_merge( $errors, (array) $search_videos->get_errors() );
        } else {
            $videos        = $search_videos->get_videos();
            $searched_data = $search_videos->get_searched_data();

            if ( ! empty( $performer_list ) ) {
                $filter_results = lvjm_filter_videos_by_performers( $videos, $performer_list );
                $videos         = $filter_results['videos'];

                if ( ! isset( $searched_data['multi_category'] ) || ! is_array( $searched_data['multi_category'] ) ) {
                    $searched_data['multi_category'] = array();
                }

                $reports = array();
                foreach ( $performer_list as $performer_name ) {
                    $count = isset( $filter_results['matches'][ $performer_name ] ) ? $filter_results['matches'][ $performer_name ]['count'] : 0;
                    $reports[] = array(
                        'name'             => $performer_name,
                        'display_name'     => '' !== $performer_name ? $performer_name : esc_html__( 'No performer filter', 'lvjm_lang' ),
                        'video_count'      => $count,
                        'status'           => $count > 0,
                        'category_reports' => array(),
                    );
                }

                $searched_data['multi_category']['performer_reports'] = $reports;
            }
        }
    }

    if ( ! $ajax_call ) {
        return $videos;
    }

    wp_send_json(
        array(
            'videos'        => array_values( (array) $videos ),
            'errors'        => $errors,
            'searched_data' => $searched_data,
        )
    );

    wp_die();
}
add_action( 'wp_ajax_lvjm_search_videos', 'lvjm_search_videos' );
