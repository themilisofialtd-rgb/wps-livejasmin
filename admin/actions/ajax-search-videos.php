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

    $errors = array();
    $searched_data = array();

    $normalize_performer = static function ( $name ) {
        $name = (string) $name;

        return strtolower( preg_replace( '/[^a-z0-9]/', '', $name ) );
    };

    $extract_performer_set = static function ( $video ) {
        $names = array();

        if ( ! empty( $video['performers'] ) ) {
            if ( is_array( $video['performers'] ) ) {
                $names = $video['performers'];
            } elseif ( $video['performers'] instanceof \Traversable ) {
                $names = iterator_to_array( $video['performers'] );
            }
        }

        if ( empty( $names ) && ! empty( $video['models'] ) ) {
            if ( is_array( $video['models'] ) ) {
                $names = $video['models'];
            } elseif ( $video['models'] instanceof \Traversable ) {
                $names = iterator_to_array( $video['models'] );
            }
        }

        if ( empty( $names ) && ! empty( $video['lvjm_performer_candidates'] ) && is_array( $video['lvjm_performer_candidates'] ) ) {
            $names = $video['lvjm_performer_candidates'];
        }

        if ( empty( $names ) || ! is_array( $names ) ) {
            $actors = isset( $video['actors'] ) ? (string) $video['actors'] : '';
            if ( '' !== $actors ) {
                $names = array_map( 'trim', explode( ',', $actors ) );
            }
        }

        if ( empty( $names ) || ! is_array( $names ) ) {
            return array();
        }

        if ( isset( $names['data'] ) && is_array( $names['data'] ) ) {
            $names = $names['data'];
        } elseif ( is_object( $names ) && isset( $names->data ) && is_array( $names->data ) ) {
            $names = $names->data;
        } elseif ( $names instanceof \Traversable ) {
            $names = iterator_to_array( $names );
        }

        if ( empty( $names ) || ! is_array( $names ) ) {
            return array();
        }

        $collected = array();
        foreach ( $names as $entry ) {
            $value = '';
            if ( is_array( $entry ) ) {
                $keys = array( 'name', 'displayName', 'username', 'id' );
                foreach ( $keys as $key ) {
                    if ( isset( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) {
                        $value = (string) $entry[ $key ];
                        break;
                    }
                }
            } elseif ( is_object( $entry ) ) {
                $keys = array( 'name', 'displayName', 'username', 'id' );
                foreach ( $keys as $key ) {
                    if ( isset( $entry->$key ) && '' !== (string) $entry->$key ) {
                        $value = (string) $entry->$key;
                        break;
                    }
                }
            } else {
                $value = (string) $entry;
            }

            $value = trim( $value );
            if ( '' !== $value ) {
                $collected[] = $value;
            }
        }

        if ( empty( $collected ) ) {
            return array();
        }

        return array_values( array_unique( $collected ) );
    };
    // Force brutal loop if All Straight Categories is chosen
    if ( isset($params['cat_s']) && $params['cat_s'] === 'all_straight' ) {
        $params['multi_category_search'] = '1';
    }

    $videos = array();

    $is_multi_straight = isset($params['multi_category_search']) && $params['multi_category_search'] === '1';
    $performer   = isset( $params['performer'] ) ? sanitize_text_field( (string) $params['performer'] ) : '';
    $deep_search = isset( $params['deep_search'] ) && '1' === (string) $params['deep_search'];
    $search_name = '';
    if ( $deep_search ) {
        $search_name = isset( $params['search_name'] ) ? sanitize_text_field( (string) $params['search_name'] ) : '';
        $performer   = '';
    }

    if ( $deep_search ) {
        $limit = isset( $params['limit'] ) ? (int) $params['limit'] : 60;
        if ( $limit <= 0 ) {
            $limit = 60;
        }

        if ( '' === $search_name ) {
            $errors = array(
                'code'     => 'deep_search_missing_name',
                'message'  => __( 'Deep Search requires a performer or model name.', 'lvjm_lang' ),
                'solution' => __( 'Enter a name and try again.', 'lvjm_lang' ),
            );
        } else {
            $normalized_target = $normalize_performer( $search_name );

            $available_categories = LVJM()->get_ordered_categories();
            $straight_categories  = array();

            foreach ( (array) $available_categories as $category_entry ) {
                if ( ! isset( $category_entry['id'] ) ) {
                    continue;
                }

                if ( 'optgroup' === $category_entry['id'] ) {
                    if ( isset( $category_entry['name'] ) && 0 === strcasecmp( (string) $category_entry['name'], 'Straight' ) ) {
                        foreach ( (array) $category_entry['sub_cats'] as $sub_cat ) {
                            if ( empty( $sub_cat['id'] ) ) {
                                continue;
                            }
                            $straight_categories[ (string) $sub_cat['id'] ] = isset( $sub_cat['name'] ) ? (string) $sub_cat['name'] : (string) $sub_cat['id'];
                        }
                    }
                    continue;
                }

                $id    = (string) $category_entry['id'];
                $label = isset( $category_entry['name'] ) ? (string) $category_entry['name'] : $id;
                $haystack = strtolower( $id . ' ' . $label );
                if ( false !== strpos( $haystack, 'gay' ) || false !== strpos( $haystack, 'shemale' ) || false !== strpos( $haystack, 'trans' ) || false !== strpos( $haystack, 'ladyboy' ) ) {
                    continue;
                }
                $straight_categories[ $id ] = $label;
            }

            $videos            = array();
            $deep_summary      = array();
            $base_params       = $params;
            unset( $base_params['performer'] );
            $base_params['include_existing'] = true;
            $seen_video_ids    = array();

            foreach ( $straight_categories as $cat_id => $cat_label ) {
                if ( count( $videos ) >= $limit ) {
                    break;
                }

                $tag_params            = $base_params;
                $tag_params['category'] = $cat_id;
                $tag_params['cat_s']    = $cat_id;
                $remaining_limit        = $limit - count( $videos );
                $tag_params['limit']    = $remaining_limit > 60 ? 60 : max( 1, $remaining_limit );

                $search_videos = new LVJM_Search_Videos( $tag_params );

                if ( $search_videos->has_errors() ) {
                    continue;
                }

                $tag_matches = array();
                foreach ( (array) $search_videos->get_videos() as $video ) {
                    $candidates = $extract_performer_set( $video );

                    if ( empty( $candidates ) ) {
                        continue;
                    }

                    $matched = false;
                    foreach ( $candidates as $candidate ) {
                        if ( $normalize_performer( $candidate ) === $normalized_target ) {
                            $matched = true;
                            break;
                        }
                    }

                    if ( ! $matched ) {
                        continue;
                    }

                    $video_id = isset( $video['id'] ) ? (string) $video['id'] : '';

                    if ( '' !== $video_id && isset( $seen_video_ids[ $video_id ] ) ) {
                        continue;
                    }

                    if ( '' !== $video_id ) {
                        $seen_video_ids[ $video_id ] = true;
                    }

                    $video['source_tag'] = $cat_label;
                    $videos[]            = $video;
                    $tag_matches[]       = $video;

                    if ( count( $videos ) >= $limit ) {
                        break;
                    }
                }

                $matched_count = count( $tag_matches );
                error_log( sprintf( '[WPS-LiveJasmin] Search name: %s | Tag: %s | Videos matched: %d', $search_name, $cat_label, $matched_count ) );

                if ( $matched_count > 0 ) {
                    $deep_summary[] = array(
                        'name'  => $search_name,
                        'tag'   => $cat_label,
                        'count' => $matched_count,
                    );
                }
            }

            $searched_data = array(
                'deep_summary' => $deep_summary,
                'search_name'  => $search_name,
            );
        }
    } elseif ( $is_multi_straight ) {
        $straight_categories = ['69', 'Above Average', 'Amateur', 'Anal', 'Angry', 'Asian', 'Ass', 'Ass To mouth', 'Athletic', 'Auburn Hair', 'Babe', 'Bald', 'Ball Sucking', 'Bathroom', 'Bbc', 'BBW', 'Bdsm', 'Bed', 'Big Ass', 'Big Boobs', 'Big Booty', 'Big Breasts', 'Big Cock', 'Big Tists', 'Bizarre', 'Black Eyes', 'Black Girl', 'Black Hair', 'Blonde', 'Blond Hair', 'Blowjob', 'Blue Eyes', 'Blue Hair', 'Bondage', 'Boots', 'Booty', 'Bossy', 'Brown Eyes', 'Brown Hair', 'Brunette', 'Butt Plug', 'Cam Girl', 'Cam Porn', 'Cameltoe', 'Celebrity', 'Cfnm', 'Cheerleader', 'Clown Hair', 'Cock', 'College Girl', 'Cop', 'Cosplay', 'Cougar', 'Couple', 'Cowgirl', 'Creampie', 'Crew Cut', 'Cum', 'Cum On Tits', 'Cumshot', 'Curious', 'Cut', 'Cute', 'Dance', 'Deepthroat', 'Dilde', 'Dirty', 'Doctor', 'Doggy', 'Domination', 'Double Penetration', 'Ebony', 'Erotic', 'Eye Contact', 'Facesitting', 'Facial', 'Fake Tits', 'Fat Ass', 'Fetish', 'Fingering', 'Fire Red Hair', 'Fishnet', 'Fisting', 'Flirting', 'Foot Sex', 'Footjob', 'Fuck', 'Gag', 'Gaping', 'Gilf', 'Girl', 'Glamour', 'Glasses', 'Green Eyes', 'Grey Eyes', 'Group', 'Gym', 'Hairy', 'Handjob', 'Hard Cock', 'Hd', 'High Heels', 'Homemade', 'Homy', 'Hot', 'Hot Flirt', 'Housewife', 'Huge Cock', 'Huge Tits', 'Innocent', 'Interracial', 'Intim Piercing', 'Jeans', 'Kitchen', 'Ladyboy', 'Large Build', 'Latex', 'Latin', 'Latina', 'Leather', 'Lesbian', 'Lick', 'Lingerie', 'Live Sex', 'Long Hair', 'Long Nails', 'Machine', 'Maid', 'Massage', 'Masturbation', 'Mature', 'Milf', 'Missionary', 'Misstress', 'Moaning', 'Muscular', 'Muslim', 'Naked', 'Nasty', 'Natural Tits', 'Normal Cock', 'Normal Tits', 'Nurse', 'Nylon', 'Office', 'Oiled', 'Orange Hair', 'Orgasm', 'Orgy', 'Outdoor', 'Party', 'Pawg', 'Petite', 'Piercing', 'Pink Hair', 'Pissing', 'Pool', 'Pov', 'Pregnant', 'Princess', 'Public', 'punish', 'Pussy', 'Pvc', 'Quicky', 'Redhead', 'Remote Toy', 'Reverse Cowgirl', 'Riding', 'Rimjob', 'Roleplay', 'Romantic', 'Room', 'Rough', 'Schoolgirl', 'Scissoring', 'Scream', 'Secretary', 'Sensual', 'Sextoy', 'Sexy', 'Shaved', 'Short Girl', 'Short Hair', 'Shoulder Lenght Hair', 'Shy', 'Skinny', 'Slave', 'Sloppy', 'Slutty', 'Small Ass', 'Small Cock', 'Smoking', 'Solo', 'Sologirl', 'squirt', 'Stockings', 'Strap On', 'Stretching', 'Striptease', 'Stroking', 'Suck', 'Swallow', 'Tall', 'Tattoo', 'Teacher', 'Teasing', 'Teen', 'Treesome', 'Tight', 'Tiny Tits', 'Titjob', 'Toy', 'Trimmed', 'Uniform', 'Virgin', 'Watching', 'Wet', 'White', 'Lesbian'];

        $seen_ids = array();
        foreach ( $straight_categories as $cat ) {
            $params['category'] = $cat;
            $params['cat_s'] = $cat;
            $search_videos = new LVJM_Search_Videos( $params );

            if ( ! $search_videos->has_errors() ) {
                $new_videos = $search_videos->get_videos();
                foreach ($new_videos as $v) {
                    $vid = null;
                    if (is_array($v)) {
                        $vid = isset($v['id']) ? $v['id'] : null;
                    } elseif (is_object($v)) {
                        $vid = isset($v->id) ? $v->id : null;
                    }
                    if ($vid && !isset($seen_ids[$vid])) {
                        $videos[] = $v;
                        $seen_ids[$vid] = true;
                    }
                }

                $msg = '→ ' . $cat . ' (' . count($new_videos) . ' videos)';
                error_log('[WPS-LiveJasmin] Brutal search ' . $msg);
                if (!isset($GLOBALS['lvjm_debug'])) { $GLOBALS['lvjm_debug'] = array(); }
                $GLOBALS['lvjm_debug'][] = $msg;

            }
        }
    } else {
        $search_videos = new LVJM_Search_Videos( $params );
        if ( ! $search_videos->has_errors() ) {
            $videos = $search_videos->get_videos();
            $searched_data = $search_videos->get_searched_data();
        }
    }

    // Performer filtering
    if ( '' !== $performer && ! $deep_search ) {
        $filtered              = array();
        $normalized_performer = $normalize_performer( $performer );

        if ( ! function_exists( 'lvjm_get_embed_and_actors' ) ) {
            $actions_file = dirname( __FILE__ ) . '/ajax-get-embed-and-actors.php';
            if ( file_exists( $actions_file ) ) {
                require_once $actions_file;
            }
        }
        foreach ( (array) $videos as $v ) {
            $match = false;
            $names = $extract_performer_set( $v );

            if ( ! empty( $names ) ) {
                if ( empty( $v['actors'] ) ) {
                    $v['actors'] = implode( ', ', $names );
                }

                foreach ( $names as $name ) {
                    if ( $normalize_performer( $name ) === $normalized_performer ) {
                        $match = true;
                        break;
                    }
                }
            }

            if ( ! $match ) {
                $actors = isset( $v['actors'] ) ? (string) $v['actors'] : '';
                if ( '' !== $actors && false !== stripos( $actors, $performer ) ) {
                    $match = true;
                }
            }

            if ( ! $match && function_exists( 'lvjm_get_embed_and_actors' ) && isset( $v['id'] ) ) {
                try {
                    $more = lvjm_get_embed_and_actors( array( 'video_id' => $v['id'] ) );
                    if ( ! empty( $more['performer_name'] ) && false !== stripos( $more['performer_name'], $performer ) ) {
                        $match = true;
                        $v['actors'] = $more['performer_name'];
                    }
                } catch ( \Throwable $e ) {}
            }
            if ( $match ) {
                $filtered[] = $v;
            }
        }
        $videos = $filtered;
    }

    if ( ! $ajax_call ) {
        return $videos;
    }

    wp_send_json(array(
        'videos'        => $videos,
        'errors'        => $errors,
        'searched_data' => $searched_data,
        'deep_summary'  => isset( $searched_data['deep_summary'] ) ? $searched_data['deep_summary'] : array(),
        'deep_search'   => $deep_search,
        'search_name'   => $search_name,
    ));

    wp_die();
}
add_action( 'wp_ajax_lvjm_search_videos', 'lvjm_search_videos' );
