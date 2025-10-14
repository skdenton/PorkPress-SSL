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
        var initialAction = settings.initialAction;

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

        function getRowData( $tr ) {
            var type = $tr.find( '.dns-type' ).val().trim();
            var name = $tr.find( '.dns-name' ).val().trim();
            var content = $tr.find( '.dns-content' ).val().trim();
            var ttlStr = $tr.find( '.dns-ttl' ).val().trim();
            var ttl = parseInt( ttlStr, 10 );

            if ( ! type || ! name || ! content || ttlStr === '' ) {
                return { error: __( 'All fields are required.', 'porkpress-ssl' ) };
            }

            if ( isNaN( ttl ) ) {
                return { error: __( 'TTL must be a number.', 'porkpress-ssl' ) };
            }

            return {
                data: {
                    type: type,
                    name: name,
                    content: content,
                    ttl: ttl
                }
            };
        }

        function bindEvents() {
            $table.on( 'click', '.dns-add-btn', function ( e ) {
                e.preventDefault();
                var result = getRowData( $( this ).closest( 'tr' ) );
                if ( result.error ) {
                    speak( result.error );
                    return;
                }
                send( 'add', result.data, __( 'Record added.', 'porkpress-ssl' ) );
            } );

            $table.on( 'click', '.dns-update', function ( e ) {
                e.preventDefault();
                var $tr = $( this ).closest( 'tr' );
                var result = getRowData( $tr );
                if ( result.error ) {
                    speak( result.error );
                    return;
                }
                var id = $tr.data( 'id' );
                send( 'edit', $.extend( { record_id: id }, result.data ), __( 'Record updated.', 'porkpress-ssl' ) );
            } );

            $table.on( 'click', '.dns-delete', function ( e ) {
                e.preventDefault();
                if ( ! confirm( __( 'Delete this record?', 'porkpress-ssl' ) ) ) {
                    return;
                }
                var $tr = $( this ).closest( 'tr' );
                var id = $tr.data( 'id' );
                send( 'delete', { record_id: id }, __( 'Record deleted.', 'porkpress-ssl' ) );
            } );
        }

        bindEvents();

        if ( initialAction && initialAction.action ) {
            send( initialAction.action, initialAction.data || {}, initialAction.successMessage );
        }

        return {
            sanitizeField: sanitizeField,
            render: render,
            handleError: handleError,
            send: send,
            $table: $table,
            bindEvents: bindEvents
        };
    }

    global.porkpressDnsTable = {
        create: create
    };
}( window, jQuery ));
