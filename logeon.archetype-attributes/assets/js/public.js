(function (window) {
    'use strict';

    if (window.__archetypeAttributesGameLoaded === true) {
        return;
    }

    if (window.__archetypeAttributesPublicLoaded === true) {
        return;
    }
    window.__archetypeAttributesPublicLoaded = true;

    var bindings = {};

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

    function resolveForm(event) {
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

    function elements(form) {
        if (!form) {
            return null;
        }
        var field = form.querySelector('[data-role="character-create-archetype-attributes"]');
        if (!field) {
            return null;
        }
        return {
            field: field,
            body: field.querySelector('[data-role="character-create-archetype-attributes-body"]')
        };
    }

    function bind(form) {
        if (!form || !form.id) {
            return;
        }
        if (bindings[form.id] === true) {
            refresh(form, 180);
            return;
        }
        bindings[form.id] = true;

        var select = form.querySelector('[data-role="character-create-archetype-select"]');
        if (select) {
            select.addEventListener('change', function () {
                refresh(form, 80);
            });
        }

        refresh(form, 180);
    }

    function refresh(form, delay) {
        if (!form) {
            return;
        }
        if (form.__aaPublicTimer) {
            window.clearTimeout(form.__aaPublicTimer);
        }
        form.__aaPublicTimer = window.setTimeout(function () {
            var select = form.querySelector('[data-role="character-create-archetype-select"]');
            var nodes = elements(form);
            if (!nodes || !nodes.body) {
                return;
            }
            if (!select) {
                nodes.field.style.display = 'none';
                nodes.body.innerHTML = '';
                return;
            }

            var ids = selectedArchetypeIds(select);
            if (!ids.length) {
                nodes.field.style.display = 'none';
                nodes.body.innerHTML = '';
                return;
            }

            post('/archetype-attributes/character-create/rules', { archetype_ids: ids })
                .then(function (response) {
                    render(form, response && response.dataset ? response.dataset : {});
                })
                .catch(function () {
                    nodes.field.style.display = 'none';
                    nodes.body.innerHTML = '';
                });
        }, delay || 0);
    }

    function render(form, dataset) {
        var nodes = elements(form);
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
            var notes = [];
            if (row.display_hint) {
                notes.push('<div class="small text-muted mt-1">' + escapeHtml(String(row.display_hint)) + '</div>');
            }
            if (Array.isArray(row.suggestions)) {
                for (var j = 0; j < row.suggestions.length; j += 1) {
                    notes.push('<div class="small text-muted">' + escapeHtml(String(row.suggestions[j] || '')) + '</div>');
                }
            }

            html.push(
                '<div class="border rounded p-2" data-attribute-id="' + attributeId + '">'
                + '<div class="fw-semibold">' + escapeHtml(row.name || ('Attributo #' + attributeId)) + '</div>'
                + '<div class="small text-muted">' + escapeHtml(row.description || '') + '</div>'
                + '<input type="number" step="0.01" class="form-control mt-2" data-field="attribute-base-value" value="' + escapeHtml(defaultValue) + '"' + readonly + '>'
                + notes.join('')
                + '</div>'
            );
        }

        nodes.body.innerHTML = html.join('');
        nodes.field.style.display = nodes.body.children.length > 0 ? '' : 'none';
    }

    document.addEventListener('character-create:before-show', function (event) {
        bind(resolveForm(event));
    });

    document.addEventListener('character-create:collect-payload', function (event) {
        var detail = (event && event.detail && typeof event.detail === 'object') ? event.detail : {};
        var form = resolveForm(event);
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
    });
})(window);
