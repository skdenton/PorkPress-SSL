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

    window.porkpressDnsTable.create( {
        $table: $( '#porkpress-dns-records' ),
        speak: speak,
        __: __,
        initialAction: {
            action: 'retrieve'
        }
    } );
} );
