(function () {
    'use strict';

    var page = {
        root: null,
        modal: null,
        timer: null,
        addon: {},

        init: function () {
            this.root = document.querySelector('[data-module-combat-coordination]');
            this.modal = document.getElementById('location-conflicts-modal');
            if (!this.root || !this.modal) { return; }
            this.bind();
        },

        bind: function () {
            var self = this;
            this.modal.addEventListener('shown.bs.modal', function () { self.scheduleRefresh(180); });
            this.modal.addEventListener('change', function (event) {
                if (event.target && event.target.id === 'location-combat-conflict-id') {
                    self.scheduleRefresh(80);
                }
            });
            this.modal.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (action === 'combat-coordination-save') { event.preventDefault(); self.save(); return; }
                if (action === 'combat-coordination-cancel') {
                    event.preventDefault();
                    self.cancel(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (
                    action === 'location-combat-load'
                    || action === 'location-combat-start'
                    || action === 'location-combat-sync'
                    || action === 'location-combat-declare'
                    || action === 'location-combat-resolve'
                ) {
                    self.scheduleRefresh(700);
                }
            });
        },

        conflictId: function () {
            var select = document.getElementById('location-combat-conflict-id');
            return select ? (parseInt(select.value || '0', 10) || 0) : 0;
        },

        scheduleRefresh: function (delay) {
            var self = this;
            if (this.timer) { clearTimeout(this.timer); }
            this.timer = window.setTimeout(function () { self.refresh(); }, Math.max(0, parseInt(delay || '0', 10) || 0));
        },

        refresh: function () {
            var conflictId = this.conflictId();
            if (conflictId <= 0) { this.clear(); return; }
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') { return; }

            var self = this;
            window.Request.http.post('/combat/state', { conflict_id: conflictId }).then(function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.render(dataset && dataset.tier3_coordination ? dataset.tier3_coordination : {});
            }).catch(function () {
                self.clear();
            });
        },

        render: function (addon) {
            addon = addon && typeof addon === 'object' ? addon : {};
            this.addon = addon;
            this.setText('[data-role="combat-coordination-message"]', String(addon.message || 'Nessun piano coordinato attivo.'));
            if (!addon.enabled) {
                this.setHtml('[data-role="combat-coordination-plans"]', '<div class="text-muted small">Layer coordination non disponibile.</div>');
                return;
            }

            this.renderParticipants();
            this.renderPlans(Array.isArray(addon.plans) ? addon.plans : []);
        },

        renderParticipants: function () {
            var participants = Array.isArray(this.addon.participant_options) ? this.addon.participant_options : [];
            this.renderSelect('[data-role="coord-leader"]', participants, 'character_id', 'label', 'Leader...');
            this.renderSelect('[data-role="coord-target"]', participants, 'character_id', 'label', 'Target opzionale...');
            if (this.addon.viewer_character_id) {
                var leader = this.root.querySelector('[data-role="coord-leader"]');
                if (leader && leader.querySelector('option[value="' + String(this.addon.viewer_character_id) + '"]')) {
                    leader.value = String(this.addon.viewer_character_id);
                }
            }
        },

        renderSelect: function (selector, rows, valueKey, labelKey, placeholder) {
            var select = this.root.querySelector(selector);
            if (!select) { return; }
            var current = String(select.value || '');
            var html = '<option value="">' + this.escape(placeholder || 'Seleziona...') + '</option>';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<option value="' + this.escape(row[valueKey] || '') + '">' + this.escape(row[labelKey] || '-') + '</option>';
            }
            select.innerHTML = html;
            if (current && select.querySelector('option[value="' + current + '"]')) { select.value = current; }
        },

        renderPlans: function (plans) {
            if (!plans.length) {
                this.setHtml('[data-role="combat-coordination-plans"]', '<div class="text-muted small">Nessun piano coordinato attivo.</div>');
                return;
            }
            var html = '';
            for (var i = 0; i < plans.length; i += 1) {
                var row = plans[i] || {};
                html += '<div class="border rounded p-2 mb-2">'
                    + '<div class="fw-semibold">' + this.escape(row.maneuver_label || row.maneuver_key || '-') + '</div>'
                    + '<div class="small text-muted">Leader #' + (parseInt(row.leader_id || '0', 10) || 0) + ' · azione ' + this.escape(row.required_action_type || '-') + '</div>'
                    + '<div class="small mb-2">Supporter: ' + this.escape(Array.isArray(row.supporter_ids) ? row.supporter_ids.join(', ') : '-') + '</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="combat-coordination-cancel" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Annulla piano</button>'
                    + '</div>';
            }
            this.setHtml('[data-role="combat-coordination-plans"]', html);
        },

        save: function () {
            var conflictId = this.conflictId();
            if (conflictId <= 0) { return; }
            var payload = {
                conflict_id: conflictId,
                leader_id: parseInt(this.fieldValue('[data-role="coord-leader"]') || '0', 10) || 0,
                maneuver_key: this.fieldValue('[data-role="coord-maneuver"]') || 'focus_fire',
                primary_target_id: parseInt(this.fieldValue('[data-role="coord-target"]') || '0', 10) || 0,
                supporter_ids: this.fieldValue('[data-role="coord-supporters"]') || ''
            };
            if (!payload.leader_id) {
                this.toast('Seleziona un leader.', 'warning');
                return;
            }

            var self = this;
            window.Request.http.post('/combat-coordination/plan/save', payload).then(function () {
                self.toast('Piano coordinato salvato.', 'success');
                self.scheduleRefresh(120);
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        cancel: function (id) {
            var self = this;
            if (!id) { return; }
            window.Request.http.post('/combat-coordination/plan/cancel', { id: id }).then(function () {
                self.toast('Piano coordinato annullato.', 'success');
                self.scheduleRefresh(120);
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        fieldValue: function (selector) {
            var field = this.root.querySelector(selector);
            return field ? String(field.value || '').trim() : '';
        },

        clear: function () {
            this.setText('[data-role="combat-coordination-message"]', 'Nessun piano coordinato attivo.');
            this.setHtml('[data-role="combat-coordination-plans"]', '<div class="text-muted small">Nessun piano.</div>');
        },

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.textContent = String(value == null ? '' : value); }
        },

        setHtml: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.innerHTML = String(value == null ? '' : value); }
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
