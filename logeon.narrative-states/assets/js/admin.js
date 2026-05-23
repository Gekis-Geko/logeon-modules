const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createLogeonNarrativeStatesAdminModule() {
    return {
        ctx: null,
        root: null,
        presetForm: null,
        presetStateForm: null,
        assignmentForm: null,
        presetRows: [],
        presetStateRows: [],
        stateCatalogRows: [],
        assignmentRows: [],
        characterSearchTimer: null,

        mount: function (ctx) {
            this.ctx = ctx || null;
            this.root = document.querySelector('#admin-page [data-admin-page="narrative-states"]');
            if (!this.root) {
                return this;
            }

            this.presetForm = document.getElementById('lfns-preset-form');
            this.presetStateForm = document.getElementById('lfns-preset-state-form');
            this.assignmentForm = document.getElementById('lfns-assignment-form');

            this.bindEvents();
            this.loadAll(true);
            return this;
        },

        unmount: function () {},

        bindEvents: function () {
            var self = this;

            if (this.presetForm) {
                this.presetForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.savePreset();
                });
            }

            if (this.presetStateForm) {
                this.presetStateForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.savePresetState();
                });
            }

            if (this.assignmentForm) {
                this.assignmentForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.saveAssignment();
                });
            }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'lfns-admin-reload') {
                    event.preventDefault();
                    self.loadAll(true);
                    return;
                }
                if (action === 'lfns-preset-reset') {
                    event.preventDefault();
                    self.resetPresetForm();
                    return;
                }
                if (action === 'lfns-preset-state-reset') {
                    event.preventDefault();
                    self.resetPresetStateForm();
                    return;
                }
                if (action === 'lfns-assignment-reset') {
                    event.preventDefault();
                    self.resetAssignmentForm();
                    return;
                }
                if (action === 'lfns-preset-edit') {
                    event.preventDefault();
                    self.editPreset(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-preset-delete') {
                    event.preventDefault();
                    self.deletePreset(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-preset-state-edit') {
                    event.preventDefault();
                    self.editPresetState(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-preset-state-delete') {
                    event.preventDefault();
                    self.deletePresetState(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-assignment-edit') {
                    event.preventDefault();
                    self.editAssignment(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-assignment-delete') {
                    event.preventDefault();
                    self.deleteAssignment(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfns-preset-states-load') {
                    event.preventDefault();
                    self.loadPresetStatesForCurrentPreset();
                    return;
                }
                if (action === 'lfns-assignments-load') {
                    event.preventDefault();
                    self.loadAssignmentsForCurrentCharacter();
                }
            });

            var characterSearch = document.getElementById('lfns-character-search');
            if (characterSearch) {
                characterSearch.addEventListener('input', function () {
                    if (self.assignmentForm && self.assignmentForm.elements.character_id) {
                        self.assignmentForm.elements.character_id.value = '';
                    }
                    self.scheduleCharacterSearch(characterSearch.value || '');
                });
            }

            document.addEventListener('click', function (event) {
                var result = event.target && event.target.closest ? event.target.closest('[data-role="lfns-character-result"]') : null;
                if (!result) {
                    return;
                }

                event.preventDefault();
                self.selectCharacterResult(result);
            });
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

        notify: function (body, type) {
            if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                globalWindow.Toast.show({ body: body, type: type || 'info' });
            }
        },

        loadAll: function (loadAssignments) {
            var self = this;
            return Promise.all([
                this.request('/admin/narrative-states/states/catalog', 'lfnsStatesCatalog', {}),
                this.request('/admin/narrative-states/presets/list', 'lfnsPresetsList', {})
            ]).then(function (responses) {
                self.stateCatalogRows = responses[0] && Array.isArray(responses[0].dataset) ? responses[0].dataset : [];
                self.presetRows = responses[1] && Array.isArray(responses[1].dataset) ? responses[1].dataset : [];
                self.renderStateOptions();
                self.renderPresetOptions();
                self.renderPresetsTable();
                self.loadPresetStatesForCurrentPreset(true);
                if (loadAssignments === true) {
                    self.loadAssignmentsForCurrentCharacter(true);
                }
            }).catch(function (error) {
                self.stateCatalogRows = [];
                self.presetRows = [];
                self.renderStateOptions();
                self.renderPresetOptions();
                self.renderPresetsTable();
                self.notify((error && error.message) ? error.message : 'Caricamento modulo non riuscito.', 'warning');
            });
        },

        renderStateOptions: function () {
            var select = document.getElementById('lfns-state-select');
            if (!select) {
                return;
            }

            var html = ['<option value="">Seleziona...</option>'];
            for (var i = 0; i < this.stateCatalogRows.length; i += 1) {
                var row = this.stateCatalogRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(row.name || row.code || ('Stato #' + id)) + '</option>');
            }
            select.innerHTML = html.join('');
        },

        renderPresetOptions: function () {
            var presetSelect = document.getElementById('lfns-preset-select');
            var assignmentPresetSelect = document.getElementById('lfns-assignment-preset-select');
            var html = ['<option value="">Seleziona...</option>'];
            for (var i = 0; i < this.presetRows.length; i += 1) {
                var row = this.presetRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0 || parseInt(row.is_active || '0', 10) !== 1) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(row.name || ('Preset #' + id)) + '</option>');
            }

            if (presetSelect) {
                presetSelect.innerHTML = html.join('');
            }
            if (assignmentPresetSelect) {
                assignmentPresetSelect.innerHTML = html.join('');
            }
        },

        renderPresetsTable: function () {
            var table = document.getElementById('lfns-presets-table');
            var empty = document.getElementById('lfns-presets-empty');
            if (!table || !empty) {
                return;
            }

            if (!this.presetRows.length) {
                table.innerHTML = '';
                empty.classList.remove('d-none');
                return;
            }

            empty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.presetRows.length; i += 1) {
                var row = this.presetRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td>' + id + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.name || '-') + '</div><div class="small text-muted">' + escapeHtml(row.slug || '') + '</div></td>'
                    + '<td>' + escapeHtml(row.target_type_label || 'Personaggio') + '</td>'
                    + '<td>' + escapeHtml(String(row.steps_count || '0')) + '</td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="lfns-preset-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfns-preset-delete" data-id="' + id + '">Elimina</button>'
                    + '</td>'
                    + '</tr>'
                );
            }
            table.innerHTML = html.join('');
        },

        loadPresetStatesForCurrentPreset: function (quiet) {
            if (!this.presetStateForm) {
                return Promise.resolve();
            }

            var presetId = parseInt(this.presetStateForm.elements.preset_id.value || '0', 10) || 0;
            if (presetId <= 0) {
                this.presetStateRows = [];
                this.renderPresetStatesTable();
                if (quiet !== true) {
                    this.notify('Seleziona un preset per vedere i suoi step.', 'warning');
                }
                return Promise.resolve();
            }

            var self = this;
            return this.request('/admin/narrative-states/preset-states/list', 'lfnsPresetStatesList', {
                preset_id: presetId
            }).then(function (response) {
                self.presetStateRows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderPresetStatesTable();
            }).catch(function (error) {
                self.presetStateRows = [];
                self.renderPresetStatesTable();
                if (quiet !== true) {
                    self.notify((error && error.message) ? error.message : 'Caricamento step preset non riuscito.', 'warning');
                }
            });
        },

        renderPresetStatesTable: function () {
            var table = document.getElementById('lfns-preset-states-table');
            var empty = document.getElementById('lfns-preset-states-empty');
            if (!table || !empty) {
                return;
            }

            if (!this.presetStateRows.length) {
                table.innerHTML = '';
                empty.classList.remove('d-none');
                return;
            }

            empty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.presetStateRows.length; i += 1) {
                var row = this.presetStateRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td>' + escapeHtml(row.preset_name || '-') + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.state_name || '-') + '</div><div class="small text-muted">' + escapeHtml(row.state_code || '') + '</div></td>'
                    + '<td>' + escapeHtml(row.effect_mode_label || 'Applica') + '</td>'
                    + '<td>' + escapeHtml(row.duration_label || '-') + '</td>'
                    + '<td class="text-end">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="lfns-preset-state-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfns-preset-state-delete" data-id="' + id + '">Elimina</button>'
                    + '</td>'
                    + '</tr>'
                );
            }
            table.innerHTML = html.join('');
        },

        loadAssignmentsForCurrentCharacter: function (quiet) {
            if (!this.assignmentForm) {
                return Promise.resolve();
            }

            var characterId = parseInt(this.assignmentForm.elements.character_id.value || '0', 10) || 0;
            if (characterId <= 0) {
                this.assignmentRows = [];
                this.renderAssignmentsTable();
                if (quiet !== true) {
                    this.notify('Seleziona un personaggio per vedere le assegnazioni.', 'warning');
                }
                return Promise.resolve();
            }

            var self = this;
            return this.request('/admin/narrative-states/assignments/list', 'lfnsAssignmentsList', {
                character_id: characterId
            }).then(function (response) {
                self.assignmentRows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderAssignmentsTable();
            }).catch(function (error) {
                self.assignmentRows = [];
                self.renderAssignmentsTable();
                if (quiet !== true) {
                    self.notify((error && error.message) ? error.message : 'Caricamento assegnazioni non riuscito.', 'warning');
                }
            });
        },

        renderAssignmentsTable: function () {
            var table = document.getElementById('lfns-assignments-table');
            var empty = document.getElementById('lfns-assignments-empty');
            if (!table || !empty) {
                return;
            }

            if (!this.assignmentRows.length) {
                table.innerHTML = '';
                empty.classList.remove('d-none');
                return;
            }

            empty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.assignmentRows.length; i += 1) {
                var row = this.assignmentRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.preset_name || '-') + '</div><div class="small text-muted">' + escapeHtml(row.preset_slug || '') + '</div></td>'
                    + '<td>' + escapeHtml(row.target_type_label || 'Personaggio') + '</td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="lfns-assignment-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfns-assignment-delete" data-id="' + id + '">Elimina</button>'
                    + '</td>'
                    + '</tr>'
                );
            }
            table.innerHTML = html.join('');
        },

        serializeForm: function (form) {
            var payload = {};
            if (!form || typeof FormData === 'undefined') {
                return payload;
            }

            var data = new FormData(form);
            data.forEach(function (value, key) {
                payload[key] = value;
            });
            return payload;
        },

        savePreset: function () {
            if (!this.presetForm) {
                return;
            }

            var payload = this.serializeForm(this.presetForm);
            var id = parseInt(payload.id || '0', 10) || 0;
            var url = id > 0
                ? '/admin/narrative-states/presets/update'
                : '/admin/narrative-states/presets/create';
            var action = id > 0 ? 'lfnsPresetUpdate' : 'lfnsPresetCreate';
            var self = this;

            this.request(url, action, payload).then(function () {
                self.notify(id > 0 ? 'Preset aggiornato.' : 'Preset creato.', 'success');
                self.resetPresetForm();
                self.loadAll(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio preset non riuscito.', 'warning');
            });
        },

        savePresetState: function () {
            if (!this.presetStateForm) {
                return;
            }

            var payload = this.serializeForm(this.presetStateForm);
            var id = parseInt(payload.id || '0', 10) || 0;
            var url = id > 0
                ? '/admin/narrative-states/preset-states/update'
                : '/admin/narrative-states/preset-states/create';
            var action = id > 0 ? 'lfnsPresetStateUpdate' : 'lfnsPresetStateCreate';
            var self = this;

            this.request(url, action, payload).then(function () {
                self.notify(id > 0 ? 'Step aggiornato.' : 'Step creato.', 'success');
                self.resetPresetStateForm(payload.preset_id || '');
                self.loadPresetStatesForCurrentPreset(true);
                self.loadAll(false);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio step non riuscito.', 'warning');
            });
        },

        saveAssignment: function () {
            if (!this.assignmentForm) {
                return;
            }

            var payload = this.serializeForm(this.assignmentForm);
            var id = parseInt(payload.id || '0', 10) || 0;
            var url = id > 0
                ? '/admin/narrative-states/assignments/update'
                : '/admin/narrative-states/assignments/create';
            var action = id > 0 ? 'lfnsAssignmentUpdate' : 'lfnsAssignmentCreate';
            var self = this;

            this.request(url, action, payload).then(function () {
                self.notify(id > 0 ? 'Assegnazione aggiornata.' : 'Assegnazione creata.', 'success');
                self.resetAssignmentForm(payload.character_id || '');
                self.loadAssignmentsForCurrentCharacter(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio assegnazione non riuscito.', 'warning');
            });
        },

        editPreset: function (presetId) {
            var row = this.findRow(this.presetRows, presetId);
            if (!row || !this.presetForm) {
                return;
            }

            this.presetForm.elements.id.value = String(row.id || 0);
            this.presetForm.elements.name.value = String(row.name || '');
            this.presetForm.elements.slug.value = String(row.slug || '');
            this.presetForm.elements.description.value = String(row.description || '');
            this.presetForm.elements.target_type.value = String(row.target_type || 'character');
            this.presetForm.elements.category_label.value = String(row.category_label || '');
            this.presetForm.elements.visible_to_players.value = String(row.visible_to_players || 0);
            this.presetForm.elements.sort_order.value = String(row.sort_order || 100);
            this.presetForm.elements.is_active.value = String(row.is_active || 0);

            if (this.presetStateForm) {
                this.presetStateForm.elements.preset_id.value = String(row.id || 0);
                this.loadPresetStatesForCurrentPreset(true);
            }
        },

        editPresetState: function (presetStateId) {
            var row = this.findRow(this.presetStateRows, presetStateId);
            if (!row || !this.presetStateForm) {
                return;
            }

            this.presetStateForm.elements.id.value = String(row.id || 0);
            this.presetStateForm.elements.preset_id.value = String(row.preset_id || '');
            this.presetStateForm.elements.state_id.value = String(row.state_id || '');
            this.presetStateForm.elements.effect_mode.value = String(row.effect_mode || 'apply');
            this.presetStateForm.elements.intensity.value = String(row.intensity || 1);
            this.presetStateForm.elements.duration_value.value = String(row.duration_value || 0);
            this.presetStateForm.elements.duration_unit.value = String(row.duration_unit || 'scene');
            this.presetStateForm.elements.sort_order.value = String(row.sort_order || 100);
        },

        editAssignment: function (assignmentId) {
            var row = this.findRow(this.assignmentRows, assignmentId);
            if (!row || !this.assignmentForm) {
                return;
            }

            this.assignmentForm.elements.id.value = String(row.id || 0);
            this.assignmentForm.elements.character_id.value = String(row.character_id || 0);
            this.assignmentForm.elements.preset_id.value = String(row.preset_id || '');
            this.assignmentForm.elements.sort_order.value = String(row.sort_order || 100);
            this.assignmentForm.elements.is_active.value = String(row.is_active || 0);

            var search = document.getElementById('lfns-character-search');
            if (search) {
                search.value = String(row.character_label || '');
            }
        },

        deletePreset: function (presetId) {
            if (presetId <= 0 || !globalWindow.confirm('Eliminare questo preset narrativo?')) {
                return;
            }

            var self = this;
            this.request('/admin/narrative-states/presets/delete', 'lfnsPresetDelete', { id: presetId })
                .then(function () {
                    self.notify('Preset eliminato.', 'success');
                    self.resetPresetForm();
                    self.loadAll(true);
                })
                .catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione preset non riuscita.', 'warning');
                });
        },

        deletePresetState: function (presetStateId) {
            if (presetStateId <= 0 || !globalWindow.confirm('Eliminare questo step del preset?')) {
                return;
            }

            var self = this;
            this.request('/admin/narrative-states/preset-states/delete', 'lfnsPresetStateDelete', { id: presetStateId })
                .then(function () {
                    self.notify('Step eliminato.', 'success');
                    self.resetPresetStateForm(self.presetStateForm ? self.presetStateForm.elements.preset_id.value : '');
                    self.loadPresetStatesForCurrentPreset(true);
                    self.loadAll(false);
                })
                .catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione step non riuscita.', 'warning');
                });
        },

        deleteAssignment: function (assignmentId) {
            if (assignmentId <= 0 || !globalWindow.confirm('Eliminare questa assegnazione?')) {
                return;
            }

            var self = this;
            this.request('/admin/narrative-states/assignments/delete', 'lfnsAssignmentDelete', { id: assignmentId })
                .then(function () {
                    self.notify('Assegnazione eliminata.', 'success');
                    self.resetAssignmentForm(self.assignmentForm ? self.assignmentForm.elements.character_id.value : '');
                    self.loadAssignmentsForCurrentCharacter(true);
                })
                .catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione assegnazione non riuscita.', 'warning');
                });
        },

        resetPresetForm: function () {
            if (!this.presetForm) {
                return;
            }

            this.presetForm.reset();
            this.presetForm.elements.id.value = '0';
            this.presetForm.elements.target_type.value = 'character';
            this.presetForm.elements.visible_to_players.value = '1';
            this.presetForm.elements.sort_order.value = '100';
            this.presetForm.elements.is_active.value = '1';
        },

        resetPresetStateForm: function (presetId) {
            if (!this.presetStateForm) {
                return;
            }

            var currentPresetId = String(presetId || this.presetStateForm.elements.preset_id.value || '');
            this.presetStateForm.reset();
            this.presetStateForm.elements.id.value = '0';
            this.presetStateForm.elements.preset_id.value = currentPresetId;
            this.presetStateForm.elements.effect_mode.value = 'apply';
            this.presetStateForm.elements.intensity.value = '1';
            this.presetStateForm.elements.duration_value.value = '0';
            this.presetStateForm.elements.duration_unit.value = 'scene';
            this.presetStateForm.elements.sort_order.value = '100';
        },

        resetAssignmentForm: function (characterId) {
            if (!this.assignmentForm) {
                return;
            }

            var currentCharacterId = String(characterId || this.assignmentForm.elements.character_id.value || '');
            var currentCharacterLabel = '';
            var search = document.getElementById('lfns-character-search');
            if (search) {
                currentCharacterLabel = String(search.value || '');
            }

            this.assignmentForm.reset();
            this.assignmentForm.elements.id.value = '0';
            this.assignmentForm.elements.character_id.value = currentCharacterId;
            this.assignmentForm.elements.sort_order.value = '100';
            this.assignmentForm.elements.is_active.value = '1';
            if (search) {
                search.value = currentCharacterLabel;
            }
            this.clearSearchResults('lfns-character-search-results');
        },

        findRow: function (rows, id) {
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                if ((parseInt(row.id || '0', 10) || 0) === id) {
                    return row;
                }
            }
            return null;
        },

        scheduleCharacterSearch: function (query) {
            var self = this;
            globalWindow.clearTimeout(this.characterSearchTimer);
            this.characterSearchTimer = globalWindow.setTimeout(function () {
                self.runCharacterSearch(query);
            }, 180);
        },

        runCharacterSearch: function (query) {
            var self = this;
            var needle = String(query || '').trim();
            if (needle.length < 2) {
                this.clearSearchResults('lfns-character-search-results');
                return;
            }

            this.request('/admin/narrative-states/characters/search', 'lfnsCharactersSearch', {
                query: needle
            }).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderCharacterSearchResults(rows);
            }).catch(function () {
                self.clearSearchResults('lfns-character-search-results');
            });
        },

        renderCharacterSearchResults: function (rows) {
            var root = document.getElementById('lfns-character-search-results');
            if (!root) {
                return;
            }

            if (!rows.length) {
                root.innerHTML = '';
                return;
            }

            var html = [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html.push(
                    '<button type="button" class="list-group-item list-group-item-action"'
                    + ' data-role="lfns-character-result"'
                    + ' data-id="' + (parseInt(row.id || '0', 10) || 0) + '"'
                    + ' data-label="' + escapeHtml(row.label || '') + '">'
                    + escapeHtml(row.label || ('#' + String(row.id || '0')))
                    + '</button>'
                );
            }
            root.innerHTML = html.join('');
        },

        selectCharacterResult: function (trigger) {
            if (!this.assignmentForm) {
                return;
            }

            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) {
                return;
            }

            var label = String(trigger.getAttribute('data-label') || '').trim();
            this.assignmentForm.elements.character_id.value = String(id);
            var search = document.getElementById('lfns-character-search');
            if (search) {
                search.value = label;
            }
            this.clearSearchResults('lfns-character-search-results');
            this.loadAssignmentsForCurrentCharacter(true);
        },

        clearSearchResults: function (id) {
            var root = document.getElementById(id);
            if (root) {
                root.innerHTML = '';
            }
        }
    };
}

globalWindow.LogeonNarrativeStatesAdminModuleFactory = createLogeonNarrativeStatesAdminModule;

if (globalWindow.AdminRegistry) {
    globalWindow.AdminRegistry.registerModule('admin.narrative-states', 'LogeonNarrativeStatesAdminModuleFactory');
    globalWindow.AdminRegistry.extendPage('narrative-states', ['admin.narrative-states']);
}

export { createLogeonNarrativeStatesAdminModule as LogeonNarrativeStatesAdminModuleFactory };
export default createLogeonNarrativeStatesAdminModule;
