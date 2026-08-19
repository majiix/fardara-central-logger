/**
 * Central Logger Admin JavaScript
 */
(function ($) {
    'use strict';

    $(function () {
        var $modal = $('#cl-context-modal');
        var $modalJson = $('#cl-modal-json-view code');
        var $copyBtn = $('#cl-modal-copy-btn');
        var currentRawJson = '';

        // Open JSON Modal
        $(document).on('click', '.cl-view-context-btn', function (e) {
            e.preventDefault();
            var raw = $(this).attr('data-context');
            var logId = $(this).attr('data-log-id');

            currentRawJson = raw;

            try {
                var parsed = JSON.parse(raw);
                $modalJson.text(JSON.stringify(parsed, null, 2));
            } catch (err) {
                $modalJson.text(raw);
            }

            $('#cl-modal-title').text(centralLoggerData.modalTitle + ' (#' + logId + ')');
            $modal.addClass('is-open').attr('aria-hidden', 'false');
        });

        // Close Modal
        function closeModal() {
            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $modalJson.empty();
            $copyBtn.text(centralLoggerData.copyText);
        }

        $(document).on('click', '.cl-modal-close, .cl-modal-close-btn, .cl-modal-overlay', function (e) {
            e.preventDefault();
            closeModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                closeModal();
            }
        });

        // Copy to clipboard
        $copyBtn.on('click', function (e) {
            e.preventDefault();
            var textToCopy = $modalJson.text();
            if (!navigator.clipboard) {
                var temp = $('<textarea>');
                $('body').append(temp);
                temp.val(textToCopy).select();
                document.execCommand('copy');
                temp.remove();
            } else {
                navigator.clipboard.writeText(textToCopy);
            }

            var originalHtml = $copyBtn.html();
            $copyBtn.text(centralLoggerData.copiedText);
            setTimeout(function () {
                $copyBtn.html(originalHtml);
            }, 2000);
        });

        // Expand/Collapse Long Message Preview
        $(document).on('click', '.cl-btn-toggle-msg', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $preview = $btn.siblings('.cl-message-preview');
            var full = $btn.attr('data-full-msg');

            $preview.text(full);
            $btn.remove();
        });
    });
})(jQuery);
