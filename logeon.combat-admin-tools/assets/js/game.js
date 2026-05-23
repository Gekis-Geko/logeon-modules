(function () {
    'use strict';

    var page = {
        root: null,
        modal: null,
        timer: null,

        init: function () {
            this.root = document.querySelector('[data-module-combat-admin-tools]');
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
                self.render(dataset && dataset.tier3_admin_tools ? dataset.tier3_admin_tools : {});
            }).catch(function () { self.clear(); });
        },

        render: function (addon) {
            addon = addon && typeof addon === 'object' ? addon : {};
            this.setText('[data-role="combat-admin-tools-message"]', String(addon.message || 'Nessuna diagnostica disponibile.'));
            if (!addon.enabled) {
                this.setHtml('[data-role="combat-admin-tools-content"]', '<div class="text-muted small">Diagnostica non disponibile per questo contesto.</div>');
                return;
            }

            var html = '<div class="small mb-2">Escalation: <b>' + (parseInt(addon.escalation_level || '0', 10) || 0) + '</b> · Pending: <b>' + (parseInt(addon.pending_count || '0', 10) || 0) + '</b></div>';
            html += '<div class="small text-muted mb-1">Hotspot fatica</div>';
            if (Array.isArray(addon.fatigue_hotspots) && addon.fatigue_hotspots.length) {
                html += '<ul class="small ps-3">';
                for (var i = 0; i < addon.fatigue_hotspots.length; i += 1) {
                    var row = addon.fatigue_hotspots[i] || {};
                    html += '<li>' + this.escape(row.label || ('PG #' + (parseInt(row.character_id || '0', 10) || 0))) + ': stamina ' + (parseInt(row.stamina_current || '0', 10) || 0) + ', fatigue ' + (parseInt(row.fatigue_level || '0', 10) || 0) + '</li>';
                }
                html += '</ul>';
            } else {
                html += '<div class="text-muted small mb-2">Nessun hotspot rilevante.</div>';
            }

            html += '<div class="small text-muted mb-1">Pressione effetti</div>';
            if (Array.isArray(addon.effect_pressure) && addon.effect_pressure.length) {
                html += '<ul class="small ps-3">';
                for (var j = 0; j < addon.effect_pressure.length; j += 1) {
                    var effectRow = addon.effect_pressure[j] || {};
                    html += '<li>PG #' + (parseInt(effectRow.character_id || '0', 10) || 0) + ': ' + (parseInt(effectRow.effect_count || '0', 10) || 0) + ' effetti attivi</li>';
                }
                html += '</ul>';
            } else {
                html += '<div class="text-muted small">Nessuna pressione effetti anomala.</div>';
            }

            this.setHtml('[data-role="combat-admin-tools-content"]', html);
        },

        clear: function () {
            this.setText('[data-role="combat-admin-tools-message"]', 'Nessuna diagnostica disponibile.');
            this.setHtml('[data-role="combat-admin-tools-content"]', '<div class="text-muted small">Nessun dato.</div>');
        },

        setText: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.textContent = String(value == null ? '' : value); }
        },

        setHtml: function (selector, value) {
            var node = this.root.querySelector(selector);
            if (node) { node.innerHTML = String(value == null ? '' : value); }
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
