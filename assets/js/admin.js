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

        // Handle GitHub Download / Update
        $(document).on('click', '#central-logger-download-github-btn, #central-logger-github-download-plugin-link', function (e) {
            e.preventDefault();

            if (typeof centralLoggerData === 'undefined' || !centralLoggerData.ajaxUrl) {
                return;
            }

            if (centralLoggerData.confirmDownloadText && !window.confirm(centralLoggerData.confirmDownloadText)) {
                return;
            }

            var $btn = $('#central-logger-download-github-btn');
            var $link = $('#central-logger-github-download-plugin-link');
            var $status = $('#central-logger-github-download-status');

            if ($btn.length) {
                $btn.prop('disabled', true);
            }
            if ($link.length) {
                $link.css({ 'pointer-events': 'none', 'opacity': '0.5' });
            }

            if ($status.length) {
                $status.html('<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span> <span style="color: #64748b; font-weight: 600;">' + (centralLoggerData.downloadingText || 'Downloading & installing update...') + '</span>');
            }

            $.post(centralLoggerData.ajaxUrl, {
                action: 'central_logger_download_from_github',
                nonce: centralLoggerData.githubNonce
            }, function (response) {
                if ($btn.length) {
                    $btn.prop('disabled', false);
                }
                if ($link.length) {
                    $link.css({ 'pointer-events': 'auto', 'opacity': '1' });
                }

                if (response && response.success) {
                    var msg = (response.data && response.data.message) ? response.data.message : 'Successfully downloaded update!';
                    if ($status.length) {
                        $status.html('<div style="padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-weight: 600; display: inline-block;">✓ ' + msg + '</div>');
                    } else {
                        window.alert('✓ ' + msg);
                    }
                } else {
                    var errMsg = (response && response.data && response.data.message) ? response.data.message : 'Failed to download update from GitHub.';
                    if ($status.length) {
                        $status.html('<div style="padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-weight: 600; display: inline-block;">✕ ' + errMsg + '</div>');
                    } else {
                        window.alert('✕ ' + errMsg);
                    }
                }
            }).fail(function (xhr) {
                if ($btn.length) {
                    $btn.prop('disabled', false);
                }
                if ($link.length) {
                    $link.css({ 'pointer-events': 'auto', 'opacity': '1' });
                }

                var errText = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Server communication error.';
                if ($status.length) {
                    $status.html('<div style="padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-weight: 600; display: inline-block;">✕ ' + errText + '</div>');
                } else {
                    window.alert('✕ ' + errText);
                }
            });
        });
    });
})(jQuery);
