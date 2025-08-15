jQuery( function ( $ ) {
    var __ = wp.i18n.__;

    $( '.porkpress-alias-actions' ).on( 'click', 'a', function ( e ) {
        e.preventDefault();

        var link = $( this );
        var url = link.attr( 'href' );
        var action = link.data( 'action' );
        var message = __( 'Type CONFIRM to proceed:', 'porkpress-ssl' );

        if ( action === 'set-primary' || action === 'remove' ) {
            var confirmation = prompt( message );
            if ( confirmation === 'CONFIRM' ) {
                window.location.href = url + '&confirm=CONFIRM';
            }
        }
    } );
} );
