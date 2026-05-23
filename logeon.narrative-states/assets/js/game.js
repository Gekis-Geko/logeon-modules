const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createLogeonNarrativeStatesGameModule() {
    return {
        ctx: null,

        mount: function (ctx) {
            this.ctx = ctx || null;
            this.bindEvents();
            this.refreshUi();
            return this;
        },

        unmount: function () {},

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }

            return this.ctx.services.http.request({
                url: url,
                action: action,
                payload: payload || {}
            });
        },

        list: function () {
            return this.request('/logeon-narrative-presets/my', 'lfnsMyPresets', {});
        },

        applyPreset: function (payload) {
            return this.request('/logeon-narrative-presets/apply', 'lfnsApplyPreset', payload || {});
        },

        bindEvents: function () {
            var self = this;
            if (globalWindow.__lfnsGameBound === true) {
                return;
            }

            document.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'lfns-page-reload') {
                    event.preventDefault();
                    self.refreshUi();
                    return;
                }
                if (action === 'lfns-preset-apply') {
                    event.preventDefault();
                    self.handleApply(trigger);
                }
            });

            globalWindow.__lfnsGameBound = true;
        },

        handleApply: function (trigger) {
            var assignmentId = parseInt(trigger.getAttribute('data-assignment-id') || '0', 10) || 0;
            var targetType = String(trigger.getAttribute('data-target-type') || 'character').trim().toLowerCase();
            if (assignmentId <= 0) {
                return;
            }

            var payload = { assignment_id: assignmentId };
            if (targetType === 'scene') {
                var sceneInput = document.getElementById('lfns-scene-id');
                var sceneId = sceneInput ? (parseInt(sceneInput.value || '0', 10) || 0) : 0;
                if (sceneId > 0) {
                    payload.scene_id = sceneId;
                    payload.location_id = sceneId;
                }
            }

            var self = this;
            this.applyPreset(payload).then(function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.notify(String(dataset.message || 'Preset applicato.'), 'success');
                self.refreshUi();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Applicazione preset non riuscita.', 'warning');
            });
        },

        refreshUi: function () {
            var self = this;
            this.list().then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderPage(rows);
            }).catch(function () {
                self.renderPage([]);
            });
        },

        renderPage: function (rows) {
            var root = document.getElementById('logeon-narrative-presets-page-list');
            if (!root) {
                return;
            }

            if (!rows.length) {
                root.innerHTML = '<div class="alert alert-secondary mb-0">Nessun preset narrativo assegnato al personaggio corrente.</div>';
                return;
            }

            var html = ['<div class="row g-3">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var assignmentId = parseInt(row.id || '0', 10) || 0;
                var visible = parseInt(row.visible_to_players || '0', 10) === 1;
                html.push(
                    '<div class="col-12 col-lg-6">'
                    + '<div class="card h-100">'
                    + '<div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">'
                    + '<div>'
                    + '<h6 class="mb-1">' + escapeHtml(row.preset_name || 'Preset') + '</h6>'
                    + '<div class="small text-muted">' + escapeHtml(row.preset_slug || '') + '</div>'
                    + '</div>'
                    + '<div class="d-flex flex-wrap gap-1">'
                    + '<span class="badge text-bg-secondary">' + escapeHtml(row.target_type_label || 'Personaggio') + '</span>'
                    + (visible ? '<span class="badge text-bg-info">Visibile</span>' : '<span class="badge text-bg-dark">Staff only</span>')
                    + '</div>'
                    + '</div>'
                    + '<p class="small text-muted mb-2">' + escapeHtml(row.preset_description || 'Nessuna descrizione.') + '</p>'
                    + '<div class="small mb-3">Step configurati: <b>' + escapeHtml(String(row.steps_count || '0')) + '</b></div>'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfns-preset-apply" data-assignment-id="' + assignmentId + '" data-target-type="' + escapeHtml(row.target_type || 'character') + '">Applica preset</button>'
                    + '</div>'
                    + '</div>'
                    + '</div>'
                );
            }
            html.push('</div>');
            root.innerHTML = html.join('');
        },

        notify: function (body, type) {
            if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                globalWindow.Toast.show({ body: body, type: type || 'info' });
            }
        }
    };
}

globalWindow.LogeonNarrativeStatesGameModuleFactory = createLogeonNarrativeStatesGameModule;

if (globalWindow.GameRegistry) {
    globalWindow.GameRegistry.registerModule('game.narrative-states', 'LogeonNarrativeStatesGameModuleFactory');
    globalWindow.GameRegistry.extendPage('narrative-states', ['game.narrative-states']);
}

export { createLogeonNarrativeStatesGameModule as LogeonNarrativeStatesGameModuleFactory };
export default createLogeonNarrativeStatesGameModule;
