(function () {
    'use strict';

    var page = {
        root: null,
        state: { contexts: [], plans: [], maneuvers: [], baseTier: 1 },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="combat-coordination"]');
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

                if (action === 'combat-coordination-admin-reload') { self.load(); return; }
                if (action === 'combat-coordination-plan-save') { self.save(); return; }
                if (action === 'combat-coordination-plan-reset') { self.reset(); return; }
                if (action === 'combat-coordination-plan-edit') { self.edit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'combat-coordination-plan-cancel') { self.cancel(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/combat-coordination/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.contexts = Array.isArray(dataset.contexts) ? dataset.contexts : [];
                self.state.plans = Array.isArray(dataset.plans) ? dataset.plans : [];
                self.state.maneuvers = Array.isArray(dataset.maneuvers) ? dataset.maneuvers : [];
                self.state.baseTier = parseInt(dataset.base_combat_tier || '1', 10) || 1;
                self.render();
            });
        },

        render: function () {
            this.renderSelect('[name="conflict_id"]', this.state.contexts, 'conflict_id', 'label');
            this.renderSelect('[name="maneuver_key"]', this.state.maneuvers, 'value', 'label');
            this.setText('[data-role="combat-coordination-tier-state"]', this.state.baseTier >= 2
                ? 'Narrative Combat e pronto per Combat Coordination.'
                : 'Narrative Combat e sotto Tier 2: il modulo restera inattivo.');
            this.renderTable();
        },

        renderSelect: function (selector, rows, valueKey, labelKey) {
            var select = this.root.querySelector(selector);
            if (!select) { return; }
            var current = String(select.value || '');
            var html = '<option value="">Seleziona...</option>';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<option value="' + this.escape(row[valueKey] || '') + '">' + this.escape(row[labelKey] || '-') + '</option>';
            }
            select.innerHTML = html;
            if (current && select.querySelector('option[value="' + current + '"]')) { select.value = current; }
        },

        renderTable: function () {
            var body = this.root.querySelector('[data-role="combat-coordination-table"]');
            if (!body) { return; }
            if (!this.state.plans.length) {
                body.innerHTML = '<tr><td colspan="4" class="text-muted small">Nessun piano coordinato registrato.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < this.state.plans.length; i += 1) {
                var row = this.state.plans[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.maneuver_label || row.maneuver_key || '-') + '</div><div class="small text-muted">Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0) + ' · leader #' + (parseInt(row.leader_id || '0', 10) || 0) + '</div></td>'
                    + '<td class="text-center">' + this.escape(row.required_action_type || '-') + '</td>'
                    + '<td class="text-center">' + this.escape(row.status || '-') + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="combat-coordination-plan-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="combat-coordination-plan-cancel" data-id="' + id + '">Annulla</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        save: function () {
            var payload = {
                id: parseInt(this.fieldValue('id') || '0', 10) || 0,
                conflict_id: parseInt(this.fieldValue('conflict_id') || '0', 10) || 0,
                leader_id: parseInt(this.fieldValue('leader_id') || '0', 10) || 0,
                primary_target_id: parseInt(this.fieldValue('primary_target_id') || '0', 10) || 0,
                maneuver_key: this.fieldValue('maneuver_key') || 'focus_fire',
                supporter_ids: this.fieldValue('supporter_ids'),
                bonus_scale: parseInt(this.fieldValue('bonus_scale') || '1', 10) || 1,
                notes: this.fieldValue('notes')
            };
            if (!payload.conflict_id || !payload.leader_id) {
                this.toast('Conflitto e leader sono obbligatori.', 'warning');
                return;
            }

            var self = this;
            this.post('/admin/combat-coordination/plan/save', payload, function () {
                self.toast('Piano coordinato salvato.', 'success');
                self.reset();
                self.load();
            });
        },

        edit: function (id) {
            for (var i = 0; i < this.state.plans.length; i += 1) {
                var row = this.state.plans[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) !== id) { continue; }
                this.setField('id', String(row.id || 0));
                this.setField('conflict_id', String(row.conflict_id || ''));
                this.setField('leader_id', String(row.leader_id || ''));
                this.setField('primary_target_id', String(row.primary_target_id || ''));
                this.setField('maneuver_key', row.maneuver_key || 'focus_fire');
                this.setField('supporter_ids', Array.isArray(row.supporter_ids) ? row.supporter_ids.join(', ') : '');
                this.setField('bonus_scale', String(row.bonus_scale || 1));
                this.setField('notes', row.notes || '');
                return;
            }
        },

        cancel: function (id) {
            var self = this;
            this.confirm('Annulla piano', 'Confermi l\'annullamento del piano coordinato selezionato?', function () {
                self.post('/admin/combat-coordination/plan/cancel', { id: id }, function () {
                    self.toast('Piano coordinato annullato.', 'success');
                    self.load();
                });
            });
        },

        reset: function () {
            var form = this.root.querySelector('[data-role="combat-coordination-form"]');
            if (!form) { return; }
            form.reset();
            this.setField('id', '0');
            this.setField('maneuver_key', 'focus_fire');
            this.setField('bonus_scale', '1');
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
