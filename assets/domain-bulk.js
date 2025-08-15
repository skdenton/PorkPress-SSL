jQuery(function($){
    // Inject CSS for new UI elements
    var css = '<style type="text/css">' +
        '.toggle-subdomains .dashicons { vertical-align: middle !important; }' +
        '.subdomain-indent { display: inline-block; width: 28px; }' +
    '</style>';
    $('head').append(css);

    $('#porkpress-domain-actions').on('submit', function(e){
        e.preventDefault();
        var domains = $('input[name="domains[]"]:checked').map(function(){return $(this).val();}).get();
        var action = $('select[name="bulk_action"]').val();
        var site = $('input[name="site_name"]').val();
        if(!domains.length || !action){return;}
        var override = '';
        if(action === 'detach'){
            override = prompt(wp.i18n.__('Type CONFIRM to detach selected domains', 'porkpress-ssl'));
            if(override !== 'CONFIRM'){return;}
        } else if(action === 'attach'){
            override = prompt(wp.i18n.__('Type CONFIRM to override DNS check', 'porkpress-ssl'));
            if(override === null){return;}
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

    // Subdomain toggle logic
    $('#porkpress-domain-actions').on('click', '.toggle-subdomains', function(e) {
        e.preventDefault();
        var $button = $(this);
        var parentDomain = $button.closest('tr').data('domain');
        var $subdomainRows = $('tr.subdomain-row[data-parent="' + parentDomain + '"]');

        $subdomainRows.slideToggle(200);

        // Toggle arrow icon
        var $icon = $button.find('.dashicons');
        $icon.toggleClass('dashicons-arrow-right-alt2 dashicons-arrow-down-alt2');

        // Update aria-expanded attribute for accessibility
        var isExpanded = $button.attr('aria-expanded') === 'true';
        $button.attr('aria-expanded', !isExpanded);
    });
});
