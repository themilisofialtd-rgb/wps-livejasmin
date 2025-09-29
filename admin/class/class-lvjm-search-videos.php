<?php
/**
 * Admin Class plugin file.
 *
 * @package LIVEJASMIN\Admin\Class
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

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
         * The current category being queried.
         *
         * @var string
         */
        private $current_category = '';

        /**
         * Normalized performer name for filtering.
         *
         * @var string
         */
        private $normalized_performer = '';

        /**
         * Raw performer name provided.
         *
         * @var string
         */
        private $performer = '';

        /**
         * Parsed components of the partner feed url.
         *
         * @var array
         */
        private $base_feed_parts = array();

        /**
         * Cached base query args for partner feed.
         *
         * @var array
         */
        private $base_query_args = array();

        /**
         * Last match count for the processed category.
         *
         * @var int
         */
        private $last_match_count = 0;

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
        error_log('[WPS-LiveJasmin] class-lvjm-search-videos.php constructor called');
        error_log('[WPS-LiveJasmin] class-lvjm-search-videos.php constructor called');
                global $wp_version;
                $this->wp_version      = $wp_version;
                $this->params          = $params;
                $this->current_category = isset( $this->params['cat_s'] ) ? (string) $this->params['cat_s'] : '';
                $this->performer        = isset( $this->params['performer'] ) ? (string) $this->params['performer'] : '';
                $this->normalized_performer = $this->performer ? $this->normalize_name( $this->performer ) : '';
                $this->last_match_count = 0;
        error_log('[WPS-LiveJasmin] Search Param cat_s: ' . print_r($this->params['cat_s'], true));

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
			'sslverify' => false,
		);

		$base64_params = base64_encode( wp_json_encode( $api_params ) );
        error_log('[WPS-LiveJasmin] API Params: ' . print_r($api_params, true));
        error_log('[WPS-LiveJasmin] API URL: ' . WPSCORE()->get_api_url('lvjm/get_feed', $base64_params));

		$response = wp_remote_get( WPSCORE()->get_api_url( 'lvjm/get_feed', $base64_params ), $args );
		$response = wp_remote_get( WPSCORE()->get_api_url( 'lvjm/get_feed', $base64_params ), $args );
        error_log('[WPS-LiveJasmin] Raw API Response: ' . wp_remote_retrieve_body($response));

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

        // Replace template variables in feed_url
        $this->feed_url = str_replace(
            [
                '<%$this->params["cat_s"]%>',
                '<%get_partner_option("psid")%>',
                '<%get_partner_option("accesskey")%>'
            ],
            [
                isset($this->params['cat_s']) ? $this->params['cat_s'] : '',
                get_option('wps_lj_psid'),
                get_option('wps_lj_accesskey')
            ],
            $this->feed_url
        );

                                        $this->initialize_base_feed_parts();

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
         * Get the number of matched videos for the last processed category.
         *
         * @return int
         */
        public function get_last_match_count() {
                return $this->last_match_count;
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
         * Initialize base feed parts and query args from partner configuration.
         *
         * @return void
         */
        private function initialize_base_feed_parts() {
                $this->base_feed_parts = array();
                $this->base_query_args = array();

                if ( empty( $this->feed_url ) ) {
                        return;
                }

                $parsed_url = wp_parse_url( $this->feed_url );
                if ( empty( $parsed_url ) ) {
                        return;
                }

                $this->base_feed_parts = $parsed_url;

                $query_args = array();
                if ( isset( $parsed_url['query'] ) ) {
                        parse_str( $parsed_url['query'], $query_args );
                }

                $psid       = isset( $query_args['psid'] ) ? $query_args['psid'] : get_option( 'wps_lj_psid' );
                $access_key = isset( $query_args['accessKey'] ) ? $query_args['accessKey'] : get_option( 'wps_lj_accesskey' );

                $this->base_query_args = array(
                        'psid'      => $psid,
                        'accessKey' => $access_key,
                );
        }

        /**
         * Build the feed URL for a given category and page index using only the allowed parameters.
         *
         * @param string $category   The category/tag to request.
         * @param int    $page_index Page index to request.
         *
         * @return string
         */
        private function build_feed_url_for_category( $category, $page_index ) {
                if ( empty( $this->base_feed_parts ) ) {
                        return $this->feed_url;
                }

                $parts = $this->base_feed_parts;

                $limit = isset( $this->params['limit'] ) ? intval( $this->params['limit'] ) : 0;
                $limit = $limit > 0 ? $limit : 20;

                $query_args = array(
                        'psid'              => isset( $this->base_query_args['psid'] ) ? $this->base_query_args['psid'] : '',
                        'accessKey'         => isset( $this->base_query_args['accessKey'] ) ? $this->base_query_args['accessKey'] : '',
                        'sexualOrientation' => 'straight',
                        'tags'              => $category,
                        'limit'             => $limit,
                        'pageIndex'         => max( 0, intval( $page_index ) ),
                );

                $parts['query'] = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );

                return $this->unparse_url( $parts );
        }

        /**
         * Normalize a performer name for comparison.
         *
         * @param string $name The name to normalize.
         *
         * @return string
         */
        private function normalize_name( $name ) {
                return strtolower( preg_replace( '/[^a-z0-9]/', '', $name ) );
        }

        /**
         * Extract performer names from the raw API payload.
         *
         * @param array $video The raw video array.
         *
         * @return array
         */
        private function extract_performer_names( $video ) {
                $names = array();

                if ( isset( $video['performers'] ) ) {
                        $names = array_merge( $names, $this->parse_performer_field( $video['performers'] ) );
                }

                if ( isset( $video['models'] ) ) {
                        $names = array_merge( $names, $this->parse_performer_field( $video['models'] ) );
                }

                $names = array_map( 'trim', $names );
                $names = array_filter( $names );

                return array_values( array_unique( $names ) );
        }

        /**
         * Parse a performer field that can contain strings or arrays.
         *
         * @param mixed $field Field returned by the API.
         *
         * @return array
         */
        private function parse_performer_field( $field ) {
                $names = array();

                if ( is_array( $field ) ) {
                        foreach ( $field as $value ) {
                                if ( is_array( $value ) ) {
                                        if ( isset( $value['name'] ) && '' !== trim( $value['name'] ) ) {
                                                $names[] = $value['name'];
                                        } elseif ( isset( $value['nickname'] ) && '' !== trim( $value['nickname'] ) ) {
                                                $names[] = $value['nickname'];
                                        } elseif ( isset( $value['stageName'] ) && '' !== trim( $value['stageName'] ) ) {
                                                $names[] = $value['stageName'];
                                        }
                                } elseif ( is_string( $value ) && '' !== trim( $value ) ) {
                                        $names[] = $value;
                                }
                        }
                } elseif ( is_string( $field ) && '' !== trim( $field ) ) {
                        $names[] = $field;
                }

                return $names;
        }

        /**
         * Check if a raw video matches the requested performer.
         *
         * @param array $video         Raw video data.
         * @param array $matched_names Matching names found on the video.
         *
         * @return bool
         */
        private function video_matches_performer( $video, &$matched_names = array() ) {
                $matched_names = array();

                if ( '' === $this->normalized_performer ) {
                        return true;
                }

                $names = $this->extract_performer_names( $video );

                if ( empty( $names ) ) {
                        return false;
                }

                foreach ( $names as $name ) {
                        if ( $this->normalize_name( $name ) === $this->normalized_performer ) {
                                $matched_names[] = $name;
                        }
                }

                return ! empty( $matched_names );
        }

        /**
         * Gett feed url with orientation.
         *
         * @since 1.0.7
         *
	 * @return string The feed url with orientation.
	 */
        private function get_feed_url_with_orientation() {
                return $this->build_feed_url_for_category( $this->current_category, 0 );
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
                $this->last_match_count = 0;

                $args = array(
                        'timeout'   => 300,
                        'sslverify' => false,
                );

		$args['user-agent'] = 'WordPress/' . $this->wp_version . '; ' . home_url();

		if ( isset( $this->feed_infos->feed_auth ) ) {
			$args['headers'] = array( 'Authorization' => $this->get_partner_feed_infos( $this->feed_infos->feed_auth->data ) );
		}

                $current_page = intval( $this->get_partner_feed_infos( $this->feed_infos->feed_first_page->data ) );

                $array_found_ids = array();

                while ( false === $end ) {
                        $request_url   = $this->build_feed_url_for_category( $this->current_category, $current_page );
                        $this->feed_url = $request_url;

        error_log('[WPS-LiveJasmin] Final feed URL used: ' . $this->feed_url);
                        $response = wp_remote_get( $request_url, $args );

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

			if ( $response_body['status'] && 'ERROR' === $response_body['status'] ) {
				$end              = true;
				$page_end         = true;
				$videos_details[] = array(
					'id'       => 'end',
					'response' => 'livejasmin API Error',
				);
			}

			// feed url last page reached.
                        if ( 0 === count( (array) $response_body['data']['videos'] ) || $current_page > $response_body['data']['pagination']['totalPages'] ) {
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
                                $raw_video    = $array_feed[ $current_item ];
                                $matched_names = array();
                                if ( ! $this->video_matches_performer( $raw_video, $matched_names ) ) {
                                        if ( $current_item >= ( $count_total_feed_items - 1 ) ) {
                                                $page_end = true;
                                                ++$current_page;
                                        }
                                        ++$current_item;
                                        continue;
                                }

                                $feed_item = new LVJM_Json_Item( $raw_video );
                                $feed_item->init( $this->params, $this->feed_infos );
                                if ( $feed_item->is_valid() ) {
                                        if ( ! in_array( $feed_item->get_id(), (array) $existing_ids['partner_all_videos_ids'], true ) ) {
                                                $video_data = (array) $feed_item->get_data_for_json( $count_valid_feed_items );
                                                if ( ! empty( $matched_names ) ) {
                                                        $video_data['actors'] = implode( ', ', $matched_names );
                                                }
                                                $array_valid_videos[] = $video_data;
                                                $videos_details[]     = array(
                                                        'id'       => $feed_item->get_id(),
                                                        'response' => 'Success',
                                                );
                                                ++$counters['valid_videos'];
                                                ++$count_valid_feed_items;
                                                ++$this->last_match_count;
					} elseif ( in_array( $feed_item->get_id(), (array) $existing_ids['partner_existing_videos_ids'], true ) ) {
							$videos_details[] = array(
								'id'       => $feed_item->get_id(),
								'response' => 'Already imported',
							);
							++$counters['existing_videos'];
					} elseif ( in_array( $feed_item->get_id(), (array) $existing_ids['partner_unwanted_videos_ids'], true ) ) {
						$videos_details[] = array(
							'id'       => $feed_item->get_id(),
							'response' => 'You removed it from search results',
						);
						++$counters['removed_videos'];
					}
				} else {
					$videos_details[] = array(
						'id'       => $feed_item->get_id(),
						'response' => 'Invalid',
					);
					++$counters['invalid_videos'];
				}
				if ( ( $count_valid_feed_items >= $this->params['limit'] ) || $current_item >= ( $count_total_feed_items - 1 ) ) {
					$page_end = true;
					++$current_page;
					if ( $count_valid_feed_items >= $this->params['limit'] ) {
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
		$results = array();
		preg_match_all( '/<%(.+)%>/U', $partner_feed_item, $results );

		foreach ( (array) $results[1] as $result ) {
			if ( strpos( $result, 'get_partner_option' ) !== false ) {
				$saved_partner_options = WPSCORE()->get_product_option( 'LVJM', $this->params['partner']['id'] . '_options' );
				$option                = str_replace( array( 'get_partner_option("', '")' ), array( '', '' ), $result );
				$new_result            = '$saved_partner_options["' . $option . '"]';
				$partner_feed_item     = str_replace( '<%' . $result . '%>', eval( 'return ' . $new_result . ';' ), $partner_feed_item );
			} else {
				$partner_feed_item = str_replace( '<%' . $result . '%>', eval( 'return ' . $result . ';' ), $partner_feed_item );
			}
		}

		return $partner_feed_item;
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
