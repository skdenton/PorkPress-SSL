jQuery( function ( $ ) {
    var __ = wp.i18n.__;

    function speak( msg ) {
        if ( wp && wp.a11y && wp.a11y.speak ) {
            wp.a11y.speak( msg );
        }
    }

    if ( ! window.porkpressDnsTable || typeof window.porkpressDnsTable.create !== 'function' ) {
        return;
    }

    var dnsTable = window.porkpressDnsTable.create( {
        $table: $( '#porkpress-dns-records' ),
        speak: speak,
        __: __
    } );

    var $table = dnsTable.$table;
    var send = dnsTable.send;

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

