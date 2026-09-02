(function ($) {
    'use strict';

    $(function () {
        var $secret = $('input[name$="[webhook_secret]"]');
        if (!$secret.length) {
            return;
        }

        var $row = $secret.closest('tr');
        var $btn = $('<button type="button" class="button button-secondary" style="margin-inline-start:8px;"></button>')
            .text(mcpAdmin.i18n.regenerate)
            .on('click', function () {
                if (!window.confirm(mcpAdmin.i18n.confirmRegenerate)) {
                    return;
                }
                var random = '';
                var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                for (var i = 0; i < 40; i++) {
                    random += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                $secret.val(random).trigger('change');
            });
        $secret.after($btn);
    });
})(jQuery);
