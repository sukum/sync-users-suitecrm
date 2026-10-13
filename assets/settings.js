/**
 * Backbone models and views for SuiteCRM settings interactions.
 *
 * @package SyncSuiteCRMUsers
 */

window.syus_app = (function($, Backbone) {
    'use strict';

    function message(strings, key, fallback) {
        return strings[key] || fallback;
    }

    // read the initial state from a DOM element's data-state attribute
    function initialState($element) {
        return JSON.parse($element.attr('data-state') || '{}');
    }

    /** Adapt WordPress AJAX actions and response envelopes to model requests. */
    function SettingsApi(config) {
        this.request = (action, data, method) => {
            const result = $.Deferred();
            const xhr = $.ajax({
                url: config.ajax_url,
                type: method || 'POST',
                dataType: 'json',
                timeout: 30000,
                data: $.extend({}, data, { action, nonce: config.nonce })
            });

            xhr.done(response => {
                if (response?.success === true && response.data && typeof response.data === 'object') {
                    result.resolve(response.data);
                } else {
                    result.reject(response?.data || {});
                }
            }).fail((response, status) => {
                result.reject($.extend({}, response.responseJSON?.data, {
                    aborted: status === 'abort',
                    retryable: status === 'parsererror' || status === 'timeout' || response.status === 0 || response.status >= 500
                }));
            });

            return result.promise({ abort() { xhr.abort(); } });
        };
    }

    const ModulesModel = Backbone.Model.extend({
        defaults: {
            modules_contacts_enabled: false,
            modules_accounts_enabled: false,
            modules_leads_enabled: false,
            modules_users_enabled: false,
        },

        initialize() {
            this.disposed = false;
        },

        dispose() {
            this.disposed = true;
            this.off();
        }

    });

    const ModulesView = Backbone.View.extend({
        events: {
            'change input[data-module][type="checkbox"]': 'moduleEnableToggle'
        },

        initialize() {
            this.listenTo(this.model, 'change', this.render);
            this.render();
        },

        render() {
            this.$('input[data-module][type="checkbox"]').each((index, checkbox) => this.handleModule(checkbox));
            return this;
        },

        moduleEnableToggle(e) {
            const moduleCheckbox = e.currentTarget;
            this.model.set(moduleCheckbox.id, moduleCheckbox.checked);
        },

        handleModule(moduleCheckbox) {
            const moduleName = moduleCheckbox.dataset.module.toLowerCase();
            const isChecked = !!this.model.get(moduleCheckbox.id);
            // moduleCheckbox.checked = isChecked;
            const tr = moduleCheckbox.closest('tr');
            if (tr) {
                tr.style.opacity = isChecked ? '1' : '0.5';
            }
            this.$(`#modules_${moduleName}_mode_create_only, #modules_${moduleName}_mode_create_update`)
                .prop('disabled', !isChecked);
        }

    });

    const ConnectionModel = Backbone.Model.extend({
        defaults: {
            connection_status: 'not_configured',
            original_base_url: '',
            last_connected_label: '',
            last_error: '',
            testing: false,
            connecting: false,
            test_message: '',
            test_success: null,
            connect_message: '',
            connect_success: null
        },

        initialize(attributes, options) {
            this.api = options.api;
            this.strings = options.strings;
            this.request = null;
            this.disposed = false;
        },

        isBusy() {
            return this.get('testing') || this.get('connecting');
        },

        test(baseUrl) {
            if (this.isBusy() || this.disposed) return;
            this.set({ testing: true, test_message: '', test_success: null });
            this.request = this.api.request('syus_test_api', { base_url: baseUrl });
            this.request.done(data => testApiSuccess.call(this, data))
                .fail(error => testApiFail.call(this, error))
                .always(() => testApiAlways.call(this));
        },

        connect(credentials) {
            if (this.isBusy() || this.disposed) return;
            this.set({ connecting: true, connect_message: '', connect_success: null });
            // Credentials belong to this request; they are never stored in model attributes.
            const baseUrl = credentials.base_url;
            this.request = this.api.request('syus_connect', credentials);
            this.request.done(data => connectSuccess.call(this, data, baseUrl))
                .fail(error => connectFail.call(this, error))
                .always(() => connectAlways.call(this));
        },

        dispose() {
            this.disposed = true;
            if (this.request) this.request.abort();
            this.off();
        }
    });

    const ConnectionView = Backbone.View.extend({
        events: {
            'click #syus-test-api-btn': 'test',
            'click #syus-connect-btn': 'connect',
        },

        initialize(options) {
            this.strings = options.strings;
            this.listenTo(this.model, 'change', this.render);
            this.render();
        },

        test(event) {
            event.preventDefault();
            this.model.test(this.$('#syus_base_url').val());
        },

        connect(event) {
            event.preventDefault();
            if (this.model.isBusy()) return;
            const baseUrl = this.$('#syus_base_url').val();
            const sameCrm = this.$('#syus_same_crm').is(':checked');
            const originalUrl = this.model.get('original_base_url');

            if (originalUrl && baseUrl !== originalUrl && !sameCrm && !window.confirm(message(
                this.strings,
                'confirm_same_crm',
                'Changing SuiteCRM URL without checking "Same CRM" will purge outstanding queue items and reset all saved CRM record IDs. Do you wish to continue?'
            ))) {
                return;
            }

            this.model.connect({
                base_url: baseUrl,
                client_id: this.$('#syus_client_id').val(),
                client_secret: this.$('#syus_client_secret').val(),
                username: this.$('#syus_username').val(),
                password: this.$('#syus_password').val(),
                same_crm: sameCrm ? 1 : 0
            });
        },

        render() {
            const model = this.model;
            const status = model.get('connection_status');
            const labels = {
                connected: message(this.strings, 'connection_connected', 'Connected'),
                reconnect_required: message(this.strings, 'connection_reconnect_required', 'Reconnect Required'),
                not_configured: message(this.strings, 'connection_not_configured', 'Not Configured')
            };
            const colors = { connected: '#00a32a', reconnect_required: '#dba617', not_configured: '#787c82' };

            this.$('#syus-test-api-btn, #syus-connect-btn').prop('disabled', model.isBusy());
            this.$('#syus-test-api-spinner').toggleClass('is-active', model.get('testing'));
            this.$('#syus-connect-spinner').toggleClass('is-active', model.get('connecting'));
            this.$('#syus-test-api-result').text(model.get('test_message')).css('color',
                model.get('test_success') === null ? '' : (model.get('test_success') ? '#00a32a' : '#d63638'));
            this.$('#syus-connect-result').text(model.get('connect_message')).css('color',
                model.get('connect_success') === null ? '' : (model.get('connect_success') ? '#00a32a' : '#d63638'));
            this.$('#syus-connection-badge').text(labels[status] || status).css('background-color', colors[status] || '#787c82');
            this.$('#syus-last-connected').text(model.get('last_connected_label')).toggle(!!model.get('last_connected_label'));
            this.$('#syus-last-connection-error').toggle(!!model.get('last_error')).find('code').text(model.get('last_error'));
            return this;
        },
    });

    const BulkProgressModel = Backbone.Model.extend({
        defaults: { progress: null, stale: false, operation: '' },

        initialize(attributes, options) {
            this.api = options.api;
            this.strings = options.strings;
            this.pollInterval = options.pollInterval;
            this.pollTimer = null;
            this.pollRequest = null;
            this.commandRequest = null;
            this.pollGeneration = 0;
            this.disposed = false;
        },

        isRunning() {
            const progress = this.get('progress');
            return !!progress && progress.status === 'running';
        },

        isStale() {
            return this.isRunning() && this.get('stale');
        },

        schedulePoll() {
            window.clearTimeout(this.pollTimer);
            this.pollTimer = null;
            if (this.disposed || this.pollRequest || this.get('operation') || !this.isRunning() || this.isStale()) return;
            this.pollTimer = window.setTimeout(() => this.poll(), this.pollInterval);
        },

        stopPolling() {
            window.clearTimeout(this.pollTimer);
            this.pollTimer = null;
            this.pollGeneration++;
            const request = this.pollRequest;
            this.pollRequest = null;
            if (request) request.abort();
        },

        poll() {
            this.pollTimer = null;
            if (this.disposed || this.pollRequest || this.get('operation') || !this.isRunning() || this.isStale()) return;
            const poll_status = {
                generation: this.pollGeneration,
                succeeded: false
            };
            this.pollRequest = this.api.request('syus_poll_progress', {}, 'GET');
            this.pollRequest
                .done(data => bulkProgressSuccess.call(this, data, poll_status))
                .fail(error => bulkProgressFail.call(this, error, poll_status))
                .always(() => bulkProgressAlways.call(this, poll_status));
        },

        perform(action, operation, data, successMessage, failureMessage, onSuccess) {
            if (this.disposed || this.get('operation')) return;
            // Suspend polling so an older response cannot overwrite this command's state.
            this.stopPolling();
            this.set('operation', operation);
            this.commandRequest = this.api.request(action, data);
            this.commandRequest.done(response => {
                if (this.disposed) return;
                if (onSuccess.call(this, response) === false) {
                    this.trigger('notice', failureMessage, 'error');
                    return;
                }
                this.trigger('notice', response.message || successMessage, 'success');
            }).fail(error => {
                if (this.disposed) return;
                this.trigger('notice', error.message || failureMessage, 'error');
            }).always(() => {
                if (this.disposed) return;
                this.commandRequest = null;
                this.set('operation', '');
                this.schedulePoll();
            });
        },

        start() {
            if (this.isRunning() && !this.isStale()) return;
            this.perform('syus_start_bulk', 'starting', {},
                message(this.strings, 'bulk_started', 'Bulk synchronization initiated in background.'),
                message(this.strings, 'bulk_start_failed', 'Failed to start bulk synchronization.'),
                function(data) {
                    if (!data.progress || !data.progress.run_id) return false;
                    this.set({ progress: data.progress, stale: !!data.stale });
                });
        },

        cancel() {
            const progress = this.get('progress');
            if (!this.isRunning() || this.isStale() || progress.cancel_requested) return;
            this.perform('syus_cancel_bulk', 'cancelling', { run_id: progress.run_id },
                message(this.strings, 'bulk_cancel_requested', 'Cancellation requested. Processing will halt at the next user boundary.'),
                message(this.strings, 'bulk_cancel_failed', 'Failed to request cancellation.'),
                function(data) {
                    if (!data.progress || !data.progress.run_id) return false;
                    this.set({ progress: data.progress, stale: !!data.stale });
                });
        },

        clearRun() {
            if (!this.isStale()) return;
            this.perform('syus_clear_bulk', 'clearing', { run_id: this.get('progress').run_id },
                message(this.strings, 'bulk_cleared', 'Bulk synchronization progress cleared.'),
                message(this.strings, 'bulk_clear_failed', 'Failed to clear bulk synchronization progress.'),
                function() { this.set({ progress: null, stale: false }); });
        },

        dispose() {
            this.disposed = true;
            this.stopPolling();
            if (this.commandRequest) this.commandRequest.abort();
            this.off();
        }
    });

    const BulkProgressView = Backbone.View.extend({
        events: {
            'click #syus-start-bulk-btn': 'start',
            'click #syus-cancel-bulk-btn': 'cancel',
            'click #syus-clear-bulk-btn': 'clearRun'
        },

        initialize(options) {
            this.strings = options.strings;
            this.listenTo(this.model, 'change', this.render);
            this.listenTo(this.model, 'notice', options.notice);
            this.render();
        },

        start(event) {
            event.preventDefault();
            this.model.start();
        },

        cancel(event) {
            event.preventDefault();
            if (this.model.get('operation') || !this.model.isRunning() || this.model.isStale()) return;
            if (window.confirm(message(this.strings, 'confirm_cancel', 'Are you sure you want to cancel the bulk sync?'))) {
                this.model.cancel();
            }
        },

        clearRun(event) {
            event.preventDefault();
            this.model.clearRun();
        },

        render() {
            const progress = this.model.get('progress');
            const operation = this.model.get('operation');
            const busy = !!operation;
            const running = this.model.isRunning();
            const stale = this.model.isStale();
            const snapshot = progress || {};
            const status = snapshot.status || 'idle';
            const labels = {
                idle: message(this.strings, 'bulk_idle', 'IDLE'),
                running: message(this.strings, 'bulk_running', 'RUNNING'),
                completed: message(this.strings, 'bulk_completed', 'COMPLETED'),
                cancelled: message(this.strings, 'bulk_cancelled', 'CANCELLED'),
                interrupted: message(this.strings, 'bulk_interrupted', 'INTERRUPTED')
            };
            const colors = { completed: '#00a32a', cancelled: '#787c82', interrupted: '#d63638' };
            const total = Math.max(0, parseInt(snapshot.total_users, 10) || 0);
            const processed = Math.max(0, parseInt(snapshot.processed_users, 10) || 0);
            const percent = total > 0 ? Math.min(100, Math.round(processed / total * 100)) : 0;

            this.$('#syus-start-bulk-btn').prop('disabled', busy || (running && !stale)).text(operation === 'starting'
                ? message(this.strings, 'starting_bulk', 'Starting bulk sync...')
                : message(this.strings, 'start_bulk', 'Sync all existing users'));
            this.$('#syus-cancel-bulk-btn').toggle(running && !stale).prop('disabled', busy || !!snapshot.cancel_requested)
                .text(operation === 'cancelling' || snapshot.cancel_requested
                    ? message(this.strings, 'cancelling_bulk', 'Requesting cancellation...')
                    : message(this.strings, 'cancel_bulk', 'Cancel sync'));
            this.$('#syus-clear-bulk-btn').toggle(stale).prop('disabled', busy).text(operation === 'clearing'
                ? message(this.strings, 'clearing', 'Clearing...')
                : message(this.strings, 'clear_bulk', 'Clear sync'));
            this.$('#syus-bulk-spinner').toggleClass('is-active', busy);
            this.$('#syus-bulk-panel').toggle(!!progress);
            this.$('#syus-bulk-stale-notice').toggle(stale);
            this.$('#syus-bulk-status-badge').text(stale
                ? message(this.strings, 'bulk_stalled', 'STALLED / INTERRUPTED')
                : (labels[status] || status.toUpperCase())).css('background-color', stale ? '#dba617' : (colors[status] || '#2271b1'));
            this.$('#syus-bulk-progress-bar').css('width', `${percent}%`);
            this.$('#syus-stat-run-id code').text(snapshot.run_id || '—');
            this.$('#syus-stat-total-users').text(total);
            this.$('#syus-stat-processed-users').text(processed);
            this.$('#syus-stat-successes').text(snapshot.success_count || 0);
            this.$('#syus-stat-failures').text(snapshot.failure_count || 0);
            this.$('#syus-stat-skips').text(snapshot.skipped_count || 0);
            return this;
        }
    });

    const SettingsPageView = Backbone.View.extend({
        events: {
            'click .nav-tab-wrapper a.nav-tab': 'selectTab',
            'change #auto_sync_enabled': 'syncAutoModeState',
            'change input[name="auto_sync_mode"]': 'syncAutoModeState',
            'submit #syus-settings-form': 'save'
        },

        initialize(options) {
            this.$form = this.$('#syus-settings-form');
            const config = options.config || {};
            this.config = $.extend({}, config, {
                nonce: this.$form.find('input[name="nonce"]').val() || config.nonce
            });
            this.strings = this.config.strings || {};
            this.notice = options.notice;
            this.saveRequest = null;
            this.disposed = false;

            const api = new SettingsApi(this.config);
            const $connection = this.$form.find('#syus-tab-authentication');
            const $bulk = this.$form.find('#syus-tab-bulk');
            const $modules = this.$form.find('#syus-tab-modules');
            this.connectionModel = new ConnectionModel(initialState($connection), { api, strings: this.strings });
            this.modulesModel = new ModulesModel(initialState($modules));
            this.bulkModel = new BulkProgressModel(initialState($bulk), {
                api, strings: this.strings, pollInterval: this.config.poll_interval || 3000
            });
            this.connectionView = new ConnectionView({
                el: $connection, model: this.connectionModel, strings: this.strings
            });
            this.modulesView = new ModulesView({
                el: $modules, model: this.modulesModel
            });
            this.bulkView = new BulkProgressView({
                el: $bulk, model: this.bulkModel, strings: this.strings, notice: this.notice
            });

            const hash = window.location.hash;
            if (hash.startsWith('#tab-')) {
                this.switchTab(hash.slice(5));
            }
            this.syncAutoModeState();
            this.bulkModel.schedulePoll();
        },

        selectTab(event) {
            event.preventDefault();
            this.switchTab($(event.currentTarget).attr('data-tab'));
        },

        switchTab(tabId) {
            const $tabs = this.$('.nav-tab-wrapper a.nav-tab');
            const $tab = $tabs.filter((index, tab) => tab.getAttribute('data-tab') === tabId);
            const $contents = this.$('.syus-tab-content');
            const $content = $contents.filter((index, content) => content.id === `syus-tab-${tabId}`);
            if (!$tab.length || !$content.length) return;

            $tabs.removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            $contents.hide();
            $content.show();
            if (window.history.replaceState) {
                window.history.replaceState(null, null, `#tab-${tabId}`);
            }
        },

        syncAutoModeState() {
            const isEnabled = this.$('#auto_sync_enabled').is(':checked');
            this.$('#syus-auto-mode-fieldset input[type="radio"]').prop('disabled', !isEnabled);

            const isBatch = this.$('input[name="auto_sync_mode"]:checked').val() === 'batch';
            this.$('#batch_time_hour, #batch_time_minute').prop('disabled', !isEnabled || !isBatch);
            if (isBatch && isEnabled) {
                this.$('#syus-batch-time-row').show();
                this.$('#syus-batch-time-row').css('opacity', 1);
            } else {
                this.$('#syus-batch-time-row').css('opacity', isEnabled ? '0.5' : '0.3');
            }
        },

        setSaving(saving) {
            this.$('#syus-save-btn').prop('disabled', saving);
            this.$('#syus-save-spinner').toggleClass('is-active', saving);
        },

        save(event) {
            event.preventDefault();
            if (this.disposed || this.saveRequest) return;

            const formData = `${this.$form.serialize()}&action=syus_save_settings`;
            this.setSaving(true);
            this.saveRequest = $.post(this.config.ajax_url, formData);
            this.saveRequest.always(() => {
                if (this.disposed) return;
                this.saveRequest = null;
                this.setSaving(false);
            }).done(response => {
                if (this.disposed) return;
                if (response?.success) {
                    this.notice(response.data?.message || message(this.strings, 'saved', 'Settings saved successfully.'), 'success');
                } else {
                    this.notice(response?.data?.message || 'Failed to save settings.', 'error');
                }
            }).fail(xhr => {
                if (this.disposed) return;
                this.notice(xhr.responseJSON?.data?.message || 'Error communicating with server.', 'error');
            });
        },

        remove() {
            if (this.disposed) return this;
            this.disposed = true;
            if (this.saveRequest) this.saveRequest.abort();
            this.saveRequest = null;
            this.setSaving(false);
            this.connectionModel.dispose();
            this.modulesModel.dispose();
            this.bulkModel.dispose();
            [this.connectionView, this.modulesView, this.bulkView].forEach(view => {
                view.stopListening();
                view.undelegateEvents();
            });
            this.stopListening();
            this.undelegateEvents();
            if (this.$el.data('syusSettingsPageView') === this) {
                this.$el.removeData('syusSettingsPageView');
            }
            // Detach behavior while preserving the server-rendered WordPress markup.
            return this;
        }
    });

    window.SYUSSettings = {
        init(options) {
            const $element = $(options.el);
            // Accept the original form selector as well as the page wrapper.
            const $page = $element.is('#syus-settings-form')
                ? $element.closest('#syus-settings-page, .syus-wrap') : $element;
            if (!$page.length || !$page.find('#syus-settings-form').length) return null;

            const existing = $page.data('syusSettingsPageView');
            if (existing) return existing;
            const view = new SettingsPageView({ el: $page, config: options.config, notice: options.notice });
            $page.data('syusSettingsPageView', view);
            return view;
        }
    };

    function testApiSuccess(data) {
        if (this.disposed) return;
        this.set({
            test_message: data.message || message(this.strings, 'api_reachable', 'SuiteCRM REST V8 API is reachable.'),
            test_success: true
        });
    }

    function testApiFail(error) {
        if (this.disposed) return;
        this.set({
            test_message: error.message || message(this.strings, 'api_test_failed', 'API reachability test failed.'),
            test_success: false
        });
    }

    function testApiAlways() {
        if (this.disposed) return;
        this.request = null;
        this.set('testing', false);
    }

    function connectSuccess(data, baseUrl) {
        if (this.disposed) return;
        this.set({
            connection_status: data.connection_status || 'connected',
            original_base_url: baseUrl,
            last_connected_label: data.last_connected_label || '',
            last_error: '',
            connect_message: data.message || message(this.strings, 'connected', 'Connected successfully.'),
            connect_success: true
        });
    }

    function connectFail(error) {
        if (this.disposed) return;
        const errorMessage = error.message || message(this.strings, 'connect_failed', 'Connection failed.');
        this.set({
            connection_status: 'reconnect_required',
            last_error: errorMessage,
            connect_message: errorMessage,
            connect_success: false
        });
    }

    function connectAlways() {
        if (this.disposed) return;
        this.request = null;
        this.set('connecting', false);
    }

    function bulkProgressSuccess(data, poll_status) {
        if (this.disposed || poll_status.generation !== this.pollGeneration) return;
        if (data.progress === undefined) {
            this.trigger('notice', message(this.strings, 'poll_failed', 'Unable to retrieve bulk synchronization progress.'), 'error');
            return;
        }
        poll_status.succeeded = true;
        this.set({ progress: data.progress || null, stale: !!data.stale });
    }

    function bulkProgressFail(error, poll_status) {
        if (this.disposed || poll_status.generation !== this.pollGeneration || error.aborted) return;
        this.trigger('notice', error.message || message(this.strings, 'poll_failed', 'Unable to retrieve bulk synchronization progress.'), 'error');
    }

    function bulkProgressAlways(poll_status) {
        if (this.disposed || poll_status.generation !== this.pollGeneration) return;
        this.pollRequest = null;
        if (poll_status.succeeded) this.schedulePoll();
    }

    return {
        BulkProgressModel,
        BulkProgressView,
        ConnectionModel,
        ConnectionView,
        ModulesModel,
        ModulesView,
        SettingsPageView
    };
})(jQuery, Backbone);
