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
 * Queries the LiveJasmin/AW Empire video-promotion API and prepares
 * results for the admin UI.
 */
class LVJM_Search_Videos {

        /**
         * Raw search params from the request.
         *
         * @var array
         */
        private $params = array();

        /**
         * Collected errors during the search lifecycle.
         *
         * @var array
         */
        private $errors = array();

        /**
         * Normalized videos ready for the UI.
         *
         * @var array
         */
        private $videos = array();

        /**
         * Metadata about the executed search.
         *
         * @var array
         */
        private $searched_data = array();

        /**
         * Current WordPress version (used for HTTP User-Agent header).
         *
         * @var string
         */
        private $wp_version = '';

        /**
         * Sexual orientation used for the API request.
         *
         * @var string
         */
        private $orientation = 'straight';

        /**
         * Normalized partner category slug for the current search.
         *
         * @var string
         */
        private $category_slug = '';

        /**
         * LiveJasmin credentials (psid & accessKey).
         *
         * @var array
         */
        private $credentials = array();

        /**
         * Maximum amount of videos returned per API call.
         *
         * @var int
         */
        private $max_results_per_request = 60;

        /**
         * API list endpoint base URL.
         *
         * @var string
         */
        private $list_endpoint = 'https://pt.ptawe.com/api/video-promotion/v1/list';

        /**
         * Constructor.
         *
         * @param array $params Parameters for the search.
         */
        public function __construct( $params ) {
                global $wp_version;

                $this->wp_version = $wp_version;
                $this->params     = is_array( $params ) ? $params : array();

                $this->initialize_defaults();

                if ( ! $this->validate_partner() ) {
                        return;
                }

                $this->orientation   = $this->determine_orientation();
                $this->category_slug = $this->determine_category_slug();

                $credentials = $this->resolve_credentials();
                if ( is_wp_error( $credentials ) ) {
                        $this->errors = array(
                                'code'     => 'missing_credentials',
                                'message'  => __( 'Your AWEmpire PSID or Access Key is missing.', 'wps-livejasmin' ),
                                'solution' => __( 'Please add both credentials in the LiveJasmin settings.', 'wps-livejasmin' ),
                        );
                        $this->log_debug( 'Missing LiveJasmin credentials. Aborting search.' );
                        return;
                }

                $this->credentials = $credentials;

                $this->retrieve_videos_from_api();
        }

        /**
         * Prepare default structures for search data & counters.
         */
        private function initialize_defaults() {
                $this->errors = array();
                $this->videos = array();
                $this->searched_data = array(
                        'videos_details' => array(),
                        'counters'       => array(
                                'valid_videos'    => 0,
                                'invalid_videos'  => 0,
                                'existing_videos' => 0,
                                'removed_videos'  => 0,
                        ),
                        'videos'     => array(),
                        'pagination' => array(
                                'pageIndex'  => 0,
                                'pageSize'   => 0,
                                'count'      => 0,
                                'totalPages' => 0,
                                'totalCount' => 0,
                        ),
                );
        }

        /**
         * Ensure a partner is present in the params.
         *
         * @return bool
         */
        private function validate_partner() {
                if ( empty( $this->params['partner'] ) || empty( $this->params['partner']['id'] ) ) {
                        $this->errors = array(
                                'code'     => 'missing_partner',
                                'message'  => __( 'The partner configuration is missing.', 'wps-livejasmin' ),
                                'solution' => __( 'Select a partner and try again.', 'wps-livejasmin' ),
                        );
                        return false;
                }

                return true;
        }

        /**
         * Retrieve LiveJasmin credentials for the current partner.
         *
         * @return array|WP_Error
         */
        private function resolve_credentials() {
                $partner_id = isset( $this->params['partner']['id'] ) ? sanitize_text_field( (string) $this->params['partner']['id'] ) : '';

                $psid       = '';
                $access_key = '';

                if ( $partner_id ) {
                        $saved_partner_options = WPSCORE()->get_product_option( 'LVJM', $partner_id . '_options' );
                        if ( is_array( $saved_partner_options ) ) {
                                if ( empty( $psid ) && ! empty( $saved_partner_options['psid'] ) ) {
                                        $psid = sanitize_text_field( (string) $saved_partner_options['psid'] );
                                }
                                if ( empty( $access_key ) && ! empty( $saved_partner_options['accesskey'] ) ) {
                                        $access_key = sanitize_text_field( (string) $saved_partner_options['accesskey'] );
                                }
                        }
                }

                $global_partner_options = WPSCORE()->get_product_option( 'LVJM', 'livejasmin_options' );
                if ( is_array( $global_partner_options ) ) {
                        if ( empty( $psid ) && ! empty( $global_partner_options['psid'] ) ) {
                                $psid = sanitize_text_field( (string) $global_partner_options['psid'] );
                        }
                        if ( empty( $access_key ) && ! empty( $global_partner_options['accesskey'] ) ) {
                                $access_key = sanitize_text_field( (string) $global_partner_options['accesskey'] );
                        }
                }

                if ( empty( $psid ) ) {
                        $psid = sanitize_text_field( (string) get_option( 'wps_lj_psid' ) );
                }

                if ( empty( $access_key ) ) {
                        $access_key = sanitize_text_field( (string) get_option( 'wps_lj_accesskey' ) );
                }

                if ( '' === $psid || '' === $access_key ) {
                        return new WP_Error( 'missing_credentials', 'Missing LiveJasmin credentials.' );
                }

                return array(
                        'psid'       => $psid,
                        'access_key' => $access_key,
                );
        }

        /**
         * Execute paginated requests against the LiveJasmin list endpoint.
         */
        private function retrieve_videos_from_api() {
                $limit_total = $this->determine_limit();
                $remaining   = $limit_total;
                $page_index  = 0;
                $max_pages   = 50;

                $existing_ids = $this->get_partner_existing_ids();
                $existing_lookup = array_flip( (array) $existing_ids['partner_existing_videos_ids'] );
                $removed_lookup  = array_flip( (array) $existing_ids['partner_unwanted_videos_ids'] );

                $videos_details      = array();
                $valid_videos        = array();
                $seen_ids            = array();
                $total_pages_report  = null;
                $total_count_report  = null;

                while ( $remaining > 0 && $page_index < $max_pages ) {
                        $per_page = min( $this->max_results_per_request, $remaining );
                        $response = $this->request_page( $page_index, $per_page );

                        if ( is_wp_error( $response ) ) {
                                $this->handle_wp_error( $response );
                                return;
                        }

                        if ( isset( $response['status'] ) && 'ERROR' === strtoupper( (string) $response['status'] ) ) {
                                $this->errors = array(
                                        'code'     => 'api_error',
                                        'message'  => __( 'LiveJasmin API returned an error.', 'wps-livejasmin' ),
                                        'solution' => __( 'Please review your query and try again.', 'wps-livejasmin' ),
                                );
                                $this->log_debug( 'LiveJasmin API returned an error payload.', array( 'payload' => $this->truncate_for_log( $response ) ) );
                                return;
                        }

                        $payload    = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
                        $videos     = isset( $payload['videos'] ) && is_array( $payload['videos'] ) ? $payload['videos'] : array();
                        $pagination = isset( $payload['pagination'] ) && is_array( $payload['pagination'] ) ? $payload['pagination'] : array();

                        if ( isset( $pagination['totalPages'] ) ) {
                                $total_pages_report = (int) $pagination['totalPages'];
                        }

                        if ( isset( $pagination['totalCount'] ) ) {
                                $total_count_report = (int) $pagination['totalCount'];
                        }

                        $this->log_debug(
                                'LiveJasmin page fetched.',
                                array(
                                        'pageIndex'  => $page_index,
                                        'requested'  => $per_page,
                                        'returned'   => count( $videos ),
                                        'totalPages' => $total_pages_report,
                                        'totalCount' => $total_count_report,
                                )
                        );

                        if ( empty( $videos ) ) {
                                break;
                        }

                        $stop_after_page = false;

                        foreach ( (array) $videos as $raw_video ) {
                                if ( $remaining <= 0 ) {
                                        $stop_after_page = true;
                                        break;
                                }

                                $normalized = $this->normalize_video_item( (array) $raw_video );
                                if ( empty( $normalized ) ) {
                                        $videos_details[] = array(
                                                'id'       => isset( $raw_video['id'] ) ? (string) $raw_video['id'] : 'invalid',
                                                'response' => 'Invalid',
                                        );
                                        ++$this->searched_data['counters']['invalid_videos'];
                                        continue;
                                }

                                $video_id = (string) $normalized['id'];
                                if ( isset( $seen_ids[ $video_id ] ) ) {
                                        continue;
                                }

                                $seen_ids[ $video_id ] = true;

                                $status = 'valid';
                                if ( isset( $existing_lookup[ $video_id ] ) ) {
                                        $status                         = 'existing';
                                        $normalized['already_imported'] = true;
                                        $normalized['checked']          = false;
                                } elseif ( isset( $removed_lookup[ $video_id ] ) ) {
                                        $status                         = 'removed';
                                        $normalized['already_imported'] = false;
                                        $normalized['checked']          = false;
                                } else {
                                        $normalized['already_imported'] = false;
                                }

                                $normalized['import_status'] = $status;

                                $valid_videos[] = $normalized;

                                switch ( $status ) {
                                        case 'existing':
                                                $videos_details[] = array(
                                                        'id'       => $video_id,
                                                        'response' => 'Already imported',
                                                );
                                                ++$this->searched_data['counters']['existing_videos'];
                                                break;
                                        case 'removed':
                                                $videos_details[] = array(
                                                        'id'       => $video_id,
                                                        'response' => 'You removed it from search results',
                                                );
                                                ++$this->searched_data['counters']['removed_videos'];
                                                break;
                                        default:
                                                $videos_details[] = array(
                                                        'id'       => $video_id,
                                                        'response' => 'Success',
                                                );
                                                ++$this->searched_data['counters']['valid_videos'];
                                                break;
                                }

                                --$remaining;
                        }

                        if ( $stop_after_page ) {
                                break;
                        }

                        ++$page_index;

                        if ( null !== $total_pages_report && $page_index >= $total_pages_report ) {
                                break;
                        }
                }

                $fetched_pages = $page_index;
                if ( ! empty( $valid_videos ) && $remaining <= 0 ) {
                        $fetched_pages = $page_index + 1;
                }

                $this->searched_data['videos_details']            = $videos_details;
                $this->searched_data['videos']                    = $valid_videos;
                $this->searched_data['pagination']['pageIndex']   = max( 0, $fetched_pages - 1 );
                $this->searched_data['pagination']['pageSize']    = min( $this->max_results_per_request, $limit_total );
                $this->searched_data['pagination']['count']       = count( $valid_videos );
                $this->searched_data['pagination']['totalPages']  = ( null !== $total_pages_report ) ? $total_pages_report : max( 1, $fetched_pages );
                $this->searched_data['pagination']['totalCount']  = ( null !== $total_count_report ) ? $total_count_report : count( $valid_videos );

                $this->videos = $valid_videos;
        }

        /**
         * Handle WP_Error responses from the API request.
         *
         * @param WP_Error $error Error instance.
         */
        private function handle_wp_error( WP_Error $error ) {
                switch ( $error->get_error_code() ) {
                        case 'livejasmin_credentials':
                                $this->errors = array(
                                        'code'     => 'AWEmpire credentials error',
                                        'message'  => __( 'Your AWEmpire PSID or Access Key is wrong.', 'wps-livejasmin' ),
                                        'solution' => __( 'Please configure LiveJasmin.', 'wps-livejasmin' ),
                                );
                                break;
                        default:
                                $this->errors = array(
                                        'code'     => 'api_error',
                                        'message'  => __( 'There was an error communicating with the LiveJasmin API.', 'wps-livejasmin' ),
                                        'solution' => __( 'Please try again later.', 'wps-livejasmin' ),
                                );
                                break;
                }

                $this->log_debug( 'LiveJasmin API request failed.', array( 'error' => $error->get_error_message() ) );
        }

        /**
         * Determine the amount of results requested for the search.
         *
         * @return int
         */
        private function determine_limit() {
                $limit = isset( $this->params['limit'] ) ? absint( $this->params['limit'] ) : 0;
                if ( $limit <= 0 ) {
                        $limit = 30;
                }

                return max( 1, $limit );
        }

        /**
         * Build the LiveJasmin API query parameters.
         *
         * @param int $page_index Zero based page index.
         * @param int $limit      Number of videos requested for the page.
         * @return array
         */
        private function build_request_args( $page_index, $limit ) {
                $client_ip = function_exists( 'lvjm_get_client_ip_address' ) ? lvjm_get_client_ip_address() : '';

                $args = array(
                        'psid'              => $this->credentials['psid'],
                        'accessKey'         => $this->credentials['access_key'],
                        'limit'             => max( 1, min( $this->max_results_per_request, absint( $limit ) ) ),
                        'pageIndex'         => max( 0, absint( $page_index ) ),
                        'sexualOrientation' => $this->orientation,
                        'site'              => 'wl3',
                        'language'          => 'en',
                );

                if ( $client_ip ) {
                        $args['clientIp'] = $client_ip;
                }

                $tags = $this->determine_tags();
                if ( '' !== $tags ) {
                        $args['tags'] = $tags;
                }

                $performer = isset( $this->params['performer'] ) ? sanitize_text_field( (string) $this->params['performer'] ) : '';
                if ( '' !== $performer ) {
                        // The LiveJasmin API expects forced performers as an array parameter
                        // formatted as `forcedPerformers[]=` in the query string. By using an
                        // empty string as the key we force http_build_query() to output the
                        // proper bracket syntax required by the endpoint.
                        $args['forcedPerformers'] = array( '' => $performer );
                }

                if ( isset( $this->params['tags'] ) && '' === $tags && ! empty( $this->params['tags'] ) ) {
                        $args['tags'] = sanitize_text_field( (string) $this->params['tags'] );
                }

                return $args;
        }

        /**
         * Perform a single HTTP request to the list endpoint.
         *
         * @param int $page_index Page index.
         * @param int $limit      Page size.
         * @return array|WP_Error
         */
        private function request_page( $page_index, $limit ) {
                $query_args    = $this->build_request_args( $page_index, $limit );
                $query_string  = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
                $request_url   = $this->list_endpoint . '?' . $query_string;
                $masked_params = $query_args;

                if ( isset( $masked_params['psid'] ) ) {
                        $masked_params['psid'] = $this->mask_value( $masked_params['psid'] );
                }
                if ( isset( $masked_params['accessKey'] ) ) {
                        $masked_params['accessKey'] = $this->mask_value( $masked_params['accessKey'] );
                }

                $this->log_debug(
                        'Requesting LiveJasmin list endpoint.',
                        array(
                                'url'    => $this->list_endpoint,
                                'params' => $masked_params,
                        )
                );

                $args = array(
                        'timeout'   => 45,
                        'sslverify' => true,
                        'headers'   => array(
                                'Accept' => 'application/json',
                        ),
                        'user-agent' => 'WordPress/' . $this->wp_version . '; ' . home_url(),
                );

                $response = wp_remote_get( $request_url, $args );
                if ( is_wp_error( $response ) ) {
                                return $response;
                }

                $status_code = wp_remote_retrieve_response_code( $response );
                if ( 403 === $status_code ) {
                        return new WP_Error( 'livejasmin_credentials', 'Unauthorized LiveJasmin credentials.' );
                }

                if ( $status_code < 200 || $status_code >= 300 ) {
                        return new WP_Error( 'http_error', 'Unexpected HTTP status code: ' . $status_code );
                }

                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );

                if ( null === $data ) {
                        return new WP_Error( 'invalid_json', 'Unable to decode LiveJasmin response.' );
                }

                return $data;
        }

        /**
         * Normalize a single video item from the LiveJasmin API response.
         *
         * @param array $item Video item.
         * @return array|null
         */
        private function normalize_video_item( array $item ) {
                $video_id = $this->extract_first_non_empty( $item, array( 'id', 'videoId', 'video_id', 'code' ) );
                if ( '' === $video_id ) {
                        return null;
                }

                $title = $this->extract_first_non_empty( $item, array( 'title', 'name', 'headline' ) );
                $desc  = $this->extract_first_non_empty( $item, array( 'description', 'desc', 'summary', 'shortDescription' ) );

                $tags_value = $this->extract_first_non_empty( $item, array( 'tags', 'categories', 'keywords' ) );
                $tags_list  = $this->sanitize_text_list( $tags_value );
                $tags       = implode( ', ', $tags_list );

                $duration_value = $this->extract_first_non_empty( $item, array( 'lengthSeconds', 'duration', 'length', 'durationSeconds' ) );
                $duration       = $this->normalize_duration( $duration_value );

                $thumb_url_value = $this->extract_first_non_empty( $item, array( 'mainThumbnailUrl', 'thumbUrl', 'thumbnailUrl', 'previewUrl', 'imageUrl', 'previewImage' ) );
                $thumb_url       = esc_url_raw( (string) $thumb_url_value );

                $thumbs_value = $this->extract_first_non_empty( $item, array( 'thumbnailSet', 'thumbs', 'thumbnails', 'images', 'snapshotUrls', 'previewImages' ) );
                $thumbs_urls  = $this->sanitize_url_list( $thumbs_value );

                if ( empty( $thumbs_urls ) && '' !== $thumb_url ) {
                        $thumbs_urls = array( $thumb_url );
                }

                $trailer_value = $this->extract_first_non_empty( $item, array( 'trailerUrl', 'previewVideoUrl', 'teaserUrl', 'trailerVideoUrl' ) );
                $video_url     = $this->extract_first_non_empty( $item, array( 'videoUrl', 'videoLink', 'videoDownloadUrl', 'streamUrl' ) );
                $tracking_url  = $this->extract_first_non_empty( $item, array( 'trackingUrl', 'joinUrl', 'clickUrl', 'targetUrl' ) );

                $quality = $this->extract_first_non_empty( $item, array( 'quality', 'videoQuality' ) );
                $is_hd   = $this->extract_first_non_empty( $item, array( 'isHd', 'hd', 'hdVideo' ) );
                if ( is_bool( $is_hd ) ) {
                        $is_hd = $is_hd ? '1' : '0';
                } elseif ( is_numeric( $is_hd ) ) {
                        $is_hd = ( (int) $is_hd ) ? '1' : '0';
                } else {
                        $is_hd = '' !== $is_hd ? (string) $is_hd : '';
                }

                $uploader_value = $this->extract_first_non_empty( $item, array( 'uploader', 'channel', 'studio', 'siteName' ) );

                $actors_value = $this->extract_first_non_empty( $item, array( 'performers', 'models', 'actors' ) );
                $actors_list  = $this->sanitize_text_list( $actors_value );
                $actors       = implode( ', ', $actors_list );

                return array(
                        'id'           => (string) $video_id,
                        'title'        => LVJM_Item::clean_string( (string) $title ),
                        'desc'         => is_string( $desc ) ? wp_kses_post( $desc ) : '',
                        'tags'         => LVJM_Item::clean_string( $tags ),
                        'duration'     => (string) $duration,
                        'thumb_url'    => $thumb_url,
                        'thumbs_urls'  => $thumbs_urls,
                        'trailer_url'  => esc_url_raw( (string) $trailer_value ),
                        'video_url'    => esc_url_raw( (string) $video_url ),
                        'tracking_url' => esc_url_raw( (string) $tracking_url ),
                        'quality'      => is_string( $quality ) ? $quality : '',
                        'isHd'         => $is_hd,
                        'uploader'     => LVJM_Item::clean_string( (string) $uploader_value ),
                        'embed'        => '',
                        'actors'       => LVJM_Item::clean_string( $actors ),
                        'partner_cat'  => $this->category_slug,
                        'checked'      => true,
                        'grabbed'      => false,
                );
        }

        /**
         * Extract the first non-empty value from an array by trying multiple keys.
         * Supports dot notation for nested arrays/objects.
         *
         * @param array        $source Array to inspect.
         * @param array|string $keys   Keys to inspect.
         * @return mixed
         */
        private function extract_first_non_empty( array $source, $keys ) {
                $keys = (array) $keys;
                foreach ( $keys as $key ) {
                        $value = $this->get_value_from_path( $source, $key );
                        if ( is_array( $value ) ) {
                                if ( ! empty( $value ) ) {
                                        return $value;
                                }
                        } elseif ( null !== $value && '' !== trim( (string) $value ) ) {
                                return $value;
                        }
                }

                return '';
        }

        /**
         * Retrieve a value from an array using a dot-notated path.
         *
         * @param array  $source Array to inspect.
         * @param string $path   Path (supports dot notation).
         * @return mixed
         */
        private function get_value_from_path( array $source, $path ) {
                if ( '' === $path ) {
                        return '';
                }

                $segments = explode( '.', $path );
                $value    = $source;

                foreach ( $segments as $segment ) {
                        if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
                                $value = $value[ $segment ];
                        } elseif ( is_object( $value ) && isset( $value->$segment ) ) {
                                $value = $value->$segment;
                        } else {
                                return '';
                        }
                }

                return $value;
        }

        /**
         * Convert a raw duration value into seconds.
         *
         * @param mixed $value Raw duration value.
         * @return int
         */
        private function normalize_duration( $value ) {
                if ( is_numeric( $value ) ) {
                        return absint( $value );
                }

                if ( ! is_string( $value ) ) {
                        return 0;
                }

                $value = trim( $value );
                if ( '' === $value ) {
                        return 0;
                }

                if ( preg_match( '/^(\d+):(\d+):(\d+)$/', $value, $matches ) ) {
                        return ( (int) $matches[1] * 3600 ) + ( (int) $matches[2] * 60 ) + (int) $matches[3];
                }

                if ( preg_match( '/^(\d+):(\d+)$/', $value, $matches ) ) {
                        return ( (int) $matches[1] * 60 ) + (int) $matches[2];
                }

                if ( preg_match( '/^(\d+)$/', $value ) ) {
                        return absint( $value );
                }

                return 0;
        }

        /**
         * Sanitize a list of text values into a flat array of unique strings.
         *
         * @param mixed $value Raw value.
         * @return array
         */
        private function sanitize_text_list( $value ) {
                $values = array();

                if ( is_array( $value ) ) {
                        $values = $value;
                } elseif ( is_string( $value ) ) {
                        if ( false !== strpos( $value, ';' ) ) {
                                $values = explode( ';', $value );
                        } elseif ( false !== strpos( $value, ',' ) ) {
                                $values = explode( ',', $value );
                        } elseif ( false !== strpos( $value, '|' ) ) {
                                $values = explode( '|', $value );
                        } elseif ( '' !== $value ) {
                                $values = array( $value );
                        }
                }

                $sanitized = array();
                foreach ( (array) $values as $single ) {
                        if ( is_array( $single ) ) {
                                $single = implode( ' ', $single );
                        }
                        $single = trim( wp_strip_all_tags( (string) $single ) );
                        if ( '' !== $single ) {
                                $sanitized[] = $single;
                        }
                }

                return array_values( array_unique( $sanitized ) );
        }

        /**
         * Sanitize a list of URLs into an array.
         *
         * @param mixed $value Raw value.
         * @return array
         */
        private function sanitize_url_list( $value ) {
                $values = array();

                if ( is_array( $value ) ) {
                        $values = $value;
                } elseif ( is_string( $value ) ) {
                        if ( false !== strpos( $value, ';' ) || false !== strpos( $value, ',' ) ) {
                                $values = preg_split( '/[;,]/', $value );
                        } elseif ( '' !== $value ) {
                                $values = array( $value );
                        }
                }

                $urls = array();
                foreach ( (array) $values as $single ) {
                        if ( is_array( $single ) ) {
                                if ( isset( $single['url'] ) ) {
                                        $single = $single['url'];
                                } elseif ( isset( $single['src'] ) ) {
                                        $single = $single['src'];
                                } else {
                                        $single = reset( $single );
                                }
                        }
                        $single = esc_url_raw( trim( (string) $single ) );
                        if ( '' !== $single ) {
                                $urls[] = $single;
                        }
                }

                return array_values( array_unique( $urls ) );
        }

        /**
         * Determine the appropriate orientation for the request.
         *
         * @return string
         */
        private function determine_orientation() {
                if ( isset( $this->params['sexualOrientation'] ) && '' !== $this->params['sexualOrientation'] ) {
                        return sanitize_text_field( (string) $this->params['sexualOrientation'] );
                }

                $category = '';
                if ( isset( $this->params['cat_s'] ) && '' !== $this->params['cat_s'] ) {
                        $category = (string) $this->params['cat_s'];
                } elseif ( isset( $this->params['category'] ) && '' !== $this->params['category'] ) {
                        $category = (string) $this->params['category'];
                }

                $category = strtolower( $category );
                if ( false !== strpos( $category, 'shemale' ) || false !== strpos( $category, 'trans' ) ) {
                        return 'shemale';
                }

                if ( false !== strpos( $category, 'gay' ) ) {
                        return 'gay';
                }

                return 'straight';
        }

        /**
         * Determine the normalized partner category slug for metadata.
         *
         * @return string
         */
        private function determine_category_slug() {
                $category = '';
                if ( isset( $this->params['cat_s'] ) && '' !== $this->params['cat_s'] ) {
                        $category = (string) $this->params['cat_s'];
                } elseif ( isset( $this->params['category'] ) && '' !== $this->params['category'] ) {
                        $category = (string) $this->params['category'];
                }

                if ( '' === $category ) {
                        return '';
                }

                if ( function_exists( 'lvjm_normalize_category_slug' ) ) {
                        return lvjm_normalize_category_slug( $category );
                }

                return sanitize_title( $category );
        }

        /**
         * Determine tags value passed to the API.
         *
         * @return string
         */
        private function determine_tags() {
                if ( isset( $this->params['tags'] ) && '' !== $this->params['tags'] ) {
                        return sanitize_text_field( (string) $this->params['tags'] );
                }

                if ( isset( $this->params['cat_s'] ) && '' !== $this->params['cat_s'] ) {
                        return (string) $this->params['cat_s'];
                }

                return '';
        }

        /**
         * Log debug messages when WP_DEBUG is enabled.
         *
         * @param string $message Message to log.
         * @param array  $context Contextual data.
         */
        private function log_debug( $message, array $context = array() ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        if ( ! empty( $context ) ) {
                                $message .= ' ' . wp_json_encode( $context );
                        }
                        error_log( '[WPS-LiveJasmin] ' . $message );
                }
        }

        /**
         * Mask sensitive values before logging.
         *
         * @param string $value Value to mask.
         * @return string
         */
        private function mask_value( $value ) {
                $value   = (string) $value;
                $length  = strlen( $value );
                $visible = min( 3, $length );
                $prefix  = substr( $value, 0, $visible );
                return $prefix . str_repeat( '*', max( 0, $length - $visible ) );
        }

        /**
         * Shorten large payloads before logging them.
         *
         * @param mixed $value Value to truncate.
         * @return string
         */
        private function truncate_for_log( $value ) {
                $encoded = wp_json_encode( $value );
                if ( ! is_string( $encoded ) ) {
                        return '';
                }

                if ( strlen( $encoded ) > 2000 ) {
                        return substr( $encoded, 0, 2000 ) . '…';
                }

                return $encoded;
        }

        /**
         * Get videos collected during the search.
         *
         * @return array
         */
        public function get_videos() {
                return $this->videos;
        }

        /**
         * Get metadata about the executed search.
         *
         * @return array
         */
        public function get_searched_data() {
                return $this->searched_data;
        }

        /**
         * Get errors collected during the search.
         *
         * @return array
         */
        public function get_errors() {
                return $this->errors;
        }

        /**
         * Determine whether the search finished with errors.
         *
         * @return bool
         */
        public function has_errors() {
                return ! empty( $this->errors );
        }

        /**
         * Retrieve already imported and removed video identifiers.
         *
         * @return array
         */
        private function get_partner_existing_ids() {
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
