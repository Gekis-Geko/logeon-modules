const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createLogeonAdvancedItemsGameModule() {
    return {
        ctx: null,
        mounted: false,
        refreshBound: false,
        bagDetailBound: false,
        bagAssignments: null,

        mount: function (ctx) {
            this.ctx = ctx || null;
            if (!this.refreshBound) {
                this.bindEvents();
                this.refreshBound = true;
            }
            if (!this.bagDetailBound) {
                this.bindBagDetailEvents();
                this.bagDetailBound = true;
            }
            this.mounted = true;
            if (document.getElementById('advanced-items-page-list')) {
                this.refreshUi();
            }
            return this;
        },

        unmount: function () {
            this.mounted = false;
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

        list: function (payload) {
            return this.request('/advanced-items/my', 'lfaiMyList', payload || {});
        },

        useItem: function (payload) {
            return this.request('/advanced-items/use', 'lfaiUseItem', payload || {});
        },

        restoreItem: function (payload) {
            return this.request('/advanced-items/restore', 'lfaiRestoreItem', payload || {});
        },

        bindEvents: function () {
            var self = this;

            document.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'lfai-page-reload') {
                    event.preventDefault();
                    self.refreshUi();
                    return;
                }
                if (action === 'lfai-item-use') {
                    event.preventDefault();
                    self.handleItemAction(trigger, false);
                    return;
                }
                if (action === 'lfai-item-restore') {
                    event.preventDefault();
                    self.handleItemAction(trigger, true);
                }
            });
        },

        handleItemAction: function (trigger, restore) {
            var assignmentId = parseInt(trigger.getAttribute('data-assignment-id') || '0', 10) || 0;
            if (assignmentId <= 0) {
                return;
            }

            var self = this;
            var request = restore ? this.restoreItem : this.useItem;
            request.call(this, { assignment_id: assignmentId }).then(function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                self.bagAssignments = null;
                self.notify(String(dataset.message || (restore ? 'Oggetto ripristinato.' : 'Oggetto usato.')), 'success');
                self.refreshUi();
            }).catch(function (error) {
                self.notify((error && error.message) ? error.message : 'Operazione non riuscita.', 'warning');
            });
        },

        bindBagDetailEvents: function () {
            var self = this;
            document.addEventListener('game:bag-detail:rendered', function (event) {
                self.onBagDetailRendered(event);
            });
        },

        onBagDetailRendered: function (event) {
            var slot = document.querySelector('[data-role="advanced-items-bag-detail"]');
            if (!slot) {
                return;
            }

            var detail = event && event.detail ? event.detail : {};
            var item = detail && detail.item ? detail.item : null;
            if (!item) {
                slot.classList.add('d-none');
                slot.innerHTML = '';
                return;
            }

            var linkedItemId = parseInt(item.item_id || item.id || '0', 10) || 0;
            if (linkedItemId <= 0) {
                slot.classList.add('d-none');
                slot.innerHTML = '';
                return;
            }

            var self = this;
            this.ensureBagAssignments().then(function (rows) {
                self.renderBagAdvancedDetail(slot, linkedItemId, rows);
            }).catch(function () {
                slot.classList.add('d-none');
                slot.innerHTML = '';
            });
        },

        ensureBagAssignments: function () {
            if (Array.isArray(this.bagAssignments)) {
                return Promise.resolve(this.bagAssignments);
            }

            var self = this;
            return this.list({}).then(function (response) {
                self.bagAssignments = response && Array.isArray(response.dataset) ? response.dataset : [];
                return self.bagAssignments;
            });
        },

        renderBagAdvancedDetail: function (slot, linkedItemId, rows) {
            var dataset = Array.isArray(rows) ? rows : [];
            var matches = [];

            for (var i = 0; i < dataset.length; i += 1) {
                var row = dataset[i] || {};
                var rowLinkedItemId = parseInt(row.linked_item_id || '0', 10) || 0;
                if (rowLinkedItemId === linkedItemId) {
                    matches.push(row);
                }
            }

            if (!matches.length) {
                slot.classList.add('d-none');
                slot.innerHTML = '';
                return;
            }

            matches.sort(function (left, right) {
                return (parseInt(right.is_equipped || '0', 10) || 0) - (parseInt(left.is_equipped || '0', 10) || 0);
            });

            var selected = matches[0] || {};
            var statusTone = String(selected.resource_tone || 'secondary').trim() || 'secondary';
            var statusText = String(selected.resource_status || 'Stato avanzato').trim() || 'Stato avanzato';
            var resourceLabel = String(selected.resource_label || '').trim();
            var rarity = String(selected.rarity_label || '').trim();
            var mode = String(selected.resource_mode_label || '').trim();

            var badges = [];
            badges.push('<span class="badge text-bg-' + escapeHtml(statusTone) + '">' + escapeHtml(statusText) + '</span>');
            if (mode !== '') {
                badges.push('<span class="badge text-bg-light">' + escapeHtml(mode) + '</span>');
            }
            if (rarity !== '') {
                badges.push('<span class="badge text-bg-warning">' + escapeHtml(rarity) + '</span>');
            }
            if (parseInt(selected.is_equipped || '0', 10) === 1) {
                badges.push('<span class="badge text-bg-primary">Equipaggiato</span>');
            }

            slot.innerHTML = ''
                + '<div class="card border-0 mt-2">'
                + '  <div class="card-body p-2">'
                + '    <div class="small text-uppercase text-muted fw-semibold mb-1">Modulo oggetti avanzati</div>'
                + '    <div class="d-flex flex-wrap gap-1 mb-2">' + badges.join('') + '</div>'
                + (resourceLabel !== '' ? '<div class="small text-muted">' + escapeHtml(resourceLabel) + '</div>' : '')
                + '  </div>'
                + '</div>';
            slot.classList.remove('d-none');
        },

        refreshUi: function () {
            var self = this;
            this.list({}).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderPage(rows);
            }).catch(function () {
                self.renderPage([]);
            });
        },

        renderPage: function (rows) {
            var root = document.getElementById('advanced-items-page-list');
            if (!root) {
                return;
            }

            if (!rows.length) {
                root.innerHTML = '<div class="alert alert-secondary mb-0">Nessun oggetto avanzato assegnato al personaggio corrente.</div>';
                return;
            }

            var html = ['<div class="row g-3">'];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var assignmentId = parseInt(row.id || '0', 10) || 0;
                var tone = String(row.resource_tone || 'secondary').trim();
                var rarity = String(row.rarity_label || '').trim();
                var linkedItem = String(row.linked_item_label || '').trim();
                var description = String(row.profile_description || '').trim();
                var actions = [];
                if (parseInt(row.can_use || '0', 10) === 1) {
                    actions.push('<button type="button" class="btn btn-sm btn-outline-primary" data-action="lfai-item-use" data-assignment-id="' + assignmentId + '">Usa</button>');
                }
                if (parseInt(row.can_restore || '0', 10) === 1) {
                    actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-action="lfai-item-restore" data-assignment-id="' + assignmentId + '">Ripristina</button>');
                }

                html.push(
                    '<div class="col-12 col-lg-6">'
                    + '<div class="card h-100">'
                    + '<div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">'
                    + '<div>'
                    + '<h6 class="mb-1">' + escapeHtml(row.display_name || 'Oggetto avanzato') + '</h6>'
                    + '<div class="small text-muted">' + escapeHtml(row.profile_name || '') + '</div>'
                    + '</div>'
                    + '<div class="d-flex flex-wrap gap-1 justify-content-end">'
                    + '<span class="badge text-bg-' + escapeHtml(tone) + '">' + escapeHtml(row.resource_status || 'Stato') + '</span>'
                    + (parseInt(row.is_equipped || '0', 10) === 1 ? '<span class="badge text-bg-primary">Equipaggiato</span>' : '')
                    + '</div>'
                    + '</div>'
                    + (description !== '' ? '<p class="small text-muted mb-2">' + escapeHtml(description) + '</p>' : '<p class="small text-muted mb-2">Nessuna descrizione disponibile.</p>')
                    + '<div class="d-flex flex-wrap gap-2 mb-2">'
                    + '<span class="badge text-bg-light">' + escapeHtml(row.resource_mode_label || 'Nessuna') + '</span>'
                    + '<span class="badge text-bg-light">' + escapeHtml(row.category || 'gear') + '</span>'
                    + (rarity !== '' ? '<span class="badge text-bg-warning">' + escapeHtml(rarity) + '</span>' : '')
                    + (linkedItem !== '' ? '<span class="badge text-bg-info">' + escapeHtml(linkedItem) + '</span>' : '')
                    + '</div>'
                    + '<div class="small fw-semibold mb-3">' + escapeHtml(row.resource_label || 'Nessuna risorsa') + '</div>'
                    + '<div class="d-flex flex-wrap gap-2">' + actions.join('') + '</div>'
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

globalWindow.LogeonAdvancedItemsGameModuleFactory = createLogeonAdvancedItemsGameModule;

if (globalWindow.GameRegistry) {
    globalWindow.GameRegistry.registerModule('game.advanced-items', 'LogeonAdvancedItemsGameModuleFactory');
    globalWindow.GameRegistry.extendPage('advanced-items', ['game.advanced-items']);
    globalWindow.GameRegistry.extendPage('bag', ['game.advanced-items']);
}

export { createLogeonAdvancedItemsGameModule as LogeonAdvancedItemsGameModuleFactory };
export default createLogeonAdvancedItemsGameModule;
