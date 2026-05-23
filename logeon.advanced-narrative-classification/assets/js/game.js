(function () {
    'use strict';

    var page = {
        root: null,
        state: {
            summary: {},
            taxonomies_tree: [],
            featured_tags: [],
            selectedTagIds: [],
            selectedNodeIds: []
        },

        init: function () {
            this.root = document.querySelector('[data-module-page="advanced-narrative-classification"]');
            if (!this.root) { return; }
            this.bind();
            this.loadBootstrap();
        },

        bind: function () {
            var self = this;
            var form = this.root.querySelector('[data-role="anc-game-discovery-form"]');
            if (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.runDiscovery();
                });
            }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'anc-game-pick-featured-tag') { self.toggleTag(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-game-pick-node') { self.toggleNode(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-game-remove-tag') { self.removeTag(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-game-remove-node') { self.removeNode(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'anc-game-open-tag-context') { self.loadTagContext(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
            });
        },

        loadBootstrap: function () {
            var self = this;
            this.post('/advanced-narrative-classification/bootstrap', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.state.summary = dataset.summary || {};
                self.state.taxonomies_tree = Array.isArray(dataset.taxonomies_tree) ? dataset.taxonomies_tree : [];
                self.state.featured_tags = Array.isArray(dataset.featured_tags) ? dataset.featured_tags : [];
                self.renderBootstrap();
                self.runDiscovery();
            });
        },

        renderBootstrap: function () {
            var summary = this.state.summary || {};
            this.setText('[data-role="anc-game-summary-taxonomies"]', summary.taxonomies || 0);
            this.setText('[data-role="anc-game-summary-nodes"]', summary.nodes || 0);
            this.setText('[data-role="anc-game-summary-aliases"]', summary.aliases || 0);
            this.setText('[data-role="anc-game-summary-links"]', summary.links || 0);
            this.renderTaxonomyTree();
            this.renderFeaturedTags();
            this.renderActiveFilters();
        },

        renderTaxonomyTree: function () {
            var box = this.root.querySelector('[data-role="anc-game-taxonomy-tree"]');
            if (!box) { return; }
            var rows = Array.isArray(this.state.taxonomies_tree) ? this.state.taxonomies_tree : [];
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">Nessuna tassonomia disponibile.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var taxonomy = rows[i] || {};
                html += '<div class="mb-3"><div class="fw-semibold mb-2">' + this.escape(taxonomy.name || taxonomy.slug || '-') + '</div>';
                html += this.renderNodesList(Array.isArray(taxonomy.nodes) ? taxonomy.nodes : []);
                html += '</div>';
            }
            box.innerHTML = html;
        },

        renderNodesList: function (nodes) {
            if (!nodes.length) { return '<div class="text-muted small">Nessun nodo.</div>'; }
            var html = '<ul class="list-unstyled mb-0">';
            for (var i = 0; i < nodes.length; i += 1) {
                var node = nodes[i] || {};
                var nodeId = parseInt(node.id || '0', 10) || 0;
                var active = this.state.selectedNodeIds.indexOf(nodeId) >= 0;
                html += '<li class="mb-2">'
                    + '<button type="button" class="btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline-secondary') + '" data-action="anc-game-pick-node" data-id="' + nodeId + '">'
                    + this.escape(node.label || node.slug || ('Nodo #' + nodeId))
                    + '</button>';
                if (Array.isArray(node.linked_tags) && node.linked_tags.length) {
                    html += '<div class="small text-muted mt-1">' + this.escape(this.tagsLabel(node.linked_tags)) + '</div>';
                }
                if (Array.isArray(node.children) && node.children.length) {
                    html += '<div class="ms-3 mt-2">' + this.renderNodesList(node.children) + '</div>';
                }
                html += '</li>';
            }
            html += '</ul>';
            return html;
        },

        renderFeaturedTags: function () {
            var box = this.root.querySelector('[data-role="anc-game-featured-tags"]');
            if (!box) { return; }
            var rows = Array.isArray(this.state.featured_tags) ? this.state.featured_tags : [];
            if (!rows.length) {
                box.innerHTML = '<span class="text-muted small">Nessun tag in evidenza.</span>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                var active = this.state.selectedTagIds.indexOf(id) >= 0;
                html += '<button type="button" class="btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline-primary') + '" data-action="anc-game-pick-featured-tag" data-id="' + id + '">'
                    + this.escape(row.label || row.slug || ('Tag #' + id))
                    + '</button>';
            }
            box.innerHTML = html;
        },

        renderActiveFilters: function () {
            var box = this.root.querySelector('[data-role="anc-game-active-filters"]');
            if (!box) { return; }
            var html = '';
            for (var i = 0; i < this.state.selectedTagIds.length; i += 1) {
                var tag = this.findFeaturedTag(this.state.selectedTagIds[i]);
                html += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="anc-game-remove-tag" data-id="' + this.state.selectedTagIds[i] + '">'
                    + this.escape((tag && (tag.label || tag.slug)) || ('Tag #' + this.state.selectedTagIds[i])) + ' ×</button>';
            }
            for (var j = 0; j < this.state.selectedNodeIds.length; j += 1) {
                var node = this.findNode(this.state.selectedNodeIds[j]);
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="anc-game-remove-node" data-id="' + this.state.selectedNodeIds[j] + '">'
                    + this.escape((node && (node.label || node.slug)) || ('Nodo #' + this.state.selectedNodeIds[j])) + ' ×</button>';
            }
            box.innerHTML = html || '<span class="text-muted small">Nessun filtro attivo.</span>';
        },

        toggleTag: function (id) {
            if (id <= 0) { return; }
            var index = this.state.selectedTagIds.indexOf(id);
            if (index >= 0) { this.state.selectedTagIds.splice(index, 1); }
            else { this.state.selectedTagIds.push(id); }
            this.renderFeaturedTags();
            this.renderActiveFilters();
            this.runDiscovery();
        },

        toggleNode: function (id) {
            if (id <= 0) { return; }
            var index = this.state.selectedNodeIds.indexOf(id);
            if (index >= 0) { this.state.selectedNodeIds.splice(index, 1); }
            else { this.state.selectedNodeIds.push(id); }
            this.renderTaxonomyTree();
            this.renderActiveFilters();
            this.runDiscovery();
        },

        removeTag: function (id) {
            var index = this.state.selectedTagIds.indexOf(id);
            if (index >= 0) { this.state.selectedTagIds.splice(index, 1); }
            this.renderFeaturedTags();
            this.renderActiveFilters();
            this.runDiscovery();
        },

        removeNode: function (id) {
            var index = this.state.selectedNodeIds.indexOf(id);
            if (index >= 0) { this.state.selectedNodeIds.splice(index, 1); }
            this.renderTaxonomyTree();
            this.renderActiveFilters();
            this.runDiscovery();
        },

        findFeaturedTag: function (id) {
            var rows = this.state.featured_tags || [];
            for (var i = 0; i < rows.length; i += 1) {
                if ((parseInt(rows[i].id || '0', 10) || 0) === id) { return rows[i]; }
            }
            return null;
        },

        findNode: function (id) {
            var queue = [];
            var taxonomies = this.state.taxonomies_tree || [];
            for (var i = 0; i < taxonomies.length; i += 1) {
                queue = queue.concat(Array.isArray(taxonomies[i].nodes) ? taxonomies[i].nodes : []);
            }
            while (queue.length) {
                var row = queue.shift();
                if ((parseInt(row.id || '0', 10) || 0) === id) { return row; }
                if (Array.isArray(row.children) && row.children.length) {
                    queue = queue.concat(row.children);
                }
            }
            return null;
        },

        runDiscovery: function () {
            var form = this.root.querySelector('[data-role="anc-game-discovery-form"]');
            if (!form) { return; }
            var payload = {
                query: this.field(form, 'query'),
                entity_type: this.field(form, 'entity_type'),
                match_mode: this.field(form, 'match_mode'),
                tag_ids: this.state.selectedTagIds.slice(),
                node_ids: this.state.selectedNodeIds.slice(),
                limit: 8
            };
            var self = this;
            this.post('/advanced-narrative-classification/discover', payload, function (response) {
                self.renderDiscovery(response && response.dataset ? response.dataset : {});
            });
        },

        renderDiscovery: function (dataset) {
            var box = this.root.querySelector('[data-role="anc-game-results"]');
            if (!box) { return; }
            var results = dataset.results || {};
            var totals = dataset.totals || {};
            var matchedTags = Array.isArray(dataset.matched_tags) ? dataset.matched_tags : [];
            var types = [
                ['quest_definition', 'Quest'],
                ['narrative_event', 'Eventi narrativi'],
                ['system_event', 'Eventi di sistema'],
                ['scene', 'Scene'],
                ['faction', 'Fazioni']
            ];
            var html = '';

            if (matchedTags.length) {
                html += '<div class="mb-3"><div class="small text-muted mb-1">Tag risolti</div><div class="d-flex flex-wrap gap-2">';
                for (var t = 0; t < matchedTags.length; t += 1) {
                    var tag = matchedTags[t] || {};
                    html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="anc-game-open-tag-context" data-id="' + (parseInt(tag.id || '0', 10) || 0) + '">'
                        + this.escape(tag.label || tag.slug || '-') + '</button>';
                }
                html += '</div></div>';
            }

            for (var i = 0; i < types.length; i += 1) {
                var key = types[i][0];
                var label = types[i][1];
                var rows = Array.isArray(results[key]) ? results[key] : [];
                if (!rows.length) { continue; }
                html += '<div class="mb-4"><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">' + label + '</h6><span class="small text-muted">' + (parseInt(totals[key] || '0', 10) || rows.length) + '</span></div>';
                html += '<div class="list-group">';
                for (var j = 0; j < rows.length; j += 1) {
                    html += this.renderResultRow(key, rows[j] || {});
                }
                html += '</div></div>';
            }

            if (!html) {
                html = '<div class="text-muted">Nessun contenuto trovato per i filtri selezionati.</div>';
            }
            box.innerHTML = html;
        },

        renderResultRow: function (entityType, row) {
            var title = this.rowTitle(entityType, row);
            var body = '';
            if (entityType === 'quest_definition' && row.slug) { body = 'Slug: ' + row.slug; }
            if (entityType === 'narrative_event' && row.event_type) { body = 'Tipo: ' + row.event_type; }
            if (entityType === 'system_event' && row.type) { body = 'Tipo: ' + row.type + (row.status ? ' · Stato: ' + row.status : ''); }
            if (entityType === 'scene' && row.map_name) { body = 'Mappa: ' + row.map_name; }
            if (entityType === 'faction' && row.type) { body = 'Tipo: ' + row.type + (row.scope ? ' · Ambito: ' + row.scope : ''); }

            var tagsHtml = '';
            var tags = Array.isArray(row.narrative_tags) ? row.narrative_tags : [];
            if (tags.length) {
                tagsHtml += '<div class="d-flex flex-wrap gap-1 mt-2">';
                for (var i = 0; i < tags.length; i += 1) {
                    var tag = tags[i] || {};
                    tagsHtml += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="anc-game-open-tag-context" data-id="' + (parseInt(tag.id || '0', 10) || 0) + '">'
                        + this.escape(tag.label || tag.slug || '-') + '</button>';
                }
                tagsHtml += '</div>';
            }

            var nodesHtml = '';
            var nodes = Array.isArray(row.classification_nodes) ? row.classification_nodes : [];
            if (nodes.length) {
                nodesHtml += '<div class="small text-muted mt-2">';
                for (var j = 0; j < nodes.length; j += 1) {
                    var node = nodes[j] || {};
                    nodesHtml += '<span class="me-2">' + this.escape((node.taxonomy_name || '') + ': ' + (node.node_label || '')) + '</span>';
                }
                nodesHtml += '</div>';
            }

            return '<div class="list-group-item">'
                + '<div class="fw-semibold">' + this.escape(title) + '</div>'
                + (body ? '<div class="small text-muted">' + this.escape(body) + '</div>' : '')
                + tagsHtml
                + nodesHtml
                + '</div>';
        },

        loadTagContext: function (tagId) {
            if (tagId <= 0) { return; }
            var self = this;
            this.post('/advanced-narrative-classification/tag/context', { tag_id: tagId }, function (response) {
                self.renderTagContext(response && response.dataset ? response.dataset : {});
            });
        },

        renderTagContext: function (dataset) {
            var box = this.root.querySelector('[data-role="anc-game-tag-context"]');
            if (!box) { return; }
            var tag = dataset.tag || {};
            if (!tag.id) {
                box.classList.add('d-none');
                box.innerHTML = '';
                return;
            }
            var aliases = Array.isArray(dataset.aliases) ? dataset.aliases : [];
            var nodes = Array.isArray(dataset.taxonomy_nodes) ? dataset.taxonomy_nodes : [];
            var usage = dataset.usage || {};
            var html = '<div class="fw-semibold mb-1">' + this.escape(tag.label || tag.slug || '-') + '</div>';
            if (tag.description) { html += '<div class="small text-muted mb-2">' + this.escape(tag.description) + '</div>'; }
            if (aliases.length) { html += '<div class="small mb-1"><b>Alias:</b> ' + this.escape(this.aliasesLabel(aliases)) + '</div>'; }
            if (nodes.length) { html += '<div class="small mb-1"><b>Tassonomie:</b> ' + this.escape(this.nodesLabel(nodes)) + '</div>'; }
            html += '<div class="small"><b>Uso:</b> quest ' + (usage.quest_definition || 0)
                + ' · eventi ' + (usage.narrative_event || 0)
                + ' · sistema ' + (usage.system_event || 0)
                + ' · scene ' + (usage.scene || 0)
                + ' · fazioni ' + (usage.faction || 0)
                + '</div>';
            box.innerHTML = html;
            box.classList.remove('d-none');
        },

        rowTitle: function (entityType, row) {
            if (entityType === 'quest_definition') { return row.title || row.slug || ('Quest #' + (row.id || '?')); }
            if (entityType === 'narrative_event') { return row.title || ('Evento #' + (row.id || '?')); }
            if (entityType === 'system_event') { return row.title || ('Evento di sistema #' + (row.id || '?')); }
            if (entityType === 'scene') { return row.name || ('Scena #' + (row.id || '?')); }
            if (entityType === 'faction') { return row.name || row.code || ('Fazione #' + (row.id || '?')); }
            return '#' + (row.id || '?');
        },

        tagsLabel: function (tags) {
            var out = [];
            for (var i = 0; i < tags.length; i += 1) {
                out.push(String((tags[i] || {}).label || (tags[i] || {}).slug || ''));
            }
            return out.join(', ');
        },

        aliasesLabel: function (aliases) {
            var out = [];
            for (var i = 0; i < aliases.length; i += 1) {
                out.push(String((aliases[i] || {}).alias || ''));
            }
            return out.join(', ');
        },

        nodesLabel: function (nodes) {
            var out = [];
            for (var i = 0; i < nodes.length; i += 1) {
                var row = nodes[i] || {};
                out.push(String((row.taxonomy_name || '') + ': ' + (row.node_label || '')));
            }
            return out.join(', ');
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

        field: function (form, name) {
            var node = form.querySelector('[name="' + name + '"]');
            return node ? String(node.value || '').trim() : '';
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
