(function () {
    'use strict';

    var page = {
        root: null,
        state: { contexts: [], profiles: [], options: {}, baseTier: 1 },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="combat-ai"]');
            if (!this.root) { return; }
            this.bind();
            this.load();
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'combat-ai-admin-reload') { self.load(); return; }
                if (action === 'combat-ai-profile-save') { self.save(); return; }
                if (action === 'combat-ai-profile-reset') { self.reset(); return; }
                if (action === 'combat-ai-profile-edit') { self.edit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'combat-ai-profile-delete') { self.remove(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/combat-ai/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.contexts = Array.isArray(dataset.contexts) ? dataset.contexts : [];
                self.state.profiles = Array.isArray(dataset.profiles) ? dataset.profiles : [];
                self.state.options = dataset.options || {};
                self.state.baseTier = parseInt(dataset.base_combat_tier || '1', 10) || 1;
                self.render();
            });
        },

        render: function () {
            this.renderSelect('[name="conflict_id"]', this.state.contexts, 'conflict_id', 'label', 'Seleziona conflitto...');
            this.renderSelect('[name="behavior_key"]', this.state.options.behaviors || [], 'value', 'label', '');
            this.renderSelect('[name="automation_mode"]', this.state.options.automation_modes || [], 'value', 'label', '');
            this.renderSelect('[name="priority_focus"]', this.state.options.priority_focuses || [], 'value', 'label', '');
            this.setText('[data-role="combat-ai-tier-state"]', this.state.baseTier >= 2
                ? 'Narrative Combat e pronto per Combat AI.'
                : 'Narrative Combat e sotto Tier 2: il modulo restera inattivo.');
            this.renderTable();
        },

        renderSelect: function (selector, rows, valueKey, labelKey, placeholder) {
            var select = this.root.querySelector(selector);
            if (!select) { return; }
            var current = String(select.value || '');
            var html = placeholder ? '<option value="">' + this.escape(placeholder) + '</option>' : '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<option value="' + this.escape(row[valueKey] || '') + '">' + this.escape(row[labelKey] || '-') + '</option>';
            }
            select.innerHTML = html;
            if (current && select.querySelector('option[value="' + current + '"]')) {
                select.value = current;
            }
        },

        renderTable: function () {
            var body = this.root.querySelector('[data-role="combat-ai-table"]');
            if (!body) { return; }
            if (!this.state.profiles.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted small">Nessun profilo AI registrato.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < this.state.profiles.length; i += 1) {
                var row = this.state.profiles[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.character_name || ('PG #' + (parseInt(row.character_id || '0', 10) || 0))) + '</div><div class="small text-muted">Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0) + '</div></td>'
                    + '<td class="text-center">' + this.escape(row.behavior_key || '-') + '</td>'
                    + '<td class="text-center">' + this.escape(row.automation_mode || '-') + '</td>'
                    + '<td class="text-center">' + this.escape(row.priority_focus || '-') + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="combat-ai-profile-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="combat-ai-profile-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        save: function () {
            var payload = {
                id: parseInt(this.fieldValue('id') || '0', 10) || 0,
                conflict_id: parseInt(this.fieldValue('conflict_id') || '0', 10) || 0,
                character_id: parseInt(this.fieldValue('character_id') || '0', 10) || 0,
                behavior_key: this.fieldValue('behavior_key') || 'opportunist',
                automation_mode: this.fieldValue('automation_mode') || 'suggest_only',
                priority_focus: this.fieldValue('priority_focus') || 'balanced',
                notes: this.fieldValue('notes')
            };
            if (!payload.conflict_id || !payload.character_id) {
                this.toast('Conflitto e personaggio sono obbligatori.', 'warning');
                return;
            }

            var self = this;
            this.post('/admin/combat-ai/profile/save', payload, function () {
                self.toast('Profilo AI salvato.', 'success');
                self.reset();
                self.load();
            });
        },

        edit: function (id) {
            for (var i = 0; i < this.state.profiles.length; i += 1) {
                var row = this.state.profiles[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) !== id) { continue; }
                this.setField('id', String(row.id || 0));
                this.setField('conflict_id', String(row.conflict_id || ''));
                this.setField('character_id', String(row.character_id || ''));
                this.setField('behavior_key', row.behavior_key || 'opportunist');
                this.setField('automation_mode', row.automation_mode || 'suggest_only');
                this.setField('priority_focus', row.priority_focus || 'balanced');
                this.setField('notes', row.notes || '');
                return;
            }
        },

        remove: function (id) {
            var self = this;
            this.confirm('Elimina profilo', 'Confermi l\'eliminazione del profilo AI selezionato?', function () {
                self.post('/admin/combat-ai/profile/delete', { id: id }, function () {
                    self.toast('Profilo AI eliminato.', 'success');
                    self.reset();
                    self.load();
                });
            });
        },

        reset: function () {
            var form = this.root.querySelector('[data-role="combat-ai-form"]');
            if (!form) { return; }
            form.reset();
            this.setField('id', '0');
            this.setField('behavior_key', 'opportunist');
            this.setField('automation_mode', 'suggest_only');
            this.setField('priority_focus', 'balanced');
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
            if (window.confirm(body) && typeof onConfirm === 'function') { onConfirm(); }
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
            return (error && error.message) ? String(error.message) : 'Operazione non riuscita.';
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
