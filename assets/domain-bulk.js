jQuery(function($){
    $('#porkpress-domain-actions').on('submit', function(e){
        e.preventDefault();
        var domains = $('input[name="domains[]"]:checked').map(function(){return $(this).val();}).get();
        var action = $('select[name="bulk_action"]').val();
        var site = $('input[name="site_id"]').val();
        if(!domains.length || !action){return;}
        var override = '';
        if(action === 'detach'){
            override = prompt('Type CONFIRM to detach selected domains');
            if(override !== 'CONFIRM'){return;}
        }
        var total = domains.length, processed = 0;
        var $progress = $('#porkpress-domain-progress');
        $progress.text('0/'+total);
        function next(){
            if(!domains.length){$progress.text('Done');return;}
            var domain = domains.shift();
            $.post(porkpressBulk.ajaxUrl, {
                action: 'porkpress_ssl_bulk_action',
                nonce: porkpressBulk.nonce,
                domain: domain,
                bulk_action: action,
                site_id: site,
                override: override
            }, function(resp){
                processed++;
                if(!resp.success){console.error('Action failed', domain, resp.data);}
                $progress.text(processed + '/' + total);
                next();
            });
        }
        next();
    });
    $('#cb-select-all').on('change', function(){
        $('input[name="domains[]"]').prop('checked', this.checked);
    });
});
