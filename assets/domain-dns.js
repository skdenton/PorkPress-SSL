jQuery(function($){
    $('#porkpress-domain-actions').on('click', '.porkpress-dns-toggle', function(){
        var $btn = $(this);
        var $row = $btn.closest('tr').next('.porkpress-dns-details');
        $row.stop(true, true).slideToggle(200);
        $btn.toggleClass('open');
        var expanded = $btn.hasClass('open');
        $btn.attr('aria-expanded', expanded);
        if(expanded){
            $btn.removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
        }else{
            $btn.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
        }
    });
});
