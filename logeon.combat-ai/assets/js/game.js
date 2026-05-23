(function () {
    'use strict';

    var page = {
        root: null,
        modal: null,
        timer: null,

        init: function () {
            this.root = document.querySelector('[data-module-combat-ai]');
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
                if (action === 'combat-ai-declare') {
                    event.preventDefault();
                    self.declareSuggested(
                        parseInt(trigger.getAttribute('data-character-id') || '0', 10) || 0
                    );
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
            if (conflictId <= 0) {
                this.clear();
                return;
            }
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                return;
            }

            var self = this;
            window.Request.http.post('/combat/state', { conflict_id: conflictId }).then(function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.render(dataset && dataset.tier3_ai ? dataset.tier3_ai : {});
            }).catch(function () {
                self.clear();
            });
        },

        render: function (addon) {
            addon = addon && typeof addon === 'object' ? addon : {};
            this.setText('[data-role="combat-ai-message"]', String(addon.message || 'Nessun suggerimento AI disponibile.'));
            if (!addon.enabled) {
                this.setHtml('[data-role="combat-ai-suggestions"]', '<div class="text-muted small">Layer AI non disponibile per questo contesto.</div>');
                return;
            }

            var suggestions = Array.isArray(addon.suggestions) ? addon.suggestions : [];
            if (!suggestions.length) {
                this.setHtml('[data-role="combat-ai-suggestions"]', '<div class="text-muted small">Nessun partecipante profilato per Combat AI.</div>');
                return;
            }

            var html = '';
            for (var i = 0; i < suggestions.length; i += 1) {
                var row = suggestions[i] || {};
                html += '<div class="border rounded p-2 mb-2">'
                    + '<div class="fw-semibold">' + this.escape(row.character_name || ('PG #' + (parseInt(row.character_id || '0', 10) || 0))) + '</div>'
                    + '<div class="small text-muted">' + this.escape(row.behavior_key || '-') + ' - ' + this.escape(row.priority_focus || '-') + '</div>'
                    + '<div class="small mb-2"><b>' + this.escape(row.action_type || '-') + '</b>: ' + this.escape(row.summary || '-') + '</div>';
                if (row.available) {
                    html += '<button type="button" class="btn btn-sm btn-outline-info" data-action="combat-ai-declare" data-character-id="' + (parseInt(row.character_id || '0', 10) || 0) + '">Dichiara azione AI</button>';
                } else {
                    html += '<div class="small text-muted">Suggerimento non disponibile.</div>';
                }
                html += '</div>';
            }
            this.setHtml('[data-role="combat-ai-suggestions"]', html);
        },

        declareSuggested: function (characterId) {
            var conflictId = this.conflictId();
            if (conflictId <= 0 || characterId <= 0) { return; }
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') { return; }

            var self = this;
            window.Request.http.post('/combat-ai/declare', {
                conflict_id: conflictId,
                character_id: characterId
            }).then(function () {
                self.toast('Azione AI dichiarata con successo.', 'success');
                self.scheduleRefresh(120);
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        clear: function () {
            this.setText('[data-role="combat-ai-message"]', 'Nessun suggerimento AI disponibile.');
            this.setHtml('[data-role="combat-ai-suggestions"]', '<div class="text-muted small">Nessun suggerimento.</div>');
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
