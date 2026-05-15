/* global clpVarnish, ajaxurl */
(function ($) {
    'use strict';

    var $button = $('#clp-varnish-test-connection');
    var $result = $('#clp-varnish-test-result');
    var $server = $('input[name="server"]');

    $button.on('click', function (e) {
        e.preventDefault();

        var server = $server.val().trim();
        if (!server) {
            $result.html('<span class="clp-test-error">' + clpVarnish.emptyServer + '</span>');
            return;
        }

        $button.prop('disabled', true).text(clpVarnish.testing);
        $result.html('');

        $.post(ajaxurl, {
            action: 'clp_varnish_test_connection',
            nonce:  clpVarnish.nonce,
            server: server,
        })
        .done(function (response) {
            var $span = $('<span>').text(response.data.message);
            if (response.success) {
                $span.addClass('clp-test-success').prepend('✓ ');
            } else {
                $span.addClass('clp-test-error').prepend('✗ ');
            }
            $result.empty().append($span);
        })
        .fail(function () {
            $result.html('<span class="clp-test-error">' + clpVarnish.requestFailed + '</span>');
        })
        .always(function () {
            $button.prop('disabled', false).text(clpVarnish.test);
        });
    });

}(jQuery));
