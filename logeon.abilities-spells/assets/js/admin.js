const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

var JSON_METADATA_PRESETS = {
    ability: {
        ui_icon: { ui_icon: 'bi bi-stars' },
        ui_audio: { ui_audio: 'spell-chime', ui_variant: 'soft' },
        combat_attack: { ui_icon: 'bi bi-lightning-charge', family: 'combat', slot: 'attack' },
        magic_spell: { ui_icon: 'bi bi-magic', family: 'magic', slot: 'spell' }
    },
    grant: {
        label_only: { label: 'Assassino' },
        label_hidden: { label: 'Assassino', visibility: 'staff_only' },
        rank_window: { note: 'assegnazione limitata a una finestra grado', source_window: true }
    },
    requirement: {
        note_optional: { note: 'Req opzionale' },
        hidden_requirement: { note: 'Requisito nascosto al player', visibility: 'hidden' },
        staff_hint: { staff_hint: 'Verificare prerequisito in scheda personaggio' }
    },
    effect: {
        attribute_bonus: { label: 'Bonus attributo', display_mode: 'passive' },
        passive_aura: { label: 'Aura passiva', aura_scope: 'self' },
        temporary_buff: { label: 'Buff temporaneo', duration_turns: 3 }
    }
};

function stringifyJsonPreset(value) {
    try {
        return JSON.stringify(value || {});
    } catch (error) {
        return '{}';
    }
}

function createLogeonAbilitiesSpellsAdminModule() {
    return {
        ctx: null,
        root: null,
        grid: null,
        abilityModalNode: null,
        abilityModal: null,
        abilityForm: null,
        assignmentModalNode: null,
        assignmentModal: null,
        assignmentForm: null,
        assignmentFilterForm: null,
        assignmentFilterSearchInput: null,
        assignmentFilterResults: null,
        assignmentSearchInput: null,
        assignmentSearchResults: null,
        grantSourceResults: null,
        stateSelect: null,
        pointCategorySelect: null,
        rewardPointCategorySelect: null,
        assignmentAbilitySelect: null,
        assignmentsTable: null,
        assignmentsEmpty: null,
        assignmentContext: null,
        pendingApprovalsTable: null,
        pendingApprovalsEmpty: null,
        rankRewardForm: null,
        rankRewardsTable: null,
        rankRewardsEmpty: null,
        grantForm: null,
        grantsTable: null,
        grantsEmpty: null,
        requirementForm: null,
        requirementsTable: null,
        requirementsEmpty: null,
        effectForm: null,
        effectsTable: null,
        effectsEmpty: null,
        abilityRulesSection: null,
        abilityRulesPanels: null,
        abilityRulesEmpty: null,
        abilityRulesContext: null,
        abilitiesRows: [],
        abilitiesById: {},
        attributeRows: [],
        archetypeRows: [],
        guildRows: [],
        assignmentRows: [],
        stateRows: [],
        pointCategoryRows: [],
        pendingApprovalRows: [],
        rankRewardRows: [],
        grantRows: [],
        requirementRows: [],
        effectRows: [],
        editingAbilityId: 0,
        characterSearchTimer: null,
        optionalWarningsShown: {},
        runtimeCapabilities: {},

        mount: function (ctx) {
            this.ctx = ctx || null;
            this.root = document.querySelector('#admin-page [data-admin-page="abilities-spells"]');
            if (!this.root || !document.getElementById('grid-admin-lfas-abilities')) {
                return this;
            }

            this.abilityModalNode = document.getElementById('lfas-ability-modal');
            this.abilityForm = document.getElementById('lfas-ability-form');
            this.assignmentModalNode = document.getElementById('lfas-assignment-modal');
            this.assignmentForm = document.getElementById('lfas-assignment-form');
            this.assignmentFilterForm = document.getElementById('lfas-assignment-filter-form');
            this.assignmentFilterSearchInput = document.getElementById('lfas-assignment-filter-character-search');
            this.assignmentFilterResults = document.getElementById('lfas-assignment-filter-character-results');
            this.assignmentSearchInput = document.getElementById('lfas-assignment-character-search');
            this.assignmentSearchResults = document.getElementById('lfas-assignment-character-results');
            this.grantSourceResults = document.getElementById('lfas-grant-source-results');
            this.stateSelect = document.getElementById('lfas-state-select');
            this.pointCategorySelect = document.getElementById('lfas-point-category-select');
            this.rewardPointCategorySelect = document.getElementById('lfas-rank-reward-category-select');
            this.assignmentAbilitySelect = document.getElementById('lfas-assignment-ability-select');
            this.assignmentsTable = document.getElementById('lfas-assignments-table');
            this.assignmentsEmpty = document.getElementById('lfas-assignments-empty');
            this.assignmentContext = this.root.querySelector('[data-role="lfas-assignment-context"]');
            this.pendingApprovalsTable = document.getElementById('lfas-pending-approvals-table');
            this.pendingApprovalsEmpty = document.getElementById('lfas-pending-approvals-empty');
            this.rankRewardForm = document.getElementById('lfas-rank-reward-form');
            this.rankRewardsTable = document.getElementById('lfas-rank-rewards-table');
            this.rankRewardsEmpty = document.getElementById('lfas-rank-rewards-empty');
            this.grantForm = document.getElementById('lfas-grant-form');
            this.grantsTable = document.getElementById('lfas-grants-table');
            this.grantsEmpty = document.getElementById('lfas-grants-empty');
            this.requirementForm = document.getElementById('lfas-requirement-form');
            this.requirementsTable = document.getElementById('lfas-requirements-table');
            this.requirementsEmpty = document.getElementById('lfas-requirements-empty');
            this.effectForm = document.getElementById('lfas-effect-form');
            this.effectsTable = document.getElementById('lfas-effects-table');
            this.effectsEmpty = document.getElementById('lfas-effects-empty');
            this.abilityRulesSection = this.root.querySelector('[data-role="lfas-ability-rules-section"]');
            this.abilityRulesPanels = this.root.querySelector('[data-role="lfas-ability-rules-panels"]');
            this.abilityRulesEmpty = this.root.querySelector('[data-role="lfas-ability-rules-empty"]');
            this.abilityRulesContext = this.root.querySelector('[data-role="lfas-ability-rules-context"]');

            if (!this.abilityForm || !this.assignmentForm || !this.assignmentFilterForm) {
                return this;
            }

            if (typeof globalWindow.bootstrap !== 'undefined' && globalWindow.bootstrap && typeof globalWindow.bootstrap.Modal === 'function') {
                this.abilityModal = globalWindow.bootstrap.Modal.getOrCreateInstance(this.abilityModalNode);
                this.assignmentModal = globalWindow.bootstrap.Modal.getOrCreateInstance(this.assignmentModalNode);
            }

            this.bindEvents();
            this.initPopovers(this.root);
            this.initGrid();
            this.loadAll();
            return this;
        },

        unmount: function () {},

        bindEvents: function () {
            var self = this;

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (!action) {
                    return;
                }

                event.preventDefault();

                if (action === 'lfas-admin-reload') {
                    self.loadAll();
                    return;
                }
                if (action === 'lfas-ability-create') {
                    self.openAbilityCreate();
                    return;
                }
                if (action === 'lfas-ability-save') {
                    self.saveAbility(false);
                    return;
                }
                if (action === 'lfas-ability-save-continue') {
                    self.saveAbility(true);
                    return;
                }
                if (action === 'lfas-ability-edit') {
                    self.openAbilityEdit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-ability-delete') {
                    self.deleteAbilityById(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-ability-delete-current') {
                    self.deleteAbilityById(self.editingAbilityId);
                    return;
                }
                if (action === 'lfas-assignment-create') {
                    self.openAssignmentCreate();
                    return;
                }
                if (action === 'lfas-assignment-save') {
                    self.saveAssignment();
                    return;
                }
                if (action === 'lfas-assignment-delete') {
                    self.deleteAssignment(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-assignment-reset') {
                    self.resetAssignmentFilter();
                    return;
                }
                if (action === 'lfas-rank-reward-save') {
                    self.saveRankReward();
                    return;
                }
                if (action === 'lfas-rank-reward-reset') {
                    self.resetRankRewardForm();
                    return;
                }
                if (action === 'lfas-rank-reward-edit') {
                    self.editRankReward(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-rank-reward-delete') {
                    self.deleteRankReward(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-pending-approval-approve') {
                    self.resolvePendingApproval(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0, 'approve');
                    return;
                }
                if (action === 'lfas-pending-approval-reject') {
                    self.resolvePendingApproval(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0, 'reject');
                    return;
                }
                if (action === 'lfas-grant-save') {
                    self.saveGrant();
                    return;
                }
                if (action === 'lfas-grant-reset') {
                    self.resetGrantForm();
                    return;
                }
                if (action === 'lfas-grant-edit') {
                    self.editGrant(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-grant-delete') {
                    self.deleteGrant(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-requirement-save') {
                    self.saveRequirement();
                    return;
                }
                if (action === 'lfas-requirement-reset') {
                    self.resetRequirementForm();
                    return;
                }
                if (action === 'lfas-requirement-edit') {
                    self.editRequirement(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-requirement-delete') {
                    self.deleteRequirement(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-effect-save') {
                    self.saveEffect();
                    return;
                }
                if (action === 'lfas-effect-reset') {
                    self.resetEffectForm();
                    return;
                }
                if (action === 'lfas-effect-edit') {
                    self.editEffect(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-effect-delete') {
                    self.deleteEffect(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'lfas-json-preset-apply') {
                    self.applyJsonPreset(
                        String(trigger.getAttribute('data-form-key') || '').trim(),
                        String(trigger.getAttribute('data-target-name') || 'metadata_json').trim(),
                        String(trigger.getAttribute('data-preset') || '').trim()
                    );
                }
            });

            this.root.addEventListener('change', function (event) {
                var picker = event.target && event.target.closest ? event.target.closest('[data-role="lfas-json-preset-picker"]') : null;
                if (!picker) {
                    return;
                }

                self.applyJsonPreset(
                    String(picker.getAttribute('data-form-key') || '').trim(),
                    String(picker.getAttribute('data-target-name') || 'metadata_json').trim(),
                    String(picker.value || '').trim()
                );
            });

            this.assignmentFilterForm.addEventListener('submit', function (event) {
                event.preventDefault();
                self.loadAssignmentsForCurrentCharacter(false);
            });

            if (this.abilityForm.elements.effect_mode) {
                this.abilityForm.elements.effect_mode.addEventListener('change', function () {
                    self.syncStateField();
                });
            }

            if (this.requirementForm && this.requirementForm.elements.requirement_type) {
                this.requirementForm.elements.requirement_type.addEventListener('change', function () {
                    self.syncRequirementKeyField();
                });
            }

            if (this.grantForm && this.grantForm.elements.source_type) {
                this.grantForm.elements.source_type.addEventListener('change', function () {
                    self.syncGrantSourceField();
                });
            }

            if (this.grantForm && this.grantForm.elements.source_picker) {
                this.grantForm.elements.source_picker.addEventListener('change', function () {
                    if (self.currentGrantSourceType() === 'character') {
                        self.scheduleCharacterSearch(this.value || '', 'grant');
                        return;
                    }
                    self.applyGrantSourcePickerValue();
                });
                this.grantForm.elements.source_picker.addEventListener('input', function () {
                    if (self.currentGrantSourceType() === 'character') {
                        self.clearCharacterSelection('grant');
                        self.scheduleCharacterSearch(this.value || '', 'grant');
                        return;
                    }
                    self.applyGrantSourcePickerValue();
                });
                this.grantForm.elements.source_picker.addEventListener('focus', function () {
                    if (self.currentGrantSourceType() === 'character') {
                        self.scheduleCharacterSearch(this.value || '', 'grant');
                    }
                });
            }

            if (this.effectForm && this.effectForm.elements.effect_type) {
                this.effectForm.elements.effect_type.addEventListener('change', function () {
                    self.syncEffectTargetFields();
                });
            }

            if (this.effectForm && this.effectForm.elements.target_system) {
                this.effectForm.elements.target_system.addEventListener('change', function () {
                    self.syncEffectTargetFields();
                });
                this.effectForm.elements.target_system.addEventListener('input', function () {
                    self.syncEffectTargetFields();
                });
            }

            if (this.assignmentFilterSearchInput) {
                this.assignmentFilterSearchInput.addEventListener('input', function () {
                    self.clearCharacterSelection('filter');
                    self.scheduleCharacterSearch(this.value || '', 'filter');
                });
                this.assignmentFilterSearchInput.addEventListener('focus', function () {
                    self.scheduleCharacterSearch(this.value || '', 'filter');
                });
            }

            if (this.assignmentSearchInput) {
                this.assignmentSearchInput.addEventListener('input', function () {
                    self.clearCharacterSelection('modal');
                    self.scheduleCharacterSearch(this.value || '', 'modal');
                });
                this.assignmentSearchInput.addEventListener('focus', function () {
                    self.scheduleCharacterSearch(this.value || '', 'modal');
                });
            }

            document.addEventListener('click', function (event) {
                var result = event.target && event.target.closest ? event.target.closest('[data-role="lfas-character-result"]') : null;
                if (result) {
                    event.preventDefault();
                    self.selectCharacterResult(result);
                    return;
                }

                var insideSearch = event.target && event.target.closest ? event.target.closest('[data-role="lfas-character-search-wrap"]') : null;
                if (!insideSearch) {
                    self.clearCharacterSearchResults();
                }
            });

            if (this.abilityModalNode) {
                this.abilityModalNode.addEventListener('shown.bs.modal', function () {
                    self.initPopovers(self.abilityModalNode);
                });
            }
        },

        initPopovers: function (scope) {
            if (!globalWindow.bootstrap || typeof globalWindow.bootstrap.Popover !== 'function') {
                return;
            }

            var root = scope && scope.querySelectorAll ? scope : this.root;
            if (!root || !root.querySelectorAll) {
                return;
            }

            var nodes = root.querySelectorAll('[data-bs-toggle="popover"]');
            for (var i = 0; i < nodes.length; i += 1) {
                globalWindow.bootstrap.Popover.getOrCreateInstance(nodes[i], {
                    container: 'body',
                    html: true,
                    sanitize: false,
                    trigger: nodes[i].getAttribute('data-bs-trigger') || 'focus'
                });
            }
        },

        initGrid: function () {
            var self = this;

            this.grid = new globalWindow.Datagrid('grid-admin-lfas-abilities', {
                name: 'LogeonAbilitiesSpellsAdmin',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/abilities-spells/abilities/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
                onGetDataSuccess: function (response) {
                    self.storeAbilities(response && Array.isArray(response.dataset) ? response.dataset : []);
                    self.renderAssignmentAbilityOptions();
                    self.syncRequirementKeyField();
                },
                onGetDataError: function () {
                    self.storeAbilities([]);
                    self.renderAssignmentAbilityOptions();
                    self.syncRequirementKeyField();
                },
                columns: [
                    {
                        label: 'Abilita',
                        field: 'name',
                        sortable: true,
                        style: { textAlign: 'left', width: '26%' },
                        format: function (row) {
                            var description = String(row.description || '').trim();
                            if (description.length > 120) {
                                description = description.substring(0, 120) + '...';
                            }
                            return '<div class="fw-semibold">' + escapeHtml(row.name || '-') + '</div>'
                                + '<div class="small text-muted font-monospace">' + escapeHtml(row.slug || '') + '</div>'
                                + '<div class="small text-muted">' + escapeHtml(row.type || 'ability') + (row.point_category_name ? ' · ' + escapeHtml(row.point_category_name) : '') + '</div>'
                                + (description !== '' ? '<div class="small text-muted mt-1">' + escapeHtml(description) + '</div>' : '');
                        }
                    },
                    {
                        label: 'Bersaglio',
                        field: 'target_type',
                        sortable: true,
                        style: { textAlign: 'left', width: '10%' },
                        format: function (row) {
                            return self.targetBadge(row.target_type);
                        }
                    },
                    {
                        label: 'Effetto',
                        field: 'effect_mode',
                        sortable: true,
                        style: { textAlign: 'left', width: '18%' },
                        format: function (row) {
                            return self.effectBadge(row.effect_mode, row.narrative_state_name);
                        }
                    },
                    {
                        label: 'Runtime',
                        sortable: false,
                        style: { textAlign: 'left', width: '20%' },
                        format: function (row) {
                            return '<span class="badge text-bg-light text-dark">Recupero ' + escapeHtml(String(parseInt(row.cooldown_seconds || '0', 10) || 0)) + 's</span> '
                                + '<span class="badge text-bg-light text-dark">Lv max ' + escapeHtml(String(parseInt(row.max_level || '1', 10) || 1)) + '</span>'
                                + '<div class="small text-muted mt-1">Ordine ' + escapeHtml(String(parseInt(row.sort_order || '100', 10) || 100)) + '</div>';
                        }
                    },
                    {
                        label: 'Flags',
                        sortable: true,
                        style: { textAlign: 'left', width: '16%' },
                        format: function (row) {
                            var flags = [];
                            flags.push(parseInt(row.is_active || '0', 10) === 1
                                ? '<span class="badge text-bg-success">Attiva</span>'
                                : '<span class="badge text-bg-secondary">Disattiva</span>');
                            if (parseInt(row.requires_learning || '0', 10) === 1) {
                                flags.push('<span class="badge text-bg-primary">Apprendimento</span>');
                            }
                            if (parseInt(row.requires_staff_approval || '0', 10) === 1) {
                                flags.push('<span class="badge text-bg-warning">Approvazione</span>');
                            }
                            if (parseInt(row.is_public || '0', 10) === 1) {
                                flags.push('<span class="badge text-bg-info">Pubblica</span>');
                            }
                            return flags.join(' ');
                        }
                    },
                    {
                        label: 'Regole',
                        sortable: false,
                        style: { textAlign: 'left', width: '12%' },
                        format: function (row) {
                            return '<span class="badge text-bg-light text-dark">Assegnazioni ' + escapeHtml(String(parseInt(row.grants_count || '0', 10) || 0)) + '</span> '
                                + '<span class="badge text-bg-light text-dark">Requisiti ' + escapeHtml(String(parseInt(row.requirements_count || '0', 10) || 0)) + '</span> '
                                + '<span class="badge text-bg-light text-dark">Effetti ' + escapeHtml(String(parseInt(row.effects_count || '0', 10) || 0)) + '</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'left', width: '120px' },
                        format: function (row) {
                            var id = parseInt(row.id || '0', 10) || 0;
                            if (id <= 0) {
                                return '-';
                            }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-ability-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-ability-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
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

        requestOptional: function (url, action, payload, fallbackDataset, warningLabel) {
            var self = this;
            return this.request(url, action, payload).catch(function () {
                if (warningLabel && !self.optionalWarningsShown[warningLabel]) {
                    self.optionalWarningsShown[warningLabel] = true;
                    self.notify(warningLabel + ' non disponibile: integrazione disattivata.', 'info');
                }
                return { dataset: Array.isArray(fallbackDataset) ? fallbackDataset : [] };
            });
        },

        loadRuntimeCapabilities: function () {
            var self = this;
            return this.request('/admin/modules/capabilities', 'lfasModulesCapabilities', {}).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                var map = {};
                for (var i = 0; i < rows.length; i += 1) {
                    var row = rows[i] || {};
                    var name = String(row.name || '').trim();
                    if (!name) {
                        continue;
                    }
                    map[name] = !!parseInt(row.available || '0', 10);
                }
                self.runtimeCapabilities = map;
                return map;
            }).catch(function () {
                self.runtimeCapabilities = {};
                return {};
            });
        },

        hasCapability: function (capability) {
            if (!capability) {
                return false;
            }
            return !!this.runtimeCapabilities[String(capability)];
        },

        notify: function (body, type) {
            if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                globalWindow.Toast.show({ body: body, type: type || 'info' });
            }
        },

        confirm: function (title, body, onConfirm) {
            if (typeof globalWindow.Dialog === 'function') {
                globalWindow.Dialog('warning', { title: title, body: '<p>' + body + '</p>' }, function () {
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                }).show();
                return;
            }

            if (globalWindow.confirm(title + '\n\n' + body) && typeof onConfirm === 'function') {
                onConfirm();
            }
        },

        loadAll: function () {
            var self = this;

            return this.loadRuntimeCapabilities().then(function () {
                return Promise.all([
                    self.request('/admin/abilities-spells/states/list', 'lfasStatesList', {}),
                    self.request('/admin/abilities-spells/abilities/list', 'lfasAbilitiesListForMeta', {}),
                    self.request('/admin/abilities-spells/point-categories/list', 'lfasPointCategoriesList', {}),
                    self.request('/admin/abilities-spells/rewards/list', 'lfasRankRewardsList', {}),
                    self.request('/admin/abilities-spells/approvals/pending', 'lfasPendingApprovalsList', {}),
                    self.hasCapability('character.attributes')
                        ? self.requestOptional(
                            '/admin/character-attributes/definitions/list',
                            'lfasAttributeDefinitionsList',
                            { results: 100, page: 1, orderBy: 'position|ASC' },
                            [],
                            'Modulo Attributi'
                        )
                        : Promise.resolve({ dataset: [] }),
                    self.hasCapability('character.archetypes')
                        ? self.requestOptional(
                            '/admin/archetypes/list',
                            'lfasArchetypesList',
                            { results: 100, page: 1, orderBy: 'sort_order|ASC' },
                            [],
                            'Modulo Archetipi'
                        )
                        : Promise.resolve({ dataset: [] }),
                    self.requestOptional(
                        '/admin/guilds/admin-list',
                        'lfasGuildsAdminList',
                        { results: 100, page: 1, orderBy: 'g.name|ASC' },
                        [],
                        'Modulo Gilde'
                    )
                ]);
            }).then(function (responses) {
                self.stateRows = responses[0] && Array.isArray(responses[0].dataset) ? responses[0].dataset : [];
                self.storeAbilities(responses[1] && Array.isArray(responses[1].dataset) ? responses[1].dataset : []);
                self.pointCategoryRows = responses[2] && Array.isArray(responses[2].dataset) ? responses[2].dataset : [];
                self.rankRewardRows = responses[3] && Array.isArray(responses[3].dataset) ? responses[3].dataset : [];
                self.pendingApprovalRows = responses[4] && Array.isArray(responses[4].dataset) ? responses[4].dataset : [];
                self.attributeRows = responses[5] && Array.isArray(responses[5].dataset) ? responses[5].dataset : [];
                self.archetypeRows = responses[6] && Array.isArray(responses[6].dataset) ? responses[6].dataset : [];
                self.guildRows = responses[7] && Array.isArray(responses[7].dataset) ? responses[7].dataset : [];
                self.renderStateOptions();
                self.renderPointCategoryOptions();
                self.renderAssignmentAbilityOptions();
                self.renderIntegrationDatalists();
                self.renderPendingApprovalsTable();
                self.renderRankRewardsTable();
                self.syncStateField();
                self.syncGrantSourceField();
                self.syncRequirementKeyField();
                self.syncEffectTargetFields();
                self.reloadGrid();
                self.loadAssignmentsForCurrentCharacter(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Caricamento dati modulo non riuscito.', 'warning');
                self.stateRows = [];
                self.storeAbilities([]);
                self.attributeRows = [];
                self.archetypeRows = [];
                self.guildRows = [];
                self.pointCategoryRows = [];
                self.pendingApprovalRows = [];
                self.rankRewardRows = [];
                self.renderStateOptions();
                self.renderPointCategoryOptions();
                self.renderAssignmentAbilityOptions();
                self.renderIntegrationDatalists();
                self.renderPendingApprovalsTable();
                self.renderRankRewardsTable();
                self.renderAssignmentsTable();
                self.syncGrantSourceField();
                self.syncRequirementKeyField();
                self.syncEffectTargetFields();
                self.reloadGrid();
            });
        },

        reloadGrid: function () {
            if (this.grid && typeof this.grid.loadData === 'function') {
                this.grid.loadData({}, 25, 1, 'sort_order|ASC');
            }
        },

        storeAbilities: function (rows) {
            this.abilitiesRows = Array.isArray(rows) ? rows.slice() : [];
            this.abilitiesById = {};

            for (var i = 0; i < this.abilitiesRows.length; i += 1) {
                var row = this.abilitiesRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id > 0) {
                    this.abilitiesById[id] = row;
                }
            }
        },

        renderStateOptions: function () {
            if (!this.stateSelect) {
                return;
            }

            var html = ['<option value="0">Nessuno</option>'];
            for (var i = 0; i < this.stateRows.length; i += 1) {
                var row = this.stateRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(String(row.name || row.code || ('Stato #' + id)).trim()) + '</option>');
            }
            this.stateSelect.innerHTML = html.join('');
        },

        renderPointCategoryOptions: function () {
            var html = ['<option value="0">Nessuna</option>'];
            for (var i = 0; i < this.pointCategoryRows.length; i += 1) {
                var row = this.pointCategoryRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(String(row.name || row.slug || ('Categoria #' + id)).trim()) + '</option>');
            }

            if (this.pointCategorySelect) {
                this.pointCategorySelect.innerHTML = html.join('');
            }

            if (this.rewardPointCategorySelect) {
                var rewardHtml = ['<option value="0">Seleziona...</option>'].concat(html.slice(1));
                this.rewardPointCategorySelect.innerHTML = rewardHtml.join('');
            }
        },

        renderAssignmentAbilityOptions: function () {
            if (!this.assignmentAbilitySelect) {
                return;
            }

            var html = ['<option value="">Seleziona...</option>'];
            for (var i = 0; i < this.abilitiesRows.length; i += 1) {
                var row = this.abilitiesRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0 || parseInt(row.is_active || '0', 10) !== 1) {
                    continue;
                }
                html.push('<option value="' + id + '">' + escapeHtml(String(row.name || ('Abilita #' + id))) + '</option>');
            }
            this.assignmentAbilitySelect.innerHTML = html.join('');
        },

        renderDatalistOptions: function (id, rows) {
            var node = document.getElementById(id);
            if (!node) {
                return;
            }

            var seen = {};
            var html = [];
            var items = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < items.length; i += 1) {
                var row = items[i] || {};
                var value = String(row.value || '').trim();
                if (value === '' || Object.prototype.hasOwnProperty.call(seen, value)) {
                    continue;
                }
                seen[value] = true;
                var label = String(row.label || '').trim();
                html.push('<option value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</option>');
            }

            node.innerHTML = html.join('');
        },

        attributeKeyOptions: function () {
            var options = [];
            for (var i = 0; i < this.attributeRows.length; i += 1) {
                var row = this.attributeRows[i] || {};
                var slug = String(row.slug || '').trim();
                if (slug === '') {
                    continue;
                }
                options.push({
                    value: slug,
                    label: String(row.name || slug)
                });
            }
            return options;
        },

        abilityKeyOptions: function () {
            var options = [];
            for (var i = 0; i < this.abilitiesRows.length; i += 1) {
                var row = this.abilitiesRows[i] || {};
                var slug = String(row.slug || '').trim();
                if (slug === '') {
                    continue;
                }
                options.push({
                    value: slug,
                    label: String(row.name || slug)
                });
            }
            return options;
        },

        archetypeKeyOptions: function () {
            var options = [];
            for (var i = 0; i < this.archetypeRows.length; i += 1) {
                var row = this.archetypeRows[i] || {};
                var slug = String(row.slug || row.id || '').trim();
                if (slug === '') {
                    continue;
                }
                options.push({
                    value: slug,
                    label: String(row.name || slug)
                });
            }
            return options;
        },

        grantArchetypeOptions: function () {
            var options = [];
            for (var i = 0; i < this.archetypeRows.length; i += 1) {
                var row = this.archetypeRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                options.push({
                    value: String(id) + ' | ' + String(row.name || row.slug || ('Archetipo #' + id)),
                    label: String(row.name || row.slug || ('Archetipo #' + id))
                });
            }
            return options;
        },

        grantGuildOptions: function () {
            var options = [];
            for (var i = 0; i < this.guildRows.length; i += 1) {
                var row = this.guildRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                options.push({
                    value: String(id) + ' | ' + String(row.name || ('Gilda #' + id)),
                    label: String(row.name || ('Gilda #' + id))
                });
            }
            return options;
        },

        stateKeyOptions: function () {
            var options = [];
            for (var i = 0; i < this.stateRows.length; i += 1) {
                var row = this.stateRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                options.push({
                    value: String(id),
                    label: String(row.name || row.code || ('Stato #' + id))
                });
            }
            return options;
        },

        renderIntegrationDatalists: function () {
            this.renderDatalistOptions('lfas-requirement-key-options', this.attributeKeyOptions());
            this.renderDatalistOptions('lfas-effect-target-key-options', this.attributeKeyOptions());
        },

        labelSourceType: function (value) {
            var type = String(value || '').trim().toLowerCase();
            if (type === 'character') {
                return 'Personaggio';
            }
            if (type === 'archetype') {
                return 'Archetipo';
            }
            if (type === 'guild') {
                return 'Gilda';
            }
            if (type === 'custom') {
                return 'Personalizzato';
            }
            return type || '-';
        },

        labelGrantMode: function (value) {
            var mode = String(value || '').trim().toLowerCase();
            if (mode === 'unlock') {
                return 'Sblocca';
            }
            if (mode === 'auto_learn') {
                return 'Apprendi automaticamente';
            }
            if (mode === 'bonus') {
                return 'Bonus';
            }
            if (mode === 'forbid') {
                return 'Vieta';
            }
            return mode || '-';
        },

        labelRetentionPolicy: function (value) {
            var policy = String(value || '').trim().toLowerCase();
            if (policy === 'keep_when_lost') {
                return 'Mantieni alla perdita';
            }
            if (policy === 'while_source_active') {
                return 'Finche la sorgente e attiva';
            }
            if (policy === 'disable_when_lost') {
                return 'Disattiva alla perdita';
            }
            if (policy === 'refund_when_lost') {
                return 'Rimborsa alla perdita';
            }
            return policy || '-';
        },

        labelRequirementType: function (value) {
            var type = String(value || '').trim().toLowerCase();
            if (type === 'attribute') {
                return 'Attributo';
            }
            if (type === 'rank') {
                return 'Grado';
            }
            if (type === 'ability') {
                return 'Abilita';
            }
            if (type === 'archetype') {
                return 'Archetipo';
            }
            if (type === 'guild') {
                return 'Gilda';
            }
            if (type === 'custom') {
                return 'Personalizzato';
            }
            return type || '-';
        },

        labelAvailabilityPolicy: function (value) {
            var policy = String(value || '').trim().toLowerCase();
            if (policy === 'block') {
                return 'Blocca';
            }
            if (policy === 'ignore') {
                return 'Ignora';
            }
            if (policy === 'hide') {
                return 'Nascondi';
            }
            return policy || '-';
        },

        labelEffectType: function (value) {
            var type = String(value || '').trim().toLowerCase();
            if (type === 'modifier') {
                return 'Modificatore';
            }
            if (type === 'narrative_state') {
                return 'Stato narrativo';
            }
            if (type === 'custom') {
                return 'Personalizzato';
            }
            return type || '-';
        },

        labelActivationPolicy: function (value) {
            var policy = String(value || '').trim().toLowerCase();
            if (policy === 'while_ability_usable') {
                return 'Finche utilizzabile';
            }
            if (policy === 'while_ability_learned') {
                return 'Finche appresa';
            }
            if (policy === 'on_use') {
                return 'All\'uso';
            }
            if (policy === 'manual_toggle') {
                return 'Attivazione manuale';
            }
            if (policy === 'temporary') {
                return 'Temporaneo';
            }
            return policy || '-';
        },

        labelOperation: function (value) {
            var operation = String(value || '').trim().toLowerCase();
            if (operation === 'add') {
                return 'Aggiungi';
            }
            if (operation === 'apply') {
                return 'Applica';
            }
            if (operation === 'remove') {
                return 'Rimuovi';
            }
            return operation || '-';
        },

        labelAssignmentStatus: function (value) {
            var status = String(value || '').trim().toLowerCase();
            if (status === 'learned') {
                return 'Appresa';
            }
            if (status === 'available') {
                return 'Disponibile';
            }
            if (status === 'learning') {
                return 'In apprendimento';
            }
            if (status === 'pending_approval') {
                return 'In approvazione';
            }
            if (status === 'suspended') {
                return 'Sospesa';
            }
            if (status === 'disabled') {
                return 'Disattivata';
            }
            return status || '-';
        },

        labelApprovalStatus: function (value) {
            var status = String(value || '').trim().toLowerCase();
            if (status === 'approved') {
                return 'Approvata';
            }
            if (status === 'pending') {
                return 'In attesa';
            }
            if (status === 'rejected') {
                return 'Respinta';
            }
            return status || '-';
        },

        resolveGrantSourcePickerDisplay: function (sourceType, sourceId) {
            var type = String(sourceType || '').trim().toLowerCase();
            var id = parseInt(sourceId || '0', 10) || 0;
            if (id <= 0) {
                return '';
            }

            var rows = [];
            if (type === 'archetype') {
                rows = this.archetypeRows;
            } else if (type === 'guild') {
                rows = this.guildRows;
            } else {
                return String(id);
            }

            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var rowId = parseInt(row.id || '0', 10) || 0;
                if (rowId !== id) {
                    continue;
                }
                return String(id) + ' | ' + String(row.name || row.slug || (type + ' #' + id));
            }

            return String(id);
        },

        syncGrantSourceField: function () {
            if (!this.grantForm) {
                return;
            }

            var typeField = this.grantForm.elements.source_type;
            var idField = this.grantForm.elements.source_id;
            var pickerField = this.grantForm.elements.source_picker;
            if (!typeField || !idField || !pickerField) {
                return;
            }

            var type = String(typeField.value || 'character').trim().toLowerCase();
            var idValue = parseInt(idField.value || '0', 10) || 0;
            var helpId = this.root.querySelector('[data-role="lfas-grant-source-id-help"]');
            var helpPicker = this.root.querySelector('[data-role="lfas-grant-source-picker-help"]');
            var options = [];
            var placeholder = 'Seleziona o cerca una sorgente...';
            var helpIdText = 'Inserisci l ID sorgente richiesto dal tipo selezionato.';
            var helpPickerText = 'Per archetipi e gilde puoi scegliere dalla lista; per gli altri tipi puoi lasciare vuoto.';

            if (type === 'archetype') {
                options = this.grantArchetypeOptions();
                placeholder = 'Es. 12 | Assassino';
                helpIdText = 'Per gli archetipi il source_id è l ID dell archetipo.';
                helpPickerText = 'Scegli un archetipo dalla lista: il source_id viene compilato automaticamente.';
            } else if (type === 'guild') {
                options = this.grantGuildOptions();
                placeholder = 'Es. 7 | Guardie della Citta';
                helpIdText = 'Per le gilde il source_id è l ID della gilda.';
                helpPickerText = 'Scegli una gilda dalla lista: il source_id viene compilato automaticamente.';
            } else if (type === 'character') {
                options = [];
                placeholder = 'Cerca un personaggio...';
                helpIdText = 'Per le assegnazioni da personaggio usa l ID del personaggio.';
                helpPickerText = 'Cerca e seleziona un personaggio: il source_id viene compilato automaticamente.';
            } else if (type === 'custom') {
                options = [];
                placeholder = 'Nessun suggerimento disponibile';
                helpIdText = 'Per le assegnazioni personalizzate usa un source_id tecnico concordato col resolver.';
                helpPickerText = 'Il selettore guidato non è usato per i source custom.';
            }

            pickerField.setAttribute('placeholder', placeholder);
            this.renderDatalistOptions('lfas-grant-source-options', options);
            pickerField.value = this.resolveGrantSourcePickerDisplay(type, idValue);
            pickerField.setAttribute('list', type === 'character' ? '' : 'lfas-grant-source-options');
            this.clearCharacterSearchResults('grant');

            if (helpId) {
                helpId.textContent = helpIdText;
            }
            if (helpPicker) {
                helpPicker.textContent = helpPickerText;
            }
        },

        currentGrantSourceType: function () {
            if (!this.grantForm || !this.grantForm.elements || !this.grantForm.elements.source_type) {
                return 'character';
            }
            return String(this.grantForm.elements.source_type.value || 'character').trim().toLowerCase();
        },

        applyGrantSourcePickerValue: function () {
            if (!this.grantForm) {
                return;
            }

            var pickerField = this.grantForm.elements.source_picker;
            var idField = this.grantForm.elements.source_id;
            if (!pickerField || !idField) {
                return;
            }

            var raw = String(pickerField.value || '').trim();
            if (raw === '') {
                return;
            }

            var match = raw.match(/^(\d+)/);
            if (!match) {
                return;
            }

            idField.value = String(parseInt(match[1], 10) || 0);
        },

        syncRequirementKeyField: function () {
            if (!this.requirementForm) {
                return;
            }

            var typeField = this.requirementForm.elements.requirement_type;
            var keyField = this.requirementForm.elements.requirement_key;
            if (!typeField || !keyField) {
                return;
            }

            var type = String(typeField.value || 'attribute').trim().toLowerCase();
            var help = this.root.querySelector('[data-role="lfas-requirement-key-help"]');
            var options = [];
            var placeholder = 'strength';
            var helpText = 'Per gli attributi usa di norma lo slug, per esempio strength.';

            if (type === 'rank') {
                options = [{ value: 'character_rank', label: 'Grado personaggio' }];
                placeholder = 'character_rank';
                helpText = 'Per il grado usa di norma character_rank.';
                if (String(keyField.value || '').trim() === '') {
                    keyField.value = 'character_rank';
                }
            } else if (type === 'ability') {
                options = this.abilityKeyOptions();
                placeholder = 'fireball';
                helpText = 'Per le abilita usa lo slug dell abilita richiesta.';
            } else if (type === 'archetype') {
                options = this.archetypeKeyOptions();
                placeholder = 'assassino';
                helpText = 'Per gli archetipi usa slug o ID, meglio se slug.';
            } else if (type === 'guild') {
                options = [
                    { value: 'guild_id', label: 'ID gilda' },
                    { value: 'guild_role', label: 'Ruolo gilda' }
                ];
                placeholder = 'guild_id';
                helpText = 'Per ora usa una chiave convenzionale, per esempio guild_id o guild_role.';
            } else if (type === 'custom') {
                options = [
                    { value: 'custom_flag', label: 'Flag custom' },
                    { value: 'story_gate', label: 'Gate narrativo' }
                ];
                placeholder = 'custom_flag';
                helpText = 'Requisito personalizzato: scegli una chiave leggibile e stabile.';
            } else {
                options = this.attributeKeyOptions();
            }

            keyField.setAttribute('placeholder', placeholder);
            this.renderDatalistOptions('lfas-requirement-key-options', options);
            if (help) {
                help.textContent = helpText;
            }
        },

        syncEffectTargetFields: function () {
            if (!this.effectForm) {
                return;
            }

            var effectTypeField = this.effectForm.elements.effect_type;
            var targetSystemField = this.effectForm.elements.target_system;
            var targetKeyField = this.effectForm.elements.target_key;
            if (!effectTypeField || !targetSystemField || !targetKeyField) {
                return;
            }

            var effectType = String(effectTypeField.value || 'modifier').trim().toLowerCase();
            var targetSystem = String(targetSystemField.value || '').trim().toLowerCase();
            var help = this.root.querySelector('[data-role="lfas-effect-target-key-help"]');
            var options = [];
            var placeholder = 'strength';
            var helpText = 'Per gli effetti attributo usa lo slug dell attributo, per esempio strength.';

            if (effectType === 'narrative_state') {
                if (targetSystem === '' || targetSystem === 'character_attributes') {
                    targetSystemField.value = 'narrative_states';
                    targetSystem = 'narrative_states';
                }
            } else if (effectType === 'modifier' && (targetSystem === '' || targetSystem === 'narrative_states')) {
                targetSystemField.value = 'character_attributes';
                targetSystem = 'character_attributes';
            }

            if (targetSystem === 'narrative_states') {
                options = this.stateKeyOptions();
                placeholder = '12';
                helpText = 'Per gli stati narrativi usa di norma l ID dello stato.';
            } else if (targetSystem === 'custom') {
                options = [
                    { value: 'custom_payload', label: 'Payload custom' },
                    { value: 'manual_hook', label: 'Hook manuale' }
                ];
                placeholder = 'custom_payload';
                helpText = 'Per target custom usa una chiave tecnica coerente col resolver che la consumerà.';
            } else {
                options = this.attributeKeyOptions();
            }

            targetKeyField.setAttribute('placeholder', placeholder);
            this.renderDatalistOptions('lfas-effect-target-key-options', options);
            if (help) {
                help.textContent = helpText;
            }
        },

        currentCharacterId: function () {
            if (!this.assignmentFilterForm || !this.assignmentFilterForm.elements.character_id) {
                return 0;
            }
            return parseInt(this.assignmentFilterForm.elements.character_id.value || '0', 10) || 0;
        },

        currentCharacterLabel: function () {
            if (!this.assignmentFilterSearchInput) {
                return '';
            }
            return String(this.assignmentFilterSearchInput.value || '').trim();
        },

        resetAssignmentFilter: function () {
            if (this.assignmentFilterForm) {
                this.assignmentFilterForm.reset();
            }
            this.clearCharacterSelection('filter');
            if (this.assignmentFilterSearchInput) {
                this.assignmentFilterSearchInput.value = '';
            }
            this.assignmentRows = [];
            if (this.assignmentContext) {
                this.assignmentContext.textContent = 'Seleziona un personaggio per visualizzare le assegnazioni.';
            }
            this.clearCharacterSearchResults();
            this.renderAssignmentsTable();
        },

        loadAssignmentsForCurrentCharacter: function (quiet) {
            var self = this;
            var characterId = this.currentCharacterId();
            var characterLabel = this.currentCharacterLabel();

            if (characterId <= 0) {
                this.assignmentRows = [];
                if (this.assignmentContext) {
                    this.assignmentContext.textContent = 'Seleziona un personaggio per visualizzare le assegnazioni.';
                }
                this.renderAssignmentsTable();
                if (quiet !== true) {
                    this.notify(characterLabel !== '' ? 'Seleziona un personaggio dai suggerimenti.' : 'Seleziona un personaggio valido.', 'warning');
                }
                return Promise.resolve();
            }

            if (this.assignmentContext) {
                this.assignmentContext.textContent = 'Assegnazioni di ' + (characterLabel !== '' ? characterLabel : ('PG #' + characterId)) + ' (#' + characterId + ').';
            }

            return this.request('/admin/abilities-spells/assignments/list', 'lfasAssignmentsList', {
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
            if (!this.assignmentsTable || !this.assignmentsEmpty) {
                return;
            }

            if (!this.assignmentRows.length) {
                this.assignmentsTable.innerHTML = '';
                this.assignmentsEmpty.classList.remove('d-none');
                return;
            }

            this.assignmentsEmpty.classList.add('d-none');

            var html = [];
            for (var i = 0; i < this.assignmentRows.length; i += 1) {
                var row = this.assignmentRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var firstName = String(row.character_name || '').trim();
                var surname = String(row.character_surname || '').trim();
                var characterLabel = ((firstName + ' ' + surname).trim()) || ('PG #' + String(row.character_id || '0'));
                var actions = '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-assignment-delete" data-id="' + id + '">Rimuovi</button>';
                if (String(row.approval_status || '') === 'pending' || String(row.status || '') === 'pending_approval') {
                    actions = '<div class="d-flex justify-content-end gap-1">'
                        + '<button type="button" class="btn btn-sm btn-outline-success" data-action="lfas-pending-approval-approve" data-id="' + id + '">Approva</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-pending-approval-reject" data-id="' + id + '">Respingi</button>'
                        + '</div>';
                }
                html.push(
                    '<tr>'
                    + '<td><div class="fw-semibold">' + escapeHtml(characterLabel) + '</div><div class="small text-muted">#' + escapeHtml(String(row.character_id || '0')) + '</div></td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.ability_name || '-') + '</div><div class="small text-muted font-monospace">' + escapeHtml(row.ability_slug || '') + '</div></td>'
                    + '<td><span class="badge ' + (parseInt(row.is_active || '0', 10) === 1 ? 'text-bg-success' : 'text-bg-secondary') + '">' + (parseInt(row.is_active || '0', 10) === 1 ? 'Attiva' : 'Disattiva') + '</span>'
                    + ' <span class="badge text-bg-light text-dark">' + escapeHtml(this.labelAssignmentStatus(row.status || 'learned')) + '</span>'
                    + ' <span class="badge text-bg-light text-dark">Lv ' + escapeHtml(String(parseInt(row.level || '0', 10) || 0)) + '</span>'
                    + '<div class="small text-muted mt-1">Approvazione: ' + escapeHtml(this.labelApprovalStatus(row.approval_status || 'approved')) + ' · Ordine ' + escapeHtml(String(parseInt(row.sort_order || '100', 10) || 100)) + '</div></td>'
                    + '<td class="text-end">' + actions + '</td>'
                    + '</tr>'
                );
            }
            this.assignmentsTable.innerHTML = html.join('');
        },

        renderPendingApprovalsTable: function () {
            if (!this.pendingApprovalsTable || !this.pendingApprovalsEmpty) {
                return;
            }

            if (!this.pendingApprovalRows.length) {
                this.pendingApprovalsTable.innerHTML = '';
                this.pendingApprovalsEmpty.classList.remove('d-none');
                return;
            }

            this.pendingApprovalsEmpty.classList.add('d-none');

            var html = [];
            for (var i = 0; i < this.pendingApprovalRows.length; i += 1) {
                var row = this.pendingApprovalRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var firstName = String(row.character_name || '').trim();
                var surname = String(row.character_surname || '').trim();
                var characterLabel = ((firstName + ' ' + surname).trim()) || ('PG #' + String(row.character_id || '0'));
                var level = parseInt(row.level || '0', 10) || 0;
                var pendingPoints = parseInt(row.pending_points || '0', 10) || 0;
                var pointCategoryLabel = String(row.point_category_name || row.point_category_slug || '').trim();
                html.push(
                    '<tr>'
                    + '<td><div class="fw-semibold">' + escapeHtml(characterLabel) + '</div><div class="small text-muted">#' + escapeHtml(String(row.character_id || '0')) + '</div></td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.ability_name || '-') + '</div><div class="small text-muted font-monospace">' + escapeHtml(row.ability_slug || '') + '</div></td>'
                    + '<td><span class="badge text-bg-warning">In attesa</span> <span class="badge text-bg-light text-dark">Lv ' + escapeHtml(String(level)) + '</span>'
                    + '<div class="small text-muted mt-1">Punti in sospeso: ' + escapeHtml(String(pendingPoints)) + (pointCategoryLabel ? ' · ' + escapeHtml(pointCategoryLabel) : '') + '</div></td>'
                    + '<td class="text-end"><div class="d-flex justify-content-end gap-1">'
                    + '<button type="button" class="btn btn-sm btn-outline-success" data-action="lfas-pending-approval-approve" data-id="' + id + '">Approva</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-pending-approval-reject" data-id="' + id + '">Respingi</button>'
                    + '</div></td>'
                    + '</tr>'
                );
            }

            this.pendingApprovalsTable.innerHTML = html.join('');
        },

        renderRankRewardsTable: function () {
            if (!this.rankRewardsTable || !this.rankRewardsEmpty) {
                return;
            }

            if (!this.rankRewardRows.length) {
                this.rankRewardsTable.innerHTML = '';
                this.rankRewardsEmpty.classList.remove('d-none');
                return;
            }

            this.rankRewardsEmpty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.rankRewardRows.length; i += 1) {
                var row = this.rankRewardRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td>#' + escapeHtml(String(parseInt(row.rank || '0', 10) || 0)) + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(row.point_category_name || row.point_category_slug || '-') + '</div><div class="small text-muted">ID ' + escapeHtml(String(parseInt(row.point_category_id || '0', 10) || 0)) + '</div></td>'
                    + '<td>' + escapeHtml(String(parseInt(row.points || '0', 10) || 0)) + '</td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attiva</span>' : '<span class="badge text-bg-secondary">Disattiva</span>') + '</td>'
                    + '<td class="text-end"><div class="d-flex justify-content-end gap-1">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-rank-reward-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-rank-reward-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>'
                );
            }
            this.rankRewardsTable.innerHTML = html.join('');
        },

        syncAbilityRuleEditors: function () {
            var isEdit = this.editingAbilityId > 0;
            if (this.abilityRulesPanels) {
                this.abilityRulesPanels.classList.toggle('d-none', !isEdit);
            }
            if (this.abilityRulesEmpty) {
                this.abilityRulesEmpty.classList.toggle('d-none', isEdit);
            }
            if (this.abilityRulesContext) {
                this.abilityRulesContext.textContent = isEdit
                    ? ('Regole dell\'abilita #' + String(this.editingAbilityId) + '.')
                    : 'Salva prima l\'abilita per configurare assegnazioni, requisiti ed effetti.';
            }
        },

        clearAbilityRuleRows: function () {
            this.grantRows = [];
            this.requirementRows = [];
            this.effectRows = [];
            this.renderGrantsTable();
            this.renderRequirementsTable();
            this.renderEffectsTable();
        },

        loadAbilityRuleEditors: function () {
            var self = this;
            if (this.editingAbilityId <= 0) {
                this.clearAbilityRuleRows();
                this.syncAbilityRuleEditors();
                return Promise.resolve();
            }

            this.syncAbilityRuleEditors();
            return Promise.all([
                this.request('/admin/abilities-spells/abilities/grants/list', 'lfasGrantsList', { ability_id: this.editingAbilityId }),
                this.request('/admin/abilities-spells/abilities/requirements/list', 'lfasRequirementsList', { ability_id: this.editingAbilityId }),
                this.request('/admin/abilities-spells/abilities/effects/list', 'lfasEffectsList', { ability_id: this.editingAbilityId })
            ]).then(function (responses) {
                self.grantRows = responses[0] && Array.isArray(responses[0].dataset) ? responses[0].dataset : [];
                self.requirementRows = responses[1] && Array.isArray(responses[1].dataset) ? responses[1].dataset : [];
                self.effectRows = responses[2] && Array.isArray(responses[2].dataset) ? responses[2].dataset : [];
                self.renderGrantsTable();
                self.renderRequirementsTable();
                self.renderEffectsTable();
                self.resetGrantForm();
                self.resetRequirementForm();
                self.resetEffectForm();
            }).catch(function (error) {
                self.clearAbilityRuleRows();
                self.notify((error && error.message) ? error.message : 'Caricamento regole abilita non riuscito.', 'warning');
            });
        },

        renderGrantsTable: function () {
            if (!this.grantsTable || !this.grantsEmpty) {
                return;
            }

            if (!this.grantRows.length) {
                this.grantsTable.innerHTML = '';
                this.grantsEmpty.classList.remove('d-none');
                return;
            }

            this.grantsEmpty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.grantRows.length; i += 1) {
                var row = this.grantRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var minRank = parseInt(row.min_rank || '0', 10) || 0;
                var maxRank = parseInt(row.max_rank || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td><div class="fw-semibold">' + escapeHtml(this.labelSourceType(row.source_type || '-')) + ' #' + escapeHtml(String(parseInt(row.source_id || '0', 10) || 0)) + '</div>'
                    + '<div class="small text-muted">Priorita ' + escapeHtml(String(parseInt(row.priority || '100', 10) || 100)) + '</div></td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(this.labelGrantMode(row.grant_mode || 'unlock')) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(this.labelRetentionPolicy(row.retention_policy || 'keep_when_lost')) + '</div></td>'
                    + '<td><div class="small">' + (minRank > 0 ? ('Min ' + escapeHtml(String(minRank))) : 'Min -') + ' · ' + (maxRank > 0 ? ('Max ' + escapeHtml(String(maxRank))) : 'Max -') + '</div></td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end"><div class="d-flex justify-content-end gap-1">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-grant-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-grant-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>'
                );
            }
            this.grantsTable.innerHTML = html.join('');
        },

        renderRequirementsTable: function () {
            if (!this.requirementsTable || !this.requirementsEmpty) {
                return;
            }

            if (!this.requirementRows.length) {
                this.requirementsTable.innerHTML = '';
                this.requirementsEmpty.classList.remove('d-none');
                return;
            }

            this.requirementsEmpty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.requirementRows.length; i += 1) {
                var row = this.requirementRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td>Lv ' + escapeHtml(String(parseInt(row.level || '1', 10) || 1)) + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(this.labelRequirementType(row.requirement_type || '-')) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(row.requirement_key || '-') + ' ' + escapeHtml(row.operator || '>=') + ' ' + escapeHtml(row.required_value || '') + '</div></td>'
                    + '<td><span class="badge text-bg-light text-dark">' + escapeHtml(this.labelAvailabilityPolicy(row.policy_when_unavailable || 'block')) + '</span>' + (parseInt(row.is_hidden || '0', 10) === 1 ? ' <span class="badge text-bg-warning">Nascosto</span>' : '') + '</td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end"><div class="d-flex justify-content-end gap-1">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-requirement-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-requirement-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>'
                );
            }
            this.requirementsTable.innerHTML = html.join('');
        },

        renderEffectsTable: function () {
            if (!this.effectsTable || !this.effectsEmpty) {
                return;
            }

            if (!this.effectRows.length) {
                this.effectsTable.innerHTML = '';
                this.effectsEmpty.classList.remove('d-none');
                return;
            }

            this.effectsEmpty.classList.add('d-none');
            var html = [];
            for (var i = 0; i < this.effectRows.length; i += 1) {
                var row = this.effectRows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html.push(
                    '<tr>'
                    + '<td>Lv ' + escapeHtml(String(parseInt(row.level || '1', 10) || 1)) + '</td>'
                    + '<td><div class="fw-semibold">' + escapeHtml(this.labelEffectType(row.effect_type || '-')) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(row.target_system || '-') + ' · ' + escapeHtml(row.target_key || '-') + ' · ' + escapeHtml(this.labelOperation(row.operation || 'add')) + ' ' + escapeHtml(String(row.value || '0')) + '</div></td>'
                    + '<td><span class="badge text-bg-light text-dark">' + escapeHtml(this.labelActivationPolicy(row.activation_policy || 'while_ability_usable')) + '</span>'
                    + '<div class="small text-muted mt-1">' + escapeHtml(this.labelAvailabilityPolicy(row.policy_when_unavailable || 'ignore')) + '</div></td>'
                    + '<td>' + (parseInt(row.is_active || '0', 10) === 1 ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Disattivo</span>') + '</td>'
                    + '<td class="text-end"><div class="d-flex justify-content-end gap-1">'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfas-effect-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="lfas-effect-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>'
                );
            }
            this.effectsTable.innerHTML = html.join('');
        },

        openAbilityCreate: function () {
            this.editingAbilityId = 0;
            this.resetAbilityForm();
            this.syncAbilityModalState();
            this.clearAbilityRuleRows();
            this.showAbilityModal();
        },

        openAbilityEdit: function (abilityId) {
            var row = this.abilitiesById[abilityId] || null;
            if (!row || !this.abilityForm) {
                return;
            }

            this.editingAbilityId = abilityId;
            this.setAbilityField('id', abilityId);
            this.setAbilityField('name', row.name || '');
            this.setAbilityField('slug', row.slug || '');
            this.setAbilityField('description', row.description || '');
            this.setAbilityField('type', row.type || 'ability');
            this.setAbilityField('point_category_id', parseInt(row.point_category_id || '0', 10) || 0);
            this.setAbilityField('max_level', parseInt(row.max_level || '1', 10) || 1);
            this.setAbilityField('target_type', row.target_type || 'self');
            this.setAbilityField('effect_mode', row.effect_mode || 'none');
            this.setAbilityField('narrative_state_id', parseInt(row.narrative_state_id || '0', 10) || 0);
            this.setAbilityField('cooldown_seconds', parseInt(row.cooldown_seconds || '0', 10) || 0);
            this.setAbilityField('sort_order', parseInt(row.sort_order || '100', 10) || 100);
            this.setAbilityField('is_active', parseInt(row.is_active || '0', 10) === 1 ? 1 : 0);
            this.setAbilityField('is_public', parseInt(row.is_public || '0', 10) === 1 ? 1 : 0);
            this.setAbilityField('is_hidden_when_locked', parseInt(row.is_hidden_when_locked || '0', 10) === 1 ? 1 : 0);
            this.setAbilityField('requires_learning', parseInt(row.requires_learning || '0', 10) === 1 ? 1 : 0);
            this.setAbilityField('requires_staff_approval', parseInt(row.requires_staff_approval || '0', 10) === 1 ? 1 : 0);
            this.setAbilityField('metadata_json', row.metadata_json || '');
            this.clearJsonPresetPickers('ability');
            this.syncAbilityModalState();
            this.loadAbilityRuleEditors();
            this.showAbilityModal();
        },

        resetAbilityForm: function () {
            if (!this.abilityForm) {
                return;
            }

            this.abilityForm.reset();
            this.setAbilityField('id', 0);
            this.setAbilityField('type', 'ability');
            this.setAbilityField('point_category_id', 0);
            this.setAbilityField('max_level', 1);
            this.setAbilityField('target_type', 'self');
            this.setAbilityField('effect_mode', 'none');
            this.setAbilityField('narrative_state_id', 0);
            this.setAbilityField('cooldown_seconds', 0);
            this.setAbilityField('sort_order', 100);
            this.setAbilityField('is_active', 1);
            this.setAbilityField('is_public', 0);
            this.setAbilityField('is_hidden_when_locked', 0);
            this.setAbilityField('requires_learning', 0);
            this.setAbilityField('requires_staff_approval', 0);
            this.setAbilityField('metadata_json', '');
            this.clearJsonPresetPickers('ability');
            this.syncStateField();
            this.resetGrantForm();
            this.resetRequirementForm();
            this.resetEffectForm();
        },

        syncAbilityModalState: function () {
            var title = this.root.querySelector('[data-role="lfas-ability-modal-title"]');
            var subtitle = this.root.querySelector('[data-role="lfas-ability-modal-subtitle"]');
            var deleteButton = this.root.querySelector('[data-action="lfas-ability-delete-current"]');
            var isEdit = this.editingAbilityId > 0;

            if (title) {
                title.textContent = isEdit ? 'Modifica abilita' : 'Nuova abilita';
            }
            if (subtitle) {
                subtitle.textContent = isEdit
                    ? 'Aggiorna il comportamento dell\'abilita mantenendo intatte le assegnazioni esistenti.'
                    : 'Definisci comportamento, effetto e metadati dell\'abilita.';
            }
            if (deleteButton) {
                deleteButton.classList.toggle('d-none', !isEdit);
            }
            this.syncStateField();
            this.syncAbilityRuleEditors();
        },

        syncStateField: function () {
            if (!this.abilityForm || !this.stateSelect) {
                return;
            }

            var effectMode = String(this.abilityForm.elements.effect_mode.value || 'none').trim();
            var stateHelp = this.root.querySelector('[data-role="lfas-state-help"]');
            var needsState = effectMode === 'apply_state' || effectMode === 'remove_state';
            this.stateSelect.disabled = !needsState;
            if (!needsState) {
                this.stateSelect.value = '0';
            }
            if (stateHelp) {
                stateHelp.textContent = needsState
                    ? 'Per questo effetto devi selezionare uno stato narrativo.'
                    : 'Lascia vuoto se l\'abilita non modifica stati narrativi.';
            }
        },

        openAssignmentCreate: function () {
            if (!this.assignmentForm) {
                return;
            }

            this.assignmentForm.reset();
            this.assignmentForm.elements.sort_order.value = '100';
            this.assignmentForm.elements.is_active.value = '1';
            this.assignmentForm.elements.status.value = 'learned';
            this.assignmentForm.elements.level.value = '1';
            this.assignmentForm.elements.approval_status.value = 'approved';
            this.assignmentForm.elements.character_id.value = '';
            if (this.assignmentSearchInput) {
                this.assignmentSearchInput.value = '';
            }

            var characterId = this.currentCharacterId();
            if (characterId > 0) {
                this.assignmentForm.elements.character_id.value = String(characterId);
                if (this.assignmentSearchInput) {
                    this.assignmentSearchInput.value = this.currentCharacterLabel();
                }
            }

            this.clearCharacterSearchResults();
            this.showAssignmentModal();
        },

        setAbilityField: function (name, value) {
            if (!this.abilityForm) {
                return;
            }
            var field = this.abilityForm.elements[name];
            if (field) {
                field.value = value == null ? '' : String(value);
            }
        },

        formByKey: function (formKey) {
            var key = String(formKey || '').trim().toLowerCase();
            if (key === 'ability') {
                return this.abilityForm;
            }
            if (key === 'grant') {
                return this.grantForm;
            }
            if (key === 'requirement') {
                return this.requirementForm;
            }
            if (key === 'effect') {
                return this.effectForm;
            }
            return null;
        },

        clearJsonPresetPickers: function (formKey) {
            if (!this.root) {
                return;
            }

            var key = String(formKey || '').trim().toLowerCase();
            var pickers = this.root.querySelectorAll('[data-role="lfas-json-preset-picker"][data-form-key="' + key + '"]');
            for (var i = 0; i < pickers.length; i += 1) {
                pickers[i].value = '';
            }
        },

        resolveJsonPresetValue: function (formKey, presetKey) {
            var scope = String(formKey || '').trim().toLowerCase();
            var key = String(presetKey || '').trim();
            if (!scope || !key || !Object.prototype.hasOwnProperty.call(JSON_METADATA_PRESETS, scope)) {
                return '';
            }

            var catalog = JSON_METADATA_PRESETS[scope] || {};
            if (!Object.prototype.hasOwnProperty.call(catalog, key)) {
                return '';
            }

            return stringifyJsonPreset(catalog[key]);
        },

        applyJsonPreset: function (formKey, targetName, presetKey) {
            var form = this.formByKey(formKey);
            if (!form) {
                return;
            }

            var fieldName = String(targetName || 'metadata_json').trim();
            var field = form.elements[fieldName];
            if (!field) {
                return;
            }

            var presetValue = this.resolveJsonPresetValue(formKey, presetKey);
            if (!presetValue) {
                return;
            }

            field.value = presetValue;
            this.clearJsonPresetPickers(formKey);
        },

        validateJsonField: function (value, label) {
            var raw = String(value || '').trim();
            if (raw === '') {
                return true;
            }

            try {
                JSON.parse(raw);
                return true;
            } catch (error) {
                this.notify((label || 'Metadata JSON') + ' deve contenere un JSON valido.', 'warning');
                return false;
            }
        },

        findRowById: function (rows, id) {
            var targetId = parseInt(id || '0', 10) || 0;
            if (!Array.isArray(rows) || targetId <= 0) {
                return null;
            }
            for (var i = 0; i < rows.length; i += 1) {
                var rowId = parseInt((rows[i] && rows[i].id) || '0', 10) || 0;
                if (rowId === targetId) {
                    return rows[i];
                }
            }
            return null;
        },

        collectAbilityPayload: function () {
            return {
                id: parseInt(this.abilityForm.elements.id.value || '0', 10) || 0,
                name: String(this.abilityForm.elements.name.value || '').trim(),
                slug: String(this.abilityForm.elements.slug.value || '').trim(),
                description: String(this.abilityForm.elements.description.value || '').trim(),
                type: String(this.abilityForm.elements.type.value || 'ability').trim(),
                point_category_id: parseInt(this.abilityForm.elements.point_category_id.value || '0', 10) || 0,
                max_level: parseInt(this.abilityForm.elements.max_level.value || '1', 10) || 1,
                target_type: String(this.abilityForm.elements.target_type.value || 'self').trim(),
                effect_mode: String(this.abilityForm.elements.effect_mode.value || 'none').trim(),
                narrative_state_id: parseInt(this.abilityForm.elements.narrative_state_id.value || '0', 10) || 0,
                cooldown_seconds: parseInt(this.abilityForm.elements.cooldown_seconds.value || '0', 10) || 0,
                sort_order: parseInt(this.abilityForm.elements.sort_order.value || '100', 10) || 100,
                is_active: parseInt(this.abilityForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                is_public: parseInt(this.abilityForm.elements.is_public.value || '0', 10) === 1 ? 1 : 0,
                is_hidden_when_locked: parseInt(this.abilityForm.elements.is_hidden_when_locked.value || '0', 10) === 1 ? 1 : 0,
                requires_learning: parseInt(this.abilityForm.elements.requires_learning.value || '0', 10) === 1 ? 1 : 0,
                requires_staff_approval: parseInt(this.abilityForm.elements.requires_staff_approval.value || '0', 10) === 1 ? 1 : 0,
                metadata_json: String(this.abilityForm.elements.metadata_json.value || '').trim()
            };
        },

        saveAbility: function (keepOpen) {
            if (!this.abilityForm) {
                return;
            }

            var self = this;
            var shouldKeepOpen = keepOpen === true;
            var payload = this.collectAbilityPayload();
            if (!payload.name) {
                this.notify('Il nome abilita e obbligatorio.', 'warning');
                return;
            }
            if ((payload.effect_mode === 'apply_state' || payload.effect_mode === 'remove_state') && payload.narrative_state_id <= 0) {
                this.notify('Seleziona uno stato narrativo per questo effetto.', 'warning');
                return;
            }
            if (!this.validateJsonField(payload.metadata_json, 'Metadata abilita')) {
                return;
            }

            var endpoint = payload.id > 0
                ? '/admin/abilities-spells/abilities/update'
                : '/admin/abilities-spells/abilities/create';

            this.request(endpoint, 'lfasAbilitySave', payload).then(function (response) {
                var abilityId = payload.id > 0
                    ? payload.id
                    : (response && response.dataset ? (parseInt(response.dataset.id || '0', 10) || 0) : 0);
                self.notify('Abilita salvata.', 'success');
                self.loadAll().then(function () {
                    if (shouldKeepOpen && abilityId > 0) {
                        self.openAbilityEdit(abilityId);
                    } else {
                        self.hideAbilityModal();
                    }
                });
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio abilita non riuscito.', 'warning');
            });
        },

        deleteAbilityById: function (abilityId) {
            if (abilityId <= 0) {
                return;
            }

            var self = this;
            this.confirm('Elimina abilita', 'Confermi la rimozione di questa abilita e delle sue assegnazioni?', function () {
                self.request('/admin/abilities-spells/abilities/delete', 'lfasAbilityDelete', {
                    id: abilityId
                }).then(function () {
                    self.hideAbilityModal();
                    self.notify('Abilita eliminata.', 'success');
                    self.loadAll();
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione abilita non riuscita.', 'warning');
                });
            });
        },

        saveAssignment: function () {
            if (!this.assignmentForm) {
                return;
            }

            var self = this;
            var payload = {
                character_id: parseInt(this.assignmentForm.elements.character_id.value || '0', 10) || 0,
                ability_id: parseInt(this.assignmentForm.elements.ability_id.value || '0', 10) || 0,
                sort_order: parseInt(this.assignmentForm.elements.sort_order.value || '100', 10) || 100,
                is_active: parseInt(this.assignmentForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                status: String(this.assignmentForm.elements.status.value || 'learned').trim(),
                level: parseInt(this.assignmentForm.elements.level.value || '1', 10) || 1,
                approval_status: String(this.assignmentForm.elements.approval_status.value || 'approved').trim()
            };

            if (payload.character_id <= 0) {
                this.notify('Seleziona un personaggio valido dai suggerimenti.', 'warning');
                return;
            }
            if (payload.ability_id <= 0) {
                this.notify('Seleziona un\'abilita valida.', 'warning');
                return;
            }

            this.request('/admin/abilities-spells/assignments/create', 'lfasAssignmentCreate', payload).then(function () {
                self.hideAssignmentModal();
                if (self.assignmentFilterForm) {
                    self.assignmentFilterForm.elements.character_id.value = String(payload.character_id);
                }
                if (self.assignmentFilterSearchInput && self.assignmentSearchInput) {
                    self.assignmentFilterSearchInput.value = String(self.assignmentSearchInput.value || '').trim();
                }
                self.notify('Assegnazione salvata.', 'success');
                self.loadAssignmentsForCurrentCharacter(true);
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Assegnazione non riuscita.', 'warning');
            });
        },

        resetRankRewardForm: function () {
            if (!this.rankRewardForm) {
                return;
            }
            this.rankRewardForm.reset();
            this.rankRewardForm.elements.id.value = '0';
            this.rankRewardForm.elements.rank.value = '1';
            this.rankRewardForm.elements.point_category_id.value = '0';
            this.rankRewardForm.elements.points.value = '1';
            this.rankRewardForm.elements.is_active.value = '1';
        },

        editRankReward: function (rewardId) {
            if (!this.rankRewardForm) {
                return;
            }

            var row = null;
            for (var i = 0; i < this.rankRewardRows.length; i += 1) {
                if ((parseInt(this.rankRewardRows[i].id || '0', 10) || 0) === rewardId) {
                    row = this.rankRewardRows[i];
                    break;
                }
            }
            if (!row) {
                return;
            }

            this.rankRewardForm.elements.id.value = String(rewardId);
            this.rankRewardForm.elements.rank.value = String(parseInt(row.rank || '1', 10) || 1);
            this.rankRewardForm.elements.point_category_id.value = String(parseInt(row.point_category_id || '0', 10) || 0);
            this.rankRewardForm.elements.points.value = String(parseInt(row.points || '0', 10) || 0);
            this.rankRewardForm.elements.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
        },

        saveRankReward: function () {
            if (!this.rankRewardForm) {
                return;
            }

            var self = this;
            var payload = {
                id: parseInt(this.rankRewardForm.elements.id.value || '0', 10) || 0,
                rank: parseInt(this.rankRewardForm.elements.rank.value || '1', 10) || 1,
                point_category_id: parseInt(this.rankRewardForm.elements.point_category_id.value || '0', 10) || 0,
                points: parseInt(this.rankRewardForm.elements.points.value || '0', 10) || 0,
                is_active: parseInt(this.rankRewardForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0
            };

            if (payload.point_category_id <= 0) {
                this.notify('Seleziona una categoria punti valida.', 'warning');
                return;
            }

            this.request('/admin/abilities-spells/rewards/upsert', 'lfasRankRewardSave', payload).then(function () {
                self.notify('Premio grado salvato.', 'success');
                self.resetRankRewardForm();
                self.loadAll();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio premio non riuscito.', 'warning');
            });
        },

        deleteRankReward: function (rewardId) {
            if (rewardId <= 0) {
                return;
            }

            var self = this;
            this.confirm('Elimina premio grado', 'Confermi la rimozione di questo premio?', function () {
                self.request('/admin/abilities-spells/rewards/delete', 'lfasRankRewardDelete', { id: rewardId }).then(function () {
                    self.notify('Premio grado eliminato.', 'success');
                    self.resetRankRewardForm();
                    self.loadAll();
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione premio non riuscita.', 'warning');
                });
            });
        },

        resolvePendingApproval: function (assignmentId, decision) {
            if (assignmentId <= 0) {
                return;
            }

            var self = this;
            var isApprove = String(decision || '').trim() === 'approve';
            this.confirm(
                isApprove ? 'Approva abilita' : 'Respingi abilita',
                isApprove
                    ? 'Confermi l\'approvazione di questa richiesta?'
                    : 'Confermi il rifiuto di questa richiesta? I punti gia investiti resteranno salvati.',
                function () {
                    self.request('/admin/abilities-spells/approvals/resolve', 'lfasPendingApprovalResolve', {
                        id: assignmentId,
                        decision: isApprove ? 'approve' : 'reject'
                    }).then(function (response) {
                        var dataset = response && response.dataset ? response.dataset : null;
                        self.notify(dataset && dataset.message ? dataset.message : (isApprove ? 'Richiesta approvata.' : 'Richiesta respinta.'), 'success');
                        self.loadAll();
                    }).catch(function (error) {
                        self.notify((error && error.message) ? error.message : 'Aggiornamento approvazione non riuscito.', 'warning');
                    });
                }
            );
        },

        resetGrantForm: function () {
            if (!this.grantForm) {
                return;
            }
            this.grantForm.reset();
            this.grantForm.elements.id.value = '0';
            this.grantForm.elements.source_type.value = 'character';
            this.grantForm.elements.source_id.value = '0';
            this.grantForm.elements.grant_mode.value = 'unlock';
            this.grantForm.elements.retention_policy.value = 'keep_when_lost';
            this.grantForm.elements.min_rank.value = '0';
            this.grantForm.elements.max_rank.value = '0';
            this.grantForm.elements.priority.value = '100';
            this.grantForm.elements.is_active.value = '1';
            this.grantForm.elements.metadata_json.value = '';
            this.grantForm.elements.source_picker.value = '';
            this.clearJsonPresetPickers('grant');
            this.syncGrantSourceField();
        },

        editGrant: function (id) {
            if (!this.grantForm) {
                return;
            }
            var row = this.findRowById(this.grantRows, id);
            if (!row) {
                return;
            }
            this.grantForm.elements.id.value = String(id);
            this.grantForm.elements.source_type.value = String(row.source_type || 'character');
            this.grantForm.elements.source_id.value = String(parseInt(row.source_id || '0', 10) || 0);
            this.grantForm.elements.grant_mode.value = String(row.grant_mode || 'unlock');
            this.grantForm.elements.retention_policy.value = String(row.retention_policy || 'keep_when_lost');
            this.grantForm.elements.min_rank.value = String(parseInt(row.min_rank || '0', 10) || 0);
            this.grantForm.elements.max_rank.value = String(parseInt(row.max_rank || '0', 10) || 0);
            this.grantForm.elements.priority.value = String(parseInt(row.priority || '100', 10) || 100);
            this.grantForm.elements.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            this.grantForm.elements.metadata_json.value = String(row.metadata_json || '');
            this.clearJsonPresetPickers('grant');
            this.syncGrantSourceField();
        },

        saveGrant: function () {
            if (!this.grantForm || this.editingAbilityId <= 0) {
                return;
            }
            var self = this;
            var payload = {
                id: parseInt(this.grantForm.elements.id.value || '0', 10) || 0,
                ability_id: this.editingAbilityId,
                source_type: String(this.grantForm.elements.source_type.value || 'character').trim(),
                source_id: parseInt(this.grantForm.elements.source_id.value || '0', 10) || 0,
                grant_mode: String(this.grantForm.elements.grant_mode.value || 'unlock').trim(),
                retention_policy: String(this.grantForm.elements.retention_policy.value || 'keep_when_lost').trim(),
                min_rank: parseInt(this.grantForm.elements.min_rank.value || '0', 10) || 0,
                max_rank: parseInt(this.grantForm.elements.max_rank.value || '0', 10) || 0,
                priority: parseInt(this.grantForm.elements.priority.value || '100', 10) || 100,
                is_active: parseInt(this.grantForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                metadata_json: String(this.grantForm.elements.metadata_json.value || '').trim()
            };
            if (payload.source_id <= 0) {
                this.applyGrantSourcePickerValue();
                payload.source_id = parseInt(this.grantForm.elements.source_id.value || '0', 10) || 0;
            }
            if (payload.source_id <= 0) {
                this.notify('ID sorgente non valido.', 'warning');
                return;
            }
            if (!this.validateJsonField(payload.metadata_json, 'Metadata assegnazione')) {
                return;
            }
            this.request('/admin/abilities-spells/abilities/grants/upsert', 'lfasGrantSave', payload).then(function () {
                self.notify('Regola salvata.', 'success');
                self.loadAbilityRuleEditors();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio regola non riuscito.', 'warning');
            });
        },

        deleteGrant: function (id) {
            if (id <= 0) {
                return;
            }
            var self = this;
            this.confirm('Elimina regola', 'Confermi la rimozione di questa regola?', function () {
                self.request('/admin/abilities-spells/abilities/grants/delete', 'lfasGrantDelete', { id: id }).then(function () {
                    self.notify('Regola eliminata.', 'success');
                    self.loadAbilityRuleEditors();
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione regola non riuscita.', 'warning');
                });
            });
        },

        resetRequirementForm: function () {
            if (!this.requirementForm) {
                return;
            }
            this.requirementForm.reset();
            this.requirementForm.elements.id.value = '0';
            this.requirementForm.elements.level.value = '1';
            this.requirementForm.elements.requirement_type.value = 'attribute';
            this.requirementForm.elements.requirement_key.value = '';
            this.requirementForm.elements.operator.value = '>=';
            this.requirementForm.elements.required_value.value = '';
            this.requirementForm.elements.policy_when_unavailable.value = 'block';
            this.requirementForm.elements.is_hidden.value = '0';
            this.requirementForm.elements.is_active.value = '1';
            this.requirementForm.elements.metadata_json.value = '';
            this.clearJsonPresetPickers('requirement');
            this.syncRequirementKeyField();
        },

        editRequirement: function (id) {
            if (!this.requirementForm) {
                return;
            }
            var row = this.findRowById(this.requirementRows, id);
            if (!row) {
                return;
            }
            this.requirementForm.elements.id.value = String(id);
            this.requirementForm.elements.level.value = String(parseInt(row.level || '1', 10) || 1);
            this.requirementForm.elements.requirement_type.value = String(row.requirement_type || 'attribute');
            this.requirementForm.elements.requirement_key.value = String(row.requirement_key || '');
            this.requirementForm.elements.operator.value = String(row.operator || '>=');
            this.requirementForm.elements.required_value.value = String(row.required_value || '');
            this.requirementForm.elements.policy_when_unavailable.value = String(row.policy_when_unavailable || 'block');
            this.requirementForm.elements.is_hidden.value = parseInt(row.is_hidden || '0', 10) === 1 ? '1' : '0';
            this.requirementForm.elements.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            this.requirementForm.elements.metadata_json.value = String(row.metadata_json || '');
            this.clearJsonPresetPickers('requirement');
            this.syncRequirementKeyField();
        },

        saveRequirement: function () {
            if (!this.requirementForm || this.editingAbilityId <= 0) {
                return;
            }
            var self = this;
            var payload = {
                id: parseInt(this.requirementForm.elements.id.value || '0', 10) || 0,
                ability_id: this.editingAbilityId,
                level: parseInt(this.requirementForm.elements.level.value || '1', 10) || 1,
                requirement_type: String(this.requirementForm.elements.requirement_type.value || 'attribute').trim(),
                requirement_key: String(this.requirementForm.elements.requirement_key.value || '').trim(),
                operator: String(this.requirementForm.elements.operator.value || '>=').trim(),
                required_value: String(this.requirementForm.elements.required_value.value || '').trim(),
                policy_when_unavailable: String(this.requirementForm.elements.policy_when_unavailable.value || 'block').trim(),
                is_hidden: parseInt(this.requirementForm.elements.is_hidden.value || '0', 10) === 1 ? 1 : 0,
                is_active: parseInt(this.requirementForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                metadata_json: String(this.requirementForm.elements.metadata_json.value || '').trim()
            };
            if (!payload.requirement_key || !payload.required_value) {
                this.notify('Chiave requisito e valore sono obbligatori.', 'warning');
                return;
            }
            if (!this.validateJsonField(payload.metadata_json, 'Metadata requisito')) {
                return;
            }
            this.request('/admin/abilities-spells/abilities/requirements/upsert', 'lfasRequirementSave', payload).then(function () {
                self.notify('Requisito salvato.', 'success');
                self.loadAbilityRuleEditors();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio requisito non riuscito.', 'warning');
            });
        },

        deleteRequirement: function (id) {
            if (id <= 0) {
                return;
            }
            var self = this;
            this.confirm('Elimina requisito', 'Confermi la rimozione di questo requisito?', function () {
                self.request('/admin/abilities-spells/abilities/requirements/delete', 'lfasRequirementDelete', { id: id }).then(function () {
                    self.notify('Requisito eliminato.', 'success');
                    self.loadAbilityRuleEditors();
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione requisito non riuscita.', 'warning');
                });
            });
        },

        resetEffectForm: function () {
            if (!this.effectForm) {
                return;
            }
            this.effectForm.reset();
            this.effectForm.elements.id.value = '0';
            this.effectForm.elements.level.value = '1';
            this.effectForm.elements.effect_type.value = 'modifier';
            this.effectForm.elements.target_system.value = '';
            this.effectForm.elements.target_key.value = '';
            this.effectForm.elements.operation.value = 'add';
            this.effectForm.elements.value.value = '0';
            this.effectForm.elements.activation_policy.value = 'while_ability_usable';
            this.effectForm.elements.policy_when_unavailable.value = 'ignore';
            this.effectForm.elements.is_active.value = '1';
            this.effectForm.elements.metadata_json.value = '';
            this.clearJsonPresetPickers('effect');
            this.syncEffectTargetFields();
        },

        editEffect: function (id) {
            if (!this.effectForm) {
                return;
            }
            var row = this.findRowById(this.effectRows, id);
            if (!row) {
                return;
            }
            this.effectForm.elements.id.value = String(id);
            this.effectForm.elements.level.value = String(parseInt(row.level || '1', 10) || 1);
            this.effectForm.elements.effect_type.value = String(row.effect_type || 'modifier');
            this.effectForm.elements.target_system.value = String(row.target_system || '');
            this.effectForm.elements.target_key.value = String(row.target_key || '');
            this.effectForm.elements.operation.value = String(row.operation || 'add');
            this.effectForm.elements.value.value = String(row.value || '0');
            this.effectForm.elements.activation_policy.value = String(row.activation_policy || 'while_ability_usable');
            this.effectForm.elements.policy_when_unavailable.value = String(row.policy_when_unavailable || 'ignore');
            this.effectForm.elements.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            this.effectForm.elements.metadata_json.value = String(row.metadata_json || '');
            this.clearJsonPresetPickers('effect');
            this.syncEffectTargetFields();
        },

        saveEffect: function () {
            if (!this.effectForm || this.editingAbilityId <= 0) {
                return;
            }
            var self = this;
            var payload = {
                id: parseInt(this.effectForm.elements.id.value || '0', 10) || 0,
                ability_id: this.editingAbilityId,
                level: parseInt(this.effectForm.elements.level.value || '1', 10) || 1,
                effect_type: String(this.effectForm.elements.effect_type.value || 'modifier').trim(),
                target_system: String(this.effectForm.elements.target_system.value || '').trim(),
                target_key: String(this.effectForm.elements.target_key.value || '').trim(),
                operation: String(this.effectForm.elements.operation.value || 'add').trim(),
                value: parseFloat(this.effectForm.elements.value.value || '0') || 0,
                activation_policy: String(this.effectForm.elements.activation_policy.value || 'while_ability_usable').trim(),
                policy_when_unavailable: String(this.effectForm.elements.policy_when_unavailable.value || 'ignore').trim(),
                is_active: parseInt(this.effectForm.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                metadata_json: String(this.effectForm.elements.metadata_json.value || '').trim()
            };
            if (!payload.target_system || !payload.target_key) {
                this.notify('Sistema target e chiave target sono obbligatori.', 'warning');
                return;
            }
            if (!this.validateJsonField(payload.metadata_json, 'Metadata effetto')) {
                return;
            }
            this.request('/admin/abilities-spells/abilities/effects/upsert', 'lfasEffectSave', payload).then(function () {
                self.notify('Effetto salvato.', 'success');
                self.loadAbilityRuleEditors();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Salvataggio effetto non riuscito.', 'warning');
            });
        },

        deleteEffect: function (id) {
            if (id <= 0) {
                return;
            }
            var self = this;
            this.confirm('Elimina effetto', 'Confermi la rimozione di questo effetto?', function () {
                self.request('/admin/abilities-spells/abilities/effects/delete', 'lfasEffectDelete', { id: id }).then(function () {
                    self.notify('Effetto eliminato.', 'success');
                    self.loadAbilityRuleEditors();
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Eliminazione effetto non riuscita.', 'warning');
                });
            });
        },

        deleteAssignment: function (assignmentId) {
            if (assignmentId <= 0) {
                return;
            }

            var self = this;
            this.confirm('Rimuovi assegnazione', 'Confermi la rimozione di questa assegnazione?', function () {
                self.request('/admin/abilities-spells/assignments/delete', 'lfasAssignmentDelete', {
                    id: assignmentId
                }).then(function () {
                    self.notify('Assegnazione rimossa.', 'success');
                    self.loadAssignmentsForCurrentCharacter(true);
                }).catch(function (error) {
                    self.notify((error && error.message) ? error.message : 'Rimozione assegnazione non riuscita.', 'warning');
                });
            });
        },

        scheduleCharacterSearch: function (query, context) {
            var self = this;
            globalWindow.clearTimeout(this.characterSearchTimer);
            this.characterSearchTimer = globalWindow.setTimeout(function () {
                self.runCharacterSearch(query, context);
            }, 180);
        },

        runCharacterSearch: function (query, context) {
            var self = this;
            var needle = String(query || '').trim();
            if (needle.length < 2) {
                this.clearCharacterSearchResults(context);
                return;
            }

            this.request('/admin/abilities-spells/characters/search', 'lfasCharactersSearch', {
                query: needle
            }).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderCharacterSearchResults(rows, context);
            }).catch(function () {
                self.clearCharacterSearchResults(context);
            });
        },

        renderCharacterSearchResults: function (rows, context) {
            var root = this.characterResultsRoot(context);
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
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) {
                    continue;
                }
                html.push(
                    '<button type="button" class="list-group-item list-group-item-action"'
                    + ' data-role="lfas-character-result"'
                    + ' data-context="' + escapeHtml(context || 'filter') + '"'
                    + ' data-id="' + id + '"'
                    + ' data-label="' + escapeHtml(row.label || '') + '">'
                    + escapeHtml(row.label || ('#' + String(id)))
                    + '</button>'
                );
            }
            root.innerHTML = html.join('');
        },

        selectCharacterResult: function (trigger) {
            var context = String(trigger.getAttribute('data-context') || 'filter').trim() || 'filter';
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            var label = String(trigger.getAttribute('data-label') || '').trim();
            if (id <= 0) {
                return;
            }

            this.setCharacterSelection(context, id, label);
            this.clearCharacterSearchResults(context);

            if (context === 'filter') {
                this.loadAssignmentsForCurrentCharacter(true);
            }
        },

        setCharacterSelection: function (context, characterId, label) {
            var hiddenInput = this.characterIdInput(context);
            var searchInput = this.characterSearchInput(context);
            if (hiddenInput) {
                hiddenInput.value = characterId > 0 ? String(characterId) : '';
            }
            if (searchInput && label !== '') {
                searchInput.value = label;
            }
        },

        clearCharacterSelection: function (context) {
            var hiddenInput = this.characterIdInput(context);
            if (hiddenInput) {
                hiddenInput.value = '';
            }
        },

        characterIdInput: function (context) {
            if (context === 'grant') {
                return this.grantForm && this.grantForm.elements ? this.grantForm.elements.source_id : null;
            }
            if (context === 'modal') {
                return this.assignmentForm && this.assignmentForm.elements ? this.assignmentForm.elements.character_id : null;
            }
            return this.assignmentFilterForm && this.assignmentFilterForm.elements ? this.assignmentFilterForm.elements.character_id : null;
        },

        characterSearchInput: function (context) {
            if (context === 'grant') {
                return this.grantForm && this.grantForm.elements ? this.grantForm.elements.source_picker : null;
            }
            return context === 'modal' ? this.assignmentSearchInput : this.assignmentFilterSearchInput;
        },

        characterResultsRoot: function (context) {
            if (context === 'grant') {
                return this.grantSourceResults;
            }
            return context === 'modal' ? this.assignmentSearchResults : this.assignmentFilterResults;
        },

        clearCharacterSearchResults: function (context) {
            if (!context || context === 'grant') {
                if (this.grantSourceResults) {
                    this.grantSourceResults.innerHTML = '';
                }
            }
            if (!context || context === 'filter') {
                if (this.assignmentFilterResults) {
                    this.assignmentFilterResults.innerHTML = '';
                }
            }
            if (!context || context === 'modal') {
                if (this.assignmentSearchResults) {
                    this.assignmentSearchResults.innerHTML = '';
                }
            }
        },

        showAbilityModal: function () {
            if (this.abilityModal && typeof this.abilityModal.show === 'function') {
                this.abilityModal.show();
            }
        },

        hideAbilityModal: function () {
            if (this.abilityModal && typeof this.abilityModal.hide === 'function') {
                this.abilityModal.hide();
            }
        },

        showAssignmentModal: function () {
            if (this.assignmentModal && typeof this.assignmentModal.show === 'function') {
                this.assignmentModal.show();
            }
        },

        hideAssignmentModal: function () {
            if (this.assignmentModal && typeof this.assignmentModal.hide === 'function') {
                this.assignmentModal.hide();
            }
        },

        targetBadge: function (targetType) {
            var type = String(targetType || 'self').trim().toLowerCase();
            if (type === 'scene') {
                return '<span class="badge text-bg-primary">Scena</span>';
            }
            return '<span class="badge text-bg-secondary">Se stesso</span>';
        },

        effectBadge: function (effectMode, stateName) {
            var mode = String(effectMode || 'none').trim().toLowerCase();
            var name = String(stateName || '').trim();
            if (mode === 'apply_state') {
                return '<span class="badge text-bg-info">Applica stato</span>' + (name !== '' ? '<div class="small text-muted mt-1">' + escapeHtml(name) + '</div>' : '');
            }
            if (mode === 'remove_state') {
                return '<span class="badge text-bg-warning">Rimuovi stato</span>' + (name !== '' ? '<div class="small text-muted mt-1">' + escapeHtml(name) + '</div>' : '');
            }
            return '<span class="badge text-bg-light text-dark">Nessun effetto</span>';
        }
    };
}

globalWindow.LogeonAbilitiesSpellsAdminModuleFactory = createLogeonAbilitiesSpellsAdminModule;

if (globalWindow.AdminRegistry) {
    globalWindow.AdminRegistry.registerModule('admin.abilities-spells', 'LogeonAbilitiesSpellsAdminModuleFactory');
    globalWindow.AdminRegistry.extendPage('abilities-spells', ['admin.abilities-spells']);
}

export { createLogeonAbilitiesSpellsAdminModule as LogeonAbilitiesSpellsAdminModuleFactory };
export default createLogeonAbilitiesSpellsAdminModule;
