(function () {
    'use strict';

    var page = {
        root: null,
        state: { settings: {}, options: [], constraints: {}, lastUpdate: {} },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="narrative-combat"]');
            if (!this.root) {
                return;
            }

            this.bind();
            this.load();
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) {
                    return;
                }
                event.preventDefault();

                if (action === 'narrative-combat-settings-reload') {
                    self.load();
                    return;
                }
                if (action === 'narrative-combat-settings-save') {
                    self.save();
                }
            });

            this.root.addEventListener('change', function (event) {
                var target = event.target;
                if (!target || target.name !== 'combat_depth') {
                    return;
                }
                self.renderDescription();
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/narrative-combat/settings/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.settings = dataset.settings || {};
                self.state.options = Array.isArray(dataset.options) ? dataset.options : [];
                self.state.constraints = dataset.constraints || {};
                self.state.lastUpdate = dataset.last_update || {};
                self.render();
            });
        },

        save: function () {
            var self = this;
            this.post('/admin/narrative-combat/settings/update', {
                combat_depth: this.fieldValue('combat_depth') || '2'
            }, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.settings = dataset.settings || {};
                self.state.options = Array.isArray(dataset.options) ? dataset.options : [];
                self.state.constraints = dataset.constraints || {};
                self.state.lastUpdate = dataset.last_update || {};
                self.render();
                if (self.state.lastUpdate && self.state.lastUpdate.was_forced) {
                    self.toast('Tier 2 mantenuto attivo automaticamente per una dipendenza Tier 3.', 'warning');
                    return;
                }
                self.toast('Profondita combattimento aggiornata.', 'success');
            });
        },

        render: function () {
            this.renderOptions();
            this.setField('combat_depth', String(this.state.settings.combat_depth || 2));
            this.setText('[data-role="narrative-combat-effective-tier"]', 'Tier ' + String(this.state.settings.combat_depth || 2));
            this.renderConstraints();
            this.renderDescription();
        },

        renderOptions: function () {
            var select = this.root.querySelector('[name="combat_depth"]');
            if (!select) {
                return;
            }

            var html = '';
            for (var i = 0; i < this.state.options.length; i += 1) {
                var option = this.state.options[i] || {};
                html += '<option value="' + this.escapeAttr(option.value || '') + '"'
                    + (option.disabled ? ' disabled' : '')
                    + '>' + this.escapeHtml(option.label || ('Tier ' + String(option.value || ''))) + '</option>';
            }

            if (html) {
                select.innerHTML = html;
            }
        },

        renderConstraints: function () {
            var wrap = this.root.querySelector('[data-role="narrative-combat-tier-lock-wrap"]');
            var messageNode = this.root.querySelector('[data-role="narrative-combat-tier-lock-message"]');
            var message = this.state.constraints && this.state.constraints.message
                ? String(this.state.constraints.message)
                : '';

            if (wrap) {
                wrap.classList.toggle('d-none', !message);
            }
            if (messageNode) {
                messageNode.textContent = message || 'Tier 2 forzato da una estensione avanzata attiva.';
            }
        },

        renderDescription: function () {
            var selected = this.fieldValue('combat_depth');
            var description = '';
            for (var i = 0; i < this.state.options.length; i += 1) {
                var option = this.state.options[i] || {};
                if (String(option.value || '') === String(selected || '')) {
                    description = String(option.description || '');
                    break;
                }
            }
            this.setText('[data-role="narrative-combat-settings-description"]', description || 'Nessuna descrizione disponibile.');
        },

        fieldValue: function (name) {
            var field = this.root.querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        },

        setField: function (name, value) {
            var field = this.root.querySelector('[name="' + name + '"]');
            if (field) {
                field.value = value == null ? '' : String(value);
            }
        },

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) {
                node.textContent = String(value == null ? '' : value);
            }
        },

        post: function (url, payload, onSuccess) {
            var self = this;
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                this.toast('Servizio HTTP non disponibile.', 'danger');
                return;
            }
            window.Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response || {});
                }
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        toast: function (body, type) {
            if (window.Toast && typeof window.Toast.show === 'function') {
                window.Toast.show({ body: body, type: type || 'info' });
            }
        },

        errorMessage: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            return 'Operazione non riuscita.';
        },

        escapeHtml: function (value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        escapeAttr: function (value) {
            return this.escapeHtml(value);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            page.init();
        });
    } else {
        page.init();
    }
}());
