(function ( global, $ ) {
    'use strict';

    if ( ! global || ! $ ) {
        return;
    }

    function create( options ) {
        var settings = $.extend( {
            $table: $( '#porkpress-dns-records' ),
            speak: function () {},
            __: ( typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.__ === 'function' ) ? wp.i18n.__ : function ( text ) {
                return text;
            }
        }, options || {} );

        var $table = settings.$table;
        var speak = settings.speak;
        var __ = settings.__;

        function sanitizeField( value ) {
            return $( '<div>' ).text( value == null ? '' : value ).text();
        }

        function handleError( err ) {
            var msg = err && err.message ? err.message :
                err && err.responseJSON && err.responseJSON.data ? err.responseJSON.data :
                __( 'Request failed', 'porkpress-ssl' );

            if ( typeof speak === 'function' ) {
                speak( msg );
            }
        }

        function render( records ) {
            if ( ! Array.isArray( records ) ) {
                handleError( { message: __( 'Received invalid record data.', 'porkpress-ssl' ) } );
                return;
            }

            var $tbody = $table.find( 'tbody' );
            $tbody.empty();

            records.forEach( function ( r ) {
                var $tr = $( '<tr>' ).attr( 'data-id', r.id );

                $( '<td>' ).append(
                    $( '<input>', { type: 'text', 'class': 'dns-type' } )
                        .val( sanitizeField( r.type ) )
                ).appendTo( $tr );

                $( '<td>' ).append(
                    $( '<input>', { type: 'text', 'class': 'dns-name' } )
                        .val( sanitizeField( r.name ) )
                ).appendTo( $tr );

                $( '<td>' ).append(
                    $( '<input>', { type: 'text', 'class': 'dns-content' } )
                        .val( sanitizeField( r.content ) )
                ).appendTo( $tr );

                $( '<td>' ).append(
                    $( '<input>', { type: 'number', 'class': 'dns-ttl' } )
                        .val( sanitizeField( r.ttl ) )
                ).appendTo( $tr );

                var $actions = $( '<td>' );
                $( '<button>', { 'class': 'button dns-update' } )
                    .text( __( 'Update', 'porkpress-ssl' ) )
                    .appendTo( $actions );
                $( '<button>', { 'class': 'button dns-delete' } )
                    .text( __( 'Delete', 'porkpress-ssl' ) )
                    .appendTo( $actions );
                $tr.append( $actions );

                $tbody.append( $tr );
            } );

            var $addRow = $( '<tr>' ).addClass( 'dns-add' );
            $( '<td>' ).append(
                $( '<input>', { type: 'text', 'class': 'dns-type' } )
            ).appendTo( $addRow );
            $( '<td>' ).append(
                $( '<input>', { type: 'text', 'class': 'dns-name' } )
            ).appendTo( $addRow );
            $( '<td>' ).append(
                $( '<input>', { type: 'text', 'class': 'dns-content' } )
            ).appendTo( $addRow );
            $( '<td>' ).append(
                $( '<input>', { type: 'number', 'class': 'dns-ttl' } ).val( 300 )
            ).appendTo( $addRow );
            var $addActions = $( '<td>' );
            $( '<button>', { 'class': 'button dns-add-btn' } )
                .text( __( 'Add', 'porkpress-ssl' ) )
                .appendTo( $addActions );
            $addRow.append( $addActions );
            $tbody.append( $addRow );
        }

        function send( action, data, successMsg ) {
            return wp.ajax.post( 'porkpress_dns_' + action, $.extend( {
                nonce: porkpressDNS.nonce,
                domain: porkpressDNS.domain
            }, data ) )
                .done( function ( res ) {
                    if ( res && res.records ) {
                        render( res.records );
                    }
                    if ( successMsg && typeof speak === 'function' ) {
                        speak( successMsg );
                    }
                } )
                .fail( handleError );
        }

        return {
            sanitizeField: sanitizeField,
            render: render,
            handleError: handleError,
            send: send,
            $table: $table
        };
    }

    global.porkpressDnsTable = {
        create: create
    };
}( window, jQuery ));
