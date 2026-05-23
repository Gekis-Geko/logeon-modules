(function () {
    'use strict';

    var page = {
        root: null,
        state: { summary: {}, professions: [], processes: [], sources: [], recent_jobs: [] },

        init: function () {
            this.root = document.querySelector('[data-module-page="crafting-production"]');
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

                if (action === 'crafting-game-reload') { self.load(); return; }
                if (action === 'crafting-game-execute') {
                    self.execute(parseInt(trigger.getAttribute('data-process-id') || '0', 10) || 0, String(trigger.getAttribute('data-station-type') || ''));
                }
            });
        },

        load: function () {
            var self = this;
            this.post('/crafting-production/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.summary = dataset.summary || {};
                self.state.professions = Array.isArray(dataset.professions) ? dataset.professions : [];
                self.state.processes = Array.isArray(dataset.processes) ? dataset.processes : [];
                self.state.sources = Array.isArray(dataset.sources) ? dataset.sources : [];
                self.state.recent_jobs = Array.isArray(dataset.recent_jobs) ? dataset.recent_jobs : [];
                self.render();
            });
        },

        render: function () {
            this.setText('[data-role="crafting-game-summary-professions"]', this.state.summary.professions || 0);
            this.setText('[data-role="crafting-game-summary-available"]', this.state.summary.available_processes || 0);
            this.setText('[data-role="crafting-game-summary-blocked"]', this.state.summary.blocked_processes || 0);
            this.setText('[data-role="crafting-game-summary-sources"]', this.state.summary.sources || 0);
            this.renderProfessions();
            this.renderProcesses();
            this.renderSources();
            this.renderJobs();
        },

        renderProfessions: function () {
            var box = this.root.querySelector('[data-role="crafting-game-professions"]');
            var rows = this.state.professions || [];
            if (!box) { return; }
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessuna professione assegnata. Alcuni processi potrebbero restare bloccati.</div>';
                return;
            }
            var html = '<div class="d-flex flex-wrap gap-2">';
            for (var i = 0; i < rows.length; i += 1) {
                html += '<span class="badge text-bg-dark">' + this.escape(rows[i].name || rows[i].code || '-') + '</span>';
            }
            html += '</div>';
            box.innerHTML = html;
        },

        renderProcesses: function () {
            var box = this.root.querySelector('[data-role="crafting-game-processes"]');
            var rows = this.state.processes || [];
            if (!box) { return; }
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessun processo disponibile in questo momento.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                html += this.processCard(rows[i] || {});
            }
            box.innerHTML = html;
        },

        renderSources: function () {
            var box = this.root.querySelector('[data-role="crafting-game-sources"]');
            var rows = this.state.sources || [];
            if (!box) { return; }
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessuna sorgente contestuale disponibile.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var source = rows[i] || {};
                var items = Array.isArray(source.items) ? source.items : [];
                var itemLabels = [];
                for (var j = 0; j < items.length; j += 1) {
                    itemLabels.push((items[j].item_name || ('Item #' + (parseInt(items[j].item_id || '0', 10) || 0))) + ' x' + (parseInt(items[j].quantity || '0', 10) || 0));
                }
                html += '<div class="border rounded p-3 mb-2">'
                    + '<div class="fw-semibold">' + this.escape(source.name || '-') + '</div>'
                    + '<div class="small text-muted mb-1">' + this.escape(source.source_type_label || '-') + ' · ' + this.escape(source.scope_label || '-') + '</div>'
                    + '<div class="small">' + this.escape(source.description || '') + '</div>'
                    + '<div class="small text-muted mt-1">' + this.escape(itemLabels.join(', ') || 'Nessun item collegato') + '</div>'
                    + '</div>';
            }
            box.innerHTML = html;
        },

        renderJobs: function () {
            var box = this.root.querySelector('[data-role="crafting-game-jobs"]');
            var rows = this.state.recent_jobs || [];
            if (!box) { return; }
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Ancora nessuna lavorazione registrata per questo personaggio.</div>';
                return;
            }
            var html = '<div class="list-group">';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<div class="list-group-item py-2">'
                    + '<div class="fw-semibold">' + this.escape(row.process_name || '-') + '</div>'
                    + '<div class="small text-muted">' + this.escape(row.status || '-') + ' · ' + this.escape(row.started_at || '-') + '</div>'
                    + '</div>';
            }
            html += '</div>';
            box.innerHTML = html;
        },

        processCard: function (process) {
            var executable = parseInt(process.is_executable || '0', 10) === 1;
            var badges = [
                '<span class="badge text-bg-secondary">' + this.escape(process.process_type_label || '-') + '</span>'
            ];
            if (process.station_type) {
                badges.push('<span class="badge text-bg-light border text-dark">Stazione: ' + this.escape(process.station_type) + '</span>');
            }
            var inputs = Array.isArray(process.inputs) ? process.inputs : [];
            var outputs = Array.isArray(process.outputs) ? process.outputs : [];
            var requirements = Array.isArray(process.requirements) ? process.requirements : [];
            var blocking = Array.isArray(process.blocking_reasons) ? process.blocking_reasons : [];
            var html = '<div class="card mb-3"><div class="card-body">';
            html += '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2"><div><div class="fw-semibold">' + this.escape(process.name || '-') + '</div>';
            if (process.description) { html += '<div class="small text-muted">' + this.escape(process.description) + '</div>'; }
            html += '<div class="small mt-1">' + badges.join(' ') + '</div></div>';
            if (executable) {
                html += '<button type="button" class="btn btn-sm btn-success" data-action="crafting-game-execute" data-process-id="' + (parseInt(process.id || '0', 10) || 0) + '" data-station-type="' + this.escapeAttr(process.station_type || '') + '">Produci</button>';
            } else {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" disabled>Bloccato</button>';
            }
            html += '</div>';
            html += '<div class="small"><b>Input:</b> ' + this.escape(this.describeRows(inputs, 'item_name', 'quantity')) + '</div>';
            html += '<div class="small"><b>Output:</b> ' + this.escape(this.describeRows(outputs, 'item_name', 'quantity')) + '</div>';
            if (requirements.length) {
                html += '<div class="small"><b>Requisiti:</b> ' + this.escape(this.describeRequirements(requirements)) + '</div>';
            }
            html += '<div class="small text-muted mt-2">' + this.escape(process.explanation || '') + '</div>';
            if (blocking.length) {
                html += '<ul class="small text-warning mt-2 mb-0">';
                for (var i = 0; i < blocking.length; i += 1) {
                    html += '<li>' + this.escape(blocking[i]) + '</li>';
                }
                html += '</ul>';
            }
            html += '</div></div>';
            return html;
        },

        describeRows: function (rows, labelKey, qtyKey) {
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) { return 'Nessuno'; }
            var labels = [];
            for (var i = 0; i < rows.length; i += 1) {
                labels.push(String(rows[i][labelKey] || '-') + ' x' + (parseInt(rows[i][qtyKey] || '0', 10) || 0));
            }
            return labels.join(', ');
        },

        describeRequirements: function (rows) {
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) { return 'Nessuno'; }
            var labels = [];
            for (var i = 0; i < rows.length; i += 1) {
                labels.push(String(rows[i].requirement_type_label || '-') + ': ' + String(rows[i].value_label || rows[i].requirement_value || '-'));
            }
            return labels.join(', ');
        },

        execute: function (processId, stationType) {
            var self = this;
            this.post('/crafting-production/execute', { process_id: processId, station_type: stationType || '' }, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.toast(dataset.explanation || 'Produzione completata.', 'success');
                self.load();
            });
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

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) {
                node.textContent = String(value == null ? '' : value);
            }
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
