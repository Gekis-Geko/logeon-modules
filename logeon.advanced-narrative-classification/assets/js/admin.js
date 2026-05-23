(function () {
    'use strict';

    var page = {
        root: null,
        state: {
            summary: {},
            core_tags: [],
            taxonomies: [],
            nodes: [],
            aliases: [],
            node_links: []
        },

        init: function () {
            this.root = document.querySelector('#admin-page [data-admin-page="advanced-narrative-classification"]');
            if (!this.root) { return; }
            this.bind();
            this.load();
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'anc-admin-reload') { self.load(); return; }
                if (action === 'anc-taxonomy-save') { self.saveTaxonomy(); return; }
                if (action === 'anc-taxonomy-reset') { self.resetForm('taxonomy'); return; }
                if (action === 'anc-node-save') { self.saveNode(); return; }
                if (action === 'anc-node-reset') { self.resetForm('node'); return; }
                if (action === 'anc-alias-save') { self.saveAlias(); return; }
                if (action === 'anc-alias-reset') { self.resetForm('alias'); return; }
                if (action === 'anc-node-tags-save') { self.saveNodeTags(); return; }
                if (action === 'anc-node-tags-select-none') { self.selectNoneNodeTags(); return; }
                if (action === 'anc-discovery-reset') { self.resetDiscovery(); return; }

                if (action === 'anc-taxonomy-edit') { self.editTaxonomy(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-taxonomy-delete') { self.deleteTaxonomy(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-node-edit') { self.editNode(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-node-delete') { self.deleteNode(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-node-manage-tags') { self.pickNodeForTags(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-alias-edit') { self.editAlias(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-alias-delete') { self.deleteAlias(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
            });

            var discoveryForm = this.root.querySelector('[data-role="anc-discovery-form"]');
            if (discoveryForm) {
                discoveryForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.runDiscovery();
                });
            }

            var nodeTagsSelect = this.root.querySelector('[data-role="anc-node-tags-node-id"]');
            if (nodeTagsSelect) {
                nodeTagsSelect.addEventListener('change', function () {
                    self.renderNodeTags();
                });
            }

            var nodeFormTaxonomy = this.root.querySelector('[data-role="anc-node-form"] [name="taxonomy_id"]');
            if (nodeFormTaxonomy) {
                nodeFormTaxonomy.addEventListener('change', function () {
                    self.populateParentOptions();
                });
            }
        },

        load: function () {
            var self = this;
            this.post('/admin/advanced-narrative-classification/bootstrap', {}, function (response) {
                self.state = response && response.dataset ? response.dataset : self.state;
                self.render();
            });
        },

        render: function () {
            this.renderSummary();
            this.populateTagSelects();
            this.populateTaxonomyOptions();
            this.populateParentOptions();
            this.renderTaxonomies();
            this.renderNodes();
            this.renderAliases();
            this.populateNodeTagsNodeSelect();
            this.renderNodeTags();
        },

        renderSummary: function () {
            var summary = this.state.summary || {};
            this.setText('[data-role="anc-summary-taxonomies"]', summary.taxonomies || 0);
            this.setText('[data-role="anc-summary-nodes"]', summary.nodes || 0);
            this.setText('[data-role="anc-summary-aliases"]', summary.aliases || 0);
            this.setText('[data-role="anc-summary-links"]', summary.links || 0);
            this.setText('[data-role="anc-summary-orphans"]', summary.orphan_tags || 0);
        },

        populateTagSelects: function () {
            var tags = Array.isArray(this.state.core_tags) ? this.state.core_tags : [];
            var html = '<option value="">Seleziona tag...</option>';
            for (var i = 0; i < tags.length; i += 1) {
                var row = tags[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.escape(row.label || row.slug || ('Tag #' + id)) + '</option>';
            }
            var select = this.root.querySelector('[data-role="anc-alias-form"] [name="tag_id"]');
            if (select) { select.innerHTML = html; }
        },

        populateTaxonomyOptions: function () {
            var taxonomies = Array.isArray(this.state.taxonomies) ? this.state.taxonomies : [];
            var html = '<option value="">Seleziona tassonomia...</option>';
            for (var i = 0; i < taxonomies.length; i += 1) {
                var row = taxonomies[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.escape(row.name || row.slug || ('#' + id)) + '</option>';
            }
            var select = this.root.querySelector('[data-role="anc-node-form"] [name="taxonomy_id"]');
            if (select) { select.innerHTML = html; }
        },

        populateParentOptions: function () {
            var taxonomySelect = this.root.querySelector('[data-role="anc-node-form"] [name="taxonomy_id"]');
            var taxonomyId = taxonomySelect ? (parseInt(taxonomySelect.value || '0', 10) || 0) : 0;
            var nodes = Array.isArray(this.state.nodes) ? this.state.nodes : [];
            var currentNodeId = parseInt(this.formValue('node', 'id') || '0', 10) || 0;
            var html = '<option value="">Nessun parent</option>';
            for (var i = 0; i < nodes.length; i += 1) {
                var row = nodes[i] || {};
                if (parseInt(row.taxonomy_id || '0', 10) !== taxonomyId) { continue; }
                if (parseInt(row.parent_id || '0', 10) > 0) { continue; }
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0 || id === currentNodeId) { continue; }
                html += '<option value="' + id + '">' + this.escape(row.label || row.slug || ('#' + id)) + '</option>';
            }
            var select = this.root.querySelector('[data-role="anc-node-form"] [name="parent_id"]');
            if (select) { select.innerHTML = html; }
        },

        renderTaxonomies: function () {
            var body = this.root.querySelector('[data-role="anc-taxonomies-table"]');
            if (!body) { return; }
            var rows = Array.isArray(this.state.taxonomies) ? this.state.taxonomies : [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessuna tassonomia.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.name || '-') + '</div><div class="small text-muted">' + this.escape(row.slug || '') + '</div></td>'
                    + '<td class="text-center">' + (parseInt(row.nodes_count || '0', 10) || 0) + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="anc-taxonomy-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="anc-taxonomy-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        renderNodes: function () {
            var body = this.root.querySelector('[data-role="anc-nodes-table"]');
            if (!body) { return; }
            var rows = Array.isArray(this.state.nodes) ? this.state.nodes : [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessun nodo.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.label || '-') + '</div><div class="small text-muted">' + this.escape(row.taxonomy_name || '') + (row.parent_label ? ' / ' + this.escape(row.parent_label) : '') + '</div></td>'
                    + '<td class="text-center">' + (parseInt(row.linked_tags_count || '0', 10) || 0) + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-secondary" data-action="anc-node-manage-tags" data-id="' + id + '">Tag</button>'
                    + '<button type="button" class="btn btn-outline-primary" data-action="anc-node-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="anc-node-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        renderAliases: function () {
            var body = this.root.querySelector('[data-role="anc-aliases-table"]');
            if (!body) { return; }
            var rows = Array.isArray(this.state.aliases) ? this.state.aliases : [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted small">Nessun alias.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                html += '<tr>'
                    + '<td><div class="fw-semibold">' + this.escape(row.alias || '-') + '</div><div class="small text-muted">' + this.escape(row.notes || '') + '</div></td>'
                    + '<td>' + this.escape(row.tag_label || row.tag_slug || '-') + '</td>'
                    + '<td class="text-end"><div class="btn-group btn-group-sm">'
                    + '<button type="button" class="btn btn-outline-primary" data-action="anc-alias-edit" data-id="' + id + '">Modifica</button>'
                    + '<button type="button" class="btn btn-outline-danger" data-action="anc-alias-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></td>'
                    + '</tr>';
            }
            body.innerHTML = html;
        },

        populateNodeTagsNodeSelect: function () {
            var select = this.root.querySelector('[data-role="anc-node-tags-node-id"]');
            if (!select) { return; }
            var nodes = Array.isArray(this.state.nodes) ? this.state.nodes : [];
            var current = String(select.value || '');
            var html = '<option value="">Seleziona nodo...</option>';
            for (var i = 0; i < nodes.length; i += 1) {
                var row = nodes[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.escape((row.taxonomy_name || '') + ' / ' + (row.label || ('#' + id))) + '</option>';
            }
            select.innerHTML = html;
            if (current) { select.value = current; }
        },

        renderNodeTags: function () {
            var container = this.root.querySelector('[data-role="anc-node-tags-container"]');
            var select = this.root.querySelector('[data-role="anc-node-tags-node-id"]');
            if (!container || !select) { return; }
            var nodeId = parseInt(select.value || '0', 10) || 0;
            if (nodeId <= 0) {
                container.innerHTML = '<div class="text-muted small">Seleziona un nodo per gestire i tag collegati.</div>';
                return;
            }

            var tags = Array.isArray(this.state.core_tags) ? this.state.core_tags : [];
            var links = Array.isArray(this.state.node_links) ? this.state.node_links : [];
            var selected = {};
            for (var i = 0; i < links.length; i += 1) {
                var link = links[i] || {};
                if ((parseInt(link.node_id || '0', 10) || 0) !== nodeId) { continue; }
                selected[parseInt(link.tag_id || '0', 10) || 0] = true;
            }

            var html = '';
            for (var j = 0; j < tags.length; j += 1) {
                var row = tags[j] || {};
                var tagId = parseInt(row.id || '0', 10) || 0;
                if (tagId <= 0) { continue; }
                var checked = selected[tagId] ? ' checked' : '';
                html += '<div class="form-check mb-1">'
                    + '<input class="form-check-input" type="checkbox" value="' + tagId + '" id="anc-node-tag-' + tagId + '"' + checked + '>'
                    + '<label class="form-check-label small" for="anc-node-tag-' + tagId + '">'
                    + this.escape(row.label || row.slug || ('Tag #' + tagId))
                    + (row.category ? ' <span class="text-muted">(' + this.escape(row.category) + ')</span>' : '')
                    + '</label></div>';
            }
            container.innerHTML = html || '<div class="text-muted small">Nessun tag disponibile.</div>';
        },

        selectNoneNodeTags: function () {
            var container = this.root.querySelector('[data-role="anc-node-tags-container"]');
            if (!container) { return; }
            var checks = container.querySelectorAll('input[type="checkbox"]');
            for (var i = 0; i < checks.length; i += 1) {
                checks[i].checked = false;
            }
        },

        saveNodeTags: function () {
            var select = this.root.querySelector('[data-role="anc-node-tags-node-id"]');
            var container = this.root.querySelector('[data-role="anc-node-tags-container"]');
            if (!select || !container) { return; }
            var nodeId = parseInt(select.value || '0', 10) || 0;
            if (nodeId <= 0) {
                this.toast('Seleziona un nodo tassonomico.', 'warning');
                return;
            }
            var checks = container.querySelectorAll('input[type="checkbox"]:checked');
            var tagIds = [];
            for (var i = 0; i < checks.length; i += 1) {
                var tagId = parseInt(checks[i].value || '0', 10) || 0;
                if (tagId > 0) { tagIds.push(tagId); }
            }
            var self = this;
            this.post('/admin/advanced-narrative-classification/node/tags/sync', { node_id: nodeId, tag_ids: tagIds }, function () {
                self.toast('Collegamenti nodo-tag salvati.', 'success');
                self.load();
            });
        },

        saveTaxonomy: function () {
            var payload = this.collectForm('taxonomy');
            if (!payload.slug || !payload.name) {
                this.toast('Slug e nome sono obbligatori.', 'warning');
                return;
            }
            var self = this;
            this.post('/admin/advanced-narrative-classification/taxonomy/upsert', payload, function () {
                self.toast('Tassonomia salvata.', 'success');
                self.resetForm('taxonomy');
                self.load();
            });
        },

        saveNode: function () {
            var payload = this.collectForm('node');
            if (!payload.taxonomy_id || !payload.slug || !payload.label) {
                this.toast('Tassonomia, slug e label sono obbligatori.', 'warning');
                return;
            }
            var self = this;
            this.post('/admin/advanced-narrative-classification/node/upsert', payload, function () {
                self.toast('Nodo salvato.', 'success');
                self.resetForm('node');
                self.load();
            });
        },

        saveAlias: function () {
            var payload = this.collectForm('alias');
            if (!payload.tag_id || !payload.alias) {
                this.toast('Tag e alias sono obbligatori.', 'warning');
                return;
            }
            var self = this;
            this.post('/admin/advanced-narrative-classification/alias/upsert', payload, function () {
                self.toast('Alias salvato.', 'success');
                self.resetForm('alias');
                self.load();
            });
        },

        editTaxonomy: function (id) {
            var rows = this.state.taxonomies || [];
            for (var i = 0; i < rows.length; i += 1) {
                if ((parseInt(rows[i].id || '0', 10) || 0) !== id) { continue; }
                this.fillForm('taxonomy', rows[i]);
                return;
            }
        },

        editNode: function (id) {
            var rows = this.state.nodes || [];
            for (var i = 0; i < rows.length; i += 1) {
                if ((parseInt(rows[i].id || '0', 10) || 0) !== id) { continue; }
                this.fillForm('node', rows[i]);
                this.populateParentOptions();
                var parent = this.root.querySelector('[data-role="anc-node-form"] [name="parent_id"]');
                if (parent) { parent.value = String(rows[i].parent_id || ''); }
                return;
            }
        },

        editAlias: function (id) {
            var rows = this.state.aliases || [];
            for (var i = 0; i < rows.length; i += 1) {
                if ((parseInt(rows[i].id || '0', 10) || 0) !== id) { continue; }
                this.fillForm('alias', rows[i]);
                return;
            }
        },

        pickNodeForTags: function (id) {
            var select = this.root.querySelector('[data-role="anc-node-tags-node-id"]');
            if (!select) { return; }
            select.value = String(id || '');
            this.renderNodeTags();
        },

        deleteTaxonomy: function (id) {
            var self = this;
            this.confirm('Elimina tassonomia', 'Confermi l\'eliminazione della tassonomia selezionata?', function () {
                self.post('/admin/advanced-narrative-classification/taxonomy/delete', { id: id }, function () {
                    self.toast('Tassonomia eliminata.', 'success');
                    self.load();
                });
            });
        },

        deleteNode: function (id) {
            var self = this;
            this.confirm('Elimina nodo', 'Confermi l\'eliminazione del nodo selezionato?', function () {
                self.post('/admin/advanced-narrative-classification/node/delete', { id: id }, function () {
                    self.toast('Nodo eliminato.', 'success');
                    self.load();
                });
            });
        },

        deleteAlias: function (id) {
            var self = this;
            this.confirm('Elimina alias', 'Confermi l\'eliminazione dell\'alias selezionato?', function () {
                self.post('/admin/advanced-narrative-classification/alias/delete', { id: id }, function () {
                    self.toast('Alias eliminato.', 'success');
                    self.load();
                });
            });
        },

        resetForm: function (kind) {
            var form = this.root.querySelector('[data-role="anc-' + kind + '-form"]');
            if (!form) { return; }
            form.reset();
            var idField = form.querySelector('[name="id"]');
            if (idField) { idField.value = '0'; }
            if (kind === 'node') { this.populateParentOptions(); }
        },

        resetDiscovery: function () {
            var form = this.root.querySelector('[data-role="anc-discovery-form"]');
            if (form) { form.reset(); }
            var output = this.root.querySelector('[data-role="anc-discovery-output"]');
            if (output) { output.innerHTML = 'Nessuna preview eseguita.'; }
        },

        runDiscovery: function () {
            var form = this.root.querySelector('[data-role="anc-discovery-form"]');
            if (!form) { return; }
            var payload = {
                query: this.field(form, 'query'),
                entity_type: this.field(form, 'entity_type'),
                match_mode: this.field(form, 'match_mode'),
                limit: 5
            };
            var self = this;
            this.post('/admin/advanced-narrative-classification/discover', payload, function (response) {
                self.renderDiscovery(response && response.dataset ? response.dataset : {});
            });
        },

        renderDiscovery: function (dataset) {
            var output = this.root.querySelector('[data-role="anc-discovery-output"]');
            if (!output) { return; }
            var results = dataset.results || {};
            var totals = dataset.totals || {};
            var types = [
                ['quest_definition', 'Quest'],
                ['narrative_event', 'Eventi narrativi'],
                ['system_event', 'Eventi di sistema'],
                ['scene', 'Scene'],
                ['faction', 'Fazioni']
            ];
            var html = '';
            for (var i = 0; i < types.length; i += 1) {
                var key = types[i][0];
                var label = types[i][1];
                var rows = Array.isArray(results[key]) ? results[key] : [];
                if (!rows.length) { continue; }
                html += '<div class="mb-3"><div class="fw-semibold mb-1">' + label + ' <span class="text-muted">(' + (parseInt(totals[key] || '0', 10) || rows.length) + ')</span></div><ul class="mb-0">';
                for (var j = 0; j < rows.length; j += 1) {
                    var row = rows[j] || {};
                    html += '<li><b>' + this.escape(this.rowTitle(key, row)) + '</b>';
                    if (Array.isArray(row.narrative_tags) && row.narrative_tags.length) {
                        html += ' <span class="text-muted">· ' + this.escape(this.tagNames(row.narrative_tags)) + '</span>';
                    }
                    html += '</li>';
                }
                html += '</ul></div>';
            }
            if (!html) {
                html = '<span class="text-muted">Nessun risultato per i filtri scelti.</span>';
            }
            output.innerHTML = html;
        },

        rowTitle: function (entityType, row) {
            if (entityType === 'quest_definition') { return row.title || row.slug || ('Quest #' + (row.id || '?')); }
            if (entityType === 'narrative_event') { return row.title || ('Evento #' + (row.id || '?')); }
            if (entityType === 'system_event') { return row.title || ('Evento di sistema #' + (row.id || '?')); }
            if (entityType === 'scene') { return row.name || ('Scena #' + (row.id || '?')); }
            if (entityType === 'faction') { return row.name || row.code || ('Fazione #' + (row.id || '?')); }
            return '#' + (row.id || '?');
        },

        tagNames: function (tags) {
            var out = [];
            for (var i = 0; i < tags.length; i += 1) {
                var row = tags[i] || {};
                out.push(String(row.label || row.slug || ('#' + (row.id || '?'))));
            }
            return out.join(', ');
        },

        collectForm: function (kind) {
            var form = this.root.querySelector('[data-role="anc-' + kind + '-form"]');
            var payload = {};
            if (!form) { return payload; }
            var fields = form.querySelectorAll('[name]');
            for (var i = 0; i < fields.length; i += 1) {
                var field = fields[i];
                payload[field.name] = String(field.value || '').trim();
            }
            return payload;
        },

        fillForm: function (kind, row) {
            var form = this.root.querySelector('[data-role="anc-' + kind + '-form"]');
            if (!form || !row) { return; }
            var fields = form.querySelectorAll('[name]');
            for (var i = 0; i < fields.length; i += 1) {
                var field = fields[i];
                var value = row[field.name];
                field.value = value == null ? '' : String(value);
            }
        },

        formValue: function (kind, name) {
            var form = this.root.querySelector('[data-role="anc-' + kind + '-form"]');
            return form ? this.field(form, name) : '';
        },

        field: function (form, name) {
            var node = form.querySelector('[name="' + name + '"]');
            return node ? String(node.value || '').trim() : '';
        },

        post: function (url, payload, onSuccess) {
            var self = this;
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                this.toast('Servizio HTTP non disponibile.', 'danger');
                return;
            }
            window.Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') { onSuccess(response || {}); }
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
            if (node) { node.textContent = String(value == null ? '' : value); }
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
        document.addEventListener('DOMContentLoaded', function () { page.init(); });
    } else {
        page.init();
    }
}());
