/**
 * SuiteCRM User Sync — Administration Scripts
 *
 * Handles:
 * - Shared admin notices
 * - Settings page initialization
 * - Activity log row deletion and clearing
 *
 * @package SyncSuiteCRMUsers
 */

(function($) {
    'use strict';

    // Global configuration object (localized via ssuAdminData)
    const config = window.ssuAdminData || {
        ajax_url: window.ajaxurl || '/wp-admin/admin-ajax.php',
        nonce: '',
        poll_interval: 3000,
        strings: {}
    };

    /**
     * Helper: Display admin dismissible notice
     *
     * @param {string} msg
     * @param {string} type ('success', 'error', 'info', 'warning')
     * @param {string} containerSelector
     */
    function showNotice(msg, type, containerSelector) {
        type = type || 'success';
        const selector = containerSelector || '#ssu-notice-container';
        let $container = $(selector);

        if (!$container.length) {
            $container = $('<div id="ssu-notice-container" style="margin-top:10px;"></div>');
            $('.wrap h1').first().after($container);
        }

        const html = `<div class="notice notice-${type} is-dismissible" style="margin: 10px 0;"><p>${msg}</p></div>`;
        $container.html(html);

        // Make WordPress dismiss button work if wp is initialized
        if (window.wp?.updates?.noticeDismissHandler) {
            $container.find('.is-dismissible').each(function() {
                const $notice = $(this);
                if (!$notice.find('.notice-dismiss').length) {
                    const $btn = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
                    $btn.on('click', () => {
                        $notice.fadeTo(100, 0, () => {
                            $notice.slideUp(100, () => {
                                $notice.remove();
                            });
                        });
                    });
                    $notice.append($btn);
                }
            });
        }
    }

    /* ==========================================================================
       1. SETTINGS PAGE CONTROLS
       ========================================================================== */

    function initSettingsPage() {
        if (!$('#ssu-settings-page').length) {
            return;
        }

        window.SSUSettings.init({
            el: '#ssu-settings-page',
            config,
            notice: showNotice
        });
    }

    /* ==========================================================================
       2. ACTIVITY LOG PAGE ACTIONS
       ========================================================================== */

    function initActivityLogPage() {
        if (!$('.ssu-activity-log-wrap').length) {
            return;
        }

        // --- 2.1 Delete records shown on this page ---
        $('#ssu-delete-shown-logs-btn').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const rawIds = $btn.attr('data-ids');
            let ids = [];

            try {
                ids = JSON.parse(rawIds);
            } catch (err) {
                ids = [];
            }

            if (!ids || !ids.length) {
                return;
            }

            const confirmMsg = config.strings.confirm_delete_shown ||
                `Are you sure you want to delete the ${ids.length} records currently displayed on this page?`;

            if (!window.confirm(confirmMsg)) {
                return;
            }

            const $spinner = $('#ssu-log-action-spinner');
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(config.ajax_url, {
                action: 'ssu_delete_logs',
                ids,
                nonce: config.nonce
            }, res => {
                $spinner.removeClass('is-active');
                if (res.success) {
                    showNotice(res.data.message || 'Records deleted successfully.', 'success');
                    // Remove rows smoothly and update UI
                    ids.forEach(id => {
                        $(`#ssu-log-row-${id}`).fadeOut(300, function() {
                            $(this).remove();
                        });
                    });
                    setTimeout(() => {
                        window.location.reload();
                    }, 800);
                } else {
                    $btn.prop('disabled', false);
                    showNotice(res.data.message || 'Failed to delete records.', 'error');
                }
            }).fail(xhr => {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                const msg = xhr.responseJSON?.data?.message || 'Server error while deleting records.';
                showNotice(msg, 'error');
            });
        });

        // --- 2.2 Clear all logs ---
        $('#ssu-clear-all-logs-btn').on('click', function(e) {
            e.preventDefault();
            const confirmMsg = config.strings.confirm_clear_all ||
                'Are you sure you want to permanently clear ALL activity log records? This cannot be undone.';

            if (!window.confirm(confirmMsg)) {
                return;
            }

            const $btn = $(this);
            const $spinner = $('#ssu-log-action-spinner');
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(config.ajax_url, {
                action: 'ssu_clear_logs',
                nonce: config.nonce
            }, res => {
                $spinner.removeClass('is-active');
                if (res.success) {
                    showNotice(res.data.message || 'All activity logs cleared successfully.', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } else {
                    $btn.prop('disabled', false);
                    showNotice(res.data.message || 'Failed to clear logs.', 'error');
                }
            }).fail(xhr => {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                const msg = xhr.responseJSON?.data?.message || 'Server error while clearing logs.';
                showNotice(msg, 'error');
            });
        });
    }

    function initQueuePage() {
        const $page = $('.ssu-queue-wrap');
        if (!$page.length) {
            return;
        }
        $page.on('click', '.ssu-queue-delete, #ssu-clear-queue', function(e) {
            e.preventDefault();
            const clear = this.id === 'ssu-clear-queue';
            let ids = [];
            if (!clear) {
                try {
                    ids = JSON.parse($(this).attr('data-ids'));
                } catch (err) {
                    return;
                }
                if (!Array.isArray(ids) || !ids.length) {
                    return;
                }
            }
            if (!window.confirm(config.strings[clear ? 'confirm_clear_queue' : 'confirm_delete_queue'])) {
                return;
            }
            const $buttons = $page.find('button');
            const enabledButtons = $buttons.filter(':enabled');
            $buttons.prop('disabled', true);
            $('#ssu-queue-spinner').addClass('is-active');
            $.post(config.ajax_url, {
                action: clear ? 'ssu_clear_queue' : 'ssu_delete_queue',
                ids,
                nonce: config.nonce
            }).done(res => {
                if (res.success) {
                    window.location.reload();
                } else {
                    showNotice(res.data?.message || config.strings.queue_error, 'error');
                }
            }).fail(xhr => {
                showNotice(xhr.responseJSON?.data?.message || config.strings.queue_error, 'error');
            }).always(() => {
                enabledButtons.prop('disabled', false);
                $('#ssu-queue-spinner').removeClass('is-active');
            });
        });
    }

    /* ==========================================================================
       INITIALIZE ON DOM READY
       ========================================================================== */
    $(() => {
        initSettingsPage();
        initActivityLogPage();
        initQueuePage();
    });

})(jQuery);
