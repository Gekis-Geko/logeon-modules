(function () {
    'use strict';

    var page = {
        root: null,

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="combat-admin-tools"]');
            if (!this.root) { return; }
            this.bind();
            this.load();
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                if (String(trigger.getAttribute('data-action') || '') === 'combat-admin-tools-reload') {
                    event.preventDefault();
                    self.load();
                }
            });
        },

        load: function () {
            var self = this;
            this.post('/admin/combat-admin-tools/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.render(dataset);
            });
        },

        render: function (dataset) {
            dataset = dataset || {};
            var baseTier = parseInt(dataset.base_combat_tier || '1', 10) || 1;
            this.setText('[data-role="combat-admin-tools-tier-state"]', baseTier >= 2
                ? 'Narrative Combat e pronto per la diagnostica Tier 3.'
                : 'Narrative Combat e sotto Tier 2: il modulo restera inattivo.');

            var pending = Array.isArray(dataset.pending) ? dataset.pending : [];
            if (!pending.length) {
                this.setHtml('[data-role="combat-admin-tools-pending"]', '<div class="text-muted small">Nessuna coda pending rilevante.</div>');
            } else {
                var pendingHtml = '<ul class="small mb-0 ps-3">';
                for (var i = 0; i < pending.length; i += 1) {
                    var row = pending[i] || {};
                    pendingHtml += '<li>Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0) + ': ' + (parseInt(row.pending_count || '0', 10) || 0) + ' azioni pending</li>';
                }
                pendingHtml += '</ul>';
                this.setHtml('[data-role="combat-admin-tools-pending"]', pendingHtml);
            }

            var body = this.root.querySelector('[data-role="combat-admin-tools-contexts"]');
            if (!body) { return; }
            var contexts = Array.isArray(dataset.contexts) ? dataset.contexts : [];
            if (!contexts.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted small">Nessun contesto combattimento trovato.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < contexts.length; i += 1) {
                var row = contexts[i] || {};
                html += '<tr>'
                    + '<td><div class="fw-semibold">Conflitto #' + (parseInt(row.conflict_id || '0', 10) || 0) + '</div><div class="small text-muted">' + this.escape(row.status || '-') + '</div></td>'
                    + '<td class="text-center">' + (parseInt(row.tier_level || '0', 10) || 0) + '</td>'
                    + '<td class="text-center">' + (parseInt(row.escalation_level || '0', 10) || 0) + '</td>'
                    + '<td class="text-center">' + (parseInt(row.active_participants || '0', 10) || 0) + ' / ' + (parseInt(row.participants || '0', 10) || 0) + '</td>'
                    + '<td class="text-center">' + (parseInt(row.risk_participants || '0', 10) || 0) + '</td>'
                    + '</tr>';
            }
            body.innerHTML = html;
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
