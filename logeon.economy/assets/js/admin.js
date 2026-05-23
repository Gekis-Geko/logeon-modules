(function () {
    'use strict';

    var page = {
        root: null,
        state: {
            summary: {},
            effects: [],
            scope_options: [],
            effect_type_options: [],
            target_type_options: [],
            reference_options: {
                shops: {},
                areas: {},
                factions: {},
                events: {},
                items: {},
                categories: {}
            },
            dependencies: {}
        },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="economy-effects"]');
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

                if (action === 'economy-admin-reload') { self.load(); return; }
                if (action === 'economy-admin-save') { self.save(); return; }
                if (action === 'economy-admin-preview') { self.preview(); return; }
                if (action === 'economy-admin-reset') { self.resetForm(); return; }
                if (action === 'economy-admin-edit') { self.edit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'economy-admin-delete') { self.remove(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
            });

            this.root.addEventListener('change', function (event) {
                var target = event.target;
                if (!target || !target.name) { return; }
                if (target.name === 'scope_type') {
                    self.populateScopeLinks();
                }
                if (target.name === 'target_type') {
                    self.populateTargetRefs();
                }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/economy-effects/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state = dataset;
                self.render();
            });
        },

        render: function () {
            this.setText('[data-role="economy-summary-total"]', this.state.summary.total || 0);
            this.setText('[data-role="economy-summary-active"]', this.state.summary.active || 0);
            this.setText('[data-role="economy-summary-scheduled"]', this.state.summary.scheduled || 0);
            this.renderDependencies();
            this.populateBasicSelects();
            this.populateScopeLinks();
            this.populateTargetRefs();
            this.renderTable();
        },

        renderDependencies: function () {
            var node = this.root.querySelector('[data-role="economy-dependencies-note"]');
            if (!node) { return; }
            var deps = this.state.dependencies || {};
            var parts = [];
            if (parseInt(deps.factions_active || '0', 10) === 1) { parts.push('fazioni attive'); }
            if (parseInt(deps.multi_currency_active || '0', 10) === 1) { parts.push('valute multiple attive'); }
            if (parseInt(deps.quests_active || '0', 10) === 1) { parts.push('quest attive'); }
            if (parseInt(deps.social_status_active || '0', 10) === 1) { parts.push('stati sociali attivi'); }
            node.textContent = parts.length
                ? ('Integrazioni runtime rilevate: ' + parts.join(', ') + '.')
                : 'Nessuna integrazione bundled opzionale attiva oltre al core shop.';
        },

        populateBasicSelects: function () {
            this.renderSelectOptions('scope_type', this.state.scope_options || [], 'value', 'label');
            this.renderSelectOptions('effect_type', this.state.effect_type_options || [], 'value', 'label');
            this.renderSelectOptions('target_type', this.state.target_type_options || [], 'value', 'label');
        },

        populateScopeLinks: function () {
            var scope = this.fieldValue('scope_type') || 'global';
            var options = [];
            if (scope === 'shop') { options = this.objectToOptions((this.state.reference_options || {}).shops || {}); }
            if (scope === 'area') { options = this.objectToOptions((this.state.reference_options || {}).areas || {}); }
            if (scope === 'faction') { options = this.objectToOptions((this.state.reference_options || {}).factions || {}); }
            if (scope === 'event') { options = this.objectToOptions((this.state.reference_options || {}).events || {}); }
            this.renderSelectOptions('link_ids', options, 'value', 'label', true);
        },

        populateTargetRefs: function () {
            var targetType = this.fieldValue('target_type') || 'all';
            var options = [{ value: '', label: 'Nessuno' }];
            if (targetType === 'item') {
                options = options.concat(this.objectToOptions((this.state.reference_options || {}).items || {}));
            }
            if (targetType === 'category') {
                options = options.concat(this.objectToOptions((this.state.reference_options || {}).categories || {}));
            }
            this.renderSelectOptions('target_ref_id', options, 'value', 'label');
        },

        objectToOptions: function (map) {
            var out = [];
            var keys = Object.keys(map || {});
            for (var i = 0; i < keys.length; i += 1) {
                out.push({ value: keys[i], label: map[keys[i]] });
            }
            return out;
        },

        renderSelectOptions: function (fieldName, rows, valueKey, labelKey, multiple) {
            var form = this.form();
            var field = form ? form.querySelector('[name="' + fieldName + '"]') : null;
            if (!field) { return; }
            var current;
            if (multiple) {
                current = [];
                var selected = field.selectedOptions || [];
                for (var s = 0; s < selected.length; s += 1) {
                    current.push(String(selected[s].value || ''));
                }
            } else {
                current = String(field.value || '');
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var value = String(row[valueKey] == null ? '' : row[valueKey]);
                var label = String(row[labelKey] == null ? '' : row[labelKey]);
                html += '<option value="' + this.escapeAttr(value) + '">' + this.escape(label) + '</option>';
            }
            field.innerHTML = html;
            if (multiple) {
                for (var j = 0; j < field.options.length; j += 1) {
                    field.options[j].selected = current.indexOf(String(field.options[j].value || '')) >= 0;
                }
            } else if (current) {
                field.value = current;
            }
        },

        renderTable: function () {
            var body = this.root.querySelector('[data-role="economy-effects-table"]');
            if (!body) { return; }
            var rows = this.state.effects || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted small">Nessun effetto configurato.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var badges = [];
                if (parseInt(row.is_active || '0', 10) === 1) { badges.push('<span class="badge text-bg-success">Attivo</span>'); }
                if (parseInt(row.visible_to_players || '0', 10) === 1) { badges.push('<span class="badge text-bg-info">Player</span>'); }
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.name || '-') + '</div><div class="small text-muted">' + this.escape(row.summary_label || '') + '</div><div class="small">' + badges.join(' ') + '</div></td>'
                    + '<td><div>' + this.escape(row.scope_type_label || '-') + '</div><div class="small text-muted">' + this.escape((row.link_labels || []).join(', ') || 'Nessun link') + '</div></td>'
                    + '<td><div>' + this.escape(row.target_label || 'Tutti i beni') + '</div><div class="small text-muted">' + this.escape(row.effect_type_label || '') + '</div></td>'
                    + '<td class="text-center">' + (parseInt(row.priority || '0', 10) || 0) + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="economy-admin-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="economy-admin-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        preview: function () {
            var self = this;
            this.post('/admin/economy-effects/preview', this.collectPayload(), function (response) {
                self.renderPreview(response && response.dataset ? response.dataset : {});
            });
        },

        renderPreview: function (dataset) {
            var box = this.root.querySelector('[data-role="economy-preview-box"]');
            if (!box) { return; }
            if (!dataset || !dataset.name) {
                box.classList.add('d-none');
                box.innerHTML = '';
                return;
            }
            var html = '<div class="fw-semibold mb-1">' + this.escape(dataset.name || '-') + '</div>'
                + '<div class="small mb-1"><b>Tipo:</b> ' + this.escape(dataset.effect_type_label || '-') + '</div>'
                + '<div class="small mb-1"><b>Scope:</b> ' + this.escape(dataset.scope_type_label || '-') + '</div>'
                + '<div class="small mb-1"><b>Target:</b> ' + this.escape(dataset.target_label || '-') + '</div>'
                + '<div class="small mb-1"><b>Regola:</b> ' + this.escape(dataset.summary_label || '-') + '</div>'
                + '<div class="small mb-1"><b>Collegamenti:</b> ' + this.escape((dataset.links || []).join(', ') || 'Nessuno') + '</div>'
                + '<div class="small mb-1"><b>Visibilita:</b> ' + this.escape(dataset.player_visibility || '-') + '</div>'
                + '<div class="small"><b>Finestra:</b> ' + this.escape(dataset.schedule_label || '-') + '</div>';
            box.innerHTML = html;
            box.classList.remove('d-none');
        },

        save: function () {
            var self = this;
            this.post('/admin/economy-effects/save', this.collectPayload(), function () {
                self.toast('Effetto economico salvato.', 'success');
                self.resetForm();
                self.load();
            });
        },

        edit: function (id) {
            var rows = this.state.effects || [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) !== id) { continue; }
                this.setField('id', String(row.id || 0));
                this.setField('name', row.name || '');
                this.setField('description', row.description || '');
                this.setField('effect_type', row.effect_type || 'label');
                this.setField('scope_type', row.scope_type || 'global');
                this.setField('target_type', row.target_type || 'all');
                this.populateScopeLinks();
                this.populateTargetRefs();
                this.setField('target_ref_id', row.target_ref_id ? String(row.target_ref_id) : '');
                this.setSelectedValues('link_ids', this.pluckIds(row.links || [], 'entity_id'));
                this.setField('priority', String(row.priority || 100));
                this.setField('is_active', String(row.is_active || 0));
                this.setField('visible_to_players', String(row.visible_to_players || 0));
                this.setField('start_at', this.toLocalInput(row.start_at || ''));
                this.setField('end_at', this.toLocalInput(row.end_at || ''));
                this.setField('price_percent_value', String(row.price_percent_value || 0));
                this.setField('price_flat_value', String(row.price_flat_value || 0));
                this.setField('availability_mode', row.availability_mode || 'default');
                this.setField('stock_limit_value', String(row.stock_limit_value || 0));
                this.setField('faction_access_mode', row.faction_access_mode || 'default');
                this.setField('faction_price_percent_value', String(row.faction_price_percent_value || 0));
                this.setField('label_text', row.label_text || '');
                this.setField('notes', row.meta_json && row.meta_json.notes ? row.meta_json.notes : '');
                this.renderPreview({});
                return;
            }
        },

        remove: function (id) {
            var self = this;
            this.confirm('Elimina effetto', 'Confermi l\'eliminazione dell\'effetto selezionato?', function () {
                self.post('/admin/economy-effects/delete', { id: id }, function () {
                    self.toast('Effetto eliminato.', 'success');
                    self.resetForm();
                    self.load();
                });
            });
        },

        resetForm: function () {
            var form = this.form();
            if (!form) { return; }
            form.reset();
            this.setField('id', '0');
            this.setField('priority', '100');
            this.setField('is_active', '1');
            this.setField('visible_to_players', '1');
            this.setField('effect_type', 'label');
            this.setField('scope_type', 'global');
            this.setField('target_type', 'all');
            this.populateScopeLinks();
            this.populateTargetRefs();
            this.renderPreview({});
        },

        collectPayload: function () {
            var form = this.form();
            var payload = {};
            if (!form) { return payload; }
            var fields = form.querySelectorAll('[name]');
            for (var i = 0; i < fields.length; i += 1) {
                var field = fields[i];
                if (field.multiple) {
                    payload[field.name] = this.selectedValues(field);
                } else {
                    payload[field.name] = String(field.value || '').trim();
                }
            }
            payload.id = parseInt(payload.id || '0', 10) || 0;
            return payload;
        },

        selectedValues: function (field) {
            var out = [];
            var options = field && field.options ? field.options : [];
            for (var i = 0; i < options.length; i += 1) {
                if (options[i].selected && String(options[i].value || '').trim() !== '') {
                    out.push(String(options[i].value || ''));
                }
            }
            return out;
        },

        setSelectedValues: function (name, values) {
            var form = this.form();
            var field = form ? form.querySelector('[name="' + name + '"]') : null;
            if (!field || !field.options) { return; }
            var current = values || [];
            for (var i = 0; i < field.options.length; i += 1) {
                field.options[i].selected = current.indexOf(String(field.options[i].value || '')) >= 0;
            }
        },

        pluckIds: function (rows, key) {
            var out = [];
            for (var i = 0; i < rows.length; i += 1) {
                var value = rows[i] && rows[i][key] != null ? String(rows[i][key]) : '';
                if (value) { out.push(value); }
            }
            return out;
        },

        form: function () {
            return this.root.querySelector('[data-role="economy-form"]');
        },

        fieldValue: function (name) {
            var form = this.form();
            var field = form ? form.querySelector('[name="' + name + '"]') : null;
            return field ? String(field.value || '').trim() : '';
        },

        setField: function (name, value) {
            var form = this.form();
            var field = form ? form.querySelector('[name="' + name + '"]') : null;
            if (field) { field.value = value == null ? '' : String(value); }
        },

        toLocalInput: function (value) {
            var text = String(value || '').trim();
            if (!text) { return ''; }
            return text.slice(0, 16).replace(' ', 'T');
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

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.textContent = String(value == null ? '' : value); }
        },

        escape: function (value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        escapeAttr: function (value) {
            return this.escape(value);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { page.init(); });
    } else {
        page.init();
    }
}());
