jQuery(document).ready(function($) {
    $('#fetch_url_info').on('click', function() {
        var url = $('#external_link_url').val();
        if (!url) {
            alert('Please enter a URL first');
            return;
        }

        $.ajax({
            url: externalLinksAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'fetch_url_info',
                url: url,
                nonce: externalLinksAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var message = 'Information fetched: ';
                    if (response.data.title) {
                        $('#title').val(response.data.title);
                        message += 'Title ';
                    }
                    if (response.data.date) {
                        $('#external_link_date').val(response.data.date);
                        message += 'Date ';
                    }
                    $('#url_info_message').html(message + '<br>Please verify the information and adjust if necessary.').css('color', 'green');
                } else {
                    $('#url_info_message').html('Failed to fetch info: ' + response.data + '<br>Please enter the information manually.').css('color', 'orange');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $('#url_info_message').html('AJAX error: ' + textStatus + ' - ' + errorThrown + '<br>Please enter the information manually.').css('color', 'red');
            }
        });
    });
});