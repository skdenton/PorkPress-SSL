jQuery( function ( $ ) {
    var __ = wp.i18n.__;

    function speak( msg ) {
        if ( wp && wp.a11y && wp.a11y.speak ) {
            wp.a11y.speak( msg );
        }
    }

    function render( records ) {
        var $tbody = $( '#porkpress-dns-records tbody' );
        $tbody.empty();
        records.forEach( function ( r ) {
            var row = '<tr data-id="' + r.id + '">' +
                '<td><input type="text" class="dns-type" value="' + r.type + '" /></td>' +
                '<td><input type="text" class="dns-name" value="' + r.name + '" /></td>' +
                '<td><input type="text" class="dns-content" value="' + r.content + '" /></td>' +
                '<td><input type="number" class="dns-ttl" value="' + r.ttl + '" /></td>' +
                '<td><button class="button dns-update">' + __( 'Update', 'porkpress-ssl' ) + '</button> ' +
                '<button class="button dns-delete">' + __( 'Delete', 'porkpress-ssl' ) + '</button></td>' +
                '</tr>';
            $tbody.append( row );
        } );
        $tbody.append( '<tr class="dns-add"><td><input type="text" class="dns-type" /></td><td><input type="text" class="dns-name" /></td><td><input type="text" class="dns-content" /></td><td><input type="number" class="dns-ttl" value="300" /></td><td><button class="button dns-add-btn">' + __( 'Add', 'porkpress-ssl' ) + '</button></td></tr>' );
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

