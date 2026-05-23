const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function initializeAdvanceMapsAdmin() {
    if (globalWindow.__advanceMapsAdminLoaded === true) {
        return;
    }
    globalWindow.__advanceMapsAdminLoaded = true;

    function ensureRenderModeOptions(root) {
        if (!root || !root.querySelectorAll) { return; }
        var selects = root.querySelectorAll('select[name="render_mode"]');
        for (var i = 0; i < selects.length; i++) {
            var select = selects[i];
            if (!select) { continue; }
            var hasHybrid = false;
            for (var j = 0; j < select.options.length; j++) {
                if (String(select.options[j].value || '').toLowerCase() === 'hybrid') {
                    hasHybrid = true;
                    break;
                }
            }
            if (!hasHybrid) {
                var opt = document.createElement('option');
                opt.value = 'hybrid';
                opt.textContent = 'Ibrida';
                select.appendChild(opt);
            }
        }
    }

    function ensureModalFields(adminMaps) {
        if (!adminMaps || !adminMaps.form) { return; }
        var form = adminMaps.form;
        if (!form.querySelector('[name="version_token"]')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'version_token';
            hidden.value = '';
            form.appendChild(hidden);
        }
        if (!form.querySelector('[name="map_type"]')) {
            var mapTypeWrap = document.createElement('div');
            mapTypeWrap.className = 'col-12 col-md-6';
            mapTypeWrap.innerHTML = ''
                + '<label class="form-label">Tipo descrittivo</label>'
                + '<input type="text" class="form-control" name="map_type" maxlength="50" placeholder="globale, cittadina, quartiere...">'
                + '<div class="form-text">Campo opzionale solo descrittivo.</div>';
            form.appendChild(mapTypeWrap);
        }
        if (!form.querySelector('[name="is_visible"]')) {
            var visibleWrap = document.createElement('div');
            visibleWrap.className = 'col-12 col-md-6';
            visibleWrap.innerHTML = ''
                + '<label class="form-label">Visibilita runtime</label>'
                + '<select class="form-select" name="is_visible">'
                + '  <option value="1">Visibile ai giocatori</option>'
                + '  <option value="0">Nascosta ai giocatori</option>'
                + '</select>';
            form.appendChild(visibleWrap);
        }
    }

    function ensureHotspotsUi(adminMaps) {
        if (!adminMaps || !adminMaps.form) { return; }
        if (adminMaps.form.querySelector('[data-advance-map-hotspots]')) { return; }

        var wrap = document.createElement('div');
        wrap.className = 'col-12';
        wrap.setAttribute('data-advance-map-hotspots', '1');
        wrap.innerHTML = ''
            + '<hr class="my-3">'
            + '<h6 class="mb-2">Hotspot mappa</h6>'
            + '<p class="small text-muted mb-3">Gli hotspot possono puntare a luoghi della mappa corrente o a mappe figlie dirette.</p>'
            + '<div class="alert alert-secondary small" data-advance-hotspots-empty>'
            + 'Salva prima la mappa per gestire gli hotspot.'
            + '</div>'
            + '<div class="d-none" data-advance-hotspots-editor>'
            + '  <div class="row g-2 mb-2">'
            + '    <div class="col-12 col-md-3">'
            + '      <label class="form-label small">Target tipo</label>'
            + '      <select class="form-select form-select-sm" name="advance_hotspot_target_type">'
            + '        <option value="location">Luogo</option>'
            + '        <option value="map">Mappa figlia</option>'
            + '      </select>'
            + '    </div>'
            + '    <div class="col-12 col-md-5">'
            + '      <label class="form-label small">Target</label>'
            + '      <select class="form-select form-select-sm" name="advance_hotspot_target_id"></select>'
            + '    </div>'
            + '    <div class="col-12 col-md-4">'
            + '      <label class="form-label small">Etichetta</label>'
            + '      <input class="form-control form-control-sm" type="text" name="advance_hotspot_label" maxlength="120">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">X</label>'
            + '      <input class="form-control form-control-sm" type="number" name="advance_hotspot_x" min="0" max="100" step="0.01">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">Y</label>'
            + '      <input class="form-control form-control-sm" type="number" name="advance_hotspot_y" min="0" max="100" step="0.01">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">Larg.</label>'
            + '      <input class="form-control form-control-sm" type="number" name="advance_hotspot_width" min="1" max="100" step="0.1" value="6">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">Alt.</label>'
            + '      <input class="form-control form-control-sm" type="number" name="advance_hotspot_height" min="1" max="100" step="0.1" value="6">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">Ordine</label>'
            + '      <input class="form-control form-control-sm" type="number" name="advance_hotspot_sort_order" step="1" value="0">'
            + '    </div>'
            + '    <div class="col-6 col-md-2">'
            + '      <label class="form-label small">Visibile</label>'
            + '      <select class="form-select form-select-sm" name="advance_hotspot_is_visible">'
            + '        <option value="1">Si</option>'
            + '        <option value="0">No</option>'
            + '      </select>'
            + '    </div>'
            + '  </div>'
            + '  <input type="hidden" name="advance_hotspot_id" value="">'
            + '  <div class="d-flex gap-2 mb-3">'
            + '    <button type="button" class="btn btn-sm btn-outline-primary" data-action="advance-hotspot-save">Salva hotspot</button>'
            + '    <button type="button" class="btn btn-sm btn-outline-secondary" data-action="advance-hotspot-reset">Nuovo</button>'
            + '  </div>'
            + '  <div class="table-responsive">'
            + '    <table class="table table-sm table-striped align-middle mb-0">'
            + '      <thead>'
            + '        <tr>'
            + '          <th>ID</th>'
            + '          <th>Target</th>'
            + '          <th>Posizione</th>'
            + '          <th>Visibile</th>'
            + '          <th class="text-end">Azioni</th>'
            + '        </tr>'
            + '      </thead>'
            + '      <tbody data-advance-hotspots-table></tbody>'
            + '    </table>'
            + '  </div>'
            + '</div>';
        adminMaps.form.appendChild(wrap);
    }

    function post(url, payload) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            return Promise.reject(new Error('Servizio non disponibile.'));
        }
        return Request.http.post(url, payload || {});
    }

    function normalizeMessage(error, fallback) {
        if (typeof Request !== 'undefined' && typeof Request.getErrorMessage === 'function') {
            return Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function patchAdminMapsObject() {
        if (!globalWindow.AdminMaps || globalWindow.AdminMaps.__advanceMapsPatched === true) {
            return;
        }

        var AdminMaps = globalWindow.AdminMaps;
        AdminMaps.__advanceMapsPatched = true;
        AdminMaps.advanceHotspots = { dataset: [], options: { maps: [], locations: [] } };

        var originalInit = AdminMaps.init;
        AdminMaps.init = function () {
            var out = originalInit.apply(this, arguments);
            ensureRenderModeOptions(document);
            ensureModalFields(this);
            ensureHotspotsUi(this);
            this.bindAdvanceEvents();
            return out;
        };

        AdminMaps.bindAdvanceEvents = function () {
            var self = this;
            if (!this.root || this.root.getAttribute('data-advance-maps-bound') === '1') {
                return this;
            }
            this.root.setAttribute('data-advance-maps-bound', '1');

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'advance-hotspot-save') {
                    event.preventDefault();
                    self.saveHotspot();
                    return;
                }
                if (action === 'advance-hotspot-reset') {
                    event.preventDefault();
                    self.resetHotspotForm();
                    return;
                }
                if (action === 'advance-hotspot-edit') {
                    event.preventDefault();
                    self.editHotspot(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'advance-hotspot-delete') {
                    event.preventDefault();
                    self.deleteHotspot(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                }
            });

            var typeSelect = this.form ? this.form.querySelector('[name="advance_hotspot_target_type"]') : null;
            if (typeSelect) {
                typeSelect.addEventListener('change', function () {
                    self.populateHotspotTargetOptions();
                });
            }

            return this;
        };

        var originalFillForm = AdminMaps.fillForm;
        AdminMaps.fillForm = function (row) {
            originalFillForm.apply(this, arguments);
            var data = row || {};
            this.setField('map_type', data.map_type || '');
            this.setField('is_visible', (parseInt(data.is_visible || 1, 10) === 1) ? '1' : '0');
            this.setField('version_token', data.version_token || '');
            ensureRenderModeOptions(this.form || document);
            this.toggleHotspotsEditor(parseInt(data.id || 0, 10) || 0);
        };

        var originalCollectPayload = AdminMaps.collectPayload;
        AdminMaps.collectPayload = function () {
            var payload = originalCollectPayload.apply(this, arguments);
            payload.map_type = this.getField('map_type');
            payload.is_visible = (this.getField('is_visible') === '0') ? 0 : 1;
            payload.version_token = this.getField('version_token');
            var mode = String(payload.render_mode || '').trim().toLowerCase();
            if (mode !== 'visual' && mode !== 'hybrid') {
                payload.render_mode = 'grid';
            } else {
                payload.render_mode = mode;
            }
            return payload;
        };

        AdminMaps.openCreate = function () {
            this.fillForm({});
            this.populateParentOptions(0, 0);
            this.toggleDelete(false);
            this.modal.show();
            this.loadHotspots(0);
            return this;
        };

        AdminMaps.openEdit = function (trigger) {
            var row = this.rowFromTrigger(trigger);
            if (!row) { return this; }
            var self = this;
            post('/admin/advance-maps/maps/get', { id: row.id }).then(function (response) {
                var detail = response && response.dataset ? response.dataset : row;
                self.fillForm(detail);
                self.populateParentOptions(detail.parent_map_id || 0, detail.id || 0);
                self.toggleDelete(true);
                self.modal.show();
                self.loadHotspots(parseInt(detail.id || 0, 10) || 0);
            }).catch(function (error) {
                Toast.show({ body: normalizeMessage(error, 'Errore caricamento mappa'), type: 'error' });
            });
            return this;
        };

        AdminMaps.save = function () {
            var self = this;
            var payload = this.collectPayload();
            if (!payload.name) {
                Toast.show({ body: 'Nome mappa obbligatorio.', type: 'warning' });
                return this;
            }

            post('/admin/advance-maps/maps/save', payload).then(function () {
                Toast.show({ body: 'Mappa salvata.', type: 'success' });
                self.modal.hide();
                self.loadMapOptions(function () {
                    self.reload();
                });
            }).catch(function (error) {
                Toast.show({ body: normalizeMessage(error, 'Salvataggio non riuscito'), type: 'error' });
            });
            return this;
        };

        AdminMaps.remove = function () {
            var id = parseInt(this.getField('id') || '0', 10) || 0;
            if (id <= 0) { return this; }
            var self = this;
            Dialog('warning', {
                title: 'Conferma eliminazione',
                body: '<p>Vuoi eliminare questa mappa?</p>',
                buttons: [
                    { text: 'Annulla', class: 'btn btn-secondary', dismiss: true },
                    {
                        text: 'Elimina',
                        class: 'btn btn-danger',
                        click: function () {
                            post('/admin/advance-maps/maps/delete', { id: id }).then(function () {
                                Toast.show({ body: 'Mappa eliminata.', type: 'success' });
                                self.modal.hide();
                                self.loadMapOptions(function () {
                                    self.reload();
                                });
                            }).catch(function (error) {
                                Toast.show({ body: normalizeMessage(error, 'Eliminazione non riuscita'), type: 'error' });
                            });
                        }
                    }
                ]
            }).show();

            return this;
        };

        AdminMaps.loadMapOptions = function (callback) {
            var self = this;
            post('/admin/advance-maps/maps/list', { results: 500, page: 1, orderBy: 'name|ASC' }).then(function (response) {
                self.mapOptions = response && Array.isArray(response.dataset) ? response.dataset.slice() : [];
                if (typeof callback === 'function') {
                    callback(self.mapOptions);
                }
            }).catch(function () {
                self.mapOptions = [];
                if (typeof callback === 'function') {
                    callback(self.mapOptions);
                }
            });
            return this;
        };

        AdminMaps.toggleHotspotsEditor = function (mapId) {
            var root = this.form ? this.form.querySelector('[data-advance-map-hotspots]') : null;
            if (!root) { return this; }
            var empty = root.querySelector('[data-advance-hotspots-empty]');
            var editor = root.querySelector('[data-advance-hotspots-editor]');
            var enabled = (parseInt(mapId || 0, 10) || 0) > 0;
            if (empty) { empty.classList.toggle('d-none', enabled); }
            if (editor) { editor.classList.toggle('d-none', !enabled); }
            return this;
        };

        AdminMaps.loadHotspots = function (mapId) {
            this.toggleHotspotsEditor(mapId);
            if ((parseInt(mapId || 0, 10) || 0) <= 0) {
                this.advanceHotspots = { dataset: [], options: { maps: [], locations: [] } };
                this.renderHotspotsTable();
                this.populateHotspotTargetOptions();
                return this;
            }

            var self = this;
            post('/admin/advance-maps/hotspots/list', { map_id: mapId }).then(function (response) {
                self.advanceHotspots = {
                    dataset: response && Array.isArray(response.dataset) ? response.dataset : [],
                    options: response && response.options ? response.options : { maps: [], locations: [] }
                };
                self.resetHotspotForm();
                self.renderHotspotsTable();
                self.populateHotspotTargetOptions();
            }).catch(function (error) {
                Toast.show({ body: normalizeMessage(error, 'Errore caricamento hotspot'), type: 'error' });
            });
            return this;
        };

        AdminMaps.populateHotspotTargetOptions = function () {
            var select = this.form ? this.form.querySelector('[name="advance_hotspot_target_id"]') : null;
            var type = this.form ? String((this.form.querySelector('[name="advance_hotspot_target_type"]') || {}).value || 'location').trim() : 'location';
            if (!select) { return this; }

            var opts = (this.advanceHotspots && this.advanceHotspots.options) ? this.advanceHotspots.options : { maps: [], locations: [] };
            var list = (type === 'map') ? (Array.isArray(opts.maps) ? opts.maps : []) : (Array.isArray(opts.locations) ? opts.locations : []);
            var html = '<option value="">Seleziona...</option>';
            for (var i = 0; i < list.length; i++) {
                var item = list[i] || {};
                var id = parseInt(item.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + escapeHtml(item.name || ('#' + id)) + '</option>';
            }
            select.innerHTML = html;
            return this;
        };

        AdminMaps.renderHotspotsTable = function () {
            var body = this.form ? this.form.querySelector('[data-advance-hotspots-table]') : null;
            if (!body) { return this; }
            var rows = (this.advanceHotspots && Array.isArray(this.advanceHotspots.dataset)) ? this.advanceHotspots.dataset : [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted small">Nessun hotspot configurato.</td></tr>';
                return this;
            }

            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i] || {};
                var id = parseInt(row.id || 0, 10) || 0;
                var type = String(row.target_type || 'location');
                var targetId = parseInt(row.target_id || 0, 10) || 0;
                var label = String(row.label || '').trim();
                var targetLabel = (type === 'map' ? 'Mappa' : 'Luogo') + ' #' + targetId + (label ? (' - ' + label) : '');
                var posX = (row.x === null || typeof row.x === 'undefined' || row.x === '') ? '-' : String(row.x);
                var posY = (row.y === null || typeof row.y === 'undefined' || row.y === '') ? '-' : String(row.y);
                var pos = 'X: ' + escapeHtml(posX) + ' / Y: ' + escapeHtml(posY);
                html += '<tr>'
                    + '<td>' + id + '</td>'
                    + '<td>' + escapeHtml(targetLabel) + '</td>'
                    + '<td class="small">' + pos + '</td>'
                    + '<td>' + ((parseInt(row.is_visible || 1, 10) === 1) ? 'Si' : 'No') + '</td>'
                    + '<td class="text-end">'
                    + '  <button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="advance-hotspot-edit" data-id="' + id + '">Modifica</button>'
                    + '  <button type="button" class="btn btn-sm btn-outline-danger" data-action="advance-hotspot-delete" data-id="' + id + '">Elimina</button>'
                    + '</td>'
                    + '</tr>';
            }
            body.innerHTML = html;
            return this;
        };

        AdminMaps.resetHotspotForm = function () {
            this.setField('advance_hotspot_id', '');
            this.setField('advance_hotspot_label', '');
            this.setField('advance_hotspot_x', '');
            this.setField('advance_hotspot_y', '');
            this.setField('advance_hotspot_width', '6');
            this.setField('advance_hotspot_height', '6');
            this.setField('advance_hotspot_sort_order', '0');
            this.setField('advance_hotspot_is_visible', '1');
            this.setField('advance_hotspot_target_type', 'location');
            this.populateHotspotTargetOptions();
            this.setField('advance_hotspot_target_id', '');
            return this;
        };

        AdminMaps.editHotspot = function (id) {
            var rows = (this.advanceHotspots && Array.isArray(this.advanceHotspots.dataset)) ? this.advanceHotspots.dataset : [];
            var row = null;
            for (var i = 0; i < rows.length; i++) {
                if ((parseInt(rows[i].id || 0, 10) || 0) === id) {
                    row = rows[i];
                    break;
                }
            }
            if (!row) { return this; }
            this.setField('advance_hotspot_id', row.id || '');
            this.setField('advance_hotspot_target_type', row.target_type || 'location');
            this.populateHotspotTargetOptions();
            this.setField('advance_hotspot_target_id', row.target_id || '');
            this.setField('advance_hotspot_label', row.label || '');
            this.setField('advance_hotspot_x', (row.x === null || typeof row.x === 'undefined') ? '' : row.x);
            this.setField('advance_hotspot_y', (row.y === null || typeof row.y === 'undefined') ? '' : row.y);
            this.setField('advance_hotspot_width', (row.width === null || typeof row.width === 'undefined') ? 6 : row.width);
            this.setField('advance_hotspot_height', (row.height === null || typeof row.height === 'undefined') ? 6 : row.height);
            this.setField('advance_hotspot_sort_order', (row.sort_order === null || typeof row.sort_order === 'undefined') ? 0 : row.sort_order);
            this.setField('advance_hotspot_is_visible', (parseInt(row.is_visible || 1, 10) === 1) ? '1' : '0');
            return this;
        };

        AdminMaps.saveHotspot = function () {
            var mapId = parseInt(this.getField('id') || '0', 10) || 0;
            if (mapId <= 0) {
                Toast.show({ body: 'Salva prima la mappa.', type: 'warning' });
                return this;
            }
            var payload = {
                id: parseInt(this.getField('advance_hotspot_id') || '0', 10) || 0,
                map_id: mapId,
                target_type: this.getField('advance_hotspot_target_type'),
                target_id: parseInt(this.getField('advance_hotspot_target_id') || '0', 10) || 0,
                label: this.getField('advance_hotspot_label'),
                x: this.getField('advance_hotspot_x'),
                y: this.getField('advance_hotspot_y'),
                width: this.getField('advance_hotspot_width'),
                height: this.getField('advance_hotspot_height'),
                sort_order: parseInt(this.getField('advance_hotspot_sort_order') || '0', 10) || 0,
                is_visible: (this.getField('advance_hotspot_is_visible') === '0') ? 0 : 1
            };
            if (payload.target_id <= 0) {
                Toast.show({ body: 'Seleziona un target valido.', type: 'warning' });
                return this;
            }

            var self = this;
            post('/admin/advance-maps/hotspots/save', payload).then(function () {
                Toast.show({ body: 'Hotspot salvato.', type: 'success' });
                self.loadHotspots(mapId);
            }).catch(function (error) {
                Toast.show({ body: normalizeMessage(error, 'Salvataggio hotspot non riuscito'), type: 'error' });
            });
            return this;
        };

        AdminMaps.deleteHotspot = function (id) {
            var mapId = parseInt(this.getField('id') || '0', 10) || 0;
            if (id <= 0 || mapId <= 0) { return this; }
            var self = this;
            post('/admin/advance-maps/hotspots/delete', { id: id, map_id: mapId }).then(function () {
                Toast.show({ body: 'Hotspot eliminato.', type: 'success' });
                self.loadHotspots(mapId);
            }).catch(function (error) {
                Toast.show({ body: normalizeMessage(error, 'Eliminazione hotspot non riuscita'), type: 'error' });
            });
            return this;
        };

        if (globalWindow.AdminRegistry) {
            globalWindow.AdminRegistry.registerModule('admin.advance-maps', 'AdminAdvanceMapsModuleFactory');
            globalWindow.AdminRegistry.extendPage('maps', ['admin.advance-maps'], { after: 'admin.maps' });
        }
    }

    function createAdminAdvanceMapsModule() {
        return {
            mount: function () {
                patchAdminMapsObject();
                if (globalWindow.AdminMaps && typeof globalWindow.AdminMaps.init === 'function') {
                    globalWindow.AdminMaps.init();
                }
            },
            unmount: function () {}
        };
    }

    globalWindow.AdminAdvanceMapsModuleFactory = createAdminAdvanceMapsModule;
    patchAdminMapsObject();
}

initializeAdvanceMapsAdmin();
