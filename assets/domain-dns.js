jQuery( function ( $ ) {
    var __ = wp.i18n.__;

    function speak( msg ) {
        if ( wp && wp.a11y && wp.a11y.speak ) {
            wp.a11y.speak( msg );
        }
    }

    /**
     * Escape potentially unsafe characters for string concatenation.
     *
     * @param {string} str String to escape.
     * @return {string} Escaped string.
     */
    function escapeHtml( str ) {
        return $( '<div>' ).text( str ).html();
    }

    /**
     * Sanitize field values prior to DOM insertion.
     *
     * @param {string|number} value Value to sanitize.
     * @return {string} Sanitized string.
     */
    function sanitizeField( value ) {
        return $( '<div>' ).text( value == null ? '' : value ).text();
    }

    function render( records ) {
        var $tbody = $( '#porkpress-dns-records tbody' );
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

    function handleError( err ) {
        var msg = err && err.message ? err.message :
            err && err.responseJSON && err.responseJSON.data ? err.responseJSON.data :
            __( 'Request failed', 'porkpress-ssl' );
        alert( msg );
        speak( msg );
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
                if ( successMsg ) {
                    speak( successMsg );
                }
            } )
            .fail( handleError );
    }

    var $table = $( '#porkpress-dns-records' );

    $table.on( 'click', '.dns-add-btn', function ( e ) {
        e.preventDefault();
        var $tr = $( this ).closest( 'tr' );
        var type = $tr.find( '.dns-type' ).val().trim();
        var name = $tr.find( '.dns-name' ).val().trim();
        var content = $tr.find( '.dns-content' ).val().trim();
        var ttlStr = $tr.find( '.dns-ttl' ).val().trim();
        var ttl = parseInt( ttlStr, 10 );
        if ( ! type || ! name || ! content || ttlStr === '' ) {
            speak( __( 'All fields are required.', 'porkpress-ssl' ) );
            return;
        }
        if ( isNaN( ttl ) ) {
            speak( __( 'TTL must be a number.', 'porkpress-ssl' ) );
            return;
        }
        send( 'add', { type: type, name: name, content: content, ttl: ttl }, __( 'Record added.', 'porkpress-ssl' ) );
    } );

    $table.on( 'click', '.dns-update', function ( e ) {
        e.preventDefault();
        var $tr = $( this ).closest( 'tr' );
        var type = $tr.find( '.dns-type' ).val().trim();
        var name = $tr.find( '.dns-name' ).val().trim();
        var content = $tr.find( '.dns-content' ).val().trim();
        var ttlStr = $tr.find( '.dns-ttl' ).val().trim();
        var ttl = parseInt( ttlStr, 10 );
        if ( ! type || ! name || ! content || ttlStr === '' ) {
            speak( __( 'All fields are required.', 'porkpress-ssl' ) );
            return;
        }
        if ( isNaN( ttl ) ) {
            speak( __( 'TTL must be a number.', 'porkpress-ssl' ) );
            return;
        }
        var id = $tr.data( 'id' );
        send( 'edit', { record_id: id, type: type, name: name, content: content, ttl: ttl }, __( 'Record updated.', 'porkpress-ssl' ) );
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
} );

