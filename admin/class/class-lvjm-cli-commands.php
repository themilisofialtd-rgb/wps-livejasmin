<?php
/**
 * WP-CLI commands for LiveJasmin plugin.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LVJM_CLI_Commands' ) ) {
    /**
     * Collection of WP-CLI helpers for the LiveJasmin integration.
     */
    class LVJM_CLI_Commands {
        /**
         * Migrate all "actors" taxonomy terms to "models".
         *
         * ## EXAMPLES
         *
         *     wp lvjm migrate-actors
         *
         * @return void
         */
        public static function migrate_actors_to_models() {
            if ( ! class_exists( '\\WP_CLI' ) ) {
                return;
            }

            $actors_taxonomy = 'actors';
            $models_taxonomy = 'models';

            if ( ! taxonomy_exists( $actors_taxonomy ) ) {
                \WP_CLI::warning( sprintf( 'The "%s" taxonomy does not exist.', $actors_taxonomy ) );
                return;
            }

            if ( ! taxonomy_exists( $models_taxonomy ) ) {
                \WP_CLI::error( sprintf( 'The "%s" taxonomy does not exist.', $models_taxonomy ) );
                return;
            }

            $custom_post_type = function_exists( 'xbox_get_field_value' ) ? xbox_get_field_value( 'lvjm-options', 'custom-video-post-type' ) : '';
            if ( empty( $custom_post_type ) ) {
                $custom_post_type = 'post';
            }

            $paged    = 1;
            $per_page = 100;
            $migrated = 0;
            $created  = 0;

            \WP_CLI::log( sprintf( 'Migrating performers from "%s" to "%s"...', $actors_taxonomy, $models_taxonomy ) );

            do {
                $query = new \WP_Query(
                    array(
                        'post_type'      => $custom_post_type,
                        'posts_per_page' => $per_page,
                        'paged'          => $paged,
                        'post_status'    => 'any',
                        'fields'         => 'ids',
                        'tax_query'      => array(
                            array(
                                'taxonomy' => $actors_taxonomy,
                                'operator' => 'EXISTS',
                            ),
                        ),
                    )
                );

                if ( ! $query->have_posts() ) {
                    break;
                }

                foreach ( $query->posts as $post_id ) {
                    $terms = wp_get_object_terms( $post_id, $actors_taxonomy );
                    if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        continue;
                    }

                    $target_term_ids = array();

                    foreach ( $terms as $term ) {
                        $target = term_exists( $term->slug, $models_taxonomy );
                        if ( ! $target ) {
                            $target = wp_insert_term(
                                $term->name,
                                $models_taxonomy,
                                array(
                                    'slug' => $term->slug,
                                )
                            );
                            if ( is_wp_error( $target ) ) {
                                \WP_CLI::warning( sprintf( 'Could not migrate term "%s": %s', $term->name, $target->get_error_message() ) );
                                continue;
                            }
                            ++$created;
                        }

                        $target_term_ids[] = is_array( $target ) ? (int) $target['term_id'] : (int) $target;
                    }

                    if ( ! empty( $target_term_ids ) ) {
                        wp_add_object_terms( $post_id, $target_term_ids, $models_taxonomy );
                        wp_remove_object_terms( $post_id, wp_list_pluck( $terms, 'term_id' ), $actors_taxonomy );
                        ++$migrated;
                    }
                }

                ++$paged;
                wp_cache_flush();
            } while ( $paged <= $query->max_num_pages );

            \WP_CLI::success( sprintf( 'Migrated %d posts. Created %d new model terms.', $migrated, $created ) );
        }
    }
}
