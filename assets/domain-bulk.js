jQuery(function($){
    const { __, sprintf } = wp.i18n;
    $('#porkpress-domain-actions').on('submit', function(e){
        e.preventDefault();
        var domains = $('input[name="domains[]"]:checked').map(function(){return $(this).val();}).get();
        var action = $('select[name="bulk_action"]').val();
        var site = $('input[name="site_name"]').val();
        if(!domains.length || !action){return;}
        var override = '';
        if(action === 'detach'){
            override = prompt(__('Type CONFIRM to detach selected domains', 'porkpress-ssl'));
            if(override !== 'CONFIRM'){return;}
        } else if(action === 'attach'){
            override = prompt(__('Type CONFIRM to override DNS check', 'porkpress-ssl'));
            if(override === null){return;}
        }
        var total = domains.length, processed = 0;
        var $progress = $('#porkpress-domain-progress');
        $progress.text(sprintf(__('%1$d/%2$d', 'porkpress-ssl'), 0, total));
        function next(){
            if(!domains.length){$progress.text(__('Done', 'porkpress-ssl'));return;}
            var domain = domains.shift();
            $.post(porkpressBulk.ajaxUrl, {
                action: 'porkpress_ssl_bulk_action',
                nonce: porkpressBulk.nonce,
                domain: domain,
                bulk_action: action,
                site_name: site,
                override: override
            }, function(resp){
                processed++;
                if(!resp.success){
                    console.error(__('Action failed', 'porkpress-ssl'), domain, resp.data);
                    alert(sprintf(__('Action failed for %1$s: %2$s', 'porkpress-ssl'), domain, resp.data));
                }
                $progress.text(sprintf(__('%1$d/%2$d', 'porkpress-ssl'), processed, total));
                next();
            });
        }
        next();
    });
    $('#cb-select-all').on('change', function(){
        $('input[name="domains[]"]').prop('checked', this.checked);
    });
});
