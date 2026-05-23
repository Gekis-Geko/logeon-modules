(function (window) {
    'use strict';

    var AdminArchetypeAttributes = {
        initialized: false,
        root: null,
        grid: null,
        modalNode: null,
        modal: null,
        modalForm: null,
        rows: [],
        rowsById: {},
        meta: null,
        editingRow: null,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="archetype-attributes"]');
            if (!this.root || !document.getElementById('grid-admin-archetype-attributes')) {
                return this;
            }

            this.modalNode = this.root.querySelector('#admin-archetype-attributes-modal');
            this.modalForm = this.root.querySelector('#admin-archetype-attributes-form');
            if (!this.modalNode || !this.modalForm) {
                return this;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap && typeof bootstrap.Modal === 'function') {
                this.modal = new bootstrap.Modal(this.modalNode);
            }

            this.bind();
            this.initGrid();
            this.loadMeta();
            this.reload();

            this.initialized = true;
            return this;
        },

        bind: function () {
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

                if (action === 'admin-archetype-attributes-reload') {
                    self.loadMeta();
                    self.reload();
                    return;
                }
                if (action === 'admin-archetype-attributes-create') {
                    self.openCreate();
                    return;
                }
                if (action === 'admin-archetype-attributes-save') {
                    self.save();
                    return;
                }
                if (action === 'admin-archetype-attributes-edit') {
                    self.openEdit(self.rowFromTrigger(trigger));
                    return;
                }
                if (action === 'admin-archetype-attributes-delete') {
                    self.remove(self.rowFromTrigger(trigger));
                    return;
                }
                if (action === 'admin-archetype-attributes-delete-current') {
                    self.remove(self.editingRow);
                }
            });

            var ruleType = this.modalForm.querySelector('[name="rule_type"]');
            if (ruleType) {
                ruleType.addEventListener('change', function () {
                    self.syncValueField();
                });
            }
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-archetype-attributes', {
                name: 'AdminArchetypeAttributes',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/archetype-attributes/rules/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
                onGetDataSuccess: function (response) {
                    self.store(Array.isArray(response && response.dataset) ? response.dataset : []);
                },
                onGetDataError: function () {
                    self.store([]);
                },
                columns: [
                    {
                        label: 'Archetipo',
                        field: 'archetype_name',
                        sortable: true,
                        style: { textAlign: 'left', width: '18%' },
                        format: function (row) {
                            return '<div class="fw-semibold">' + self.e(row.archetype_name || '-') + '</div>'
                                + '<div class="small text-muted">ID #' + self.e(String(row.archetype_id || '0')) + '</div>';
                        }
                    },
                    {
                        label: 'Attributo',
                        field: 'attribute_name',
                        sortable: true,
                        style: { textAlign: 'left', width: '20%' },
                        format: function (row) {
                            return '<div class="fw-semibold">' + self.e(row.attribute_name || '-') + '</div>'
                                + '<div class="small text-muted font-monospace">' + self.e(row.attribute_slug || '') + '</div>';
                        }
                    },
                    {
                        label: 'Regola',
                        field: 'rule_type',
                        sortable: true,
                        style: { textAlign: 'left', width: '14%' },
                        format: function (row) {
                            return self.ruleTypeBadge(row.rule_type, row.rule_type_label);
                        }
                    },
                    {
                        label: 'Valore',
                        field: 'value',
                        sortable: false,
                        style: { textAlign: 'left', width: '12%' },
                        format: function (row) {
                            var value = String(row.value || '').trim();
                            return value !== ''
                                ? '<span class="font-monospace">' + self.e(value) + '</span>'
                                : '<span class="text-muted">-</span>';
                        }
                    },
                    {
                        label: 'Vincolo',
                        field: 'is_enforced',
                        sortable: true,
                        style: { textAlign: 'left', width: '14%' },
                        format: function (row) {
                            var chips = [];
                            chips.push(parseInt(row.is_enforced || 0, 10) === 1
                                ? '<span class="badge text-bg-warning">Vincolante</span>'
                                : '<span class="badge text-bg-secondary">Informativo</span>');
                            chips.push('<span class="badge text-bg-light text-dark">Priorita ' + self.e(String(row.priority || 100)) + '</span>');
                            return chips.join(' ');
                        }
                    },
                    {
                        label: 'Note',
                        field: 'notes',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            var text = String(row.notes || '').trim();
                            if (text === '') {
                                return '<span class="text-muted">-</span>';
                            }
                            if (text.length > 140) {
                                text = text.substring(0, 140) + '...';
                            }
                            return '<span class="small text-muted">' + self.e(text) + '</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'left', width: '120px' },
                        format: function (row) {
                            var id = parseInt(row.id || 0, 10) || 0;
                            if (id <= 0) {
                                return '-';
                            }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-archetype-attributes-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-archetype-attributes-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        store: function (rows) {
            this.rows = Array.isArray(rows) ? rows.slice() : [];
            this.rowsById = {};

            for (var i = 0; i < this.rows.length; i += 1) {
                var row = this.rows[i] || {};
                var id = parseInt(row.id || 0, 10) || 0;
                if (id > 0) {
                    this.rowsById[id] = row;
                }
            }
        },

        rowFromTrigger: function (trigger) {
            var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
            return id > 0 ? (this.rowsById[id] || null) : null;
        },

        reload: function () {
            if (!this.grid || typeof this.grid.loadData !== 'function') {
                return this;
            }

            this.grid.loadData({}, 25, 1, 'priority|DESC');
            return this;
        },

        ensureMeta: function (onReady) {
            var self = this;
            if (this.meta && typeof onReady === 'function') {
                onReady(this.meta);
                return;
            }

            this.loadMeta(function () {
                if (typeof onReady === 'function') {
                    onReady(self.meta || {});
                }
            });
        },

        loadMeta: function (onReady) {
            var self = this;
            this.post('/admin/archetype-attributes/meta', {}, function (response) {
                self.meta = response && response.dataset ? response.dataset : {};
                if (typeof onReady === 'function') {
                    onReady(self.meta);
                }
            });
        },

        openCreate: function () {
            var self = this;
            this.ensureMeta(function () {
                self.editingRow = null;
                self.populateFormSelects();
                self.resetForm();
                self.syncModalState();
                self.showModal();
            });
        },

        openEdit: function (row) {
            if (!row || !row.id) {
                return;
            }

            var self = this;
            this.ensureMeta(function () {
                self.editingRow = row;
                self.populateFormSelects(row);
                self.setField('id', row.id || 0);
                self.setField('archetype_id', row.archetype_id || '');
                self.setField('attribute_id', row.attribute_id || '');
                self.setField('rule_type', row.rule_type || '');
                self.setField('value', row.value || '');
                self.setField('priority', row.priority || 100);
                self.setField('is_enforced', parseInt(row.is_enforced || 0, 10) === 1 ? 1 : 0);
                self.setField('notes', row.notes || '');
                self.syncModalState();
                self.showModal();
            });
        },

        populateFormSelects: function (selectedRow) {
            var row = selectedRow || {};
            var meta = this.meta || {};

            this.fillSelect(
                'archetype_id',
                Array.isArray(meta.archetypes) ? meta.archetypes : [],
                'id',
                'name',
                'Seleziona archetipo',
                row.archetype_id || '',
                function (item) {
                    return String(item.name || ('Archetipo #' + item.id));
                }
            );

            this.fillSelect(
                'attribute_id',
                Array.isArray(meta.attributes) ? meta.attributes : [],
                'attribute_id',
                'name',
                'Seleziona attributo',
                row.attribute_id || '',
                function (item) {
                    var slug = String(item.slug || '').trim();
                    return slug !== ''
                        ? String(item.name || ('Attributo #' + item.attribute_id)) + ' (' + slug + ')'
                        : String(item.name || ('Attributo #' + item.attribute_id));
                }
            );

            var ruleTypes = meta.rule_types || {};
            var keys = Object.keys(ruleTypes);
            var options = [];
            for (var i = 0; i < keys.length; i += 1) {
                options.push({
                    value: keys[i],
                    label: String(ruleTypes[keys[i]] || keys[i])
                });
            }
            this.fillSimpleSelect('rule_type', options, 'Seleziona tipo regola', row.rule_type || '');
        },

        fillSelect: function (fieldName, rows, valueKey, labelKey, emptyLabel, selectedValue, formatter) {
            var select = this.modalForm.querySelector('[name="' + fieldName + '"]');
            if (!select) {
                return;
            }

            select.innerHTML = '';
            select.appendChild(this.option('', emptyLabel || 'Seleziona', String(selectedValue || '') === ''));

            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var value = String(row[valueKey] || '').trim();
                if (value === '') {
                    continue;
                }
                var label = typeof formatter === 'function'
                    ? formatter(row)
                    : String(row[labelKey] || value);
                select.appendChild(this.option(value, label, value === String(selectedValue || '')));
            }
        },

        fillSimpleSelect: function (fieldName, rows, emptyLabel, selectedValue) {
            var select = this.modalForm.querySelector('[name="' + fieldName + '"]');
            if (!select) {
                return;
            }

            select.innerHTML = '';
            select.appendChild(this.option('', emptyLabel || 'Seleziona', String(selectedValue || '') === ''));

            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var value = String(row.value || '').trim();
                if (value === '') {
                    continue;
                }
                select.appendChild(this.option(value, String(row.label || value), value === String(selectedValue || '')));
            }
        },

        option: function (value, label, selected) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            if (selected === true) {
                option.selected = true;
            }
            return option;
        },

        resetForm: function () {
            this.modalForm.reset();
            this.setField('id', 0);
            this.setField('priority', 100);
            this.setField('is_enforced', 0);
            this.setField('value', '');
            this.setField('notes', '');
            this.syncValueField();
        },

        syncModalState: function () {
            var title = this.root.querySelector('[data-role="admin-archetype-attributes-modal-title"]');
            var subtitle = this.root.querySelector('[data-role="admin-archetype-attributes-modal-subtitle"]');
            var deleteButton = this.root.querySelector('[data-action="admin-archetype-attributes-delete-current"]');
            var isEdit = !!(this.editingRow && this.editingRow.id);

            if (title) {
                title.textContent = isEdit ? 'Modifica regola' : 'Nuova regola';
            }
            if (subtitle) {
                subtitle.textContent = isEdit
                    ? 'Aggiorna la regola selezionata senza perdere i riferimenti esistenti.'
                    : 'Configura il comportamento dell\'attributo per l\'archetipo selezionato.';
            }
            if (deleteButton) {
                deleteButton.classList.toggle('d-none', !isEdit);
            }

            this.syncValueField();
        },

        syncValueField: function () {
            var type = this.getField('rule_type');
            var input = this.modalForm.querySelector('[name="value"]');
            var help = this.root.querySelector('[data-role="admin-archetype-attributes-value-help"]');
            if (!input || !help) {
                return;
            }

            if (type === 'suggestion') {
                input.placeholder = 'Es. Punta su presenza e carisma';
                help.textContent = 'Per i suggerimenti puoi usare testo libero.';
                return;
            }

            if (type === 'bonus') {
                input.placeholder = 'Es. +2 o -1';
                help.textContent = 'Per i bonus usa un valore numerico, positivo o negativo.';
                return;
            }

            if (type === 'fixed_value') {
                input.placeholder = 'Es. 5';
                help.textContent = 'Per il valore fisso usa un numero che blocchi il valore finale.';
                return;
            }

            if (type === 'min_value') {
                input.placeholder = 'Es. 3';
                help.textContent = 'Per il minimo usa un numero che rappresenti la soglia inferiore.';
                return;
            }

            if (type === 'max_value') {
                input.placeholder = 'Es. 10';
                help.textContent = 'Per il massimo usa un numero che rappresenti la soglia superiore.';
                return;
            }

            input.placeholder = 'Es. 5 o +2';
            help.textContent = 'Inserisci un valore numerico o testuale in base al tipo regola.';
        },

        collectPayload: function () {
            return {
                id: parseInt(String(this.getField('id') || '0'), 10) || 0,
                archetype_id: parseInt(String(this.getField('archetype_id') || '0'), 10) || 0,
                attribute_id: parseInt(String(this.getField('attribute_id') || '0'), 10) || 0,
                rule_type: String(this.getField('rule_type') || '').trim(),
                value: String(this.getField('value') || '').trim(),
                priority: parseInt(String(this.getField('priority') || '100'), 10) || 100,
                is_enforced: parseInt(String(this.getField('is_enforced') || '0'), 10) === 1 ? 1 : 0,
                notes: String(this.getField('notes') || '').trim()
            };
        },

        save: function () {
            var payload = this.collectPayload();

            if (payload.archetype_id <= 0) {
                this.toast('Seleziona un archetipo.', 'warning');
                return;
            }
            if (payload.attribute_id <= 0) {
                this.toast('Seleziona un attributo.', 'warning');
                return;
            }
            if (!payload.rule_type) {
                this.toast('Seleziona un tipo regola.', 'warning');
                return;
            }
            if (!payload.value) {
                this.toast('Inserisci il valore della regola.', 'warning');
                return;
            }
            if (payload.rule_type !== 'suggestion' && !this.isNumeric(payload.value)) {
                this.toast('Per questo tipo regola il valore deve essere numerico.', 'warning');
                return;
            }

            var self = this;
            this.post('/admin/archetype-attributes/rules/upsert', payload, function () {
                self.hideModal();
                self.toast('Regola salvata.', 'success');
                self.reload();
            });
        },

        remove: function (row) {
            if (!row || !row.id) {
                return;
            }

            var self = this;
            this.confirm('Elimina regola', 'Confermi la rimozione di questa regola?', function () {
                self.post('/admin/archetype-attributes/rules/delete', { id: row.id }, function () {
                    if (self.editingRow && parseInt(self.editingRow.id || 0, 10) === parseInt(row.id || 0, 10)) {
                        self.hideModal();
                    }
                    self.editingRow = null;
                    self.toast('Regola eliminata.', 'success');
                    self.reload();
                });
            });
        },

        showModal: function () {
            if (this.modal && typeof this.modal.show === 'function') {
                this.modal.show();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(this.modalNode).modal('show');
            }
        },

        hideModal: function () {
            if (this.modal && typeof this.modal.hide === 'function') {
                this.modal.hide();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(this.modalNode).modal('hide');
            }
        },

        post: function (url, payload, onSuccess, onError) {
            var self = this;

            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                this.toast('Servizio non disponibile.', 'danger');
                return this;
            }

            window.Request.http.post(url, payload || {})
                .then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response || {});
                    }
                })
                .catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                        return;
                    }
                    self.toast(self.err(error), 'danger');
                });

            return this;
        },

        getField: function (name) {
            var field = this.modalForm.querySelector('[name="' + name + '"]');
            return field ? field.value : '';
        },

        setField: function (name, value) {
            var field = this.modalForm.querySelector('[name="' + name + '"]');
            if (field) {
                field.value = value == null ? '' : String(value);
            }
        },

        confirm: function (title, body, onConfirm) {
            if (typeof window.Dialog === 'function') {
                window.Dialog('warning', { title: title, body: '<p>' + body + '</p>' }, function () {
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                }).show();
                return;
            }

            if (window.confirm(title + '\n\n' + body) && typeof onConfirm === 'function') {
                onConfirm();
            }
        },

        toast: function (message, type) {
            if (window.Toast && typeof window.Toast.show === 'function') {
                window.Toast.show({ body: message, type: type || 'info' });
            }
        },

        err: function (error) {
            var message = '';
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                message = String(window.Request.getErrorMessage(error, '') || '').trim();
            }
            if (message !== '') {
                return message;
            }
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            if (error && error.responseJSON && typeof error.responseJSON.error === 'string' && error.responseJSON.error.trim() !== '') {
                return error.responseJSON.error.trim();
            }
            return 'Operazione non riuscita.';
        },

        ruleTypeBadge: function (ruleType, label) {
            var type = String(ruleType || '').trim();
            var text = this.e(String(label || type || '-'));
            var cls = 'text-bg-secondary';

            if (type === 'fixed_value') {
                cls = 'text-bg-dark';
            } else if (type === 'min_value') {
                cls = 'text-bg-info';
            } else if (type === 'max_value') {
                cls = 'text-bg-warning';
            } else if (type === 'bonus') {
                cls = 'text-bg-success';
            } else if (type === 'suggestion') {
                cls = 'text-bg-primary';
            }

            return '<span class="badge ' + cls + '">' + text + '</span>';
        },

        isNumeric: function (value) {
            var normalized = String(value == null ? '' : value).trim().replace(',', '.');
            return normalized !== '' && isFinite(parseFloat(normalized));
        },

        e: function (value) {
            if (typeof window.$ === 'function') {
                return window.$('<div/>').text(value == null ? '' : String(value)).html();
            }

            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };

    window.AdminArchetypeAttributes = AdminArchetypeAttributes;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            AdminArchetypeAttributes.init();
        }, { once: true });
    } else {
        AdminArchetypeAttributes.init();
    }
})(window);
