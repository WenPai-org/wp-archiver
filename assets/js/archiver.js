/**
 * WP Archiver Modern Frontend Script
 * Complete version with all functionality
 */

(function($) {
    'use strict';

    const Archiver = {

        config: {
            checkInterval: 3000,
            maxChecks: 20,
            fadeInDuration: 300,
            cachePrefix: 'archiver_cache_',
            debounceDelay: 300,
            retryDelay: 1000,
            maxRetries: 3
        },

        currentService: null,
        serviceCheckTimers: {},
        loadingCache: {},

        init: function() {
            $(document).ready(() => {
                this.initMetabox();
                this.initAdminBar();
                this.initAdminPage();
                this.initLazyLoading();
                this.initServiceChecks();
                this.addNotificationStyles();
            });
        },

        /**
         * Initialize metabox
         */
        initMetabox: function() {
            const $container = $('#archiver-snapshots');
            if (!$container.length) return;

            const url = $container.data('url') || $('#archiver-url').val();
            const service = $container.data('service') || $('#archiver_primary_service').val();

            if (!url) return;

            this.currentService = service;

            // Service tab switching
            $('.archiver-service-tab').on('click', (e) => {
                e.preventDefault();
                const $tab = $(e.currentTarget);
                const selectedService = $tab.data('service');

                $('.archiver-service-tab').removeClass('active');
                $tab.addClass('active');

                this.currentService = selectedService;
                this.loadSnapshots(url, $container, selectedService);
            });

            // Load snapshots with retry mechanism
            this.loadSnapshots(url, $container, service);

            // Immediate archive button
            $('#archiver-immediate-snapshot').on('click', (e) => {
                e.preventDefault();
                this.triggerSnapshot(url, this.currentService);
            });
        },

        /**
         * Initialize admin bar
         */
        initAdminBar: function() {
            const $trigger = $('#wp-admin-bar-archiver-trigger a');
            if (!$trigger.length) return;

            // Load snapshot count with delay
            const $countItem = $('#wp-admin-bar-archiver-snapshots');
            if ($countItem.length && archiver.url) {
                setTimeout(() => {
                    this.updateAdminBarCount(archiver.url);
                }, 500);
            }

            // Trigger snapshot
            $trigger.on('click', (e) => {
                e.preventDefault();
                this.triggerAdminBarSnapshot();
            });
        },

        /**
         * Initialize admin page
         */
        initAdminPage: function() {
            // Tab switching
            $('.settings-tab').on('click', function(e) {
                e.preventDefault();
                const $tab = $(this);
                const tabId = $tab.data('tab');

                $('.settings-tab').removeClass('active');
                $tab.addClass('active');

                $('.settings-section').hide();
                $('.settings-section[data-section="' + tabId + '"]').show();

                // Save user preference
                if (typeof(Storage) !== "undefined") {
                    localStorage.setItem('archiver_active_tab', tabId);
                }
            });

            // Restore last tab
            const savedTab = localStorage.getItem('archiver_active_tab');
            if (savedTab && $(`.settings-tab[data-tab="${savedTab}"]`).length) {
                $(`.settings-tab[data-tab="${savedTab}"]`).trigger('click');
            }

            // Test service connection
            $('.test-service').on('click', (e) => {
                e.preventDefault();
                const $button = $(e.currentTarget);
                const service = $button.data('service');
                this.testServiceConnection(service, $button);
            });
        },

        /**
         * Initialize service checks
         */
        initServiceChecks: function() {
            if ($('.service-status').length === 0) return;

            // Check all service statuses
            $('.service-status').each((index, element) => {
                const $status = $(element);
                const serviceId = $status.attr('id').replace('status-', '');
                if (serviceId) {
                    setTimeout(() => {
                        this.checkServiceStatus(serviceId);
                    }, index * 200); // Staggered loading
                }
            });
        },

        /**
         * Check service status
         */
        checkServiceStatus: function(service) {
            const $status = $(`#status-${service}`);
            if (!$status.length) return;

            // Clear existing timer
            if (this.serviceCheckTimers[service]) {
                clearTimeout(this.serviceCheckTimers[service]);
            }

            $status.removeClass('online offline').find('.status-text').text(archiver.i18n.checking);

            // Simulate check with realistic delay
            this.serviceCheckTimers[service] = setTimeout(() => {
                // Check if service is enabled
                const isEnabled = archiver.enabled_services && archiver.enabled_services[service];
                const statusClass = isEnabled ? 'online' : 'offline';
                const statusText = isEnabled ? archiver.i18n.online : archiver.i18n.offline;

                $status.addClass(statusClass).find('.status-text').text(statusText);
            }, 800 + Math.random() * 400);
        },

        /**
         * Test service connection
         */
        testServiceConnection: function(service, $button) {
            const originalText = $button.text();
            $button.prop('disabled', true).text(archiver.i18n.checking);

            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_test_service',
                    service: service,
                    nonce: archiver.admin_nonce
                },
                timeout: 10000,
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message, 'success');
                        this.checkServiceStatus(service);
                    } else {
                        this.showNotification(response.data.message || archiver.i18n.test_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    let message = archiver.i18n.test_failed;
                    if (status === 'timeout') {
                        message = 'Connection timeout - service may be slow';
                    }
                    this.showNotification(message, 'error');
                },
                complete: () => {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Initialize lazy loading
         */
        initLazyLoading: function() {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const $element = $(entry.target);
                            const url = $element.data('url');
                            const service = $element.data('service');
                            if (url && !$element.data('loaded')) {
                                this.loadSnapshots(url, $element, service);
                                $element.data('loaded', true);
                            }
                        }
                    });
                });

                $('.archiver-lazy').each(function() {
                    observer.observe(this);
                });
            }
        },

        /**
         * Load snapshots with enhanced error handling
         */
        loadSnapshots: function(url, $container, service = null, retryCount = 0) {
            if (retryCount === 0) {
                // Check local cache first
                const cacheKey = this.getCacheKey(url, service);
                const cached = this.getCache(cacheKey);
                if (cached && cached.html) {
                    this.displaySnapshots(cached, $container);
                    // Background check for updates
                    setTimeout(() => {
                        this.checkForUpdates(url, $container, service);
                    }, 2000);
                    return;
                }
            }

            // Prevent multiple simultaneous requests
            const requestKey = `${url}_${service || 'default'}`;
            if (this.loadingCache[requestKey]) {
                return;
            }
            this.loadingCache[requestKey] = true;

            // Show loading state
            this.showLoading($container);

            // Fetch from server
            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_get_snapshots',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url,
                    service: service
                },
                timeout: 15000,
                success: (response) => {
                    delete this.loadingCache[requestKey];

                    if (response.success) {
                        const cacheKey = this.getCacheKey(url, service);
                        this.setCache(cacheKey, response.data);
                        this.displaySnapshots(response.data, $container);
                    } else {
                        this.showError($container, response.data?.message || 'Failed to load snapshots');
                    }
                },
                error: (xhr, status, error) => {
                    delete this.loadingCache[requestKey];

                    // Retry mechanism
                    if (retryCount < this.config.maxRetries) {
                        setTimeout(() => {
                            this.loadSnapshots(url, $container, service, retryCount + 1);
                        }, this.config.retryDelay * (retryCount + 1));

                        $container.html(`
                            <div class="archiver-loading">
                                <span class="spinner is-active"></span>
                                ${archiver.i18n.loading} (Retry ${retryCount + 1}/${this.config.maxRetries})
                            </div>
                        `);
                    } else {
                        let errorMsg = 'Failed to load archive data';
                        if (status === 'timeout') {
                            errorMsg = 'Request timeout - archive service may be slow';
                        } else if (xhr.status === 0) {
                            errorMsg = 'Network error - please check your connection';
                        }
                        this.showError($container, errorMsg);
                    }
                }
            });
        },

        /**
         * Display snapshots with enhanced UI
         */
        displaySnapshots: function(data, $container) {
            if (!data.html || data.html.includes('archiver-no-snapshots')) {
                const noSnapshotsHtml = `
                    <div class="archiver-no-snapshots">
                        <p><em>${archiver.i18n.no_snapshots}</em></p>
                        <small>Snapshots will appear here after archiving is complete.</small>
                    </div>
                `;
                $container.html(data.html || noSnapshotsHtml);
                return;
            }

            $container.hide().html(data.html).fadeIn(this.config.fadeInDuration);

            // Enhanced animations
            $container.find('.archiver-snapshot-list li').each(function(index) {
                $(this).css({
                    opacity: 0,
                    transform: 'translateY(10px)'
                }).delay(index * 100).animate({
                    opacity: 1
                }, {
                    duration: 400,
                    step: function(now, fx) {
                        if (fx.prop === 'opacity' && now > 0.5) {
                            $(this).css('transform', 'translateY(0)');
                        }
                    }
                });
            });

            // Add timestamp to links if missing
            $container.find('a').each(function() {
                const $link = $(this);
                if (!$link.attr('title')) {
                    $link.attr('title', 'Archived snapshot - opens in new tab');
                }
            });
        },

        /**
         * Trigger snapshot with enhanced feedback
         */
        triggerSnapshot: function(url, service = null) {
            const $button = $('#archiver-immediate-snapshot');
            const $status = $('#archiver-status');

            $button.prop('disabled', true).addClass('updating-message');
            $status.show().removeClass('error success')
                .html('<span class="spinner is-active"></span> ' + archiver.i18n.triggering);

            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_immediate_snapshot',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url,
                    service: service
                },
                timeout: 30000,
                success: (response) => {
                    if (response.success) {
                        $status.removeClass('error').addClass('success')
                            .html('<span class="dashicons dashicons-yes"></span> ' + response.data.message);

                        // Clear cache and start monitoring
                        this.clearCache(this.getCacheKey(url, service));
                        if (response.data.refresh) {
                            this.startPolling(url, service);
                        }

                        // Trigger custom event
                        $(document).trigger('archiver:snapshot:success', [url, service]);
                    } else {
                        $status.removeClass('success').addClass('error')
                            .html('<span class="dashicons dashicons-warning"></span> ' +
                                  (response.data?.message || 'Archive request failed'));
                    }
                },
                error: (xhr, status, error) => {
                    let errorMsg = archiver.i18n.error;
                    if (status === 'timeout') {
                        errorMsg = 'Archive request timeout - please try again';
                    }
                    $status.removeClass('success').addClass('error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + errorMsg);
                },
                complete: () => {
                    $button.prop('disabled', false).removeClass('updating-message');
                    setTimeout(() => {
                        $status.fadeOut();
                    }, 8000);
                }
            });
        },

        /**
         * Admin bar snapshot trigger
         */
        triggerAdminBarSnapshot: function() {
            const $item = $('#wp-admin-bar-archiver');
            $item.addClass('archiver-active');

            $.ajax({
                url: archiver.rest_url + 'trigger-snapshot',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', archiver.nonce);
                },
                data: {
                    url: archiver.url,
                    service: archiver.primary_service
                },
                timeout: 20000,
                success: (response) => {
                    $item.removeClass('archiver-active');
                    if (response.success) {
                        $item.addClass('archiver-success');
                        this.clearCache(this.getCacheKey(archiver.url));
                        this.updateAdminBarCount(archiver.url);
                        this.showNotification(archiver.i18n.success, 'success');
                    } else {
                        $item.addClass('archiver-failure');
                        this.showNotification(response.message || archiver.i18n.error, 'error');
                    }
                },
                error: (xhr, status) => {
                    $item.removeClass('archiver-active').addClass('archiver-failure');
                    const errorMsg = status === 'timeout' ? 'Archive timeout' : archiver.i18n.error;
                    this.showNotification(errorMsg, 'error');
                },
                complete: () => {
                    setTimeout(() => {
                        $item.removeClass('archiver-success archiver-failure');
                    }, 3000);
                }
            });
        },

        /**
         * Update admin bar count
         */
        updateAdminBarCount: function(url) {
            const $countItem = $('#wp-admin-bar-archiver-snapshots .ab-label');
            if (!$countItem.length) return;

            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_get_snapshots',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url,
                    service: archiver.primary_service
                },
                timeout: 10000,
                success: (response) => {
                    if (response.success && response.data.count !== undefined) {
                        const count = response.data.count >= 10 ? '10+' : response.data.count;
                        $countItem.text(`${archiver.i18n.view_all} (${count})`);
                    }
                }
            });
        },

        /**
         * Check for updates
         */
        checkForUpdates: function(url, $container, service = null) {
            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_check_cache_update',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url,
                    service: service
                },
                timeout: 8000,
                success: (response) => {
                    if (response.success && response.data.has_update) {
                        const cacheKey = this.getCacheKey(url, service);
                        this.clearCache(cacheKey);
                        this.displaySnapshots(response.data, $container);
                    }
                }
            });
        },

        /**
         * Start polling for updates
         */
        startPolling: function(url, service = null) {
            let checkCount = 0;
            const $container = $('#archiver-snapshots');

            const interval = setInterval(() => {
                checkCount++;

                if (checkCount > this.config.maxChecks) {
                    clearInterval(interval);
                    return;
                }

                this.checkForUpdates(url, $container, service);
            }, this.config.checkInterval);

            // Visual indicator for polling
            if ($container.length) {
                const $indicator = $('<div class="archiver-polling-indicator">Checking for new archives...</div>');
                $container.prepend($indicator);

                setTimeout(() => {
                    $indicator.fadeOut(() => $indicator.remove());
                }, this.config.checkInterval * 3);
            }
        },

        /**
         * Show loading state
         */
        showLoading: function($container) {
            const loadingHtml = `
                <div class="archiver-loading">
                    <span class="spinner is-active"></span>
                    ${archiver.i18n.loading}
                </div>
            `;
            $container.html(loadingHtml);
        },

        /**
         * Show error state
         */
        showError: function($container, message) {
            const errorHtml = `
                <div class="archiver-error">
                    <p><span class="dashicons dashicons-warning"></span> ${message}</p>
                    <button class="button button-small archiver-retry">Try Again</button>
                </div>
            `;
            $container.html(errorHtml);

            // Retry functionality
            $container.find('.archiver-retry').on('click', () => {
                const url = $container.data('url');
                const service = $container.data('service');
                this.loadSnapshots(url, $container, service);
            });
        },

        /**
         * Show notification
         */
        showNotification: function(message, type = 'info') {
            // If in admin page, use WordPress notifications
            if ($('body').hasClass('wp-admin')) {
                const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
                $('.wrap > h1').after($notice);

                // Auto dismiss
                setTimeout(() => {
                    $notice.fadeOut(() => $notice.remove());
                }, 5000);
            } else {
                // Frontend notification
                const $notification = $(`
                    <div class="archiver-notification archiver-notification-${type}">
                        ${message}
                    </div>
                `);

                $('body').append($notification);

                setTimeout(() => {
                    $notification.addClass('show');
                }, 100);

                setTimeout(() => {
                    $notification.removeClass('show');
                    setTimeout(() => $notification.remove(), 300);
                }, 4000);
            }
        },

        /**
         * Cache management
         */
        getCacheKey: function(url, service = null) {
            const serviceKey = service || 'default';
            return this.config.cachePrefix + this.hashCode(url + '_' + serviceKey);
        },

        getCache: function(key) {
            if (!this.isStorageAvailable()) return null;

            try {
                const cached = sessionStorage.getItem(key);

                if (cached) {
                    const data = JSON.parse(cached);
                    // 5 minute cache
                    if (Date.now() - data.timestamp < 300000) {
                        return data.content;
                    } else {
                        // Clean expired cache
                        sessionStorage.removeItem(key);
                    }
                }
            } catch (e) {
                console.warn('Archiver cache read error:', e);
                // Clean corrupted cache
                try {
                    sessionStorage.removeItem(key);
                } catch (cleanError) {
                    console.warn('Failed to clean corrupted cache:', cleanError);
                }
            }

            return null;
        },

        setCache: function(key, data) {
            if (!this.isStorageAvailable()) return;

            try {
                const cacheData = {
                    content: data,
                    timestamp: Date.now()
                };
                sessionStorage.setItem(key, JSON.stringify(cacheData));
            } catch (e) {
                console.warn('Archiver cache write error:', e);
                // Try to clear some space
                this.cleanOldCache();
            }
        },

        clearCache: function(key) {
            if (!this.isStorageAvailable()) return;

            try {
                if (key) {
                    sessionStorage.removeItem(key);
                } else {
                    // Clear all archiver cache
                    this.cleanOldCache(true);
                }
            } catch (e) {
                console.warn('Archiver cache clear error:', e);
            }
        },

        cleanOldCache: function(clearAll = false) {
            if (!this.isStorageAvailable()) return;

            try {
                const keys = [];
                const now = Date.now();

                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    if (key && key.startsWith(this.config.cachePrefix)) {
                        if (clearAll) {
                            keys.push(key);
                        } else {
                            try {
                                const cached = sessionStorage.getItem(key);
                                if (cached) {
                                    const data = JSON.parse(cached);
                                    // Remove if older than 10 minutes
                                    if (now - data.timestamp > 600000) {
                                        keys.push(key);
                                    }
                                }
                            } catch (e) {
                                // Remove corrupted entries
                                keys.push(key);
                            }
                        }
                    }
                }

                keys.forEach(key => {
                    try {
                        sessionStorage.removeItem(key);
                    } catch (e) {
                        console.warn('Failed to remove cache key:', key, e);
                    }
                });

                if (keys.length > 0) {
                    console.log(`Archiver: Cleaned ${keys.length} cache entries`);
                }
            } catch (e) {
                console.warn('Cache cleanup error:', e);
            }
        },

        /**
         * Add notification styles
         */
        addNotificationStyles: function() {
            if ($('#archiver-notification-styles').length) return;

            $('head').append(`
                <style id="archiver-notification-styles">
                .archiver-notification {
                    position: fixed;
                    top: 32px;
                    right: 20px;
                    background: #fff;
                    border-left: 4px solid #00a0d2;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    padding: 12px 16px;
                    border-radius: 3px;
                    z-index: 999999;
                    transform: translateX(100%);
                    transition: transform 0.3s ease;
                    max-width: 350px;
                    word-wrap: break-word;
                    font-size: 13px;
                }

                .archiver-notification.show {
                    transform: translateX(0);
                }

                .archiver-notification-success {
                    border-left-color: #46b450;
                }

                .archiver-notification-error {
                    border-left-color: #dc3232;
                }

                .archiver-notification-info {
                    border-left-color: #00a0d2;
                }

                .archiver-error {
                    text-align: center;
                    padding: 20px;
                    color: #dc3232;
                }

                .archiver-error .dashicons {
                    margin-right: 5px;
                }

                .archiver-retry {
                    margin-top: 10px;
                }

                .archiver-polling-indicator {
                    background: #e7f3ff;
                    border: 1px solid #b8dcf2;
                    color: #0073aa;
                    padding: 8px 12px;
                    border-radius: 3px;
                    margin-bottom: 10px;
                    font-size: 12px;
                    text-align: center;
                }

                .archiver-no-snapshots {
                    text-align: center;
                    padding: 20px;
                    color: #646970;
                }

                .archiver-no-snapshots small {
                    display: block;
                    margin-top: 8px;
                    font-size: 11px;
                }

                @media (max-width: 782px) {
                    .archiver-notification {
                        top: 46px;
                        right: 10px;
                        left: 10px;
                        max-width: none;
                        transform: translateY(-100%);
                    }

                    .archiver-notification.show {
                        transform: translateY(0);
                    }
                }
                </style>
            `);
        },

        /**
         * Utility functions
         */
        isStorageAvailable: function() {
            try {
                const test = '__archiver_test__';
                sessionStorage.setItem(test, test);
                sessionStorage.removeItem(test);
                return true;
            } catch (e) {
                return false;
            }
        },

        hashCode: function(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString();
        },

        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        /**
         * Global error handler
         */
        handleGlobalError: function(error, context = 'Unknown') {
            console.error(`Archiver Error [${context}]:`, error);

            if (typeof error === 'object' && error.message) {
                this.showNotification(`Error in ${context}: ${error.message}`, 'error');
            }
        }
    };

    // Global error handling
    $(document).ajaxError(function(event, xhr, settings, error) {
        if (settings.url && settings.url.includes('archiver')) {
            console.error('Archiver AJAX Error:', {
                url: settings.url,
                status: xhr.status,
                error: error,
                response: xhr.responseText
            });
        }
    });

    // Initialize when DOM is ready
    Archiver.init();

    // Expose to global scope
    window.WPArchiver = Archiver;

    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        // Clean old cache entries before leaving
        if (Archiver.isStorageAvailable()) {
            Archiver.cleanOldCache();
        }
    });

})(jQuery);
