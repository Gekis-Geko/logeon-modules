const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function initializeAdvanceMapsGame() {
    if (globalWindow.__advanceMapsGameLoaded === true) {
        return;
    }
    globalWindow.__advanceMapsGameLoaded = true;

    function normalizeError(error, fallback) {
        if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
            return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    function patchMapsModuleFactory() {
        if (typeof globalWindow.GameMapsModuleFactory !== 'function' || globalWindow.__advanceMapsPatchedFactory === true) {
            return;
        }

        var originalFactory = globalWindow.GameMapsModuleFactory;
        globalWindow.GameMapsModuleFactory = function () {
            var module = originalFactory.apply(this, arguments);
            if (!module || typeof module !== 'object') {
                return module;
            }
            if (typeof module.advanceList !== 'function') {
                module.advanceList = function (payload) {
                    return this.request('/advance-maps/list', 'advanceMapList', payload || {});
                };
            }
            if (typeof module.advanceContext !== 'function') {
                module.advanceContext = function (payload) {
                    return this.request('/advance-maps/context', 'advanceMapContext', payload || {});
                };
            }
            return module;
        };
        globalWindow.__advanceMapsPatchedFactory = true;
    }

    function patchMapsPageFactory() {
        if (typeof globalWindow.GameMapsPage !== 'function' || globalWindow.__advanceMapsPatchedMapsPage === true) {
            return;
        }

        var originalFactory = globalWindow.GameMapsPage;
        globalWindow.GameMapsPage = function (extension) {
            var advExtension = {
                getMaps: function () {
                    var self = this;
                    var payload = {
                        query: {
                            root_only: true
                        },
                        orderBy: 'position|ASC',
                        cache: false,
                        cache_ttl: 0
                    };
                    callMapsModule('advanceList', payload, function (response) {
                        if (!response) { return; }
                        self.dataset = Array.isArray(response.dataset) ? response.dataset : [];
                        self.build();
                    }, function (error) {
                        Toast.show({
                            body: normalizeError(error, 'Errore durante caricamento mappe'),
                            type: 'error'
                        });
                    });
                }
            };

            var merged = Object.assign({}, advExtension, extension || {});
            return originalFactory.call(this, merged);
        };
        globalWindow.__advanceMapsPatchedMapsPage = true;
    }

    function updateBreadcrumb(context) {
        var nav = document.querySelector('#locations-page nav[aria-label="breadcrumb"] ol.breadcrumb');
        if (!nav) { return; }
        var crumbs = (context && Array.isArray(context.breadcrumb)) ? context.breadcrumb : [];
        var html = '<li class="breadcrumb-item"><a href="/game/maps/">Mappe</a></li>';
        for (var i = 0; i < crumbs.length; i++) {
            var item = crumbs[i] || {};
            var id = parseInt(item.id || 0, 10) || 0;
            var name = String(item.name || 'Mappa');
            if (i === crumbs.length - 1 || id <= 0) {
                html += '<li class="breadcrumb-item active" aria-current="page">' + escapeHtml(name) + '</li>';
            } else {
                html += '<li class="breadcrumb-item"><a href="/game/maps/' + id + '">' + escapeHtml(name) + '</a></li>';
            }
        }
        nav.innerHTML = html;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function patchLocationsPageFactory() {
        if (typeof globalWindow.GameLocationsPage !== 'function' || globalWindow.__advanceMapsPatchedLocationsPage === true) {
            return;
        }

        var originalFactory = globalWindow.GameLocationsPage;
        globalWindow.GameLocationsPage = function (id, extension) {
            var advExtension = {
                context: null,
                hotspots: [],

                get: function () {
                    var self = this;
                    this.requestModule('advanceContext', {
                        map_id: self.map_id
                    }).then(function (response) {
                        var dataset = response && response.dataset ? response.dataset : null;
                        if (!dataset || !dataset.map) {
                            throw new Error('Mappa non disponibile');
                        }
                        self.context = dataset;
                        self.mapData = dataset.map || null;
                        self.childMaps = Array.isArray(dataset.children) ? dataset.children : [];
                        self.locations = Array.isArray(dataset.locations) ? dataset.locations : [];
                        self.hotspots = Array.isArray(dataset.hotspots) ? dataset.hotspots : [];
                        updateBreadcrumb(dataset);
                        self.build();
                    }).catch(function (error) {
                        Toast.show({
                            body: normalizeError(error, 'Errore durante caricamento mappa'),
                            type: 'error'
                        });
                    });
                },

                buildAdvancedVisual: function (mapName, mapImage) {
                    var visualBlock = $('#map-visual');
                    var visualPins = $('#map-visual-pins');
                    var visualImage = $('#map-visual-image');
                    if (!visualBlock.length || !visualPins.length || !visualImage.length) {
                        return;
                    }

                    visualPins.empty();
                    visualBlock.removeClass('d-none');
                    visualImage.attr('src', mapImage).attr('alt', mapName || 'Mappa');

                    var hotspots = Array.isArray(this.hotspots) ? this.hotspots : [];
                    for (var i = 0; i < hotspots.length; i++) {
                        var hs = hotspots[i] || {};
                        var x = parseFloat(hs.x);
                        var y = parseFloat(hs.y);
                        if (isNaN(x) || isNaN(y)) { continue; }
                        if (x < 0 || x > 100 || y < 0 || y > 100) { continue; }

                        var label = String(hs.label || hs.target_name || 'Punto');
                        var targetUrl = String(hs.target_url || '#');
                        var targetType = String(hs.target_type || 'location').toLowerCase();
                        var isMapTarget = (targetType === 'map');
                        var btnClass = isMapTarget ? 'btn-info' : 'btn-primary';

                        var pin = $('<a class="btn btn-sm ' + btnClass + ' position-absolute text-nowrap"></a>');
                        pin.css({
                            left: x + '%',
                            top: y + '%',
                            transform: 'translate(-50%, -50%)',
                            zIndex: 3
                        });
                        pin.attr('href', targetUrl);
                        pin.attr('title', label);
                        pin.text(label);
                        pin.appendTo(visualPins);
                    }
                },

                build: function () {
                    var childSection = $('#map-child-maps-section').addClass('d-none');
                    var locationsSection = $('#locations-page-section').addClass('d-none');
                    var emptyState = $('#locations-page-empty').addClass('d-none').text('Questa mappa non contiene ancora luoghi o sottomappe.');
                    var visualBlock = $('#map-visual').addClass('d-none');
                    $('#map-visual-pins').empty();
                    $('#locations-page-body').empty();

                    if (!this.mapData) {
                        $('[name="map_name"]').html('Mappa');
                        emptyState.removeClass('d-none').text('Mappa non trovata.');
                        return this;
                    }

                    $('[name="map_name"]').html(this.mapData.name || 'Mappa');
                    this.buildChildMaps();
                    childSection = $('#map-child-maps-section');

                    var ctx = this.context || {};
                    var mapName = this.mapData.name || '';
                    var mapImage = (this.mapData.image || '').toString().trim();
                    var mode = String(ctx.effective_render_mode || this.mapData.render_mode || 'grid').toLowerCase();
                    var requestedMode = String(ctx.render_mode || this.mapData.render_mode || mode).toLowerCase();
                    var visualReady = !!ctx.visual_ready;

                    var showVisual = (mode === 'visual' || mode === 'hybrid') && visualReady && mapImage !== '';
                    var showGrid = (mode === 'grid' || mode === 'hybrid');
                    if (requestedMode === 'hybrid' && showVisual) {
                        showGrid = true;
                    }

                    if (showVisual) {
                        this.buildAdvancedVisual(mapName, mapImage);
                    } else {
                        visualBlock.addClass('d-none');
                    }

                    if (showGrid && Array.isArray(this.locations) && this.locations.length > 0) {
                        locationsSection.removeClass('d-none');
                        this.buildBlockMap();
                    }

                    if ((!this.locations || this.locations.length === 0) && childSection.hasClass('d-none') && !showVisual) {
                        emptyState.removeClass('d-none');
                    }

                    initTooltips(document.getElementById('map-visual') || document);
                    initTooltips(document.getElementById('locations-page-body') || document);
                    initTooltips(document.getElementById('map-child-maps') || document);
                    return this;
                }
            };

            var merged = Object.assign({}, advExtension, extension || {});
            return originalFactory.call(this, id, merged);
        };
        globalWindow.__advanceMapsPatchedLocationsPage = true;
    }

    function registerGameModuleExtension() {
        if (!globalWindow.GameRegistry) { return; }
        globalWindow.GameRegistry.registerModule('game.advance-maps', 'GameAdvanceMapsModuleFactory');
        globalWindow.GameRegistry.extendPage('maps', ['game.advance-maps'], { after: 'game.maps' });
        globalWindow.GameRegistry.extendPage('locations', ['game.advance-maps'], { after: 'game.maps' });
    }

    function createGameAdvanceMapsModule() {
        return {
            mount: function () {
                patchMapsModuleFactory();
                patchMapsPageFactory();
                patchLocationsPageFactory();
            },
            unmount: function () {}
        };
    }

    globalWindow.GameAdvanceMapsModuleFactory = createGameAdvanceMapsModule;
    patchMapsModuleFactory();
    patchMapsPageFactory();
    patchLocationsPageFactory();
    registerGameModuleExtension();
}

function callMapsModule(method, payload, onSuccess, onError) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Maps module resolver not available: ' + method));
        }
        return false;
    }

    var mod = null;
    try {
        mod = globalWindow.RuntimeBootstrap.resolveAppModule('game.maps');
    } catch (error) {
        mod = null;
    }
    if (!mod || typeof mod[method] !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Maps module method not available: ' + method));
        }
        return false;
    }

    mod[method](payload || {}).then(function (response) {
        if (typeof onSuccess === 'function') {
            onSuccess(response);
        }
    }).catch(function (error) {
        if (typeof onError === 'function') {
            onError(error);
        }
    });

    return true;
}

initializeAdvanceMapsGame();

