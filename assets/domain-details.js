jQuery(function($){
    function render(records){
        var $tbody = $('#porkpress-dns-records tbody');
        $tbody.empty();
        records.forEach(function(r){
            var row = '<tr data-id="'+r.id+'">'+
                '<td><input type="text" class="dns-type" value="'+r.type+'" /></td>'+
                '<td><input type="text" class="dns-name" value="'+r.name+'" /></td>'+
                '<td><input type="text" class="dns-content" value="'+r.content+'" /></td>'+
                '<td><input type="number" class="dns-ttl" value="'+r.ttl+'" /></td>'+
                '<td><button class="button dns-update">'+porkpressDNS.i18n.update+'</button> <button class="button dns-delete">'+porkpressDNS.i18n.delete+'</button></td>'+
            '</tr>';
            $tbody.append(row);
        });
        $tbody.append('<tr class="dns-add"><td><input type="text" class="dns-type" /></td><td><input type="text" class="dns-name" /></td><td><input type="text" class="dns-content" /></td><td><input type="number" class="dns-ttl" value="300" /></td><td><button class="button dns-add-btn">'+porkpressDNS.i18n.add+'</button></td></tr>');
    }
    function send(action, data){
        data.action = 'porkpress_dns_'+action;
        data.nonce = porkpressDNS.nonce;
        data.domain = porkpressDNS.domain;
        $.post(porkpressDNS.ajaxUrl, data, function(res){
            if(res.success){
                render(res.data.records);
            }else{
                alert(res.data);
            }
        });
    }
    $('#porkpress-dns-records').on('click','.dns-add-btn', function(e){
        e.preventDefault();
        var $tr = $(this).closest('tr');
        send('add', {
            type: $tr.find('.dns-type').val(),
            name: $tr.find('.dns-name').val(),
            content: $tr.find('.dns-content').val(),
            ttl: $tr.find('.dns-ttl').val()
        });
    });
    $('#porkpress-dns-records').on('click','.dns-update', function(e){
        e.preventDefault();
        var $tr = $(this).closest('tr');
        send('edit', {
            record_id: $tr.data('id'),
            type: $tr.find('.dns-type').val(),
            name: $tr.find('.dns-name').val(),
            content: $tr.find('.dns-content').val(),
            ttl: $tr.find('.dns-ttl').val()
        });
    });
    $('#porkpress-dns-records').on('click','.dns-delete', function(e){
        e.preventDefault();
        if(!confirm(porkpressDNS.i18n.confirmDelete)) return;
        var $tr = $(this).closest('tr');
        send('delete', {
            record_id: $tr.data('id')
        });
    });
});
