(function () {
    'use strict';

    var page = {
        root: null,
        state: { settings: {}, contexts: [], features: [], base_tier: 1 },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="combat-environment"]');
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

                if (action === 'combat-environment-admin-reload') { self.load(); return; }
                if (action === 'combat-environment-settings-save') { self.saveSettings(); return; }
                if (action === 'combat-environment-feature-save') { self.saveFeature(); return; }
                if (action === 'combat-environment-feature-reset') { self.resetForm(); return; }
                if (action === 'combat-environment-feature-edit') { self.edit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'combat-environment-feature-delete') { self.remove(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/combat-environment/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.settings = dataset.settings || {};
                self.state.contexts = Array.isArray(dataset.contexts) ? dataset.contexts : [];
                self.state.features = Array.isArray(dataset.features) ? dataset.features : [];
                self.state.base_tier = parseInt(dataset.base_combat_tier || '1', 10) || 1;
                self.render();
            });
        },

        render: function () {
            this.setField('environment_complexity_mode', this.state.settings.environment_complexity_mode || 'standard');
            this.setText('[data-role="combat-environment-base-tier"]', this.state.base_tier >= 2
                ? 'Narrative Combat e pronto per Tier 3.'
                : 'Narrative Combat e sotto Tier 2: il modulo restera sostanzialmente inattivo.');
            this.renderContexts();
            this.renderTable();
        },

        renderContexts: function () {
            var select = this.root.querySelector('[name="conflict_id"]');
            if (!select) { return; }
            var current = String(select.value || '');
            var html = '<option value="">Seleziona conflitto...</option>';
            for (var i = 0; i < this.state.contexts.length; i += 1) {
                var row = this.state.contexts[i] || {};
                html += '<option value="' + (parseInt(row.conflict_id || '0', 10) || 0) + '">' + this.escape(row.label || ('Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0))) + '</option>';
            }
            select.innerHTML = html;
            if (current && select.querySelector('option[value="' + current + '"]')) {
                select.value = current;
            }
        },

        renderTable: function () {
            var body = this.root.querySelector('[data-role="combat-environment-table"]');
            if (!body) { return; }
            if (!this.state.features.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted small">Nessuna feature registrata.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < this.state.features.length; i += 1) {
                var row = this.state.features[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.feature_name || '-') + '</div><div class="small text-muted">Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0) + '</div></td>'
                    + '<td>' + this.escape(row.zone_key || 'global') + '</td>'
                    + '<td class="text-center">' + this.escape(row.feature_type_label || row.feature_type || '-') + '</td>'
                    + '<td class="text-center">' + this.escape(row.state_label || row.state_key || '-') + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="combat-environment-feature-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="combat-environment-feature-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        saveSettings: function () {
            var self = this;
            this.post('/admin/combat-environment/settings/update', {
                environment_complexity_mode: this.fieldValue('environment_complexity_mode') || 'standard'
            }, function () {
                self.toast('Impostazione ambiente aggiornata.', 'success');
                self.load();
            });
        },

        saveFeature: function () {
            var payload = {
                id: parseInt(this.fieldValue('id') || '0', 10) || 0,
                conflict_id: parseInt(this.fieldValue('conflict_id') || '0', 10) || 0,
                feature_name: this.fieldValue('feature_name'),
                feature_type: this.fieldValue('feature_type') || 'utility',
                state_key: this.fieldValue('state_key') || 'active',
                zone_key: this.fieldValue('zone_key'),
                control_side_key: this.fieldValue('control_side_key'),
                description: this.fieldValue('description'),
                visibility_impact: parseInt(this.fieldValue('visibility_impact') || '0', 10) || 0,
                mobility_impact: parseInt(this.fieldValue('mobility_impact') || '0', 10) || 0,
                hazard_impact: parseInt(this.fieldValue('hazard_impact') || '0', 10) || 0,
                cover_impact: parseInt(this.fieldValue('cover_impact') || '0', 10) || 0,
                affordance_tags: this.fieldValue('affordance_tags')
            };

            if (!payload.conflict_id) {
                this.toast('Seleziona un conflitto.', 'warning');
                return;
            }
            if (!payload.feature_name) {
                this.toast('Nome feature obbligatorio.', 'warning');
                return;
            }

            var self = this;
            this.post('/admin/combat-environment/feature/save', payload, function () {
                self.toast('Feature ambientale salvata.', 'success');
                self.resetForm();
                self.load();
            });
        },

        edit: function (id) {
            for (var i = 0; i < this.state.features.length; i += 1) {
                var row = this.state.features[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) !== id) { continue; }
                this.setField('id', String(row.id || 0));
                this.setField('conflict_id', String(row.conflict_id || ''));
                this.setField('feature_name', row.feature_name || '');
                this.setField('feature_type', row.feature_type || 'utility');
                this.setField('state_key', row.state_key || 'active');
                this.setField('zone_key', row.zone_key || '');
                this.setField('control_side_key', row.control_side_key || '');
                this.setField('description', row.description || '');
                this.setField('visibility_impact', String(parseInt(row.visibility_impact || '0', 10) || 0));
                this.setField('mobility_impact', String(parseInt(row.mobility_impact || '0', 10) || 0));
                this.setField('hazard_impact', String(parseInt(row.hazard_impact || '0', 10) || 0));
                this.setField('cover_impact', String(parseInt(row.cover_impact || '0', 10) || 0));
                this.setField('affordance_tags', Array.isArray(row.affordance_tags) ? row.affordance_tags.join(', ') : '');
                return;
            }
        },

        remove: function (id) {
            var self = this;
            this.confirm('Elimina feature', 'Confermi l\'eliminazione della feature ambientale selezionata?', function () {
                self.post('/admin/combat-environment/feature/delete', { id: id }, function () {
                    self.toast('Feature ambientale eliminata.', 'success');
                    self.resetForm();
                    self.load();
                });
            });
        },

        resetForm: function () {
            var form = this.root.querySelector('[data-role="combat-environment-feature-form"]');
            if (!form) { return; }
            form.reset();
            this.setField('id', '0');
            this.setField('feature_type', 'cover');
            this.setField('state_key', 'active');
        },

        fieldValue: function (name) {
            var field = this.root.querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        },

        setField: function (name, value) {
            var field = this.root.querySelector('[name="' + name + '"]');
            if (field) { field.value = value == null ? '' : String(value); }
        },

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.textContent = String(value == null ? '' : value); }
        },

        post: function (url, payload, onSuccess) {
            var self = this;
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                this.toast('Servizio HTTP non disponibile.', 'danger');
                return;
            }
            window.Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') { onSuccess(response || {}); }
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        confirm: function (title, body, onConfirm) {
            if (typeof window.Dialog === 'function') {
                window.Dialog('warning', { title: title, body: '<p>' + this.escape(body) + '</p>' }, function () {
                    if (typeof onConfirm === 'function') { onConfirm(); }
                }).show();
                return;
            }
            if (window.confirm(body) && typeof onConfirm === 'function') {
                onConfirm();
            }
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

        escape: function (value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { page.init(); });
    } else {
        page.init();
    }
}());
