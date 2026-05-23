(function () {
    'use strict';

    var page = {
        root: null,
        modal: null,
        timer: null,

        init: function () {
            this.root = document.querySelector('[data-module-combat-environment]');
            this.modal = document.getElementById('location-conflicts-modal');
            if (!this.root || !this.modal) {
                return;
            }

            this.bind();
        },

        bind: function () {
            var self = this;

            this.modal.addEventListener('shown.bs.modal', function () {
                self.scheduleRefresh(160);
            });

            this.modal.addEventListener('change', function (event) {
                var target = event.target;
                if (target && target.id === 'location-combat-conflict-id') {
                    self.scheduleRefresh(80);
                }
            });

            this.modal.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }
                var action = String(trigger.getAttribute('data-action') || '');
                if (action === 'combat-environment-opportunity') {
                    event.preventDefault();
                    self.interact(
                        parseInt(trigger.getAttribute('data-feature-id') || '0', 10) || 0,
                        String(trigger.getAttribute('data-interaction-key') || '')
                    );
                    return;
                }

                if (
                    action === 'location-combat-load'
                    || action === 'location-combat-start'
                    || action === 'location-combat-sync'
                    || action === 'location-combat-declare'
                    || action === 'location-combat-guard-add'
                    || action === 'location-combat-guard-remove'
                    || action === 'location-combat-env-save'
                    || action === 'location-combat-resolve'
                ) {
                    self.scheduleRefresh(700);
                }
            });
        },

        scheduleRefresh: function (delay) {
            var self = this;
            if (this.timer) {
                clearTimeout(this.timer);
            }
            this.timer = window.setTimeout(function () {
                self.refresh();
            }, Math.max(0, parseInt(delay || '0', 10) || 0));
        },

        conflictId: function () {
            var select = document.getElementById('location-combat-conflict-id');
            if (!select) {
                return 0;
            }
            return parseInt(select.value || '0', 10) || 0;
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
                self.render((dataset && dataset.tier3_environment) ? dataset.tier3_environment : {});
            }).catch(function () {
                self.clear();
            });
        },

        render: function (addon) {
            addon = addon && typeof addon === 'object' ? addon : {};
            this.setText('[data-role="combat-environment-mode"]', String(addon.complexity_mode || 'Tier 3'));
            this.setText('[data-role="combat-environment-message"]', String(addon.message || (addon.enabled ? 'Feature ambientali contestuali attive.' : 'Layer ambiente avanzato non disponibile.')));

            if (!addon.enabled) {
                this.setHtml('[data-role="combat-environment-features"]', '<div class="text-muted small">Modulo non attivo per questo contesto.</div>');
                this.setHtml('[data-role="combat-environment-opportunities"]', '<div class="text-muted small">Nessuna opportunita disponibile.</div>');
                this.setHtml('[data-role="combat-environment-zones"]', '<div class="text-muted small">Nessuna zona disponibile.</div>');
                return;
            }

            this.renderFeatures(Array.isArray(addon.features) ? addon.features : []);
            this.renderOpportunities(Array.isArray(addon.opportunities) ? addon.opportunities : []);
            this.renderZones(Array.isArray(addon.zone_summary) ? addon.zone_summary : []);
        },

        renderFeatures: function (features) {
            var html = '';
            if (!features.length) {
                html = '<div class="text-muted small">Nessuna feature avanzata registrata per questo conflitto.</div>';
                this.setHtml('[data-role="combat-environment-features"]', html);
                return;
            }

            for (var i = 0; i < features.length; i += 1) {
                var row = features[i] || {};
                var impacts = [];
                if ((parseInt(row.cover_impact || '0', 10) || 0) !== 0) { impacts.push('cover ' + row.cover_impact); }
                if ((parseInt(row.hazard_impact || '0', 10) || 0) !== 0) { impacts.push('hazard ' + row.hazard_impact); }
                if ((parseInt(row.mobility_impact || '0', 10) || 0) !== 0) { impacts.push('mobility ' + row.mobility_impact); }
                if ((parseInt(row.visibility_impact || '0', 10) || 0) !== 0) { impacts.push('visibility ' + row.visibility_impact); }
                html += '<div class="border rounded p-2 mb-2">'
                    + '<div class="fw-semibold">' + this.escape(row.feature_name || '-') + '</div>'
                    + '<div class="small text-muted">' + this.escape((row.feature_type_label || row.feature_type || '-') + ' - ' + (row.state_label || row.state_key || '-')) + '</div>'
                    + '<div class="small">' + this.escape(row.description || '') + '</div>'
                    + '<div class="small text-muted mt-1">' + this.escape(impacts.join(', ') || 'Impatto sintetico neutro') + '</div>'
                    + '</div>';
            }
            this.setHtml('[data-role="combat-environment-features"]', html);
        },

        renderOpportunities: function (opportunities) {
            var html = '';
            if (!opportunities.length) {
                html = '<div class="text-muted small">Nessuna opportunita contestuale disponibile.</div>';
                this.setHtml('[data-role="combat-environment-opportunities"]', html);
                return;
            }

            for (var i = 0; i < opportunities.length; i += 1) {
                var row = opportunities[i] || {};
                html += '<div class="border rounded p-2 mb-2">'
                    + '<div class="fw-semibold">' + this.escape(row.feature_name || '-') + '</div>'
                    + '<div class="small text-muted mb-2">' + this.escape(row.description || '') + '</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-warning" data-action="combat-environment-opportunity" data-feature-id="' + (parseInt(row.feature_id || '0', 10) || 0) + '" data-interaction-key="' + this.escapeAttr(row.action_key || '') + '">'
                    + this.escape(row.action_label || 'Agisci')
                    + '</button>'
                    + '</div>';
            }
            this.setHtml('[data-role="combat-environment-opportunities"]', html);
        },

        renderZones: function (zones) {
            var html = '';
            if (!zones.length) {
                html = '<div class="text-muted small">Nessuna suddivisione zone disponibile.</div>';
                this.setHtml('[data-role="combat-environment-zones"]', html);
                return;
            }

            html += '<ul class="small mb-0 ps-3">';
            for (var i = 0; i < zones.length; i += 1) {
                var row = zones[i] || {};
                html += '<li><b>' + this.escape(row.zone_key || 'global') + '</b>: '
                    + (parseInt(row.features || '0', 10) || 0) + ' feature, '
                    + (parseInt(row.hazards || '0', 10) || 0) + ' hazard, '
                    + (parseInt(row.cover_nodes || '0', 10) || 0) + ' cover</li>';
            }
            html += '</ul>';
            this.setHtml('[data-role="combat-environment-zones"]', html);
        },

        interact: function (featureId, actionKey) {
            var conflictId = this.conflictId();
            if (conflictId <= 0 || featureId <= 0 || !actionKey) {
                return;
            }
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                return;
            }
            var self = this;
            window.Request.http.post('/combat-environment/interact', {
                conflict_id: conflictId,
                feature_id: featureId,
                action_key: actionKey
            }).then(function () {
                self.toast('Interazione ambientale registrata.', 'success');
                self.scheduleRefresh(120);
            }).catch(function (error) {
                self.toast(self.errorMessage(error), 'danger');
            });
        },

        clear: function () {
            this.setText('[data-role="combat-environment-message"]', 'Nessuna feature ambientale avanzata disponibile.');
            this.setHtml('[data-role="combat-environment-features"]', '<div class="text-muted small">Nessuna feature.</div>');
            this.setHtml('[data-role="combat-environment-opportunities"]', '<div class="text-muted small">Nessuna opportunita.</div>');
            this.setHtml('[data-role="combat-environment-zones"]', '<div class="text-muted small">Nessuna zona.</div>');
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
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            return 'Operazione non riuscita.';
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
