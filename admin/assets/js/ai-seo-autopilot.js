(function ($) {
        'use strict';

        $(function () {
                var $button = $( '#wps-ai-seo-run' );

                if ( ! $button.length ) {
                        return;
                }

                var data = $button.data();
                var nonce = data.nonce || '';
                var batch = parseInt( data.batch, 10 );
                var statusId = data.statusTarget || 'wps-ai-seo-status';
                var $status = $( '#' + statusId );
                var running = false;
                var page = 1;
                var optimized = 0;

                if ( ! batch || batch < 1 ) {
                        batch = 5;
                }

                var messages = window.wpsAiSeoAutopilotL10n || {};

                messages.start = messages.start || 'Starting AI SEO optimization…';
                messages.progress = messages.progress || 'Optimized %1$s posts (processed %2$s in current batch)…';
                messages.complete = messages.complete || 'SEO Autopilot completed for %s posts.';
                messages.error = messages.error || 'The SEO autopilot request failed. Please try again.';

                function setStatus( message, isError ) {
                        if ( ! $status.length ) {
                                return;
                        }

                        $status.removeClass( 'wps-ai-seo-status-error wps-ai-seo-status-success' );

                        if ( isError ) {
                                $status.addClass( 'wps-ai-seo-status-error' );
                        } else {
                                $status.addClass( 'wps-ai-seo-status-success' );
                        }

                        $status.text( message );
                }

                function finish( message, isError ) {
                        running = false;
                        $button.prop( 'disabled', false ).removeClass( 'updating-message' );
                        setStatus( message, isError );
                }

                function runBatch() {
                        if ( ! running ) {
                                return;
                        }

                        $button.addClass( 'updating-message' );

                        $.post( ajaxurl, {
                                action: 'wps_run_ai_seo_autopilot',
                                nonce: nonce,
                                page: page,
                                batch: batch
                        } )
                                .done( function ( response ) {
                                        if ( ! response || ! response.success ) {
                                                finish( ( response && response.data && response.data.message ) || messages.error, true );
                                                return;
                                        }

                                        var payload = response.data || {};
                                        optimized += payload.optimized || 0;
                                        var processed = payload.processed || 0;

                                        if ( payload.has_more ) {
                                                page += 1;
                                                setStatus( messages.progress.replace( '%1$s', optimized ).replace( '%2$s', processed ), false );
                                                runBatch();
                                        } else {
                                                finish( messages.complete.replace( '%s', optimized ), false );
                                        }
                                } )
                                .fail( function () {
                                        finish( messages.error, true );
                                } );
                }

                $button.on( 'click', function ( event ) {
                        event.preventDefault();

                        if ( running ) {
                                return;
                        }

                        running = true;
                        page = 1;
                        optimized = 0;

                        setStatus( messages.start, false );
                        $button.prop( 'disabled', true );

                        runBatch();
                } );
        } );
})( jQuery );
