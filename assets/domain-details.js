jQuery(function($){
    /**
     * Escape potentially unsafe characters for string concatenation.
     *
     * @param {string} str String to escape.
     * @return {string} Escaped string.
     */
    function escapeHtml(str){
        return $('<div>').text(str).html();
    }

    /**
     * Sanitize field values before inserting into the DOM.
     *
     * @param {string|number} value Value to sanitize.
     * @return {string} Sanitized string.
     */
    function sanitizeField(value){
        return $('<div>').text(value == null ? '' : value).text();
    }

    function render(records){
        var $tbody = $('#porkpress-dns-records tbody');
        $tbody.empty();
        if(!Array.isArray(records)){
            alert(porkpressDNS.i18n.error);
            return;
        }
        records.forEach(function(r){
            var $tr = $('<tr>').attr('data-id', r.id);

            $('<td>').append(
                $('<input>', { type: 'text', 'class': 'dns-type' })
                    .val( sanitizeField(r.type) )
            ).appendTo($tr);

            $('<td>').append(
                $('<input>', { type: 'text', 'class': 'dns-name' })
                    .val( sanitizeField(r.name) )
            ).appendTo($tr);

            $('<td>').append(
                $('<input>', { type: 'text', 'class': 'dns-content' })
                    .val( sanitizeField(r.content) )
            ).appendTo($tr);

            $('<td>').append(
                $('<input>', { type: 'number', 'class': 'dns-ttl' })
                    .val( sanitizeField(r.ttl) )
            ).appendTo($tr);

            var $actions = $('<td>');
            $('<button>', { 'class': 'button dns-update' })
                .text(porkpressDNS.i18n.update)
                .appendTo($actions);
            $('<button>', { 'class': 'button dns-delete' })
                .text(porkpressDNS.i18n.delete)
                .appendTo($actions);
            $tr.append($actions);

            $tbody.append($tr);
        });

        var $addRow = $('<tr>').addClass('dns-add');
        $('<td>').append(
            $('<input>', { type: 'text', 'class': 'dns-type' })
        ).appendTo($addRow);
        $('<td>').append(
            $('<input>', { type: 'text', 'class': 'dns-name' })
        ).appendTo($addRow);
        $('<td>').append(
            $('<input>', { type: 'text', 'class': 'dns-content' })
        ).appendTo($addRow);
        $('<td>').append(
            $('<input>', { type: 'number', 'class': 'dns-ttl' }).val(300)
        ).appendTo($addRow);
        var $addActions = $('<td>');
        $('<button>', { 'class': 'button dns-add-btn' })
            .text(porkpressDNS.i18n.add)
            .appendTo($addActions);
        $addRow.append($addActions);
        $tbody.append($addRow);
    }
    function send(action, data){
        data.action = 'porkpress_dns_'+action;
        data.nonce = porkpressDNS.nonce;
        data.domain = porkpressDNS.domain;
        $.post(porkpressDNS.ajaxUrl, data)
            .done(function(res){
                if(res.success){
                    render(res.data.records);
                }else{
                    alert(res.data || porkpressDNS.i18n.error);
                }
            })
            .fail(function(){
                alert(porkpressDNS.i18n.error);
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
    send('retrieve', {});
});
