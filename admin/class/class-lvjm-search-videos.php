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
         * Track the performer filter key applied to the API request.
         *
         * @var string $active_performer_filter_key
         */
        private $active_performer_filter_key = '';

        /**
         * Track the performer filter value applied to the API request.
         *
         * @var string $active_performer_filter_value
         */
        private $active_performer_filter_value = '';

	/**
	 * The videos.
	 *
	 * @var array $videos
	 * @access private
	 */
        private $videos;

        /**
         * The last performer identifier discovered from the API payload.
         *
         * @var string $discovered_performer_id
         */
        private $discovered_performer_id = '';

	/**
	 * Cached list of local performers indexed by their normalized name.
	 *
	 * @var array|null $local_performers
	 * @access private
	 */
	private $local_performers;

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
		global $wp_version;
		$this->wp_version = $wp_version;
		$this->params     = $params;

		if ( ! isset( $this->params['limit'] ) || (int) $this->params['limit'] <= 0 ) {
			$this->params['limit'] = 60;
		}

		if ( (int) $this->params['limit'] > 60 ) {
			$this->params['limit'] = 60;
		}

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

		$response = wp_remote_get( WPSCORE()->get_api_url( 'lvjm/get_feed', $base64_params ), $args );

		if ( ! is_wp_error( $response ) && 'application/json; charset=UTF-8' === $response['headers']['content-type'] ) {

			$response_body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( null === $response_body ) {
				WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (null)', __FILE__, __LINE__ );
				$this->log_search_result( 0 );
				return false;
			} elseif ( 200 !== $response_body->data->status ) {
				WPSCORE()->write_log( 'error', 'Connection to API (get_feed) failed (status: <code>' . $response_body->data->status . '</code> message: <code>' . $response_body->message . '</code>)', __FILE__, __LINE__ );
				$this->log_search_result( 0 );
				return false;
			} else {
				// success.
				if ( isset( $response_body->data->feed_infos ) ) {
					$this->feed_infos = $response_body->data->feed_infos;
					$this->feed_url   = $this->prepare_feed_url( $this->feed_infos->feed_url->data );

					if ( ! $this->feed_url ) {
						WPSCORE()->write_log( 'error', 'Connection to Partner\'s API failed (feed url: <code>' . $this->feed_url . '</code> partner id: <code>:' . $this->params['partner']['id'] . '</code>)', __FILE__, __LINE__ );
						$this->log_search_result( 0 );
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
				$this->log_search_result( 0 );
				return false;
		}
		$this->log_search_result( 0 );
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
		return $this->filter_feed_url( $this->feed_url );
	}

	/**
	 * Prepare the partner feed URL by replacing placeholders and whitelisting query arguments.
	 *
	 * @param string $feed_url_template Template containing partner placeholders.
	 * @return string|bool
	 */
	private function prepare_feed_url( $feed_url_template ) {
		$feed_url = $this->get_partner_feed_infos( $feed_url_template );

		if ( ! $feed_url ) {
			return $feed_url;
		}

		$replacements = array(
			'<%$this->params["cat_s"]%>'         => isset( $this->params['cat_s'] ) ? (string) $this->params['cat_s'] : '',
			'<%get_partner_option("psid")%>'     => get_option( 'wps_lj_psid' ),
			'<%get_partner_option("accesskey")%>' => get_option( 'wps_lj_accesskey' ),
		);

		$feed_url = str_replace( array_keys( $replacements ), array_values( $replacements ), $feed_url );

		return $this->filter_feed_url( $feed_url );
	}

	/**
	 * Ensure the feed URL only contains the allowed query arguments.
	 *
	 * @param string $feed_url Feed URL to sanitize.
	 * @return string
	 */
	private function filter_feed_url( $feed_url ) {
		if ( empty( $feed_url ) ) {
			return $feed_url;
		}

                $parsed_url = wp_parse_url( $feed_url );

                if ( isset( $parsed_url['path'] ) ) {
                        $parsed_url['path'] = $this->normalize_feed_path( $parsed_url['path'] );
                }
		$query_args = array();

		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_args );
		}

                $query_args          = $this->prepare_query_args( $query_args );
                $parsed_url['query'] = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );

                return $this->unparse_url( $parsed_url );
        }

        /**
         * Normalize the LiveJasmin feed path to ensure client-side endpoints are used.
         *
         * @param string $path Raw path extracted from the feed URL.
         * @return string
         */
        private function normalize_feed_path( $path ) {
                $path = (string) $path;

                if ( '' === $path ) {
                        return $path;
                }

                $path = preg_replace( '#//+#', '/', $path );

                if ( false !== strpos( $path, '/api/video-promotion/v1/' ) ) {
                        $path = preg_replace( '#/api/video-promotion/v1/(client/)?list#', '/api/video-promotion/v1/client/list', $path, 1 );
                }

                return $path;
        }

        /**
         * Determine the most appropriate client IP to forward to the LiveJasmin API.
         *
         * @return string
         */
        private function determine_client_ip() {
                $server = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();
                $keys   = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );

                foreach ( $keys as $key ) {
                        if ( empty( $server[ $key ] ) ) {
                                continue;
                        }

                        $candidate = (string) $server[ $key ];

                        if ( 'HTTP_X_FORWARDED_FOR' === $key && false !== strpos( $candidate, ',' ) ) {
                                $parts     = explode( ',', $candidate );
                                $candidate = trim( reset( $parts ) );
                        }

                        $candidate = trim( $candidate );

                        if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                                return $candidate;
                        }
                }

                return '';
        }

        /**
         * Whitelist query arguments for LiveJasmin feed requests.
         *
         * @param array $query_args Original query arguments.
         * @return array
         */
        private function prepare_query_args( array $query_args ) {
                $psid        = isset( $query_args['psid'] ) ? (string) $query_args['psid'] : (string) get_option( 'wps_lj_psid' );
                $access_key  = isset( $query_args['accessKey'] ) ? (string) $query_args['accessKey'] : (string) get_option( 'wps_lj_accesskey' );
                $tags        = isset( $this->params['cat_s'] ) ? (string) $this->params['cat_s'] : '';
                $search_name = '';

                if ( isset( $this->params['search_name'] ) ) {
                        $search_name = (string) $this->params['search_name'];
                } elseif ( isset( $this->params['performer'] ) ) {
                        $search_name = (string) $this->params['performer'];
                }

                if ( '' !== $tags && function_exists( 'sanitize_text_field' ) ) {
                        $tags = sanitize_text_field( $tags );
                }
                if ( '' !== $search_name && function_exists( 'sanitize_text_field' ) ) {
                        $search_name = sanitize_text_field( trim( $search_name ) );
                }
                $limit      = isset( $query_args['limit'] ) ? (int) $query_args['limit'] : (int) $this->params['limit'];

                if ( $limit <= 0 || $limit > 60 ) {
                        $limit = 60;
                }

                $client_ip = '';

                if ( isset( $query_args['clientIp'] ) && '' !== trim( (string) $query_args['clientIp'] ) ) {
                        $client_ip = trim( (string) $query_args['clientIp'] );
                } else {
                        $client_ip = $this->determine_client_ip();
                }

                $allowed_args = array(
                        'psid'              => $psid,
                        'accessKey'         => $access_key,
                        'sexualOrientation' => 'straight',
                        'tags'              => $tags,
                        'limit'             => $limit,
                );

                if ( '' !== $client_ip ) {
                        $allowed_args['clientIp'] = $client_ip;
                }

                if ( '' !== $search_name ) {
                        $performer_args = $this->build_performer_query_parameters( $search_name );

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $performer_args ) ) {
                                $log_name = $search_name;
                                $log_keys = implode( ', ', array_keys( $performer_args ) );
                                $log_filter = $this->get_active_performer_filter_for_log();

                                if ( function_exists( 'sanitize_text_field' ) ) {
                                        $log_name = sanitize_text_field( $log_name );
                                        $log_keys = sanitize_text_field( $log_keys );
                                        $log_filter = sanitize_text_field( $log_filter );
                                }

                                lvjm_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Applying performer filter: %s | Parameters: %s | Active filter: %s',
                                                '' !== $log_name ? $log_name : '-',
                                                '' !== $log_keys ? $log_keys : '-',
                                                '' !== $log_filter ? $log_filter : '-'
                                        )
                                );
                        }

                        $allowed_args = array_merge( $allowed_args, $performer_args );
                }

		if ( isset( $query_args['pageIndex'] ) ) {
			$allowed_args['pageIndex'] = (int) $query_args['pageIndex'];
		}

                return $allowed_args;
        }

        /**
         * Build the performer-specific query arguments for the API request.
         *
         * @param string $search_name Raw performer name filter.
         * @return array
         */
        private function build_performer_query_parameters( $search_name ) {
                $search_name = trim( (string) $search_name );

                $this->active_performer_filter_key   = '';
                $this->active_performer_filter_value = '';

                if ( '' === $search_name ) {
                        return array();
                }

                $performer_id = '';

                if ( isset( $this->params['performer_id'] ) && '' !== trim( (string) $this->params['performer_id'] ) ) {
                        $performer_id = trim( (string) $this->params['performer_id'] );
                } elseif ( isset( $this->params['performerId'] ) && '' !== trim( (string) $this->params['performerId'] ) ) {
                        $performer_id = trim( (string) $this->params['performerId'] );
                }

                if ( '' !== $performer_id ) {
                        $performer_args = array( 'performerId' => $performer_id );
                        $this->active_performer_filter_key   = 'performerId';
                        $this->active_performer_filter_value = $performer_id;
                } else {
                        $performer_args = array( 'performerName' => $search_name );
                        $this->active_performer_filter_key   = 'performerName';
                        $this->active_performer_filter_value = $search_name;
                }

                /**
                 * Filter the performer query arguments sent to the LiveJasmin API.
                 *
                 * @param array $performer_args The raw performer query arguments.
                 * @param string $search_name  The performer name being searched.
                 * @param array $params        Original request parameters.
                 */
                $performer_args = apply_filters( 'lvjm_performer_query_arguments', $performer_args, $search_name, $this->params );

                // Ensure unsupported parameters are removed.
                if ( isset( $performer_args['forcedPerformers[]'] ) ) {
                        unset( $performer_args['forcedPerformers[]'] );
                }

                foreach ( $performer_args as $key => $value ) {
                        if ( '' === $value || null === $value ) {
                                unset( $performer_args[ $key ] );
                        }
                }

                if ( count( $performer_args ) > 1 ) {
                        if ( isset( $performer_args['performerId'] ) ) {
                                $performer_args = array( 'performerId' => $performer_args['performerId'] );
                        } elseif ( isset( $performer_args['performerName'] ) ) {
                                $performer_args = array( 'performerName' => $performer_args['performerName'] );
                        }
                }

                if ( empty( $performer_args ) ) {
                        $this->active_performer_filter_key   = '';
                        $this->active_performer_filter_value = '';
                } else {
                        $this->active_performer_filter_key   = key( $performer_args );
                        $this->active_performer_filter_value = reset( $performer_args );
                }

                return $performer_args;
        }

	/**
	 * Build the paged URL for the given page index.
	 *
	 * @param string $base_url   Base feed URL without pagination.
	 * @param int    $page_index The page to request.
	 * @return string
	 */
        private function build_page_url( $base_url, $page_index ) {
                $parsed_url = wp_parse_url( $base_url );
                $query_args = array();

                if ( isset( $parsed_url['query'] ) ) {
                        parse_str( $parsed_url['query'], $query_args );
                }

                $query_args['pageIndex'] = (int) $page_index;

                $query_args          = $this->prepare_query_args( $query_args );
                $parsed_url['query'] = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );

                if ( isset( $parsed_url['path'] ) ) {
                        $parsed_url['path'] = $this->normalize_feed_path( $parsed_url['path'] );
                }

                return $this->unparse_url( $parsed_url );
        }

        /**
         * Retrieve the active performer filter in a human-readable format for logging.
         *
         * @return string
         */
        private function get_active_performer_filter_for_log() {
                if ( '' === $this->active_performer_filter_key ) {
                        return 'none';
                }

                $value = $this->active_performer_filter_value;

                if ( is_array( $value ) ) {
                        $value = implode( ',', $value );
                }

                if ( function_exists( 'sanitize_text_field' ) ) {
                        $value = sanitize_text_field( (string) $value );
                }

                if ( '' === $value ) {
                        return $this->active_performer_filter_key;
                }

                return $this->active_performer_filter_key . '=' . $value;
        }

        /**
         * Mask sensitive query parameters before logging request URLs.
         *
         * @param string $url Raw request URL.
         * @return string
         */
        private function sanitize_url_for_log( $url ) {
                if ( '' === $url ) {
                        return $url;
                }

                $parsed = wp_parse_url( $url );

                if ( empty( $parsed['query'] ) ) {
                        return $url;
                }

                parse_str( $parsed['query'], $query_args );

                $sensitive_keys = array( 'psid', 'accessKey', 'signature', 'license_key', 'clientIp' );

                foreach ( $sensitive_keys as $key ) {
                        if ( isset( $query_args[ $key ] ) ) {
                                $query_args[ $key ] = '***';
                        }
                }

                $parsed['query'] = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );

                return $this->unparse_url( $parsed );
        }

        /**
         * Log the raw API response for debugging purposes.
         *
         * @param string $raw_body Raw JSON payload returned by the API.
         * @return void
         */
        private function log_raw_api_response( $raw_body ) {
                if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
                        return;
                }

                $sanitized_url = $this->sanitize_url_for_log( $this->feed_url );
                $filter_label  = $this->get_active_performer_filter_for_log();

                lvjm_log(
                        sprintf(
                                '[WPS-LiveJasmin] Raw API response | URL: %s | Performer filter: %s',
                                '' !== $sanitized_url ? $sanitized_url : '-',
                                '' !== $filter_label ? $filter_label : '-'
                        )
                );

                lvjm_log( $raw_body );
        }

	/**
	 * Normalize performer names for comparisons.
	 *
	 * @param string $name Raw performer name.
	 * @return string
	 */
        private function normalize_name( $name ) {
                $name = trim( (string) $name );

                if ( function_exists( 'remove_accents' ) ) {
                        $name = remove_accents( $name );
                } elseif ( function_exists( 'iconv' ) ) {
                        $converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name );

                        if ( false !== $converted ) {
                                $name = $converted;
                        }
                }

                $name = strtolower( $name );

                return preg_replace( '/[^a-z0-9]/', '', $name );
        }

	/**
	 * Retrieve the local performers indexed by their normalized name.
	 *
	 * @return array
	 */
	private function get_local_performer_map() {
		if ( null !== $this->local_performers ) {
			return $this->local_performers;
		}

		$taxonomy = '';
		if ( function_exists( 'xbox_get_field_value' ) ) {
			$taxonomy = (string) xbox_get_field_value( 'lvjm-options', 'custom-video-actors' );
		}

		if ( '' === $taxonomy ) {
			$taxonomy = 'models';
		}

		if ( 'actors' === $taxonomy ) {
			$taxonomy = 'models';
		}

		if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
			$this->local_performers = array();
			return $this->local_performers;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			$this->local_performers = array();
			return $this->local_performers;
		}

		$this->local_performers = array();

		foreach ( (array) $terms as $term ) {
			$candidates = array( $term->name );

			if ( ! empty( $term->slug ) ) {
				$candidates[] = $term->slug;
			}

			foreach ( $candidates as $candidate ) {
				$normalized = $this->normalize_name( $candidate );

				if ( '' === $normalized ) {
					continue;
				}

				if ( ! isset( $this->local_performers[ $normalized ] ) ) {
					$this->local_performers[ $normalized ] = $term->name;
				}
			}
		}

		return $this->local_performers;
	}

	/**
	 * Extract performer candidates from the raw API response.
	 *
	 * @param array $raw_feed_item Single video payload from the API.
	 * @return array
	 */
        private function extract_performer_candidates( $raw_feed_item ) {
                if ( empty( $raw_feed_item ) || ! is_array( $raw_feed_item ) ) {
                        return array();
                }

                $names = array();

                if ( ! empty( $raw_feed_item['performers'] ) ) {
                        if ( is_array( $raw_feed_item['performers'] ) ) {
                                $names = $raw_feed_item['performers'];
                        } elseif ( $raw_feed_item['performers'] instanceof \Traversable ) {
                                $names = iterator_to_array( $raw_feed_item['performers'] );
                        }
                }

                if ( empty( $names ) && ! empty( $raw_feed_item['models'] ) ) {
                        if ( is_array( $raw_feed_item['models'] ) ) {
                                $names = $raw_feed_item['models'];
                        } elseif ( $raw_feed_item['models'] instanceof \Traversable ) {
                                $names = iterator_to_array( $raw_feed_item['models'] );
                        }
                }

                if ( empty( $names ) || ! is_array( $names ) ) {
                        return array();
                }

                $names = $this->sanitize_performer_source( $names );

                if ( empty( $names ) ) {
                        return array();
                }

                return array_values( array_unique( $names ) );
        }

        /**
         * Normalize raw performer data into a flat list of names.
         *
         * @param array|Traversable $source Raw performer payload.
         * @return array
         */
        private function sanitize_performer_source( $source ) {
                $names = array();

                if ( is_array( $source ) ) {
                        if ( isset( $source['data'] ) && is_array( $source['data'] ) ) {
                                $source = $source['data'];
                        }
                } elseif ( is_object( $source ) ) {
                        if ( isset( $source->data ) && is_array( $source->data ) ) {
                                $source = $source->data;
                        } elseif ( $source instanceof \Traversable ) {
                                $source = iterator_to_array( $source );
                        } else {
                                $source = (array) $source;
                        }
                } elseif ( $source instanceof \Traversable ) {
                        $source = iterator_to_array( $source );
                }

                if ( empty( $source ) || ! is_array( $source ) ) {
                        return $names;
                }

                foreach ( $source as $performer ) {
                        $candidate = '';

                        if ( is_array( $performer ) ) {
                                $keys = array( 'name', 'displayName', 'username', 'id' );
                                foreach ( $keys as $key ) {
                                        if ( isset( $performer[ $key ] ) && '' !== (string) $performer[ $key ] ) {
                                                $candidate = (string) $performer[ $key ];
                                                break;
                                        }
                                }
                        } elseif ( is_object( $performer ) ) {
                                $keys = array( 'name', 'displayName', 'username', 'id' );
                                foreach ( $keys as $key ) {
                                        if ( isset( $performer->$key ) && '' !== (string) $performer->$key ) {
                                                $candidate = (string) $performer->$key;
                                                break;
                                        }
                                }
                        } else {
                                $candidate = (string) $performer;
                        }

                        $candidate = trim( $candidate );

                        if ( '' !== $candidate ) {
                                $names[] = $candidate;
                        }
                }

                if ( empty( $names ) ) {
                        return array();
                }

                return array_values( array_unique( $names ) );
        }

        /**
         * Attempt to capture the performer identifier from the raw API payload.
         *
         * @param array  $raw_feed_item Raw API payload for the current video.
         * @param string $normalized_filter Optional normalized performer filter for matching.
         * @return void
         */
        private function maybe_collect_performer_identifier( $raw_feed_item, $normalized_filter = '' ) {
                if ( '' !== $this->discovered_performer_id ) {
                        return;
                }

                if ( empty( $raw_feed_item ) || ! is_array( $raw_feed_item ) ) {
                        return;
                }

                if ( isset( $raw_feed_item['performerId'] ) && '' === $this->discovered_performer_id ) {
                        $candidate_id = trim( (string) $raw_feed_item['performerId'] );

                        if ( '' !== $candidate_id ) {
                                $this->discovered_performer_id = $candidate_id;

                                if ( '' === $normalized_filter ) {
                                        return;
                                }
                        }
                }

                $sources = array();

                if ( ! empty( $raw_feed_item['performers'] ) ) {
                        $sources[] = $raw_feed_item['performers'];
                }

                if ( ! empty( $raw_feed_item['models'] ) ) {
                        $sources[] = $raw_feed_item['models'];
                }

                foreach ( $sources as $source ) {
                        if ( empty( $source ) ) {
                                continue;
                        }

                        if ( is_object( $source ) && $source instanceof \Traversable ) {
                                $source = iterator_to_array( $source );
                        }

                        if ( ! is_array( $source ) ) {
                                continue;
                        }

                        foreach ( $source as $performer_entry ) {
                                $identifier = '';
                                $display    = '';

                                if ( is_array( $performer_entry ) ) {
                                        $identifier = isset( $performer_entry['id'] ) ? (string) $performer_entry['id'] : '';
                                        $display    = isset( $performer_entry['name'] ) ? (string) $performer_entry['name'] : '';

                                        if ( '' === $display && isset( $performer_entry['displayName'] ) ) {
                                                $display = (string) $performer_entry['displayName'];
                                        }
                                } elseif ( is_object( $performer_entry ) ) {
                                        $identifier = isset( $performer_entry->id ) ? (string) $performer_entry->id : '';
                                        $display    = isset( $performer_entry->name ) ? (string) $performer_entry->name : '';

                                        if ( '' === $display && isset( $performer_entry->displayName ) ) {
                                                $display = (string) $performer_entry->displayName;
                                        }
                                }

                                $identifier = trim( $identifier );
                                $display    = trim( $display );

                                if ( '' === $identifier ) {
                                        continue;
                                }

                                if ( '' !== $normalized_filter && '' !== $display ) {
                                        if ( $this->normalize_name( $display ) !== $normalized_filter ) {
                                                continue;
                                        }
                                }

                                $this->discovered_performer_id = $identifier;
                                return;
                        }
                }
        }

        /**
         * Find matching local performers for a given feed item.
         *
         * @param array  $raw_feed_item      The raw item data from the API.
         * @param string $normalized_filter  Optional normalized performer filter from the request.
	 * @return array
	 */
	private function find_matching_performers( $raw_feed_item, $normalized_filter = '' ) {
		$local_performers = $this->get_local_performer_map();

		if ( empty( $local_performers ) ) {
			return array();
		}

                $matches    = array();
                $candidates = $this->extract_performer_candidates( $raw_feed_item );
                $video_id   = isset( $raw_feed_item['id'] ) ? (string) $raw_feed_item['id'] : '';
                $sources    = array();

                if ( ! empty( $raw_feed_item['performers'] ) ) {
                        $sources[] = 'performers';
                }

                if ( ! empty( $raw_feed_item['models'] ) ) {
                        $sources[] = 'models';
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $normalized_filter ) {
                        $log_sources    = empty( $sources ) ? array( 'none' ) : $sources;
                        $log_candidates = empty( $candidates ) ? '-' : implode( ', ', $candidates );
                        $video_label    = '' !== $video_id ? $video_id : '-';

                        if ( function_exists( 'sanitize_text_field' ) ) {
                                $video_label    = sanitize_text_field( $video_label );
                                $log_candidates = sanitize_text_field( $log_candidates );
                                $log_sources    = array_map( 'sanitize_text_field', $log_sources );
                        }

                        lvjm_log(
                                sprintf(
                                        '[WPS-LiveJasmin] Video %s | Sources: %s | Candidates: %s',
                                        $video_label,
                                        implode( ', ', $log_sources ),
                                        '' !== $log_candidates ? $log_candidates : '-'
                                )
                        );
                }

                foreach ( $candidates as $candidate ) {
                        $normalized_candidate = $this->normalize_name( $candidate );

                        if ( '' === $normalized_candidate ) {
                                continue;
                        }

                        if ( ! isset( $local_performers[ $normalized_candidate ] ) ) {
                                continue;
                        }

                        if ( '' !== $normalized_filter && $normalized_candidate !== $normalized_filter ) {
                                continue;
                        }

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $normalized_filter && $normalized_candidate === $normalized_filter ) {
                                $log_candidate = $candidate;
                                $video_label   = '' !== $video_id ? $video_id : '-';

                                if ( function_exists( 'sanitize_text_field' ) ) {
                                        $log_candidate = sanitize_text_field( $log_candidate );
                                        $video_label   = sanitize_text_field( $video_label );
                                }

                                lvjm_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Video %s matched performer candidate: %s',
                                                $video_label,
                                                '' !== $log_candidate ? $log_candidate : '-'
                                        )
                                );
                        }

                        $matches[ $normalized_candidate ] = $local_performers[ $normalized_candidate ];
                }

                return array_values( $matches );
        }

	/**
	 * Log the outcome of a search when WP_DEBUG is enabled.
	 *
	 * @param int $count Number of videos matched.
	 * @return void
	 */
	private function log_search_result( $count ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

                $category = isset( $this->params['cat_s'] ) ? (string) $this->params['cat_s'] : '';
                $name     = '';

                if ( isset( $this->params['search_name'] ) ) {
                        $name = (string) $this->params['search_name'];
                }

                if ( '' === $name && isset( $this->params['performer'] ) ) {
                        $name = (string) $this->params['performer'];
                }

                if ( function_exists( 'sanitize_text_field' ) ) {
                        $category = sanitize_text_field( $category );
                        $name     = sanitize_text_field( $name );
                }

                if ( '' === $category ) {
                        $category = '-';
                }

                if ( '' === $name ) {
                        $name = '-';
                }

                $message = sprintf(
                        '[WPS-LiveJasmin] Name: %s | Category: %s | Videos found: %d | Profile created: no',
                        $name,
                        $category,
                        (int) $count
                );

                lvjm_log( $message );
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
                $include_existing       = ! empty( $this->params['include_existing'] );
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

                $root_feed_url = $this->get_feed_url_with_orientation();

                $this->discovered_performer_id = '';

                $requested_performer = '';
                if ( isset( $this->params['performer'] ) && '' !== (string) $this->params['performer'] ) {
                        $requested_performer = (string) $this->params['performer'];
                } elseif ( isset( $this->params['search_name'] ) && '' !== (string) $this->params['search_name'] ) {
                        $requested_performer = (string) $this->params['search_name'];
                }

                if ( '' !== $requested_performer && function_exists( 'sanitize_text_field' ) ) {
                        $requested_performer = sanitize_text_field( $requested_performer );
                }

                $normalized_performer      = $this->normalize_name( $requested_performer );
                $local_performers_existing = ! empty( $this->get_local_performer_map() );
                $require_performer_match   = '' !== $normalized_performer && $local_performers_existing;

                $args = array(
                        'timeout'   => 300,
                        'sslverify' => false,
                );

                $args['user-agent'] = 'WordPress/' . $this->wp_version . '; ' . home_url();

                if ( isset( $this->feed_infos->feed_auth ) ) {
                        $args['headers'] = array( 'Authorization' => $this->get_partner_feed_infos( $this->feed_infos->feed_auth->data ) );
                }

                if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
                        $args['headers'] = array();
                }

                $args['headers']['X-Requested-With'] = 'XMLHttpRequest';
                $args['headers']['Accept']           = 'application/json';

		$current_page = intval( $this->get_partner_feed_infos( $this->feed_infos->feed_first_page->data ) );

		while ( false === $end ) {

			$this->feed_url = $this->build_page_url( $root_feed_url, $current_page );

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                $log_url     = $this->sanitize_url_for_log( $this->feed_url );
                                $log_filter  = $this->get_active_performer_filter_for_log();

                                lvjm_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] Request URL: %s | Performer filter: %s',
                                                '' !== $log_url ? $log_url : '-',
                                                '' !== $log_filter ? $log_filter : '-'
                                        )
                                );
                        }

                        $response = wp_remote_get( $this->feed_url, $args );

                        if ( is_wp_error( $response ) ) {
                                WPSCORE()->write_log( 'error', 'Retrieving videos from JSON feed failed<code>ERROR: ' . wp_json_encode( $response->errors ) . '</code>', __FILE__, __LINE__ );
                                $this->log_search_result( 0 );
                                return false;
                        }

                        if ( 403 === wp_remote_retrieve_response_code( $response ) ) {
				WPSCORE()->write_log( 'error', 'Your AWEmpire PSID or Access Key is wrong. Please configure LiveJasmin.', __FILE__, __LINE__ );
				$this->errors = array(
					'code'     => 'AWEmpire credentials error',
					'message'  => 'Your AWEmpire PSID or Access Key is wrong.',
					'solution' => 'Please configure LiveJasmin.',
				);
				$this->log_search_result( 0 );
				return false;
			}

                        $raw_body = wp_remote_retrieve_body( $response );

                        $this->log_raw_api_response( $raw_body );

                        $response_body = json_decode( $raw_body, true );

                        if ( null === $response_body ) {
                                WPSCORE()->write_log( 'error', 'Invalid JSON response received from LiveJasmin feed.', __FILE__, __LINE__ );
                                $this->log_search_result( 0 );
                                return false;
                        }

                        $api_status  = isset( $response_body['status'] ) ? (string) $response_body['status'] : 'UNKNOWN';
                        $video_count = 0;

                        if ( isset( $response_body['data'] ) && isset( $response_body['data']['videos'] ) && is_array( $response_body['data']['videos'] ) ) {
                                $video_count = count( $response_body['data']['videos'] );
                        }

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                $log_status = $api_status;
                                $log_filter = $this->get_active_performer_filter_for_log();

                                if ( function_exists( 'sanitize_text_field' ) ) {
                                        $log_status = sanitize_text_field( $log_status );
                                        $log_filter = sanitize_text_field( $log_filter );
                                }

                                lvjm_log(
                                        sprintf(
                                                '[WPS-LiveJasmin] API response status: %s | Videos: %d | Performer filter: %s',
                                                '' !== $log_status ? $log_status : '-',
                                                (int) $video_count,
                                                '' !== $log_filter ? $log_filter : '-'
                                        )
                                );
                        }

                        if ( isset( $response_body['status'] ) && 'ERROR' === $response_body['status'] ) {
                                $end              = true;
                                $page_end         = true;
                                $videos_details[] = array(
                                        'id'       => 'end',
                                        'response' => 'livejasmin API Error',
                                );
                                $error_message    = isset( $response_body['message'] ) ? (string) $response_body['message'] : 'Unknown LiveJasmin API error.';

                                $this->errors = array(
                                        'code'     => 'livejasmin_api_error',
                                        'message'  => sprintf( 'LiveJasmin API returned an error: %s', $error_message ),
                                        'solution' => __( 'Adjust the performer filter or try another category, then search again.', 'lvjm_lang' ),
                                );

                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                        $log_error_message = $error_message;

                                        if ( function_exists( 'sanitize_text_field' ) ) {
                                                $log_error_message = sanitize_text_field( $log_error_message );
                                        }

                                        lvjm_log(
                                                sprintf(
                                                        '[WPS-LiveJasmin] API error response: %s',
                                                        '' !== $log_error_message ? $log_error_message : '-'
                                                )
                                        );
                                }

                                $this->log_search_result( 0 );
                                return false;
                        }

                        if ( ! isset( $response_body['data'] ) || ! isset( $response_body['data']['videos'] ) ) {
                                WPSCORE()->write_log( 'error', 'Unexpected LiveJasmin response format (missing videos array).', __FILE__, __LINE__ );
                                $this->log_search_result( 0 );
                                return false;
                        }

                        // feed url last page reached.
                        if ( 0 === count( (array) $response_body['data']['videos'] ) || ( isset( $response_body['data']['pagination']['totalPages'] ) && $current_page > $response_body['data']['pagination']['totalPages'] ) ) {
                                $end              = true;
                                $page_end         = true;
                                $videos_details[] = array(
                                        'id'       => 'end',
                                        'response' => 'No more videos',
                                );

                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                        $log_filter = $this->get_active_performer_filter_for_log();

                                        if ( function_exists( 'sanitize_text_field' ) ) {
                                                $log_filter = sanitize_text_field( $log_filter );
                                        }

                                        lvjm_log(
                                                sprintf(
                                                        '[WPS-LiveJasmin] LiveJasmin returned no videos for performer filter: %s',
                                                        '' !== $log_filter ? $log_filter : '-'
                                                )
                                        );
                                }
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
				$raw_feed_item = $array_feed[ $current_item ];
				$feed_item     = new LVJM_Json_Item( $raw_feed_item );
				$feed_item->init( $this->params, $this->feed_infos );
                                if ( $feed_item->is_valid() ) {
                                        $this->maybe_collect_performer_identifier( $raw_feed_item, $normalized_performer );
                                        $matching_performers = $this->find_matching_performers( $raw_feed_item, $normalized_performer );

                                        if ( $require_performer_match && empty( $matching_performers ) ) {
                                                ++$current_item;
                                                continue;
                                        }

                                        $video_id             = $feed_item->get_id();
                                        $is_existing_video    = in_array( $video_id, (array) $existing_ids['partner_existing_videos_ids'], true );
                                        $is_removed_video     = in_array( $video_id, (array) $existing_ids['partner_unwanted_videos_ids'], true );
                                        $was_previously_seen  = in_array( $video_id, (array) $existing_ids['partner_all_videos_ids'], true );
                                        $should_include_video = true;

                                        if ( $was_previously_seen && ! $include_existing ) {
                                                $should_include_video = false;
                                        }

                                        if ( $is_removed_video ) {
                                                $should_include_video = false;
                                        }

                                        if ( $should_include_video ) {
                                                $video_payload = (array) $feed_item->get_data_for_json( $count_valid_feed_items );

                                                if ( ! empty( $matching_performers ) ) {
                                                        $video_payload['actors'] = implode( ', ', $matching_performers );
                                                }

                                                $performer_candidates = $this->extract_performer_candidates( $raw_feed_item );
                                                if ( ! empty( $performer_candidates ) ) {
                                                        $video_payload['lvjm_performer_candidates'] = $performer_candidates;
                                                }

                                                if ( $include_existing && $is_existing_video ) {
                                                        $video_payload['grabbed'] = true;
                                                }

                                                $array_valid_videos[] = $video_payload;
                                                $videos_details[]     = array(
                                                        'id'       => $video_id,
                                                        'response' => $is_existing_video ? 'Already imported' : 'Success',
                                                );

                                                if ( $is_existing_video ) {
                                                        ++$counters['existing_videos'];
                                                } else {
                                                        ++$counters['valid_videos'];
                                                }

                                                ++$count_valid_feed_items;
                                        } elseif ( $was_previously_seen ) {
                                                $videos_details[] = array(
                                                        'id'       => $video_id,
                                                        'response' => 'Already imported',
                                                );
                                                ++$counters['existing_videos'];
                                        } elseif ( $is_removed_video ) {
                                                $videos_details[] = array(
                                                        'id'       => $video_id,
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

                $searched_data = array(
                        'videos_details' => $videos_details,
                        'counters'       => $counters,
                        'videos'         => $array_valid_videos,
                );

                if ( '' !== $this->discovered_performer_id ) {
                        $searched_data['performer_id'] = $this->discovered_performer_id;
                }

                $this->searched_data = $searched_data;
                $this->videos        = $array_valid_videos;
                $this->log_search_result( count( $array_valid_videos ) );
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
