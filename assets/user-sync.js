/**
 * Backbone models and views for manual SuiteCRM user synchronization.
 *
 * @package SyncUsersSuitecrm
 */

(($, Backbone) => {
    'use strict';

    const ROW_RESET_DELAY = 4000;
    const STATUS_COLORS = { success: '#00a32a', failure: '#d63638', skipped: '#787c82' };

    const responseMessage = (response, fallback) => {
        const message = response?.data?.message;
        return typeof message === 'string' && message ? message : fallback;
    };

    const UserSyncModel = Backbone.Model.extend({
        idAttribute: 'user_id',

        defaults() {
            return { user_id: 0, busy: false, outcomes: null, error: '' };
        },

        initialize(attributes, { config }) {
            this.config = config;
            this.request = null;
            this.disposed = false;
        },

        synchronize(nonce = this.config.nonce) {
            if (this.disposed || this.get('busy')) return;

            this.set({ busy: true, outcomes: null, error: '' });
            // This WordPress action is a command, rather than REST model persistence.
            this.request = $.ajax({
                url: this.config.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: { action: 'syus_sync_user', user_id: this.id, nonce }
            });

            this.request.done(response => {
                if (this.disposed) return;
                const outcomes = response?.data?.outcomes;
                const validOutcomes = Array.isArray(outcomes) && outcomes.every(outcome =>
                    outcome && ['module', 'action', 'status'].every(key => typeof outcome[key] === 'string')
                );

                if (response?.success === true && validOutcomes) {
                    this.set('outcomes', outcomes);
                } else {
                    this.set('error', responseMessage(response, this.config.strings.sync_failed || 'Synchronization failed.'));
                }
            }).fail(xhr => {
                if (this.disposed) return;
                this.set('error', responseMessage(xhr.responseJSON,
                    this.config.strings.connection_error || 'Error communicating with synchronization engine.'
                ));
            }).always(() => {
                if (this.disposed) return;
                this.request = null;
                this.set('busy', false);
            });
        },

        dispose() {
            this.disposed = true;
            if (this.request) this.request.abort();
            this.request = null;
            this.off();
        }
    });

    const UserListSyncView = Backbone.View.extend({
        events: { 'click .syus-sync-user-row-btn': 'synchronize' },

        initialize({ config, getModel }) {
            this.config = config;
            this.getModel = getModel;
            this.rows = new Map();
        },

        synchronize(event) {
            event.preventDefault();
            const $link = $(event.currentTarget);
            let row = this.rows.get(event.currentTarget);

            if (!row) {
                row = {
                    $link,
                    model: this.getModel(Number($link.data('user-id'))),
                    label: $link.text(),
                    color: $link[0].style.color,
                    pointerEvents: $link[0].style.pointerEvents,
                    ariaDisabled: $link.attr('aria-disabled'),
                    resetTimer: null
                };
                this.rows.set(event.currentTarget, row);
                this.listenTo(row.model, 'change', () => this.renderRow(row));
            }

            if (row.model.get('busy')) {
                this.renderRow(row);
                return;
            }
            if (row.resetTimer !== null) return;
            row.model.synchronize($link.data('nonce') || this.config.nonce);
        },

        renderRow(row) {
            window.clearTimeout(row.resetTimer);
            row.resetTimer = null;
            const strings = this.config.strings;
            const busy = row.model.get('busy');
            const outcomes = row.model.get('outcomes');
            const error = row.model.get('error');

            if (!busy && outcomes === null && !error) {
                this.resetRow(row);
                return;
            }

            row.$link.attr('aria-disabled', 'true').css('pointer-events', 'none');
            if (busy) {
                row.$link.text(strings.row_syncing || 'Syncing...').css('color', row.color);
                return;
            }

            if (error) {
                row.$link.text(`${strings.row_failed || 'Failed'} (${error})`).css('color', STATUS_COLORS.failure);
            } else {
                const summary = outcomes.map(outcome => `${outcome.module}: ${outcome.status}`).join(', ');
                row.$link.text(`${strings.row_synced || 'Synced'} (${summary})`).css('color', row.color);
            }

            row.resetTimer = window.setTimeout(() => this.resetRow(row), ROW_RESET_DELAY);
        },

        resetRow(row) {
            window.clearTimeout(row.resetTimer);
            row.resetTimer = null;
            row.$link.text(row.label).css({ 'pointer-events': row.pointerEvents, color: row.color });
            if (row.ariaDisabled === undefined) row.$link.removeAttr('aria-disabled');
            else row.$link.attr('aria-disabled', row.ariaDisabled);
        },

        dispose() {
            this.undelegateEvents();
            this.stopListening();
            this.rows.forEach(row => this.resetRow(row));
            this.rows.clear();
        }
    });

    const UserProfileSyncView = Backbone.View.extend({
        events: { 'click #syus-sync-user-btn': 'synchronize' },

        initialize({ config }) {
            this.config = config;
            this.$button = this.$('#syus-sync-user-btn');
            this.$spinner = this.$('#syus-sync-user-spinner');
            this.$result = this.$('#syus-user-sync-result').attr('aria-live', 'polite');
            this.initialDisabled = this.$button.prop('disabled');
            this.listenTo(this.model, 'change', this.render);
        },

        synchronize(event) {
            event.preventDefault();
            this.model.synchronize(this.$button.data('nonce') || this.config.nonce);
        },

        render() {
            const busy = this.model.get('busy');
            const outcomes = this.model.get('outcomes');
            const error = this.model.get('error');
            this.$button.prop('disabled', busy || this.initialDisabled);
            this.$spinner.toggleClass('is-active', busy);
            this.$result.attr('aria-busy', String(busy));

            if (busy) {
                this.$result.stop(true, true).slideUp(150).empty();
            } else if (outcomes !== null) {
                this.$result.empty().append(this.renderOutcomes(outcomes)).stop(true, true).slideDown(200);
            } else if (error) {
                const $notice = $('<div>', { class: 'notice notice-error inline' }).append($('<p>').text(error));
                this.$result.empty().append($notice).stop(true, true).slideDown(200);
            }
            return this;
        },

        renderOutcomes(outcomes) {
            const strings = this.config.strings;
            const $card = $('<div>', { class: 'card' }).css({ padding: '12px 16px', margin: 0, background: '#fff' });
            $('<h4>').text(strings.sync_outcomes || 'Synchronization Outcomes:').css({ margin: '0 0 8px 0' }).appendTo($card);
            const $table = $('<table>', { class: 'widefat striped' }).css('margin', 0).appendTo($card);
            const $header = $('<tr>').appendTo($('<thead>').appendTo($table));
            Object.entries({ module: 'Module', action: 'Action', status: 'Status', details: 'Details' }).forEach(([key, label]) => {
                $('<th>').text(strings[`column_${key}`] || label).appendTo($header);
            });
            const $body = $('<tbody>').appendTo($table);

            outcomes.forEach(outcome => {
                const $row = $('<tr>').appendTo($body);
                $('<td>').append($('<strong>').text(outcome.module)).appendTo($row);
                $('<td>').text(outcome.action).appendTo($row);
                const $status = $('<span>').text(outcome.status.toUpperCase()).css({
                    display: 'inline-block', padding: '2px 6px', borderRadius: '3px', color: '#fff',
                    fontSize: '11px', fontWeight: 600, background: STATUS_COLORS[outcome.status] || '#787c82'
                });
                $('<td>').append($status).appendTo($row);
                const $details = $('<td>').appendTo($row);
                if (outcome.crm_record_id) {
                    $('<code>').text(`${strings.crm_id || 'CRM ID:'} ${outcome.crm_record_id}`).appendTo($details);
                    $details.append(' ');
                }
                if (outcome.details) $('<span>').text(outcome.details).appendTo($details);
                if (!outcome.crm_record_id && !outcome.details) $details.text('\u2014');
                this.updateMapping(outcome);
            });
            return $card;
        },

        updateMapping(outcome) {
            const hasMapping = outcome.status === 'success' || ['create', 'update'].includes(outcome.action);
            if (!outcome.crm_record_id || !hasMapping) return;
            const rowId = `syus-mapping-row-${outcome.module.toLowerCase()}`;
            const $row = this.$('#syus-user-mappings-tbody tr').filter((index, row) => row.id === rowId);
            $row.find('td:nth-child(2)').empty().append(
                $('<span>').text(this.config.strings.mapped || 'Mapped').css({ color: STATUS_COLORS.success, fontWeight: 600 })
            );
            $row.find('.syus-mapping-id-cell').empty().append($('<code>').text(outcome.crm_record_id));
        },

        dispose() {
            this.undelegateEvents();
            this.stopListening();
            this.$button.prop('disabled', this.initialDisabled);
            this.$spinner.removeClass('is-active');
            this.$result.stop(true, true).attr('aria-busy', 'false');
        }
    });

    let instance = null;

    const init = (config = window.syusUserSyncData || {}) => {
        if (instance) instance.dispose();
        const options = {
            ajax_url: config.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php',
            nonce: config.nonce || '',
            strings: config.strings || {}
        };
        const models = new Map();
        const views = [];
        const getModel = userId => {
            if (!models.has(userId)) models.set(userId, new UserSyncModel({ user_id: userId }, { config: options }));
            return models.get(userId);
        };
        const $list = $('#the-list');
        if ($list.length) views.push(new UserListSyncView({ el: $list, config: options, getModel }));
        $('.syus-user-sync-section').each((index, section) => {
            const $button = $(section).find('#syus-sync-user-btn');
            if ($button.length) {
                views.push(new UserProfileSyncView({
                    el: section, config: options, model: getModel(Number($button.data('user-id')))
                }));
            }
        });

        let disposed = false;
        const handle = {
            dispose() {
                if (disposed) return;
                disposed = true;
                // These elements belong to WordPress; detach views without removing their DOM.
                views.forEach(view => view.dispose());
                models.forEach(model => model.dispose());
                models.clear();
                if (instance === handle) instance = null;
            }
        };
        instance = handle;
        return handle;
    };

    window.SYUSUserSync = { init, dispose: () => { if (instance) instance.dispose(); } };
    $(() => init());
})(jQuery, Backbone);
