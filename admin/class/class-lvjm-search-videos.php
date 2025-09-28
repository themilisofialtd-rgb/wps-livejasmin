<?php
/**
 * Admin Class plugin file.
 *
 * @package LIVEJASMIN\Admin\Class
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

require_once LVJM_DIR . 'admin/class/class-lvjm-placeholder-parser.php';

/**
 * Search Videos Class.
 *
 * @since 1.0.0
 */
class LVJM_Search_Videos {


	/**
	 * The params.
	 *
	 * @var array $params
	 * @access private
	 */
	private $params;

	/**
	 * The errors.
	 *
	 * @var array $errors
	 * @access private
	 */
	private $errors;

	/**
	 * The feed_url.
	 *
	 * @var string $feed_url
	 * @access private
	 */
	private $feed_url;

	/**
	 * The feed_infos.
	 *
	 * @var object $feed_infos
	 * @access private
	 */
	private $feed_infos;

	/**
	 * The videos.
	 *
	 * @var array $videos
	 * @access private
	 */
	private $videos;

	/**
	 * The searched_data.
	 *
	 * @var array $searched_data
	 * @access private
	 */
	private $searched_data;

	/**
	 * The wp_version.
	 *
	 * @var string $wp_version
	 * @access private
	 */
	private $wp_version;

	/**
	 * The partner_existing_videos_ids.
	 *
	 * @var array $partner_existing_videos_ids
	 * @access private
	 */
	private $partner_existing_videos_ids;

	/**
	 * The partner_unwanted_videos_ids.
	 *
	 * @var array $partner_unwanted_videos_ids
	 * @access private
	 */
	private $partner_unwanted_videos_ids;

	/**
	 * Item constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params The params needed to make the search.
	 * @return void
	 */
        public function __construct( $params ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPS-LiveJasmin] class-lvjm-search-videos.php constructor called' );
                error_log( '[WPS-LiveJasmin] Search Param cat_s: ' . ( isset( $params['cat_s'] ) ? print_r( $params['cat_s'], true ) : '' ) );
        }
                global $wp_version;
                $this->wp_version = $wp_version;
               $this->params     = $params;
               $this->errors     = array();

                // connecting to API.
                $api_params = array(
			'license_key'  => WPSCORE()->get_license_key(),
			'signature'    => WPSCORE()->get_client_signature(),
			'server_addr'  => WPSCORE()->get_server_addr(),
			'server_name'  => WPSCORE()->get_server_name(),
			'core_version' => WPSCORE_VERSION,
			'time'         => ceil( time() / 1000 ),
			'partner_id'   => $this->params['partner']['id'],
		);

                $args = array(
                        'timeout'   => 50,
                        'sslverify' => true,
                );

                $base64_params = base64_encode( wp_json_encode( $api_params ) );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPS-LiveJasmin] API Params: ' . print_r( $api_params, true ) );
                error_log( '[WPS-LiveJasmin] API URL: ' . WPSCORE()->get_api_url( 'lvjm/get_feed', $base64_params ) );
        }

                $response = wp_remote_get( WPSCORE()->get_api_url( 'lvjm/get_feed', $base64_params ), $args );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPS-LiveJasmin] Raw API Response: ' . wp_remote_retrieve_body( $response ) );
        }

		if ( ! is_wp_error( $response ) && 'application/json; charset=UTF-8' === $response['headers']['content-type'] ) {

			$response_body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( null === $response_body ) {
				WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (null)', __FILE__, __LINE__ );
				return false;
			} elseif ( 200 !== $response_body->data->status ) {
				WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (status: <code>' . $response_body->data->status . '</code> message: <code>' . $response_body->message . '</code>)', __FILE__, __LINE__ );
				return false;
			} else {
				// success.
				if ( isset( $response_body->data->feed_infos ) ) {
					$this->feed_infos = $response_body->data->feed_infos;
					$this->feed_url   = $this->get_partner_feed_infos( $this->feed_infos->feed_url->data );

                                        $saved_partner_options = WPSCORE()->get_product_option( 'LVJM', $this->params['partner']['id'] . '_options' );
                                        $psid                 = '';
                                        $access_key           = '';

                                        if ( is_array( $saved_partner_options ) ) {
                                                $psid       = isset( $saved_partner_options['psid'] ) ? sanitize_text_field( (string) $saved_partner_options['psid'] ) : '';
                                                $access_key = isset( $saved_partner_options['accesskey'] ) ? sanitize_text_field( (string) $saved_partner_options['accesskey'] ) : '';
                                        }

                                        if ( empty( $psid ) ) {
                                                $psid = sanitize_text_field( (string) get_option( 'wps_lj_psid' ) );
                                        }

                                        if ( empty( $access_key ) ) {
                                                $access_key = sanitize_text_field( (string) get_option( 'wps_lj_accesskey' ) );
                                        }

                                       if ( empty( $psid ) || empty( $access_key ) ) {
                                               error_log( '[WPS-LiveJasmin ERROR] Missing PSID or AccessKey – cannot build feed URL.' );
                                               $this->errors = array(
                                                       'code'     => 'missing_credentials',
                                                       'message'  => __( 'Your AWEmpire PSID or Access Key is missing.', 'wps-livejasmin' ),
                                                       'solution' => __( 'Please add both credentials in the LiveJasmin settings.', 'wps-livejasmin' ),
                                               );

                                               return false;
                                       }

                                        $base_url  = 'https://pt.ptawe.com/api/video-promotion/v1/list';
                                        $client_ip = lvjm_get_client_ip_address();
                                        error_log( '[WPS-LiveJasmin] Using client IP for video search feed: ' . $client_ip );
                                        $params    = array(
                                                'site'              => 'wl3',
                                                'tags'              => isset( $this->params['cat_s'] ) ? $this->params['cat_s'] : '',
                                                'sexualOrientation' => 'straight',
                                                'language'          => 'en',
                                                'clientIp'          => $client_ip,
                                                'limit'             => 120,
                                                'psid'              => $psid,
                                                'accessKey'         => $access_key,
                                                'primaryColor'      => 'be0000',
                                                'labelColor'        => 'FFFFFF',
                                        );

                                        // Append performer filter if provided.
                                        if ( isset( $this->params['performer'] ) && ! empty( $this->params['performer'] ) ) {
                                                $params['forcedPerformers'] = trim( $this->params['performer'] );
                                        }

                                        $this->feed_url = $base_url . '?' . http_build_query( $params );

                                        error_log( '[WPS-LiveJasmin] Final hard-coded feed URL: ' . $this->feed_url );

					if ( ! $this->feed_url ) {
						WPSCORE()->write_log( 'error', 'Connection to Partner\'s API failed (feed url: <code>' . $this->feed_url . '</code> partner id: <code>:' . $this->params['partner']['id'] . '</code>)', __FILE__, __LINE__ );
						return false;
					}
					switch ( $this->params['partner']['data_type'] ) {
						case 'json':
							return $this->retrieve_videos_from_json_feed();
						default:
							break;
					}
				} else {
					WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (message: <code>' . $response_body->message . '</code>)', __FILE__, __LINE__ );
				}
			}
		} elseif ( isset( $response->errors['http_request_failed'] ) ) {
				WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (error: <code>' . wp_json_encode( $response->errors ) . '</code>)', __FILE__, __LINE__ );
				return false;
		}
		return false;
	}

	/**
	 * Get videos from the current object.
	 *
	 * @since 1.0.0
	 *
	 * @return array The videos.
	 */
	public function get_videos() {
		return $this->videos;
	}

	/**
	 * Get searched data.
	 *
	 * @since 1.0.0
	 *
	 * @return array The searched data.
	 */
	public function get_searched_data() {
		return $this->searched_data;
	}

	/**
	 * Get errors.
	 *
	 * @since 1.0.0
	 *
	 * @return array The errors caught.
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get errors.
	 *
	 * @since 1.0.0
	 *
	 * @return bool true if there are some errors, false if not.
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Gett feed url with orientation.
	 *
	 * @since 1.0.7
	 *
	 * @return string The feed url with orientation.
	 */
	private function get_feed_url_with_orientation() {
		$parsed_url = wp_parse_url( $this->feed_url );
		parse_str( $parsed_url['query'], $old_query );
		$new_query = array();
                foreach ( $old_query as $key => $value ) {
                        if ( 'tags' !== $key ) {
                                $new_query[ $key ] = $value;
                                continue;
                        }

                        $decoded_tags = rawurldecode( $value );

                        $orientation_terms = array(
                                'gay'     => 'gay',
                                'shemale' => 'shemale',
                        );

                        $orientation = 'straight';
                        $pattern     = '/\s+(' . implode( '|', array_map( 'preg_quote', array_keys( $orientation_terms ) ) ) . ')$/i';

                        if ( preg_match( $pattern, $decoded_tags, $matches ) ) {
                                $matched_term = strtolower( $matches[1] );
                                if ( isset( $orientation_terms[ $matched_term ] ) ) {
                                        $orientation = $orientation_terms[ $matched_term ];
                                }
                                $decoded_tags = preg_replace( $pattern, '', $decoded_tags );
                        } else {
                                foreach ( $orientation_terms as $term => $orientation_value ) {
                                        if ( false !== stripos( $decoded_tags, $term ) ) {
                                                $orientation = $orientation_value;
                                                break;
                                        }
                                }
                        }

                        $new_query['tags']              = trim( $decoded_tags );
                        $new_query['sexualOrientation'] = $orientation;
                }
                $parsed_url['query'] = http_build_query( $new_query, '', '&', PHP_QUERY_RFC3986 );
		$feed_url            = $this->unparse_url( $parsed_url );
		return $feed_url;
	}

	/**
	 * Unparse a parsed url.
	 *
	 * @param array $parsed_url The parsed url.
	 *
	 * @return string The unparsed url.
	 */
	private function unparse_url( $parsed_url ) {
		$scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	/**
	 * Find videos from a json feed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool true if there are no error, false if not.
	 */
	private function retrieve_videos_from_json_feed() {
                $existing_ids           = $this->get_partner_existing_ids();
                $existing_lookup        = array_flip( (array) $existing_ids['partner_existing_videos_ids'] );
                $removed_lookup         = array_flip( (array) $existing_ids['partner_unwanted_videos_ids'] );
                $array_valid_videos     = array();
                $counters               = array(
                        'valid_videos'    => 0,
                        'invalid_videos'  => 0,
                        'existing_videos' => 0,
                        'removed_videos'  => 0,
                );
		$videos_details         = array();
                $count_valid_feed_items = 0;
                $end                    = false;
                $total_pages            = null;
                $max_pages_cutoff       = 50;

		$root_feed_url = $this->get_feed_url_with_orientation();

                $args = array(
                        'timeout'   => 300,
                        'sslverify' => true,
                );

		$args['user-agent'] = 'WordPress/' . $this->wp_version . '; ' . home_url();

		if ( isset( $this->feed_infos->feed_auth ) ) {
			$args['headers'] = array( 'Authorization' => $this->get_partner_feed_infos( $this->feed_infos->feed_auth->data ) );
		}

		$current_page = intval( $this->get_partner_feed_infos( $this->feed_infos->feed_first_page->data ) );

		$paged = '';
		if ( isset( $this->feed_infos->feed_paged ) ) {
			$paged = $this->get_partner_feed_infos( $this->feed_infos->feed_paged->data );
		}

		$array_found_ids = array();

                while ( false === $end ) {

                        $log_performer = isset( $this->params['performer'] ) ? sanitize_text_field( (string) $this->params['performer'] ) : '';
                        $log_category  = isset( $this->params['cat_s'] ) ? sanitize_text_field( (string) $this->params['cat_s'] ) : '';
                        $category_for_log = $log_category;
                        if ( '' === $category_for_log && isset( $this->params['category'] ) ) {
                                $category_for_log = sanitize_text_field( (string) $this->params['category'] );
                        }

                        if ( null !== $total_pages && $current_page > $total_pages ) {
                                error_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Reached total pages (%d). Performer: %s, Category: %s',
                                                $total_pages,
                                                '' === $log_performer ? 'n/a' : $log_performer,
                                                '' === $category_for_log ? 'n/a' : $category_for_log
                                        )
                                );
                                $end = true;
                                break;
                        }

                        if ( $current_page > $max_pages_cutoff ) {
                                error_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Safety cutoff triggered at page %d. Performer: %s, Category: %s',
                                                $max_pages_cutoff,
                                                '' === $log_performer ? 'n/a' : $log_performer,
                                                '' === $category_for_log ? 'n/a' : $category_for_log
                                        )
                                );
                                $end = true;
                                break;
                        }

                        if ( '' !== $paged ) {
                                        $this->feed_url = $root_feed_url . $paged . $current_page;
                        }

                        $log_feed_url  = $this->feed_url ? esc_url_raw( (string) $this->feed_url ) : '';

                        error_log(
                                sprintf(
                                        '[WPS-LiveJasmin] Fetching page %d | Final feed URL: %s | performer: %s | category: %s',
                                        $current_page,
                                        '' === $log_feed_url ? 'n/a' : $log_feed_url,
                                        '' === $log_performer ? 'n/a' : $log_performer,
                                        '' === $log_category ? 'n/a' : $log_category
                                )
                        );
                        $response = wp_remote_get( $this->feed_url, $args );

                        if ( is_wp_error( $response ) ) {
                                WPSCORE()->write_log( 'error', 'Retrieving videos from JSON feed failed<code>ERROR: ' . wp_json_encode( $response->errors ) . '</code>', __FILE__, __LINE__ );
                                return false;
                        }

                        if ( 403 === wp_remote_retrieve_response_code( $response ) ) {
                                WPSCORE()->write_log( 'error', 'Your AWEmpire PSID or Access Key is wrong. Please configure LiveJasmin.', __FILE__, __LINE__ );
                                $this->errors = array(
                                        'code'     => 'AWEmpire credentials error',
                                        'message'  => 'Your AWEmpire PSID or Access Key is wrong.',
                                        'solution' => 'Please configure LiveJasmin.',
                                );
                                return false;
                        }

                        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

                        if ( isset( $response_body['data']['pagination']['totalPages'] ) ) {
                                $total_pages = (int) $response_body['data']['pagination']['totalPages'];
                        }

                        if ( '' !== $log_performer ) {
                                $results_count = 0;
                                if ( isset( $response_body['data']['videos'] ) && is_array( $response_body['data']['videos'] ) ) {
                                        $results_count = count( $response_body['data']['videos'] );
                                }

                                error_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Testing performer: %s | category: %s | results: %d',
                                                $log_performer,
                                                '' === $category_for_log ? 'n/a' : $category_for_log,
                                                $results_count
                                        )
                                );
                        }

                        if ( $response_body['status'] && 'ERROR' === $response_body['status'] ) {
                                $end              = true;
                                $page_end         = true;
                                $videos_details[] = array(
                                        'id'       => 'end',
                                        'response' => 'livejasmin API Error',
                                );
                        }

                        // feed url last page reached.
                        if ( 0 === count( (array) $response_body['data']['videos'] ) ) {
                                error_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] No videos found, ending loop. Performer: %s, Category: %s',
                                                '' === $log_performer ? 'n/a' : $log_performer,
                                                '' === $category_for_log ? 'n/a' : $category_for_log
                                        )
                                );
                                $end              = true;
                                $page_end         = true;
                                $videos_details[] = array(
                                        'id'       => 'end',
                                        'response' => 'No more videos',
                                );
                        } else {
                                // améliorer root selon paramètres / ou si null dans la config.
                                if ( isset( $this->feed_infos->feed_item_path->node ) ) {
                                        $root       = $this->feed_infos->feed_item_path->node;
                                        $array_feed = $response_body['data'][ $root ];
                                } else {
                                        $root       = 0;
                                        $array_feed = $response_body['data'];
                                }
                                $count_total_feed_items = count( $array_feed );
                                $current_item           = 0;
                                $page_end               = false;
                        }
                        while ( false === $page_end ) {
                                $feed_item = new LVJM_Json_Item( $array_feed[ $current_item ] );
                                $feed_item->init( $this->params, $this->feed_infos );
                                if ( $feed_item->is_valid() ) {
                                        $video_id   = $feed_item->get_id();
                                        $video_data = (array) $feed_item->get_data_for_json( $count_valid_feed_items );
                                        $status     = 'valid';

                                        if ( isset( $existing_lookup[ $video_id ] ) ) {
                                                $status                       = 'existing';
                                                $video_data['already_imported'] = true;
                                                $video_data['checked']          = false;
                                        } elseif ( isset( $removed_lookup[ $video_id ] ) ) {
                                                $status                       = 'removed';
                                                $video_data['already_imported'] = false;
                                                $video_data['checked']          = false;
                                        } else {
                                                $video_data['already_imported'] = false;
                                        }

                                        $video_data['import_status'] = $status;
                                        $array_valid_videos[]        = $video_data;

                                        switch ( $status ) {
                                                case 'existing':
                                                        $videos_details[] = array(
                                                                'id'       => $video_id,
                                                                'response' => 'Already imported',
                                                        );
                                                        ++$counters['existing_videos'];
                                                        break;
                                                case 'removed':
                                                        $videos_details[] = array(
                                                                'id'       => $video_id,
                                                                'response' => 'You removed it from search results',
                                                        );
                                                        ++$counters['removed_videos'];
                                                        break;
                                                default:
                                                        $videos_details[] = array(
                                                                'id'       => $video_id,
                                                                'response' => 'Success',
                                                        );
                                                        ++$counters['valid_videos'];
                                                        break;
                                        }

                                        ++$count_valid_feed_items;
                                } else {
                                        $videos_details[] = array(
                                                'id'       => $feed_item->get_id(),
                                                'response' => 'Invalid',
                                        );
                                        ++$counters['invalid_videos'];
                                }
                                if ( $current_item >= ( $count_total_feed_items - 1 ) ) {
                                        $page_end = true;
                                        ++$current_page;
                                        if ( '' === $paged ) {
                                                $end = true;
                                        }
                                }
                                ++$current_item;
                        }
                }
                unset( $array_feed );
		$this->searched_data = array(
			'videos_details' => $videos_details,
			'counters'       => $counters,
			'videos'         => $array_valid_videos,
		);
		$this->videos        = $array_valid_videos;
		return true;
	}

	/**
	 * Get partner feed info from a feed item given.
	 *
	 * @since 1.0.0
	 *
	 * @param string $partner_feed_item The partner item.
	 * @return string The feede info.
	 */
        private function get_partner_feed_infos( $partner_feed_item ) {
                $saved_partner_options = WPSCORE()->get_product_option( 'LVJM', $this->params['partner']['id'] . '_options' );
                $context               = array(
                        'partner_options' => is_array( $saved_partner_options ) ? $saved_partner_options : array(),
                        'params'          => $this->params,
                );

                return LVJM_Placeholder_Parser::parse( $partner_feed_item, $context );
        }

	/**
	 * Get partner feed info from a feed item given.
	 *
	 * @since 1.0.0
	 *
	 * @return array The feede info.
	 */
	private function get_partner_existing_ids() {
		// retrieve existing ids from imported videos.
		global $wpdb;

		$custom_post_type = xbox_get_field_value( 'lvjm-options', 'custom-video-post-type' );
		$custom_post_type = '' !== $custom_post_type ? $custom_post_type : 'post';

		$query_str = "
			SELECT wposts.ID, wpostmetaVideoId.meta_value videoId
			FROM $wpdb->posts wposts, $wpdb->postmeta wpostmetasponsor, $wpdb->postmeta wpostmetaVideoId
			WHERE wposts.ID = wpostmetasponsor.post_id
			AND ( wpostmetasponsor.meta_key = 'partner' AND wpostmetasponsor.meta_value = %s )
			AND (wposts.ID =  wpostmetaVideoId.post_id AND wpostmetaVideoId.meta_key = 'video_id')
			AND wposts.post_type = %s
		";

		$bdd_videos                  = $wpdb->get_results( $wpdb->prepare( $query_str, $this->params['partner']['id'], $custom_post_type ), OBJECT );
		$partner_existing_videos_ids = array();
		foreach ( (array) $bdd_videos as $bdd_video ) {
			$partner_existing_videos_ids[] = $bdd_video->videoId;
		}
		unset( $bdd_videos );
		// retrieve existing ids from unwanted videos.
		$partner_unwanted_videos_ids = array();
		$unwanted_videos_ids         = WPSCORE()->get_product_option( 'LVJM', 'removed_videos_ids' );
		if ( isset( $unwanted_videos_ids[ $this->params['partner']['id'] ] ) && is_array( $unwanted_videos_ids[ $this->params['partner']['id'] ] ) ) {
			$partner_unwanted_videos_ids = $unwanted_videos_ids[ $this->params['partner']['id'] ];
		}
		unset( $unwanted_videos_ids );
		return array(
			'partner_existing_videos_ids' => $partner_existing_videos_ids,
			'partner_unwanted_videos_ids' => $partner_unwanted_videos_ids,
			'partner_all_videos_ids'      => array_merge( $partner_existing_videos_ids, $partner_unwanted_videos_ids ),
		);
	}
}
