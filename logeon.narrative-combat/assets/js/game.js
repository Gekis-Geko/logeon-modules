(function () {
    'use strict';

    var runtime = {
        modal: null,
        root: null,
        timer: null,

        init: function () {
            this.modal = document.getElementById('location-conflicts-modal');
            this.root = document.getElementById('location-conflicts-pane-combat');
            if (!this.modal || !this.root) {
                return;
            }

            this.bind();
        },

        bind: function () {
            var self = this;

            this.modal.addEventListener('shown.bs.modal', function () {
                self.scheduleRefresh(120);
            });

            this.modal.addEventListener('change', function (event) {
                var target = event.target;
                if (!target) {
                    return;
                }
                if (target.id === 'location-combat-conflict-id') {
                    self.scheduleRefresh(80);
                }
            });

            this.modal.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '');
                if (action === 'location-combat-load') {
                    self.scheduleRefresh(120);
                    return;
                }
                if (
                    action === 'location-combat-start'
                    || action === 'location-combat-sync'
                    || action === 'location-combat-declare'
                    || action === 'location-combat-guard-add'
                    || action === 'location-combat-guard-remove'
                    || action === 'location-combat-env-save'
                    || action === 'location-combat-resolve'
                ) {
                    self.scheduleRefresh(650);
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

        refresh: function () {
            var conflictId = this.getConflictId();
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
                self.render(dataset);
            }).catch(function () {
                self.clear();
            });
        },

        getConflictId: function () {
            var select = document.getElementById('location-combat-conflict-id');
            if (!select) {
                return 0;
            }
            return parseInt(select.value || '0', 10) || 0;
        },

        render: function (state) {
            var tierLevel = this.toInt(state && state.tier_level ? state.tier_level : 1, 1);
            if (tierLevel < 2) {
                this.clear();
                return;
            }

            var synthetic = (state && state.synthetic_state && typeof state.synthetic_state === 'object') ? state.synthetic_state : {};
            var momentum = (synthetic.momentum && typeof synthetic.momentum === 'object') ? synthetic.momentum : {};
            var escalation = (synthetic.escalation && typeof synthetic.escalation === 'object') ? synthetic.escalation : {};
            var teams = Array.isArray(synthetic.teams) ? synthetic.teams : [];
            var holder = String(momentum.holder_team_key || '').trim();
            var holderLabel = String(momentum.label || '').trim();
            var delta = this.toFloat(momentum.delta, 0);
            var escalationLevel = this.toInt(escalation.level, this.toInt(state && state.context ? state.context.escalation_level : 1, 1));
            var escalationLabel = String(escalation.label || ('Livello ' + escalationLevel)).trim();
            var escalationCue = String(escalation.cue || '').trim();

            this.setText('location-combat-momentum-holder', holderLabel || 'Equilibrio instabile');
            this.setText(
                'location-combat-momentum-cue',
                holder !== ''
                    ? ('Il lato ' + holder + ' sta imponendo il ritmo. Delta: ' + this.formatNumber(delta, 1))
                    : 'Nessun lato ha ancora consolidato un vantaggio netto.'
            );
            this.setText('location-combat-escalation-label', escalationLabel + ' (Lv ' + escalationLevel + ')');
            this.setText('location-combat-escalation-cue', escalationCue || 'Escalation non disponibile.');
            this.renderSideMetrics(teams);
        },

        renderSideMetrics: function (teams) {
            var wrap = document.getElementById('location-combat-side-metrics');
            if (!wrap) {
                return;
            }
            if (!Array.isArray(teams) || !teams.length) {
                wrap.innerHTML = '<span class="text-muted">Metriche non disponibili.</span>';
                return;
            }

            var lines = [];
            for (var i = 0; i < teams.length; i += 1) {
                var team = teams[i] || {};
                var teamKey = this.escape(String(team.team_key || 'side'));
                var pressure = this.formatNumber(this.toFloat(team.pressure_avg, 0), 1);
                var control = this.formatNumber(this.toFloat(team.control_avg, 0), 1);
                var attrition = this.formatNumber(this.toFloat(team.attrition_avg, 0), 1);
                var momentumScore = this.formatNumber(this.toFloat(team.momentum_score, 0), 1);
                lines.push(
                    '<li><b>' + teamKey + '</b>: pressione ' + pressure
                    + ', controllo ' + control
                    + ', attrito ' + attrition
                    + ', score ' + momentumScore
                    + '</li>'
                );
            }

            wrap.innerHTML = '<ul class="mb-0 ps-3 small">' + lines.join('') + '</ul>';
        },

        clear: function () {
            this.setText('location-combat-momentum-holder', '-');
            this.setText('location-combat-momentum-cue', '-');
            this.setText('location-combat-escalation-label', '-');
            this.setText('location-combat-escalation-cue', '-');
            this.setHtml('location-combat-side-metrics', '-');
        },

        setText: function (id, value) {
            var node = document.getElementById(id);
            if (node) {
                node.textContent = String(value == null ? '' : value);
            }
        },

        setHtml: function (id, value) {
            var node = document.getElementById(id);
            if (node) {
                node.innerHTML = String(value == null ? '' : value);
            }
        },

        toInt: function (value, fallback) {
            var parsed = parseInt(value || '0', 10);
            return isNaN(parsed) ? (fallback || 0) : parsed;
        },

        toFloat: function (value, fallback) {
            var parsed = parseFloat(value || '0');
            return isNaN(parsed) ? (fallback || 0) : parsed;
        },

        formatNumber: function (value, decimals) {
            return Number(value || 0).toFixed(decimals || 0);
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
        document.addEventListener('DOMContentLoaded', function () {
            runtime.init();
        });
    } else {
        runtime.init();
    }
}());
