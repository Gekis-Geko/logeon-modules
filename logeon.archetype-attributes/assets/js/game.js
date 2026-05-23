(function (window) {
    'use strict';

    window.__archetypeAttributesGameLoaded = true;

    var createBindings = {};
    var profileRulesCache = null;
    var profileObserver = null;

    function resolveHttp() {
        if (window.Request && window.Request.http && typeof window.Request.http.post === 'function') {
            return window.Request.http;
        }
        return null;
    }

    function post(url, payload) {
        var http = resolveHttp();
        if (!http) {
            return Promise.reject(new Error('Request non disponibile.'));
        }
        return http.post(url, payload || {});
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeNumber(value) {
        var raw = String(value == null ? '' : value).trim().replace(',', '.');
        if (raw === '') {
            return null;
        }
        var parsed = parseFloat(raw);
        return isFinite(parsed) ? parsed : null;
    }

    function selectedArchetypeIds(selectNode) {
        if (!selectNode) {
            return [];
        }
        var out = [];
        var options = selectNode.selectedOptions || [];
        for (var i = 0; i < options.length; i += 1) {
            var id = parseInt(options[i].value || '0', 10) || 0;
            if (id > 0 && out.indexOf(id) === -1) {
                out.push(id);
            }
        }
        return out;
    }

    function resolveCreateForm(event) {
        var detail = (event && event.detail && typeof event.detail === 'object') ? event.detail : {};
        if (detail.form && detail.form.nodeType === 1) {
            return detail.form;
        }
        var formId = String(detail.formId || '').trim();
        if (formId !== '') {
            return document.getElementById(formId);
        }
        return document.getElementById('create-character-modal-form');
    }

    function createFieldElements(form) {
        if (!form) {
            return null;
        }
        var field = form.querySelector('[data-role="character-create-archetype-attributes"]');
        if (!field) {
            return null;
        }
        return {
            field: field,
            body: field.querySelector('[data-role="character-create-archetype-attributes-body"]'),
            hint: field.querySelector('[data-role="character-create-archetype-attributes-hint"]')
        };
    }

    function resolveCreateArchetypeSelect(form) {
        if (!form) {
            return null;
        }
        return form.querySelector('[data-role="character-create-archetype-select"]');
    }

    function bindCreateForm(form) {
        if (!form || !form.id) {
            return;
        }

        if (createBindings[form.id] === true) {
            scheduleCreateRulesRefresh(form, 180);
            return;
        }
        createBindings[form.id] = true;

        var select = resolveCreateArchetypeSelect(form);
        if (select) {
            select.addEventListener('change', function () {
                scheduleCreateRulesRefresh(form, 80);
            });
        }

        scheduleCreateRulesRefresh(form, 180);
    }

    function scheduleCreateRulesRefresh(form, delay) {
        if (!form) {
            return;
        }

        if (form.__aaCreateTimer) {
            window.clearTimeout(form.__aaCreateTimer);
            form.__aaCreateTimer = null;
        }

        form.__aaCreateTimer = window.setTimeout(function () {
            refreshCreateRules(form, 0);
        }, delay || 0);
    }

    function refreshCreateRules(form, attempts) {
        var select = resolveCreateArchetypeSelect(form);
        if (!select) {
            if ((attempts || 0) < 8) {
                window.setTimeout(function () {
                    refreshCreateRules(form, (attempts || 0) + 1);
                }, 160);
            }
            return;
        }

        var ids = selectedArchetypeIds(select);
        var field = createFieldElements(form);
        if (!field || !field.body) {
            return;
        }

        if (!ids.length) {
            field.field.style.display = 'none';
            field.body.innerHTML = '';
            return;
        }

        post('/archetype-attributes/character-create/rules', {
            archetype_ids: ids
        }).then(function (response) {
            renderCreateRules(form, response && response.dataset ? response.dataset : {});
        }).catch(function () {
            field.field.style.display = 'none';
            field.body.innerHTML = '';
        });
    }

    function renderCreateRules(form, dataset) {
        var nodes = createFieldElements(form);
        if (!nodes || !nodes.body) {
            return;
        }

        var enabled = parseInt((dataset && dataset.enabled) || 0, 10) === 1;
        var rows = (dataset && Array.isArray(dataset.dataset)) ? dataset.dataset : [];
        if (!enabled || !rows.length) {
            nodes.field.style.display = 'none';
            nodes.body.innerHTML = '';
            return;
        }

        var html = [];
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var attributeId = parseInt(row.attribute_id || 0, 10) || 0;
            if (attributeId <= 0) {
                continue;
            }
            var fixedValue = row.fixed_value != null ? String(row.fixed_value) : '';
            var defaultValue = fixedValue !== ''
                ? fixedValue
                : (row.default_value != null ? String(row.default_value) : '');
            var readonly = fixedValue !== '' ? ' readonly' : '';
            var hint = String(row.display_hint || '').trim();
            var suggestions = Array.isArray(row.suggestions) ? row.suggestions : [];
            var notes = [];
            if (hint !== '') {
                notes.push('<div class="small text-muted mt-1">' + escapeHtml(hint) + '</div>');
            }
            for (var j = 0; j < suggestions.length; j += 1) {
                notes.push('<div class="small text-muted">' + escapeHtml(String(suggestions[j] || '')) + '</div>');
            }

            html.push(
                '<div class="border rounded p-2" data-attribute-id="' + attributeId + '">'
                + '<div class="d-flex justify-content-between align-items-center gap-2">'
                + '<div><div class="fw-semibold">' + escapeHtml(row.name || ('Attributo #' + attributeId)) + '</div>'
                + '<div class="small text-muted">' + escapeHtml(row.description || '') + '</div></div>'
                + '<div class="text-end small text-muted">' + escapeHtml(row.value_type || 'number') + '</div>'
                + '</div>'
                + '<div class="mt-2">'
                + '<input type="number" step="0.01" class="form-control" data-field="attribute-base-value" value="' + escapeHtml(defaultValue) + '"' + readonly + '>'
                + '</div>'
                + notes.join('')
                + '</div>'
            );
        }

        nodes.body.innerHTML = html.join('');
        nodes.field.style.display = nodes.body.children.length > 0 ? '' : 'none';
    }

    function collectCreatePayload(event) {
        var detail = (event && event.detail && typeof event.detail === 'object') ? event.detail : {};
        var form = resolveCreateForm(event);
        if (!form || !detail.payload || typeof detail.payload !== 'object') {
            return;
        }

        var rows = form.querySelectorAll('[data-role="character-create-archetype-attributes-body"] [data-attribute-id]');
        var out = [];
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i];
            var attributeId = parseInt(row.getAttribute('data-attribute-id') || '0', 10) || 0;
            var input = row.querySelector('[data-field="attribute-base-value"]');
            if (attributeId <= 0 || !input) {
                continue;
            }

            var value = String(input.value || '').trim();
            out.push({
                attribute_id: attributeId,
                base_value: value === '' ? null : value
            });
        }

        detail.payload.attribute_values = out;
    }

    function patchProfileModule() {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return false;
        }

        var module = null;
        try {
            module = window.RuntimeBootstrap.resolveAppModule('game.profile');
        } catch (error) {
            module = null;
        }

        if (!module || typeof module.request !== 'function' || typeof module.updateAttributeValues !== 'function') {
            return false;
        }
        if (module.__archetypeAttributesPatched === true) {
            return true;
        }

        module.__archetypeAttributesPatched = true;
        module.__originalUpdateAttributeValues = module.updateAttributeValues.bind(module);
        module.updateAttributeValues = function (payload) {
            return this.request('/archetype-attributes/profile/update-values', 'updateAttributeValues', payload || {});
        };
        return true;
    }

    function scheduleProfilePatch() {
        if (patchProfileModule()) {
            return;
        }

        var tries = 0;
        var timer = window.setInterval(function () {
            tries += 1;
            if (patchProfileModule() || tries >= 20) {
                window.clearInterval(timer);
            }
        }, 300);
    }

    function profileModalNode() {
        return document.getElementById('profile-attributes-edit-modal');
    }

    function currentProfileCharacterId(modal) {
        var node = modal ? modal.querySelector('[data-role="profile-attributes-edit-character-id"]') : null;
        return node ? (parseInt(String(node.textContent || '0').trim(), 10) || 0) : 0;
    }

    function loadProfileRules() {
        var modal = profileModalNode();
        if (!modal) {
            return;
        }
        var characterId = currentProfileCharacterId(modal);
        if (characterId <= 0) {
            return;
        }

        post('/archetype-attributes/profile/rules', {
            character_id: characterId
        }).then(function (response) {
            profileRulesCache = response && response.dataset ? response.dataset : null;
            applyProfileRules();
        }).catch(function () {
            profileRulesCache = null;
        });
    }

    function profileRulesIndex() {
        var dataset = profileRulesCache && Array.isArray(profileRulesCache.dataset) ? profileRulesCache.dataset : [];
        var index = {};
        for (var i = 0; i < dataset.length; i += 1) {
            var row = dataset[i] || {};
            var id = parseInt(row.attribute_id || 0, 10) || 0;
            if (id > 0) {
                index[id] = row;
            }
        }
        return index;
    }

    function applyProfileRules() {
        var modal = profileModalNode();
        if (!modal) {
            return;
        }
        var body = modal.querySelector('[data-role="profile-attributes-edit-body"]');
        if (!body) {
            return;
        }

        var index = profileRulesIndex();
        var rows = body.querySelectorAll('tr[data-attribute-id]');
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i];
            var attributeId = parseInt(row.getAttribute('data-attribute-id') || '0', 10) || 0;
            var rule = index[attributeId] || null;
            if (!rule) {
                continue;
            }

            var nameCell = row.children.length ? row.children[0] : null;
            if (nameCell) {
                var helper = nameCell.querySelector('[data-role="archetype-attribute-helper"]');
                if (!helper) {
                    helper = document.createElement('div');
                    helper.setAttribute('data-role', 'archetype-attribute-helper');
                    helper.className = 'small text-muted mt-1';
                    nameCell.appendChild(helper);
                }
                var chunks = [];
                if (rule.display_hint) {
                    chunks.push(escapeHtml(String(rule.display_hint)));
                }
                if (Array.isArray(rule.suggestions) && rule.suggestions.length) {
                    for (var s = 0; s < rule.suggestions.length; s += 1) {
                        chunks.push(escapeHtml(String(rule.suggestions[s] || '')));
                    }
                }
                helper.innerHTML = chunks.join('<br>');
            }

            var baseInput = row.querySelector('[data-field="base_value"]');
            if (baseInput) {
                if (rule.enforced_min_value != null) {
                    baseInput.setAttribute('min', String(rule.enforced_min_value));
                }
                if (rule.enforced_max_value != null) {
                    baseInput.setAttribute('max', String(rule.enforced_max_value));
                }
                if (rule.fixed_value != null && rule.fixed_value !== '') {
                    baseInput.value = String(rule.fixed_value);
                    baseInput.readOnly = true;
                } else {
                    baseInput.readOnly = false;
                }
            }
        }
    }

    function bindProfileModalObserver() {
        var modal = profileModalNode();
        if (!modal) {
            return;
        }

        modal.addEventListener('shown.bs.modal', function () {
            loadProfileRules();
        });

        var body = modal.querySelector('[data-role="profile-attributes-edit-body"]');
        if (!body || typeof MutationObserver !== 'function') {
            return;
        }

        if (profileObserver) {
            profileObserver.disconnect();
        }

        profileObserver = new MutationObserver(function () {
            if (profileRulesCache) {
                applyProfileRules();
            }
        });
        profileObserver.observe(body, { childList: true, subtree: false });
    }

    function init() {
        document.addEventListener('character-create:before-show', function (event) {
            bindCreateForm(resolveCreateForm(event));
        });
        document.addEventListener('character-create:collect-payload', collectCreatePayload);

        scheduleProfilePatch();
        bindProfileModalObserver();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})(window);
