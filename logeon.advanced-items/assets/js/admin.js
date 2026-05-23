const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createLogeonAdvancedItemsAdminModule() {
    return {
        ctx: null,
        root: null,
        profileForm: null,
        assignmentForm: null,
        profileSelect: null,
        characterSearchTimer: null,
        itemSearchTimer: null,
        profileRows: [],
        assignmentRows: [],

        mount: function (ctx) {
            this.ctx = ctx || null;
            this.root = document.querySelector('#admin-page [data-admin-page="advanced-items"]');
            if (!this.root) {
                return this;
            }

            this.profileForm = document.getElementById('lfai-profile-form');
            this.assignmentForm = document.getElementById('lfai-assignment-form');
            this.profileSelect = document.getElementById('lfai-profile-select');

            this.bindEvents();
            this.loadProfiles(true);
            return this;
        },

        unmount: function () {},

        bindEvents: function () {
            var self = this;

            if (this.profileForm) {
                this.profileForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.saveProfile();
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
                if (action === 'lfai-admin-reload') {
                    event.preventDefault();
                    self.loadProfiles(true);
                    return;
                }
                if (action === 'lfai-profile-reset') {
                    event.preventDefault();
                    self.resetProfileForm();
                    return;
                }
                if (action === 'lfai-assignment-reset') {
                    event.preventDefault();
                    self.resetAssignmentForm();
                    return;
                }
                if (action === 'lfai-profile-edit') {
                    event.preventDefault();
                    self.editProfile(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfai-profile-delete') {
                    event.preventDefault();
                    self.deleteProfile(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfai-assignment-edit') {
                    event.preventDefault();
                    self.editAssignment(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfai-assignment-delete') {
                    event.preventDefault();
                    self.deleteAssignment(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfai-assignments-load') {
                    event.preventDefault();
                    self.loadAssignmentsForCurrentCharacter();
                    return;
                }
            });

            var characterSearch = document.getElementById('lfai-character-search');
            if (characterSearch) {
                characterSearch.addEventListener('input', function () {
                    if (self.assignmentForm && self.assignmentForm.elements.character_id) {
                        self.assignmentForm.elements.character_id.value = '';
                    }
                    self.scheduleCharacterSearch(characterSearch.value || '');
                });
            }

            var itemSearch = document.getElementById('lfai-item-search');
            if (itemSearch) {
                itemSearch.addEventListener('input', function () {
                    if (self.profileForm && self.profileForm.elements.linked_item_id) {
                        self.profileForm.elements.linked_item_id.value = '';
                    }
                    self.scheduleItemSearch(itemSearch.value || '');
                });
            }

            document.addEventListener('click', function (event) {
                var characterResult = event.target && event.target.closest ? event.target.closest('[data-role="lfai-character-result"]') : null;
                if (characterResult) {
                    event.preventDefault();
                    self.selectCharacterResult(characterResult);
                    return;
                }

                var itemResult = event.target && event.target.closest ? event.target.closest('[data-role="lfai-item-result"]') : null;
                if (itemResult) {
                    event.preventDefault();
                    self.selectItemResult(itemResult);
                }
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

        loadProfiles: function (loadAssignments) {
            var self = this;
            return this.request('/admin/advanced-items/profiles/list', 'lfaiProfilesList', {})
                .then(function (response) {
                    self.profileRows = response && Array.isArray(response.dataset) ? response.dataset : [];
                    self.renderProfilesTable();
                    self.renderProfileSelect();
                    if (loadAssignments === true) {
                        self.loadAssignmentsForCurrentCharacter(true);
                    }
                })
                .catch(function (error) {
                    self.profileRows = [];
                    self.renderProfilesTable();
                    self.renderProfileSelect();
                    self.notify((error && error.message) ? error.message : 'Caricamento profili non riuscito.', 'warning');
                });
        },

        renderProfileSelect: function () {
            if (!this.profileSelect) {
                return;
            }

            var html = ['<option value="">Seleziona...</option>'];
            for (var i = 0; i < this.profileRows.length; i += 1) {
                var row = this.profileRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0 || parseInt(row.is_active || '0', 10) !== 1) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(row.name || ('Profilo #' + id)) + '</option>');
            }
            this.profileSelect.innerHTML = html.join('');
        },

        renderProfilesTable: function () {
            var table = document.getElementById('lfai-profiles-table');
            var empty = document.getElementById('lfai-profiles-empty');
            if (!table || !empty) {
                return;
            }

            if (!this.profileRows.length) {
                table.innerHTML = '';
                empty.classList.remove('d-none');
                return;
            }

            empty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.profileRows.length; i += 1) {
                var row = this.profileRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var linked = String(row.linked_item_label || '').trim();
                html.push(
                    '<tr>'
                    + '<td>' + id + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.name || '-') + '</div><div class="small text-muted">' + escapeHtml(row.slug || '') + '</div></td>'
                    + '<td><div>' + escapeHtml(row.resource_mode_label || 'Nessuna') + '</div><div class="small text-muted">' + escapeHtml(row.resource_config_label || '') + '</div></td>'
                    + '<td>' + (String(row.narrative_effect_label || '').trim() !== '' ? escapeHtml(row.narrative_effect_label) : '<span class="text-muted small">-</span>') + '</td>'
                    + '<td>' + (linked !== '' ? escapeHtml(linked) : '<span class="text-muted small">-</span>') + '</td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="lfai-profile-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfai-profile-delete" data-id="' + id + '">Elimina</button>'
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
            return this.request('/admin/advanced-items/assignments/list', 'lfaiAssignmentsList', {
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
            var table = document.getElementById('lfai-assignments-table');
            var empty = document.getElementById('lfai-assignments-empty');
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
                var flags = [];
                if (parseInt(row.is_equipped || '0', 10) === 1) {
                    flags.push('<span class="badge text-bg-primary">Equipaggiato</span>');
                }
                flags.push(parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>');
                html.push(
                    '<tr>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.display_name || '-') + '</div><div class="small text-muted">' + escapeHtml(row.profile_name || '') + '</div></td>'
                    + '<td><div>' + escapeHtml(row.resource_label || 'Nessuna risorsa') + '</div><div class="small text-muted">' + escapeHtml(row.resource_status || '') + '</div></td>'
                    + '<td><div class="d-flex flex-wrap gap-1">' + flags.join('') + '</div></td>'
                    + '<td class="text-end">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="lfai-assignment-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfai-assignment-delete" data-id="' + id + '">Elimina</button>'
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

        saveProfile: function () {
            if (!this.profileForm) {
                return;
            }

            var payload = this.serializeForm(this.profileForm);
            var id = parseInt(payload.id || '0', 10) || 0;
            var url = id > 0
                ? '/admin/advanced-items/profiles/update'
                : '/admin/advanced-items/profiles/create';
            var action = id > 0 ? 'lfaiProfileUpdate' : 'lfaiProfileCreate';
            var self = this;

            this.request(url, action, payload).then(function () {
                self.notify(id > 0 ? 'Profilo aggiornato.' : 'Profilo creato.', 'success');
                self.resetProfileForm();
                self.loadProfiles(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio profilo non riuscito.', 'warning');
            });
        },

        saveAssignment: function () {
            if (!this.assignmentForm) {
                return;
            }

            var payload = this.serializeForm(this.assignmentForm);
            var id = parseInt(payload.id || '0', 10) || 0;
            var url = id > 0
                ? '/admin/advanced-items/assignments/update'
                : '/admin/advanced-items/assignments/create';
            var action = id > 0 ? 'lfaiAssignmentUpdate' : 'lfaiAssignmentCreate';
            var self = this;

            this.request(url, action, payload).then(function () {
                self.notify(id > 0 ? 'Assegnazione aggiornata.' : 'Assegnazione creata.', 'success');
                self.resetAssignmentForm(payload.character_id || '');
                self.loadAssignmentsForCurrentCharacter(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio assegnazione non riuscito.', 'warning');
            });
        },

        editProfile: function (profileId) {
            var row = this.findRow(this.profileRows, profileId);
            if (!row || !this.profileForm) {
                return;
            }

            this.profileForm.elements.id.value = String(row.id || 0);
            this.profileForm.elements.name.value = String(row.name || '');
            this.profileForm.elements.slug.value = String(row.slug || '');
            this.profileForm.elements.description.value = String(row.description || '');
            this.profileForm.elements.category.value = String(row.category || 'gear');
            this.profileForm.elements.resource_mode.value = String(row.resource_mode || 'none');
            this.profileForm.elements.rarity_label.value = String(row.rarity_label || '');
            this.profileForm.elements.linked_item_id.value = String(row.linked_item_id || '');
            var itemSearch = document.getElementById('lfai-item-search');
            if (itemSearch) {
                itemSearch.value = String(row.linked_item_label || '');
            }
            this.profileForm.elements.max_charges.value = String(row.max_charges || 0);
            this.profileForm.elements.max_durability.value = String(row.max_durability || 0);
            this.profileForm.elements.max_ammo.value = String(row.max_ammo || 0);
            this.profileForm.elements.use_cost.value = String(row.use_cost || 0);
            this.profileForm.elements.restore_amount.value = String(row.restore_amount || 0);
            this.profileForm.elements.narrative_state_id.value = String(row.narrative_state_id || '');
            this.profileForm.elements.narrative_state_action.value = String(row.narrative_state_action || 'apply');
            this.profileForm.elements.narrative_state_threshold.value = String(row.narrative_state_threshold || 0);
            this.profileForm.elements.state_intensity.value = String(row.state_intensity || '1.00');
            this.profileForm.elements.state_duration_value.value = String(row.state_duration_value || 0);
            this.profileForm.elements.state_duration_unit.value = String(row.state_duration_unit || 'scene');
            this.profileForm.elements.sort_order.value = String(row.sort_order || 100);
            this.profileForm.elements.is_active.value = String(row.is_active || 0);
        },

        editAssignment: function (assignmentId) {
            var row = this.findRow(this.assignmentRows, assignmentId);
            if (!row || !this.assignmentForm) {
                return;
            }

            this.assignmentForm.elements.id.value = String(row.id || 0);
            this.assignmentForm.elements.character_id.value = String(row.character_id || 0);
            this.assignmentForm.elements.profile_id.value = String(row.profile_id || '');
            this.assignmentForm.elements.custom_name.value = String(row.custom_name || '');
            this.assignmentForm.elements.charges_current.value = String(row.charges_current || 0);
            this.assignmentForm.elements.durability_current.value = String(row.durability_current || 0);
            this.assignmentForm.elements.ammo_current.value = String(row.ammo_current || 0);
            this.assignmentForm.elements.is_equipped.value = String(row.is_equipped || 0);
            this.assignmentForm.elements.note.value = String(row.note || '');
            this.assignmentForm.elements.sort_order.value = String(row.sort_order || 100);
            this.assignmentForm.elements.is_active.value = String(row.is_active || 0);

            var characterSearch = document.getElementById('lfai-character-search');
            if (characterSearch) {
                characterSearch.value = String(row.character_label || '');
            }
        },

        deleteProfile: function (profileId) {
            if (profileId <= 0 || !globalWindow.confirm('Eliminare questo profilo avanzato?')) {
                return;
            }

            var self = this;
            this.request('/admin/advanced-items/profiles/delete', 'lfaiProfileDelete', { id: profileId })
                .then(function () {
                    self.notify('Profilo eliminato.', 'success');
                    self.resetProfileForm();
                    self.loadProfiles(true);
                })
                .catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione profilo non riuscita.', 'warning');
                });
        },

        deleteAssignment: function (assignmentId) {
            if (assignmentId <= 0 || !globalWindow.confirm('Eliminare questa assegnazione?')) {
                return;
            }

            var self = this;
            this.request('/admin/advanced-items/assignments/delete', 'lfaiAssignmentDelete', { id: assignmentId })
                .then(function () {
                    self.notify('Assegnazione eliminata.', 'success');
                    self.resetAssignmentForm(self.assignmentForm ? self.assignmentForm.elements.character_id.value : '');
                    self.loadAssignmentsForCurrentCharacter(true);
                })
                .catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione assegnazione non riuscita.', 'warning');
                });
        },

        resetProfileForm: function () {
            if (!this.profileForm) {
                return;
            }

            this.profileForm.reset();
            this.profileForm.elements.id.value = '0';
            this.profileForm.elements.category.value = 'gear';
            this.profileForm.elements.resource_mode.value = 'none';
            this.profileForm.elements.max_charges.value = '0';
            this.profileForm.elements.max_durability.value = '0';
            this.profileForm.elements.max_ammo.value = '0';
            this.profileForm.elements.use_cost.value = '1';
            this.profileForm.elements.restore_amount.value = '1';
            this.profileForm.elements.narrative_state_id.value = '';
            this.profileForm.elements.narrative_state_action.value = 'apply';
            this.profileForm.elements.narrative_state_threshold.value = '0';
            this.profileForm.elements.state_intensity.value = '1.00';
            this.profileForm.elements.state_duration_value.value = '0';
            this.profileForm.elements.state_duration_unit.value = 'scene';
            this.profileForm.elements.sort_order.value = '100';
            this.profileForm.elements.is_active.value = '1';
            this.profileForm.elements.linked_item_id.value = '';
            var search = document.getElementById('lfai-item-search');
            if (search) {
                search.value = '';
            }
            this.clearSearchResults('lfai-item-search-results');
        },

        resetAssignmentForm: function (characterId) {
            if (!this.assignmentForm) {
                return;
            }

            var currentCharacterId = String(characterId || this.assignmentForm.elements.character_id.value || '');
            var currentCharacterLabel = '';
            var search = document.getElementById('lfai-character-search');
            if (search) {
                currentCharacterLabel = String(search.value || '');
            }

            this.assignmentForm.reset();
            this.assignmentForm.elements.id.value = '0';
            this.assignmentForm.elements.character_id.value = currentCharacterId;
            this.assignmentForm.elements.sort_order.value = '100';
            this.assignmentForm.elements.is_active.value = '1';
            this.assignmentForm.elements.is_equipped.value = '0';
            this.assignmentForm.elements.charges_current.value = '0';
            this.assignmentForm.elements.durability_current.value = '0';
            this.assignmentForm.elements.ammo_current.value = '0';
            if (search) {
                search.value = currentCharacterLabel;
            }
            this.clearSearchResults('lfai-character-search-results');
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
                this.clearSearchResults('lfai-character-search-results');
                return;
            }

            this.request('/admin/advanced-items/characters/search', 'lfaiCharactersSearch', {
                query: needle
            }).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderCharacterSearchResults(rows);
            }).catch(function () {
                self.clearSearchResults('lfai-character-search-results');
            });
        },

        renderCharacterSearchResults: function (rows) {
            var root = document.getElementById('lfai-character-search-results');
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
                    + ' data-role="lfai-character-result"'
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

            var search = document.getElementById('lfai-character-search');
            if (search) {
                search.value = label;
            }
            this.clearSearchResults('lfai-character-search-results');
            this.loadAssignmentsForCurrentCharacter(true);
        },

        scheduleItemSearch: function (query) {
            var self = this;
            globalWindow.clearTimeout(this.itemSearchTimer);
            this.itemSearchTimer = globalWindow.setTimeout(function () {
                self.runItemSearch(query);
            }, 180);
        },

        runItemSearch: function (query) {
            var self = this;
            var needle = String(query || '').trim();
            if (needle.length < 2) {
                this.clearSearchResults('lfai-item-search-results');
                return;
            }

            this.request('/admin/advanced-items/items/search', 'lfaiItemsSearch', {
                query: needle
            }).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderItemSearchResults(rows);
            }).catch(function () {
                self.clearSearchResults('lfai-item-search-results');
            });
        },

        renderItemSearchResults: function (rows) {
            var root = document.getElementById('lfai-item-search-results');
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
                var label = String(row.name || '').trim();
                if (String(row.rarity || '').trim() !== '') {
                    label += ' [' + String(row.rarity || '').trim() + ']';
                }
                html.push(
                    '<button type="button" class="list-group-item list-group-item-action"'
                    + ' data-role="lfai-item-result"'
                    + ' data-id="' + (parseInt(row.id || '0', 10) || 0) + '"'
                    + ' data-label="' + escapeHtml(label) + '">'
                    + escapeHtml(label || ('#' + String(row.id || '0')))
                    + '</button>'
                );
            }
            root.innerHTML = html.join('');
        },

        selectItemResult: function (trigger) {
            if (!this.profileForm) {
                return;
            }

            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) {
                return;
            }

            this.profileForm.elements.linked_item_id.value = String(id);
            var search = document.getElementById('lfai-item-search');
            if (search) {
                search.value = String(trigger.getAttribute('data-label') || '').trim();
            }
            this.clearSearchResults('lfai-item-search-results');
        },

        clearSearchResults: function (id) {
            var root = document.getElementById(id);
            if (root) {
                root.innerHTML = '';
            }
        }
    };
}

globalWindow.LogeonAdvancedItemsAdminModuleFactory = createLogeonAdvancedItemsAdminModule;

if (globalWindow.AdminRegistry) {
    globalWindow.AdminRegistry.registerModule('admin.advanced-items', 'LogeonAdvancedItemsAdminModuleFactory');
    globalWindow.AdminRegistry.extendPage('advanced-items', ['admin.advanced-items']);
}

export { createLogeonAdvancedItemsAdminModule as LogeonAdvancedItemsAdminModuleFactory };
export default createLogeonAdvancedItemsAdminModule;
