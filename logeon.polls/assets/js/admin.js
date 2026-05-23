(function () {
    'use strict';

    var page = {
        root: null,
        state: { summary: {}, polls: [] },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="polls"]');
            if (!this.root) { return; }
            this.bind();
            this.resetForm();
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

                if (action === 'polls-admin-reload') { self.load(); return; }
                if (action === 'polls-admin-add-option') { self.addOptionRow(''); return; }
                if (action === 'polls-admin-reset') { self.resetForm(); return; }
                if (action === 'polls-admin-save') { self.save(); return; }
                if (action === 'polls-admin-remove-option') { self.removeOptionRow(trigger); return; }
                if (action === 'polls-admin-edit') { self.edit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'polls-admin-delete') { self.remove(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'polls-admin-results') { self.loadResults(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/polls/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.summary = dataset.summary || {};
                self.state.polls = Array.isArray(dataset.polls) ? dataset.polls : [];
                self.render();
            });
        },

        render: function () {
            this.setText('[data-role="polls-summary-total"]', this.state.summary.total || 0);
            this.setText('[data-role="polls-summary-draft"]', this.state.summary.draft || 0);
            this.setText('[data-role="polls-summary-active"]', this.state.summary.active || 0);
            this.setText('[data-role="polls-summary-closed"]', this.state.summary.closed || 0);
            this.renderTable();
        },

        renderTable: function () {
            var body = this.root.querySelector('[data-role="polls-admin-table"]');
            if (!body) { return; }
            var rows = this.state.polls || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="4" class="text-muted small">Nessun sondaggio.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.title || '-') + '</div><div class="small text-muted">' + this.escape(row.visibility || '') + '</div></td>'
                    + '<td class="text-center"><span class="badge ' + this.statusClass(row.derived_status || row.status) + '">' + this.escape(this.statusLabel(row.derived_status || row.status)) + '</span></td>'
                    + '<td class="text-center">' + (parseInt(row.results_total_votes || '0', 10) || 0) + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-secondary" data-action="polls-admin-results" data-id="' + id + '">Risultati</button>'
                    + '<button type="button" class="btn btn-outline-primary" data-action="polls-admin-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="polls-admin-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        resetForm: function () {
            var form = this.form();
            if (!form) { return; }
            form.reset();
            this.setField('id', '0');
            this.setField('status', 'draft');
            this.setField('visibility', 'player_only');
            this.setField('opens_at', '');
            this.setField('closes_at', '');
            this.renderOptions(['', '']);
            this.hideResults();
        },

        edit: function (id) {
            var rows = this.state.polls || [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) !== id) { continue; }
                this.setField('id', String(row.id || 0));
                this.setField('title', row.title || '');
                this.setField('description', row.description || '');
                this.setField('status', row.status || 'draft');
                this.setField('visibility', row.visibility || 'player_only');
                this.setField('opens_at', this.toLocalInput(row.opens_at || ''));
                this.setField('closes_at', this.toLocalInput(row.closes_at || ''));
                this.renderOptions(this.optionLabels(row.options || []));
                return;
            }
        },

        remove: function (id) {
            var self = this;
            this.confirm('Elimina sondaggio', 'Confermi l\'eliminazione del sondaggio selezionato?', function () {
                self.post('/admin/polls/delete', { id: id }, function () {
                    self.toast('Sondaggio eliminato.', 'success');
                    self.resetForm();
                    self.load();
                });
            });
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.title) {
                this.toast('Titolo obbligatorio.', 'warning');
                return;
            }
            if (payload.options.length < 2 || payload.options.length > 5) {
                this.toast('Servono da 2 a 5 opzioni valide.', 'warning');
                return;
            }
            var self = this;
            this.post('/admin/polls/save', payload, function () {
                self.toast('Sondaggio salvato.', 'success');
                self.resetForm();
                self.load();
            });
        },

        loadResults: function (id) {
            var self = this;
            this.post('/admin/polls/results', { poll_id: id }, function (response) {
                self.showResults(response && response.dataset ? response.dataset : {});
            });
        },

        showResults: function (dataset) {
            var card = this.root.querySelector('[data-role="polls-admin-results-card"]');
            var title = this.root.querySelector('[data-role="polls-admin-results-title"]');
            var body = this.root.querySelector('[data-role="polls-admin-results-body"]');
            if (!card || !title || !body) { return; }
            title.textContent = String(dataset.title || '');
            var options = Array.isArray(dataset.results) ? dataset.results : [];
            var totalVotes = parseInt(dataset.votes_total || dataset.results_total_votes || '0', 10) || 0;
            var html = '<div class="small text-muted mb-2">Totale voti: ' + totalVotes + '</div>';
            if (!options.length) {
                html += '<div class="text-muted small">Nessun voto registrato.</div>';
            } else {
                html += '<div class="list-group">';
                for (var i = 0; i < options.length; i += 1) {
                    var row = options[i] || {};
                    html += '<div class="list-group-item">'
                        + '<div class="d-flex justify-content-between align-items-center gap-2">'
                        + '<div class="fw-semibold">' + this.escape(row.label || '-') + '</div>'
                        + '<div class="small text-muted">' + (parseInt(row.votes || '0', 10) || 0) + ' voti · ' + (parseFloat(row.percentage || '0') || 0) + '%</div>'
                        + '</div></div>';
                }
                html += '</div>';
            }
            body.innerHTML = html;
            card.classList.remove('d-none');
        },

        hideResults: function () {
            var card = this.root.querySelector('[data-role="polls-admin-results-card"]');
            if (card) { card.classList.add('d-none'); }
        },

        collectPayload: function () {
            var options = [];
            var fields = this.root.querySelectorAll('[data-role="polls-option-label"]');
            for (var i = 0; i < fields.length; i += 1) {
                var value = String(fields[i].value || '').trim();
                if (value) { options.push({ label: value }); }
            }
            return {
                id: parseInt(this.fieldValue('id') || '0', 10) || 0,
                title: this.fieldValue('title'),
                description: this.fieldValue('description'),
                status: this.fieldValue('status') || 'draft',
                visibility: this.fieldValue('visibility') || 'player_only',
                opens_at: this.fieldValue('opens_at'),
                closes_at: this.fieldValue('closes_at'),
                options: options
            };
        },

        renderOptions: function (labels) {
            var values = Array.isArray(labels) && labels.length ? labels.slice(0, 5) : ['', ''];
            var container = this.root.querySelector('[data-role="polls-options-container"]');
            if (!container) { return; }
            var html = '';
            for (var i = 0; i < values.length; i += 1) {
                html += this.optionRowHtml(values[i], i + 1);
            }
            container.innerHTML = html;
        },

        addOptionRow: function (value) {
            var container = this.root.querySelector('[data-role="polls-options-container"]');
            if (!container) { return; }
            var count = container.querySelectorAll('[data-role="polls-option-row"]').length;
            if (count >= 5) {
                this.toast('Il massimo è 5 opzioni.', 'warning');
                return;
            }
            container.insertAdjacentHTML('beforeend', this.optionRowHtml(value || '', count + 1));
            this.renumberOptions();
        },

        removeOptionRow: function (trigger) {
            var row = trigger && trigger.closest ? trigger.closest('[data-role="polls-option-row"]') : null;
            if (!row) { return; }
            var container = this.root.querySelector('[data-role="polls-options-container"]');
            if (!container) { return; }
            var count = container.querySelectorAll('[data-role="polls-option-row"]').length;
            if (count <= 2) {
                this.toast('Servono almeno 2 opzioni.', 'warning');
                return;
            }
            row.remove();
            this.renumberOptions();
        },

        renumberOptions: function () {
            var rows = this.root.querySelectorAll('[data-role="polls-option-row"]');
            for (var i = 0; i < rows.length; i += 1) {
                var label = rows[i].querySelector('[data-role="polls-option-index"]');
                if (label) { label.textContent = String(i + 1); }
            }
        },

        optionLabels: function (options) {
            var out = [];
            for (var i = 0; i < options.length; i += 1) {
                out.push(String((options[i] || {}).label || ''));
            }
            return out.length ? out : ['', ''];
        },

        optionRowHtml: function (value, index) {
            return '<div class="input-group input-group-sm" data-role="polls-option-row">'
                + '<span class="input-group-text" data-role="polls-option-index">' + index + '</span>'
                + '<input type="text" class="form-control" data-role="polls-option-label" maxlength="160" value="' + this.escapeAttr(value || '') + '">'
                + '<button type="button" class="btn btn-outline-danger" data-action="polls-admin-remove-option">×</button>'
                + '</div>';
        },

        form: function () {
            return this.root.querySelector('[data-role="polls-admin-form"]');
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

        statusLabel: function (value) {
            var map = { draft: 'Bozza', active: 'Attivo', closed: 'Chiuso' };
            return map[String(value || '').toLowerCase()] || String(value || '-');
        },

        statusClass: function (value) {
            var map = { draft: 'text-bg-secondary', active: 'text-bg-primary', closed: 'text-bg-success' };
            return map[String(value || '').toLowerCase()] || 'text-bg-secondary';
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
