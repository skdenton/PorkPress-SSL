jQuery(function($){
    $('#porkpress-domain-actions').on('submit', function(e){
        e.preventDefault();
        var domains = $('input[name="domains[]"]:checked').map(function(){return $(this).val();}).get();
        var action = $('select[name="bulk_action"]').val();
        var site = $('input[name="site_name"]').val();
        if(!domains.length || !action){return;}
        var override = '';
        var confirmPattern = /^CONFIRM$/;
        if(action === 'detach'){
            var detachInput = prompt(wp.i18n.__('Type CONFIRM to detach selected domains', 'porkpress-ssl'));
            if(detachInput === null){return;}
            var trimmedDetach = detachInput.trim();
            if(!confirmPattern.test(trimmedDetach)){return;}
            override = 'CONFIRM';
        } else if(action === 'attach'){
            var attachInput = prompt(wp.i18n.__('Type CONFIRM to override DNS check', 'porkpress-ssl'));
            if(attachInput === null){return;}
            var trimmedAttach = attachInput.trim();
            if(!confirmPattern.test(trimmedAttach)){return;}
            override = 'CONFIRM';
        }
        var total = domains.length, processed = 0;
        var $progress = $('#porkpress-domain-progress');
        $progress.text('0/'+total);
        function next(){
            if(!domains.length){$progress.text(wp.i18n.__('Done', 'porkpress-ssl'));return;}
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
                    console.error(wp.i18n.__('Action failed', 'porkpress-ssl'), domain, resp.data);
                    alert(wp.i18n.sprintf(wp.i18n.__('Action failed for %1$s: %2$s', 'porkpress-ssl'), domain, resp.data));
                }
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
