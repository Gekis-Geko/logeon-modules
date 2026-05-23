(function () {
    'use strict';

    var page = {
        root: null,
        state: {
            summary: {},
            dependencies: {},
            options: {},
            professions: [],
            processes: [],
            sources: [],
            recent_jobs: []
        },
        selectedProfessionCharacters: [],
        professionCharacterSearchTimer: null,
        sourceScopeSearchTimer: null,

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="crafting-production"]');
            if (!this.root) { return; }
            this.bind();
            this.load();
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var professionCharacterSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="crafting-profession-character-suggestion"]') : null;
                if (professionCharacterSuggestion) {
                    event.preventDefault();
                    self.pickProfessionCharacter(professionCharacterSuggestion);
                    return;
                }

                var professionCharacterRemove = event.target && event.target.closest ? event.target.closest('[data-role="crafting-profession-character-remove"]') : null;
                if (professionCharacterRemove) {
                    event.preventDefault();
                    self.removeProfessionCharacter(parseInt(professionCharacterRemove.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }

                var sourceScopeSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="crafting-source-scope-suggestion"]') : null;
                if (sourceScopeSuggestion) {
                    event.preventDefault();
                    self.pickSourceScope(sourceScopeSuggestion);
                    return;
                }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'crafting-admin-reload') { self.load(); return; }
                if (action === 'crafting-admin-save-profession') { self.saveProfession(); return; }
                if (action === 'crafting-admin-reset-profession') { self.resetForm('profession'); return; }
                if (action === 'crafting-admin-edit-profession') { self.editProfession(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'crafting-admin-delete-profession') { self.deleteProfession(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'crafting-admin-save-process') { self.saveProcess(); return; }
                if (action === 'crafting-admin-reset-process') { self.resetForm('process'); return; }
                if (action === 'crafting-admin-edit-process') { self.editProcess(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'crafting-admin-delete-process') { self.deleteProcess(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'crafting-admin-save-source') { self.saveSource(); return; }
                if (action === 'crafting-admin-reset-source') { self.resetForm('source'); return; }
                if (action === 'crafting-admin-edit-source') { self.editSource(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'crafting-admin-delete-source') { self.deleteSource(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
            });

            var professionCharacterSearch = this.root.querySelector('#crafting-profession-character-search');
            if (professionCharacterSearch) {
                professionCharacterSearch.addEventListener('input', function () {
                    self.scheduleProfessionCharacterSearch(this.value || '');
                });
            }

            var sourceScopeSearch = this.root.querySelector('#crafting-source-scope-search');
            if (sourceScopeSearch) {
                sourceScopeSearch.addEventListener('input', function () {
                    self.clearSourceScopeSelection();
                    self.scheduleSourceScopeSearch(this.value || '');
                });
            }

            var sourceScopeType = this.form('source') ? this.form('source').querySelector('[name="scope_type"]') : null;
            if (sourceScopeType) {
                sourceScopeType.addEventListener('change', function () {
                    self.onSourceScopeTypeChange();
                });
            }
        },

        load: function () {
            var self = this;
            this.post('/admin/crafting-production/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state = dataset;
                self.render();
            });
        },

        render: function () {
            this.setText('[data-role="crafting-summary-professions"]', this.state.summary.professions || 0);
            this.setText('[data-role="crafting-summary-processes"]', this.state.summary.processes || 0);
            this.setText('[data-role="crafting-summary-active-processes"]', this.state.summary.active_processes || 0);
            this.setText('[data-role="crafting-summary-sources"]', this.state.summary.sources || 0);
            this.renderDependencies();
            this.populateSelects();
            this.renderHints();
            this.renderProfessions();
            this.renderProcesses();
            this.renderSources();
            this.renderJobs();
            this.onSourceScopeTypeChange();
        },

        renderDependencies: function () {
            var box = this.root.querySelector('[data-role="crafting-dependencies-note"]');
            if (!box) { return; }
            var parts = ['inventario core attivo'];
            if (parseInt((this.state.dependencies || {}).economy_active || '0', 10) === 1) { parts.push('economy attivo'); }
            if (parseInt((this.state.dependencies || {}).factions_active || '0', 10) === 1) { parts.push('factions attivo'); }
            if (parseInt((this.state.dependencies || {}).quests_active || '0', 10) === 1) { parts.push('quests attivo'); }
            box.textContent = 'Integrazioni rilevate: ' + parts.join(', ') + '.';
        },

        populateSelects: function () {
            this.renderSelectOptions(this.form('process').querySelector('[name="process_type"]'), (this.state.options || {}).process_types || []);
            this.renderSelectOptions(this.form('process').querySelector('[name="visibility"]'), (this.state.options || {}).visibilities || []);
            this.renderSelectOptions(this.form('process').querySelector('[name="duration_type"]'), (this.state.options || {}).duration_types || []);
            this.renderSelectOptions(this.form('source').querySelector('[name="source_type"]'), (this.state.options || {}).source_types || []);
            this.renderSelectOptions(this.form('source').querySelector('[name="visibility"]'), (this.state.options || {}).visibilities || []);
            this.renderSelectOptions(this.form('source').querySelector('[name="scope_type"]'), (this.state.options || {}).scope_types || []);
        },

        renderHints: function () {
            var hints = (this.state.options || {}).hints || {};
            this.setText('[data-role="crafting-hint-inputs"]', hints.inputs || '');
            this.setText('[data-role="crafting-hint-outputs"]', hints.outputs || '');
            this.setText('[data-role="crafting-hint-requirements"]', hints.requirements || '');
            this.setText('[data-role="crafting-hint-source-items"]', hints.source_items || '');
        },

        renderProfessions: function () {
            var body = this.root.querySelector('[data-role="crafting-professions-table"]');
            var rows = Array.isArray(this.state.professions) ? this.state.professions : [];
            if (!body) { return; }
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessuna professione configurata.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var labels = Array.isArray(row.assigned_character_labels) ? row.assigned_character_labels : [];
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.name || '-') + '</div><div class="small text-muted">' + this.escape(row.code || '') + '</div></td>'
                    + '<td><div class="small">' + this.escape(labels.join(', ') || 'Nessuna assegnazione') + '</div></td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="crafting-admin-edit-profession" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="crafting-admin-delete-profession" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Elimina</button>'
                    + '</div></td></tr>';
            }
            body.innerHTML = html;
        },

        renderProcesses: function () {
            var body = this.root.querySelector('[data-role="crafting-processes-table"]');
            var rows = Array.isArray(this.state.processes) ? this.state.processes : [];
            if (!body) { return; }
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessun processo configurato.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var badges = [];
                if (parseInt(row.is_active || '0', 10) === 1) { badges.push('<span class="badge text-bg-success">Attivo</span>'); }
                badges.push('<span class="badge text-bg-secondary">' + this.escape(row.visibility_label || '-') + '</span>');
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.name || '-') + '</div><div class="small text-muted">' + this.escape(row.summary_label || '') + '</div><div class="small">' + badges.join(' ') + '</div></td>'
                    + '<td>' + this.escape(row.process_type_label || '-') + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="crafting-admin-edit-process" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="crafting-admin-delete-process" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Elimina</button>'
                    + '</div></td></tr>';
            }
            body.innerHTML = html;
        },

        renderSources: function () {
            var body = this.root.querySelector('[data-role="crafting-sources-table"]');
            var rows = Array.isArray(this.state.sources) ? this.state.sources : [];
            if (!body) { return; }
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessuna sorgente configurata.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.name || '-') + '</div><div class="small text-muted">' + this.escape(row.source_type_label || '-') + '</div></td>'
                    + '<td><div>' + this.escape(row.scope_label || '-') + '</div><div class="small text-muted">' + this.escape(row.visibility_label || '-') + '</div></td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="crafting-admin-edit-source" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="crafting-admin-delete-source" data-id="' + (parseInt(row.id || '0', 10) || 0) + '">Elimina</button>'
                    + '</div></td></tr>';
            }
            body.innerHTML = html;
        },

        renderJobs: function () {
            var body = this.root.querySelector('[data-role="crafting-jobs-table"]');
            var rows = Array.isArray(this.state.recent_jobs) ? this.state.recent_jobs : [];
            if (!body) { return; }
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="4" class="text-muted small">Nessuna lavorazione registrata.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.process_name || '-') + '</div><div class="small text-muted">' + this.escape(row.process_type_label || '-') + '</div></td>'
                    + '<td>' + this.escape(row.character_label || '-') + '</td>'
                    + '<td>' + this.escape(row.status || '-') + '</td>'
                    + '<td>' + this.escape(row.started_at || '-') + '</td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        scheduleProfessionCharacterSearch: function (query) {
            var self = this;
            window.clearTimeout(this.professionCharacterSearchTimer);
            this.professionCharacterSearchTimer = window.setTimeout(function () {
                self.runProfessionCharacterSearch(query);
            }, 180);
        },

        runProfessionCharacterSearch: function (query) {
            var self = this;
            var needle = String(query || '').trim();
            if (needle.length < 2) {
                this.clearSearchResults('crafting-profession-character-results');
                return;
            }

            this.post('/admin/crafting-production/characters/search', { query: needle }, function (response) {
                self.renderProfessionCharacterResults(response && Array.isArray(response.dataset) ? response.dataset : []);
            });
        },

        renderProfessionCharacterResults: function (rows) {
            var root = this.root.querySelector('#crafting-profession-character-results');
            if (!root) { return; }
            if (!rows.length) {
                root.innerHTML = '';
                return;
            }

            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<button type="button" class="list-group-item list-group-item-action" data-role="crafting-profession-character-suggestion" data-id="' + id + '" data-label="' + this.escapeAttr(row.label || '') + '">'
                    + this.escape(row.label || ('Personaggio #' + id))
                    + '</button>';
            }
            root.innerHTML = html;
        },

        pickProfessionCharacter: function (node) {
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) { return; }
            var label = String(node.getAttribute('data-label') || '').trim() || ('Personaggio #' + id);

            for (var i = 0; i < this.selectedProfessionCharacters.length; i += 1) {
                if ((parseInt(this.selectedProfessionCharacters[i].id || '0', 10) || 0) === id) {
                    this.clearSearchResults('crafting-profession-character-results');
                    var search = this.root.querySelector('#crafting-profession-character-search');
                    if (search) { search.value = ''; }
                    return;
                }
            }

            this.selectedProfessionCharacters.push({ id: id, label: label });
            this.renderProfessionCharacterChips();
            this.syncProfessionCharacterIds();
            this.clearSearchResults('crafting-profession-character-results');
            var input = this.root.querySelector('#crafting-profession-character-search');
            if (input) { input.value = ''; }
        },

        removeProfessionCharacter: function (id) {
            var next = [];
            for (var i = 0; i < this.selectedProfessionCharacters.length; i += 1) {
                if ((parseInt(this.selectedProfessionCharacters[i].id || '0', 10) || 0) !== id) {
                    next.push(this.selectedProfessionCharacters[i]);
                }
            }
            this.selectedProfessionCharacters = next;
            this.renderProfessionCharacterChips();
            this.syncProfessionCharacterIds();
        },

        renderProfessionCharacterChips: function () {
            var root = this.root.querySelector('#crafting-profession-character-chips');
            if (!root) { return; }
            if (!this.selectedProfessionCharacters.length) {
                root.innerHTML = '';
                return;
            }

            var html = '';
            for (var i = 0; i < this.selectedProfessionCharacters.length; i += 1) {
                var row = this.selectedProfessionCharacters[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<span class="badge text-bg-light border text-dark">'
                    + this.escape(row.label || ('Personaggio #' + id))
                    + ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-decoration-none" data-role="crafting-profession-character-remove" data-id="' + id + '">&times;</button>'
                    + '</span>';
            }
            root.innerHTML = html;
        },

        syncProfessionCharacterIds: function () {
            var form = this.form('profession');
            if (!form) { return; }
            var hidden = form.querySelector('[name="assigned_character_ids_csv"]');
            if (!hidden) { return; }

            var ids = [];
            for (var i = 0; i < this.selectedProfessionCharacters.length; i += 1) {
                var id = parseInt(this.selectedProfessionCharacters[i].id || '0', 10) || 0;
                if (id > 0) {
                    ids.push(String(id));
                }
            }
            hidden.value = ids.join(',');
        },

        scheduleSourceScopeSearch: function (query) {
            var self = this;
            window.clearTimeout(this.sourceScopeSearchTimer);
            this.sourceScopeSearchTimer = window.setTimeout(function () {
                self.runSourceScopeSearch(query);
            }, 180);
        },

        runSourceScopeSearch: function (query) {
            var self = this;
            var form = this.form('source');
            if (!form) { return; }
            var scopeType = String((form.querySelector('[name="scope_type"]') || {}).value || '').trim();
            var needle = String(query || '').trim();
            if (scopeType === 'global' || needle.length < 2) {
                this.clearSearchResults('crafting-source-scope-results');
                return;
            }

            this.post('/admin/crafting-production/scope/search', { scope_type: scopeType, query: needle }, function (response) {
                self.renderSourceScopeResults(response && Array.isArray(response.dataset) ? response.dataset : []);
            });
        },

        renderSourceScopeResults: function (rows) {
            var root = this.root.querySelector('#crafting-source-scope-results');
            if (!root) { return; }
            if (!rows.length) {
                root.innerHTML = '';
                return;
            }

            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<button type="button" class="list-group-item list-group-item-action" data-role="crafting-source-scope-suggestion" data-id="' + id + '" data-label="' + this.escapeAttr(row.label || '') + '">'
                    + this.escape(row.label || ('Riferimento #' + id))
                    + '</button>';
            }
            root.innerHTML = html;
        },

        pickSourceScope: function (node) {
            var form = this.form('source');
            if (!form) { return; }
            var hidden = form.querySelector('[name="scope_ref_id"]');
            var input = this.root.querySelector('#crafting-source-scope-search');
            if (!hidden || !input) { return; }

            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) { return; }

            hidden.value = String(id);
            input.value = String(node.getAttribute('data-label') || '').trim();
            this.clearSearchResults('crafting-source-scope-results');
        },

        clearSourceScopeSelection: function () {
            var form = this.form('source');
            if (!form) { return; }
            var hidden = form.querySelector('[name="scope_ref_id"]');
            if (hidden) { hidden.value = '0'; }
        },

        onSourceScopeTypeChange: function () {
            var form = this.form('source');
            var input = this.root.querySelector('#crafting-source-scope-search');
            if (!form || !input) { return; }
            var scopeType = String((form.querySelector('[name="scope_type"]') || {}).value || '').trim();
            var placeholder = 'Cerca riferimento scope...';
            var disabled = false;

            if (scopeType === 'global') {
                placeholder = 'Lo scope globale non richiede un riferimento';
                disabled = true;
                input.value = '';
                this.clearSourceScopeSelection();
                this.clearSearchResults('crafting-source-scope-results');
            } else if (scopeType === 'area') {
                placeholder = 'Cerca luogo...';
            } else if (scopeType === 'faction') {
                placeholder = 'Cerca fazione...';
            } else if (scopeType === 'event') {
                placeholder = 'Cerca evento...';
            }

            input.disabled = disabled;
            input.placeholder = placeholder;
        },

        editProfession: function (id) {
            var row = this.findRow(this.state.professions, id);
            if (!row) { return; }
            this.fillForm('profession', {
                id: row.id || 0,
                code: row.code || '',
                name: row.name || '',
                description: row.description || '',
                is_active: row.is_active || 0,
                assigned_character_ids_csv: row.assigned_character_ids_csv || ''
            });
            this.selectedProfessionCharacters = [];
            var assignedIds = Array.isArray(row.assigned_character_ids) ? row.assigned_character_ids : [];
            var assignedLabels = Array.isArray(row.assigned_character_labels) ? row.assigned_character_labels : [];
            for (var i = 0; i < assignedIds.length; i += 1) {
                var assignedId = parseInt(assignedIds[i] || '0', 10) || 0;
                if (assignedId <= 0) { continue; }
                this.selectedProfessionCharacters.push({
                    id: assignedId,
                    label: assignedLabels[i] || ('Personaggio #' + assignedId)
                });
            }
            this.renderProfessionCharacterChips();
            this.syncProfessionCharacterIds();
            var search = this.root.querySelector('#crafting-profession-character-search');
            if (search) { search.value = ''; }
            this.clearSearchResults('crafting-profession-character-results');
        },

        editProcess: function (id) {
            var row = this.findRow(this.state.processes, id);
            if (!row) { return; }
            var meta = row.metadata_json || {};
            this.fillForm('process', {
                id: row.id || 0,
                name: row.name || '',
                process_type: row.process_type || '',
                category: row.category || '',
                visibility: row.visibility || '',
                is_active: row.is_active || 0,
                station_type: row.station_type || '',
                duration_type: row.duration_type || '',
                duration_value: row.duration_value || 0,
                description: row.description || '',
                inputs_lines: row.inputs_lines || '',
                outputs_lines: row.outputs_lines || '',
                requirements_lines: row.requirements_lines || '',
                notes: meta.notes || ''
            });
        },

        editSource: function (id) {
            var row = this.findRow(this.state.sources, id);
            if (!row) { return; }
            var meta = row.metadata_json || {};
            this.fillForm('source', {
                id: row.id || 0,
                name: row.name || '',
                source_type: row.source_type || '',
                description: row.description || '',
                visibility: row.visibility || '',
                scope_type: row.scope_type || '',
                scope_ref_id: row.scope_ref_id || 0,
                is_active: row.is_active || 0,
                items_lines: row.items_lines || '',
                notes: meta.notes || ''
            });
            var scopeSearch = this.root.querySelector('#crafting-source-scope-search');
            if (scopeSearch) {
                scopeSearch.value = row.scope_type === 'global' ? '' : String(row.scope_label || '');
            }
            this.clearSearchResults('crafting-source-scope-results');
            this.onSourceScopeTypeChange();
        },

        saveProfession: function () {
            var self = this;
            this.post('/admin/crafting-production/save-profession', this.collectForm('profession'), function () {
                self.toast('Professione salvata.', 'success');
                self.resetForm('profession');
                self.load();
            });
        },

        saveProcess: function () {
            var self = this;
            this.post('/admin/crafting-production/save-process', this.collectForm('process'), function () {
                self.toast('Processo salvato.', 'success');
                self.resetForm('process');
                self.load();
            });
        },

        saveSource: function () {
            var self = this;
            this.post('/admin/crafting-production/save-source', this.collectForm('source'), function () {
                self.toast('Sorgente salvata.', 'success');
                self.resetForm('source');
                self.load();
            });
        },

        deleteProfession: function (id) {
            var self = this;
            this.confirm('Elimina professione', 'Confermi l\'eliminazione della professione?', function () {
                self.post('/admin/crafting-production/delete-profession', { id: id }, function () {
                    self.toast('Professione eliminata.', 'success');
                    self.resetForm('profession');
                    self.load();
                });
            });
        },

        deleteProcess: function (id) {
            var self = this;
            this.confirm('Elimina processo', 'Confermi l\'eliminazione del processo?', function () {
                self.post('/admin/crafting-production/delete-process', { id: id }, function () {
                    self.toast('Processo eliminato.', 'success');
                    self.resetForm('process');
                    self.load();
                });
            });
        },

        deleteSource: function (id) {
            var self = this;
            this.confirm('Elimina sorgente', 'Confermi l\'eliminazione della sorgente?', function () {
                self.post('/admin/crafting-production/delete-source', { id: id }, function () {
                    self.toast('Sorgente eliminata.', 'success');
                    self.resetForm('source');
                    self.load();
                });
            });
        },

        resetForm: function (kind) {
            var form = this.form(kind);
            if (!form) { return; }
            form.reset();
            var hidden = form.querySelector('[name="id"]');
            if (hidden) { hidden.value = '0'; }
            if (kind === 'process') {
                this.fillForm('process', { is_active: '1', visibility: 'public', duration_type: 'instant', duration_value: '0' });
            }
            if (kind === 'profession') {
                this.fillForm('profession', { is_active: '1' });
                this.selectedProfessionCharacters = [];
                this.renderProfessionCharacterChips();
                this.syncProfessionCharacterIds();
                var professionSearch = this.root.querySelector('#crafting-profession-character-search');
                if (professionSearch) { professionSearch.value = ''; }
                this.clearSearchResults('crafting-profession-character-results');
            }
            if (kind === 'source') {
                this.fillForm('source', { is_active: '1', visibility: 'public', scope_type: 'global', scope_ref_id: '0' });
                var scopeSearch = this.root.querySelector('#crafting-source-scope-search');
                if (scopeSearch) { scopeSearch.value = ''; }
                this.clearSearchResults('crafting-source-scope-results');
                this.onSourceScopeTypeChange();
            }
        },

        form: function (kind) {
            return this.root.querySelector('[data-role="crafting-' + kind + '-form"]');
        },

        collectForm: function (kind) {
            var form = this.form(kind);
            var payload = {};
            if (!form) { return payload; }
            var fields = form.querySelectorAll('[name]');
            for (var i = 0; i < fields.length; i += 1) {
                payload[fields[i].name] = String(fields[i].value || '').trim();
            }
            payload.id = parseInt(payload.id || '0', 10) || 0;
            return payload;
        },

        fillForm: function (kind, values) {
            var form = this.form(kind);
            if (!form) { return; }
            var keys = Object.keys(values || {});
            for (var i = 0; i < keys.length; i += 1) {
                var field = form.querySelector('[name="' + keys[i] + '"]');
                if (field) {
                    field.value = values[keys[i]] == null ? '' : String(values[keys[i]]);
                }
            }
        },

        clearSearchResults: function (id) {
            var node = this.root.querySelector('#' + id);
            if (node) {
                node.innerHTML = '';
            }
        },

        renderSelectOptions: function (field, rows) {
            if (!field) { return; }
            var current = String(field.value || '');
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                html += '<option value="' + this.escapeAttr(rows[i].value) + '">' + this.escape(rows[i].label) + '</option>';
            }
            field.innerHTML = html;
            if (current) {
                field.value = current;
            }
        },

        findRow: function (rows, id) {
            rows = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < rows.length; i += 1) {
                if ((parseInt(rows[i].id || '0', 10) || 0) === id) {
                    return rows[i];
                }
            }
            return null;
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

        confirm: function (title, body, onConfirm) {
            if (typeof window.Dialog === 'function') {
                window.Dialog('warning', { title: title, body: '<p>' + this.escape(body) + '</p>' }, function () {
                    if (typeof onConfirm === 'function') { onConfirm(); }
                }).show();
                return;
            }
            if (window.confirm(body) && typeof onConfirm === 'function') {
                onConfirm();
            }
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
