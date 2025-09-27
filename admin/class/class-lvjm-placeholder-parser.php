<?php
/**
 * Helper to parse legacy template placeholders without eval().
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LVJM_Placeholder_Parser' ) ) {
    /**
     * Replace <%%> placeholders with safe values.
     */
    class LVJM_Placeholder_Parser {
        /**
         * Replace placeholders in a template string.
         *
         * @param string $template Template containing placeholders.
         * @param array  $context  Available variables for replacement.
         * @return string
         */
        public static function parse( $template, array $context = array() ) {
            return preg_replace_callback(
                '/<%(.+?)%>/s',
                function ( $matches ) use ( $context ) {
                    return self::resolve_expression( $matches[1], $context );
                },
                $template
            );
        }

        /**
         * Resolve a single placeholder expression.
         *
         * @param string $expression Placeholder contents.
         * @param array  $context    Context array.
         * @return string
         */
        private static function resolve_expression( $expression, array $context ) {
            $expression = trim( $expression );

            if ( preg_match( "/^get_partner_option\((\"|')(.*?)(\1)\)$/", $expression, $matches ) ) {
                $option  = $matches[2];
                $options = isset( $context['partner_options'] ) && is_array( $context['partner_options'] ) ? $context['partner_options'] : array();
                return isset( $options[ $option ] ) ? $options[ $option ] : '';
            }

            if ( preg_match( '/^\$this->([a-zA-Z0-9_]+)(.*)$/', $expression, $matches ) ) {
                $root = $matches[1];
                $path = $matches[2];
                if ( isset( $context[ $root ] ) ) {
                    return self::resolve_path( $context[ $root ], $path );
                }
            }

            if ( preg_match( '/^\$([a-zA-Z0-9_]+)(.*)$/', $expression, $matches ) ) {
                $root = $matches[1];
                $path = $matches[2];
                if ( isset( $context[ $root ] ) ) {
                    return self::resolve_path( $context[ $root ], $path );
                }
            }

            if ( preg_match( '/^"(.*)"$/s', $expression, $matches ) ) {
                return stripslashes( $matches[1] );
            }

            if ( preg_match( "/^'(.*)'$/s", $expression, $matches ) ) {
                return stripslashes( $matches[1] );
            }

            if ( is_numeric( $expression ) ) {
                return $expression;
            }

            return '';
        }

        /**
         * Resolve array/object access using bracket notation.
         *
         * @param mixed  $value Base value.
         * @param string $path  Path string such as ["foo"][0].
         * @return string
         */
        private static function resolve_path( $value, $path ) {
            if ( '' === $path ) {
                return self::scalar( $value );
            }

            preg_match_all( "/\[(\"([^\"]+)\"|'([^']+)'|([0-9]+))\]/", $path, $matches, PREG_SET_ORDER );
            foreach ( $matches as $match ) {
                $key = '';
                if ( '' !== $match[2] ) {
                    $key = $match[2];
                } elseif ( '' !== $match[3] ) {
                    $key = $match[3];
                } else {
                    $key = $match[4];
                }

                if ( is_array( $value ) && isset( $value[ $key ] ) ) {
                    $value = $value[ $key ];
                } elseif ( is_object( $value ) && isset( $value->{$key} ) ) {
                    $value = $value->{$key};
                } else {
                    return '';
                }
            }

            return self::scalar( $value );
        }

        /**
         * Convert a value to a scalar string.
         *
         * @param mixed $value Value to convert.
         * @return string
         */
        private static function scalar( $value ) {
            if ( is_scalar( $value ) ) {
                return (string) $value;
            }

            return '';
        }
    }
}
