const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createLogeonAbilitiesSpellsGameModule() {
    return {
        ctx: null,
        mounted: false,
        refreshBound: false,
        pointRows: [],

        mount: function (ctx) {
            this.ctx = ctx || null;
            if (!this.refreshBound) {
                this.bindGlobalEvents();
                this.refreshBound = true;
            }
            this.mounted = true;
            this.refreshUi();
            return this;
        },

        unmount: function () {
            this.mounted = false;
        },

        list: function (payload) {
            return this.request('/abilities-spells/my', 'logeonAbilitiesList', payload || {});
        },

        points: function (payload) {
            return this.request('/abilities-spells/points', 'logeonAbilitiesPoints', payload || {});
        },

        learn: function (payload) {
            return this.request('/abilities-spells/learn', 'logeonAbilitiesLearn', payload || {});
        },

        upgrade: function (payload) {
            return this.request('/abilities-spells/upgrade', 'logeonAbilitiesUpgrade', payload || {});
        },

        use: function (payload) {
            return this.request('/abilities-spells/use', 'logeonAbilitiesUse', payload || {});
        },

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

        bindGlobalEvents: function () {
            var self = this;
            document.addEventListener('location:sceneLauncher.refresh', function () {
                self.refreshUi();
            });

            document.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action="lfas-use-ability"]') : null;
                if (trigger) {
                    event.preventDefault();
                    var abilityId = parseInt(trigger.getAttribute('data-ability-id') || '0', 10) || 0;
                    var targetType = String(trigger.getAttribute('data-target-type') || 'self').trim().toLowerCase();
                    if (abilityId <= 0) {
                        return;
                    }

                    var payload = { ability_id: abilityId };
                    if (targetType === 'scene') {
                        var locationIdInput = document.querySelector('[name="location_id"]');
                        var locationId = locationIdInput ? (parseInt(locationIdInput.value || '0', 10) || 0) : 0;
                        if (locationId > 0) {
                            payload.location_id = locationId;
                            payload.scene_id = locationId;
                        }
                    }

                    self.use(payload).then(function (response) {
                        var dataset = response && response.dataset ? response.dataset : {};
                        var message = String(dataset.message || 'Abilita usata con successo.');
                        if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                            globalWindow.Toast.show({ body: message, type: 'success' });
                        }
                        self.refreshUi();
                    }).catch(function (error) {
                        var message = 'Uso abilita non riuscito.';
                        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                            message = error.message.trim();
                        } else if (error && typeof error.error_code === 'string' && error.error_code.trim() !== '') {
                            if (error.error_code === 'state_not_found') {
                                message = 'Stato narrativo non trovato.';
                            } else if (error.error_code === 'ability_scene_required') {
                                message = 'Questa abilita richiede una scena attiva.';
                            }
                        }
                        if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                            globalWindow.Toast.show({ body: message, type: 'warning' });
                        }
                    });
                    return;
                }

                trigger = event.target && event.target.closest ? event.target.closest('[data-action="lfas-learn-ability"]') : null;
                if (trigger) {
                    event.preventDefault();
                    self.learnOrUpgrade('learn', parseInt(trigger.getAttribute('data-ability-id') || '0', 10) || 0);
                    return;
                }

                trigger = event.target && event.target.closest ? event.target.closest('[data-action="lfas-upgrade-ability"]') : null;
                if (trigger) {
                    event.preventDefault();
                    self.learnOrUpgrade('upgrade', parseInt(trigger.getAttribute('data-ability-id') || '0', 10) || 0);
                }
            });
        },

        learnOrUpgrade: function (mode, abilityId) {
            var self = this;
            if (abilityId <= 0) {
                return;
            }

            var fn = mode === 'upgrade' ? this.upgrade.bind(this) : this.learn.bind(this);
            fn({ ability_id: abilityId }).then(function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                    globalWindow.Toast.show({ body: String(dataset.message || 'Progressione aggiornata.'), type: 'success' });
                }
                self.refreshUi();
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Operazione non riuscita.';
                if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                    globalWindow.Toast.show({ body: message, type: 'warning' });
                }
            });
        },

        refreshUi: function () {
            var self = this;
            return Promise.all([this.list({}), this.points({})]).then(function (responses) {
                var rows = responses[0] && Array.isArray(responses[0].dataset) ? responses[0].dataset : [];
                var pointRows = responses[1] && Array.isArray(responses[1].dataset) ? responses[1].dataset : [];
                self.pointRows = pointRows;
                self.renderScenePane(rows);
                self.renderProfilePanel(rows);
                self.renderPointsPanel(pointRows);
                self.renderPageList(rows);
            }).catch(function () {
                self.pointRows = [];
                self.renderScenePane([]);
                self.renderProfilePanel([]);
                self.renderPointsPanel([]);
                self.renderPageList([]);
            });
        },

        renderPointsPanel: function (rows) {
            var root = document.getElementById('logeon-abilities-page-points');
            if (!root) {
                return;
            }

            if (!rows.length) {
                root.innerHTML = '<div class="alert alert-secondary mb-0 small">Nessuna categoria punti disponibile.</div>';
                return;
            }

            var html = ['<div class="vstack gap-2">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html.push(
                    '<div class="border rounded p-2">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2">'
                    + '<div><div class="fw-semibold">' + escapeHtml(row.name || row.slug || 'Categoria') + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(row.description || '') + '</div></div>'
                    + '<span class="badge text-bg-primary">Disponibili ' + escapeHtml(String(parseInt(row.available_points || '0', 10) || 0)) + '</span>'
                    + '</div>'
                    + '<div class="small text-muted mt-2">Spesi: ' + escapeHtml(String(parseInt(row.spent_points || '0', 10) || 0)) + ' · Lifetime: ' + escapeHtml(String(parseInt(row.lifetime_points || '0', 10) || 0)) + '</div>'
                    + '</div>'
                );
            }
            html.push('</div>');
            root.innerHTML = html.join('');
        },

        renderScenePane: function (rows) {
            var root = document.getElementById('location-scene-abilities-body');
            if (!root) {
                return;
            }

            rows = this.sceneRows(rows);
            if (!rows.length) {
                root.innerHTML = '<div class="alert alert-secondary mb-0 small">Nessuna abilita assegnata al personaggio corrente.</div>';
                return;
            }

            var html = ['<div class="list-group">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var abilityId = parseInt(row.id || '0', 10) || 0;
                var name = escapeHtml(row.name || 'Abilita');
                var description = escapeHtml(row.description || '');
                var targetType = String(row.target_type || 'self').trim().toLowerCase();
                var targetLabel = targetType === 'scene' ? 'Scena' : 'Se stesso';
                var stateName = escapeHtml(row.applies_state_name || '');
                var effectLabel = this.effectLabel(row.effect_mode, stateName);
                var usable = !!row.usable;
                var statusBadge = this.statusBadge(row);
                html.push(
                    '<div class="list-group-item">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2">'
                    + '<div>'
                    + '<div class="fw-semibold">' + name + '</div>'
                    + (description !== '' ? '<div class="small text-muted mt-1">' + description + '</div>' : '')
                    + '<div class="small mt-2"><span class="badge text-bg-secondary me-1">' + escapeHtml(targetLabel) + '</span>' + statusBadge + ' ' + effectLabel + '</div>'
                    + this.requirementsHtml(row)
                    + '</div>'
                    + (usable
                        ? '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-use-ability" data-ability-id="' + abilityId + '" data-target-type="' + escapeHtml(targetType) + '">Usa</button>'
                        : '<button type="button" class="btn btn-sm btn-outline-secondary" disabled>Non usabile</button>')
                    + '</div>'
                    + '</div>'
                );
            }
            html.push('</div>');
            root.innerHTML = html.join('');
        },

        renderProfilePanel: function (rows) {
            var root = document.getElementById('location-skills-body');
            if (!root) {
                return;
            }

            rows = this.sceneRows(rows);
            if (!rows.length) {
                root.innerHTML = '<p class="small text-muted mb-0">Nessuna abilita assegnata.</p>';
                return;
            }

            var html = ['<ul class="list-group list-group-flush">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var name = escapeHtml(row.name || 'Abilita');
                var targetType = String(row.target_type || 'self').trim().toLowerCase();
                var stateName = escapeHtml(row.applies_state_name || '');
                html.push(
                    '<li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">'
                    + '<div><div class="fw-semibold small">' + name + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(targetType === 'scene' ? 'Scena corrente' : 'Se stesso') + '</div>'
                    + '<div class="small mt-1">' + this.statusBadge(row) + '</div></div>'
                    + '<div class="small text-end">' + this.effectLabel(row.effect_mode, stateName) + '</div>'
                    + '</li>'
                );
            }
            html.push('</ul>');
            root.innerHTML = html.join('');
        },

        renderPageList: function (rows) {
            var root = document.getElementById('logeon-abilities-page-list');
            if (!root) {
                return;
            }

            if (!rows.length) {
                root.innerHTML = '<div class="alert alert-secondary mb-0">Nessuna abilita assegnata al personaggio corrente.</div>';
                return;
            }

            var html = ['<div class="row g-3">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var name = escapeHtml(row.name || 'Abilita');
                var description = escapeHtml(row.description || '');
                var targetType = String(row.target_type || 'self').trim().toLowerCase();
                var stateName = escapeHtml(row.applies_state_name || '');
                var pointInfo = this.pointInfoHtml(row);
                var progressInfo = this.progressInfoHtml(row);
                html.push(
                    '<div class="col-12 col-lg-6">'
                    + '<div class="card h-100">'
                    + '<div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2">'
                    + '<h6 class="mb-1">' + name + '</h6>'
                    + '<span class="badge text-bg-secondary">' + escapeHtml(targetType === 'scene' ? 'Scena' : 'Se stesso') + '</span>'
                    + '</div>'
                    + (description !== '' ? '<p class="small text-muted mb-2">' + description + '</p>' : '<p class="small text-muted mb-2">Nessuna descrizione.</p>')
                    + '<div class="small mb-2">' + this.statusBadge(row) + '</div>'
                    + pointInfo
                    + progressInfo
                    + this.requirementsHtml(row)
                    + '<div class="small mb-3">' + this.effectLabel(row.effect_mode, stateName) + '</div>'
                    + this.actionButtonsHtml(row)
                    + '</div>'
                    + '</div>'
                    + '</div>'
                );
            }
            html.push('</div>');
            root.innerHTML = html.join('');
        },

        sceneRows: function (rows) {
            var filtered = [];
            rows = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var status = String(row.status || '').trim().toLowerCase();
                if (status === 'learned' || status === 'learning' || status === 'pending_approval' || status === 'suspended') {
                    filtered.push(row);
                }
            }
            return filtered;
        },

        pointBalanceBySlug: function (slug) {
            var target = String(slug || '').trim().toLowerCase();
            for (var i = 0; i < this.pointRows.length; i += 1) {
                var row = this.pointRows[i] || {};
                if (String(row.slug || '').trim().toLowerCase() === target) {
                    return row;
                }
            }
            return null;
        },

        pointInfoHtml: function (row) {
            var pointCategory = String((row && row.point_category) || '').trim();
            if (!pointCategory) {
                return '';
            }
            var balance = this.pointBalanceBySlug(pointCategory);
            var available = balance ? (parseInt(balance.available_points || '0', 10) || 0) : 0;
            var name = String((row && row.point_category_name) || pointCategory).trim();
            return '<div class="small text-muted mb-2">Categoria punti: <b>' + escapeHtml(name) + '</b> · Disponibili: <b>' + escapeHtml(String(available)) + '</b></div>';
        },

        progressInfoHtml: function (row) {
            var required = parseInt((row && row.points_required) || '0', 10) || 0;
            var pending = parseInt((row && row.pending_points) || '0', 10) || 0;
            var remaining = parseInt((row && row.points_remaining_to_next_level) || '0', 10) || 0;
            var pendingUpgrade = !!(row && row.pending_upgrade);
            if (required <= 0 && pending <= 0 && remaining <= 0) {
                return '';
            }
            return '<div class="small text-muted mb-2">Progressione: investiti <b>' + escapeHtml(String(pending)) + '</b> / richiesti <b>' + escapeHtml(String(required)) + '</b> · restano <b>' + escapeHtml(String(remaining)) + '</b>' + (pendingUpgrade ? ' · il livello attuale resta utilizzabile' : '') + '</div>';
        },

        actionButtonsHtml: function (row) {
            var abilityId = parseInt((row && row.id) || '0', 10) || 0;
            if (abilityId <= 0) {
                return '';
            }

            var targetType = String((row && row.target_type) || 'self').trim().toLowerCase();
            var status = String((row && row.status) || '').trim().toLowerCase();
            var approvalStatus = String((row && row.approval_status) || '').trim().toLowerCase();
            var html = ['<div class="d-flex flex-wrap gap-2">'];

            if (row && row.usable) {
                html.push('<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-use-ability" data-ability-id="' + abilityId + '" data-target-type="' + escapeHtml(targetType) + '">Usa</button>');
            }

            if (row && (row.learnable || status === 'learning') && parseInt(row.level || '0', 10) <= 0) {
                html.push('<button type="button" class="btn btn-sm btn-primary" data-action="lfas-learn-ability" data-ability-id="' + abilityId + '">' + this.learnButtonLabel(row) + '</button>');
            } else if (row && (row.upgradeable || (status === 'learning' && parseInt(row.level || '0', 10) > 0))) {
                html.push('<button type="button" class="btn btn-sm btn-primary" data-action="lfas-upgrade-ability" data-ability-id="' + abilityId + '">' + this.upgradeButtonLabel(row) + '</button>');
            }

            if (status === 'pending_approval') {
                html.push('<button type="button" class="btn btn-sm btn-outline-warning" disabled>' + (row && row.pending_upgrade ? 'Upgrade in approvazione' : 'In approvazione') + '</button>');
            } else if (approvalStatus === 'rejected') {
                html.push('<button type="button" class="btn btn-sm btn-outline-danger" disabled>Richiesta respinta</button>');
            }
            if (status === 'suspended') {
                html.push('<button type="button" class="btn btn-sm btn-outline-secondary" disabled>Sospesa</button>');
            }

            html.push('</div>');
            return html.join('');
        },

        learnButtonLabel: function (row) {
            var remaining = parseInt((row && row.points_remaining_to_next_level) || '0', 10) || 0;
            var approvalStatus = String((row && row.approval_status) || '').trim().toLowerCase();
            if (approvalStatus === 'rejected') {
                return 'Ripresenta richiesta';
            }
            if (remaining <= 0) {
                return 'Apprendi';
            }
            return 'Investi / apprendi';
        },

        upgradeButtonLabel: function (row) {
            var remaining = parseInt((row && row.points_remaining_to_next_level) || '0', 10) || 0;
            var approvalStatus = String((row && row.approval_status) || '').trim().toLowerCase();
            if (approvalStatus === 'rejected') {
                return 'Ripresenta upgrade';
            }
            if (remaining <= 0) {
                return 'Upgrade';
            }
            return 'Investi / upgrade';
        },

        effectLabel: function (effectMode, stateName) {
            var mode = String(effectMode || 'none').trim().toLowerCase();
            var name = String(stateName || '').trim();
            if (mode === 'apply_state') {
                return '<span class="badge text-bg-info">Applica' + (name !== '' ? ': ' + escapeHtml(name) : '') + '</span>';
            }
            if (mode === 'remove_state') {
                return '<span class="badge text-bg-warning">Rimuovi' + (name !== '' ? ': ' + escapeHtml(name) : '') + '</span>';
            }
            return '<span class="badge text-bg-light">Nessun effetto</span>';
        },

        statusBadge: function (row) {
            var status = String((row && row.status) || '').trim().toLowerCase();
            var approvalStatus = String((row && row.approval_status) || '').trim().toLowerCase();
            var level = parseInt((row && row.level) || '0', 10) || 0;
            var label = 'Disponibile';
            var klass = 'text-bg-secondary';

            if (status === 'learned') {
                if (approvalStatus === 'rejected') {
                    label = level > 0 ? ('Upgrade respinto · Lv.' + String(level)) : 'Richiesta respinta';
                    klass = 'text-bg-danger';
                } else {
                    label = level > 0 ? ('Appresa Lv.' + String(level)) : 'Appresa';
                    klass = 'text-bg-success';
                }
            } else if (status === 'learning') {
                label = approvalStatus === 'rejected' ? 'Richiesta respinta' : 'In apprendimento';
                klass = approvalStatus === 'rejected' ? 'text-bg-danger' : 'text-bg-primary';
            } else if (status === 'pending_approval') {
                label = (row && row.pending_upgrade) ? ('Upgrade in approvazione · Lv.' + String(level)) : 'In approvazione';
                klass = 'text-bg-warning';
            } else if (status === 'suspended') {
                label = 'Sospesa';
                klass = 'text-bg-warning';
            } else if (status === 'disabled') {
                label = 'Disattiva';
                klass = 'text-bg-dark';
            }

            return '<span class="badge ' + klass + '">' + escapeHtml(label) + '</span>';
        },

        requirementsHtml: function (row) {
            var missing = row && Array.isArray(row.missing_requirements) ? row.missing_requirements : [];
            if (!missing.length) {
                return '';
            }

            var hiddenCount = 0;
            var html = ['<div class="small text-muted mt-2">Requisiti mancanti: '];
            var labels = [];
            for (var i = 0; i < missing.length; i += 1) {
                var req = missing[i] || {};
                if (req.hidden) {
                    hiddenCount += 1;
                    continue;
                }
                var type = String(req.type || '').trim();
                var key = String(req.key || '').trim();
                var required = String(req.required || '').trim();
                if (type === 'rank') {
                    labels.push('Grado ' + escapeHtml(String(req.operator || '>=') + ' ' + required));
                } else if (type === 'attribute') {
                    labels.push(escapeHtml(key) + ' ' + escapeHtml(String(req.operator || '>=') + ' ' + required));
                } else if (type === 'ability') {
                    labels.push('Abilita ' + escapeHtml(key) + ' ' + escapeHtml(String(req.operator || '>=') + ' ' + required));
                } else if (type === 'archetype') {
                    labels.push('Archetipo ' + escapeHtml(key));
                }
            }
            if (hiddenCount > 0) {
                labels.push('alcuni requisiti sono nascosti');
            }
            html.push(labels.length ? labels.join(', ') : 'requisiti non soddisfatti');
            html.push('</div>');
            return html.join('');
        }
    };
}

globalWindow.LogeonAbilitiesSpellsGameModuleFactory = createLogeonAbilitiesSpellsGameModule;

if (globalWindow.GameRegistry) {
    globalWindow.GameRegistry.registerModule('game.abilities-spells', 'LogeonAbilitiesSpellsGameModuleFactory');
    globalWindow.GameRegistry.extendPage('location', ['game.abilities-spells']);
    globalWindow.GameRegistry.extendPage('abilities-spells', ['game.abilities-spells']);
}

export { createLogeonAbilitiesSpellsGameModule as LogeonAbilitiesSpellsGameModuleFactory };
export default createLogeonAbilitiesSpellsGameModule;
