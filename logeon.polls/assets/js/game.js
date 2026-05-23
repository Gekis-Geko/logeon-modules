(function () {
    'use strict';

    var page = {
        root: null,
        state: { summary: {}, active_polls: [], closed_polls: [] },

        init: function () {
            this.root = document.querySelector('[data-module-page="polls"]');
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

                if (action === 'polls-game-reload') { self.load(); return; }
                if (action === 'polls-game-vote') {
                    self.vote(
                        parseInt(trigger.getAttribute('data-poll-id') || '0', 10) || 0,
                        parseInt(trigger.getAttribute('data-option-id') || '0', 10) || 0
                    );
                    return;
                }
                if (action === 'polls-game-results') {
                    self.loadResults(parseInt(trigger.getAttribute('data-poll-id') || '0', 10) || 0);
                    return;
                }
            });
        },

        load: function () {
            var self = this;
            this.post('/polls/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.summary = dataset.summary || {};
                self.state.active_polls = Array.isArray(dataset.active_polls) ? dataset.active_polls : [];
                self.state.closed_polls = Array.isArray(dataset.closed_polls) ? dataset.closed_polls : [];
                self.render();
            });
        },

        render: function () {
            this.setText('[data-role="polls-game-summary-active"]', this.state.summary.active || 0);
            this.setText('[data-role="polls-game-summary-closed"]', this.state.summary.closed || 0);
            this.renderActive();
            this.renderClosed();
        },

        renderActive: function () {
            var box = this.root.querySelector('[data-role="polls-game-active"]');
            if (!box) { return; }
            var rows = this.state.active_polls || [];
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessun sondaggio attivo al momento.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                html += this.activePollHtml(rows[i] || {});
            }
            box.innerHTML = html;
        },

        renderClosed: function () {
            var box = this.root.querySelector('[data-role="polls-game-closed"]');
            if (!box) { return; }
            var rows = this.state.closed_polls || [];
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessun sondaggio chiuso disponibile.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                html += this.closedPollHtml(rows[i] || {});
            }
            box.innerHTML = html;
        },

        activePollHtml: function (poll) {
            var id = parseInt(poll.id || '0', 10) || 0;
            var options = Array.isArray(poll.options) ? poll.options : [];
            var html = '<div class="card mb-3"><div class="card-body">';
            html += '<div class="d-flex justify-content-between align-items-start gap-2 mb-2"><div><div class="fw-semibold">' + this.escape(poll.title || '-') + '</div>';
            if (poll.description) { html += '<div class="small text-muted">' + this.escape(poll.description) + '</div>'; }
            html += '</div><span class="badge text-bg-primary">Attivo</span></div>';

            if (parseInt(poll.has_voted || '0', 10) === 1) {
                html += '<div class="alert alert-success py-2 small mb-0">Hai già espresso la tua preferenza. Il voto non è modificabile.</div>';
            } else {
                html += '<div class="d-grid gap-2">';
                for (var i = 0; i < options.length; i += 1) {
                    var option = options[i] || {};
                    html += '<button type="button" class="btn btn-sm btn-outline-primary text-start" data-action="polls-game-vote" data-poll-id="' + id + '" data-option-id="' + (parseInt(option.id || '0', 10) || 0) + '">' + this.escape(option.label || '-') + '</button>';
                }
                html += '</div>';
            }

            if (poll.closes_at) {
                html += '<div class="small text-muted mt-2">Chiusura prevista: ' + this.escape(poll.closes_at) + '</div>';
            }
            html += '</div></div>';
            return html;
        },

        closedPollHtml: function (poll) {
            var id = parseInt(poll.id || '0', 10) || 0;
            var options = Array.isArray(poll.results) ? poll.results : [];
            var totalVotes = parseInt(poll.results_total_votes || '0', 10) || 0;
            var html = '<div class="card mb-3"><div class="card-body">';
            html += '<div class="d-flex justify-content-between align-items-start gap-2 mb-2"><div><div class="fw-semibold">' + this.escape(poll.title || '-') + '</div>';
            if (poll.description) { html += '<div class="small text-muted">' + this.escape(poll.description) + '</div>'; }
            html += '</div><span class="badge text-bg-success">Chiuso</span></div>';
            html += '<div class="small text-muted mb-2">Totale voti: ' + totalVotes + '</div>';
            if (options.length) {
                html += '<div class="list-group">';
                for (var i = 0; i < options.length; i += 1) {
                    var option = options[i] || {};
                    html += '<div class="list-group-item py-2"><div class="d-flex justify-content-between align-items-center gap-2"><div>'
                        + this.escape(option.label || '-') + '</div><div class="small text-muted">'
                        + (parseInt(option.votes || '0', 10) || 0) + ' voti · ' + (parseFloat(option.percentage || '0') || 0) + '%</div></div></div>';
                }
                html += '</div>';
            }
            html += '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="polls-game-results" data-poll-id="' + id + '">Ricarica risultati</button></div>';
            html += '</div></div>';
            return html;
        },

        vote: function (pollId, optionId) {
            var self = this;
            this.post('/polls/vote', { poll_id: pollId, option_id: optionId }, function () {
                self.toast('Voto registrato in forma anonima.', 'success');
                self.load();
            });
        },

        loadResults: function (pollId) {
            var self = this;
            this.post('/polls/results', { poll_id: pollId }, function () {
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
                if (typeof onSuccess === 'function') { onSuccess(response || {}); }
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
            if (node) { node.textContent = String(value == null ? '' : value); }
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
