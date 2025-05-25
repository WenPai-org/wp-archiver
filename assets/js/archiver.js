/**
 * WP Archiver 现代化前端脚本
 */

(function($) {
    'use strict';

    const Archiver = {

        config: {
            checkInterval: 5000,
            maxChecks: 12,
            fadeInDuration: 300,
            cachePrefix: 'archiver_cache_',
            debounceDelay: 300
        },

        currentService: null,
        serviceCheckTimers: {},

        init: function() {
            $(document).ready(() => {
                this.initMetabox();
                this.initAdminBar();
                this.initAdminPage();
                this.initLazyLoading();
                this.initServiceChecks();
            });
        },

        /**
         * 初始化元框
         */
        initMetabox: function() {
            const $container = $('#archiver-snapshots');
            if (!$container.length) return;

            const url = $container.data('url') || $('#archiver-url').val();
            const service = $container.data('service') || $('#archiver_primary_service').val();

            if (!url) return;

            this.currentService = service;

            // 服务标签切换
            $('.archiver-service-tab').on('click', (e) => {
                e.preventDefault();
                const $tab = $(e.currentTarget);
                const selectedService = $tab.data('service');

                $('.archiver-service-tab').removeClass('active');
                $tab.addClass('active');

                this.currentService = selectedService;
                this.loadSnapshots(url, $container, selectedService);
            });

            // 延迟加载快照
            this.loadSnapshots(url, $container, service);

            // 立即存档按钮
            $('#archiver-immediate-snapshot').on('click', (e) => {
                e.preventDefault();
                this.triggerSnapshot(url, this.currentService);
            });
        },

        /**
         * 初始化管理栏
         */
        initAdminBar: function() {
            const $trigger = $('#wp-admin-bar-archiver-trigger a');
            if (!$trigger.length) return;

            // 延迟加载快照计数
            const $countItem = $('#wp-admin-bar-archiver-snapshots');
            if ($countItem.length && archiver.url) {
                this.updateAdminBarCount(archiver.url);
            }

            // 触发快照
            $trigger.on('click', (e) => {
                e.preventDefault();
                this.triggerAdminBarSnapshot();
            });
        },

        /**
         * 初始化管理页面
         */
        initAdminPage: function() {
            // 标签切换
            $('.archiver-tab').on('click', function(e) {
                e.preventDefault();
                const $tab = $(this);
                const tabId = $tab.data('tab');

                $('.archiver-tab').removeClass('active');
                $tab.addClass('active');

                $('.archiver-tab-content').removeClass('active');
                $('#' + tabId).addClass('active');

                // 保存用户偏好
                if (typeof(Storage) !== "undefined") {
                    localStorage.setItem('archiver_active_tab', tabId);
                }
            });

            // 恢复上次的标签
            const savedTab = localStorage.getItem('archiver_active_tab');
            if (savedTab && $(`.archiver-tab[data-tab="${savedTab}"]`).length) {
                $(`.archiver-tab[data-tab="${savedTab}"]`).trigger('click');
            }

            // 标签链接
            $('.archiver-tab-link').on('click', function(e) {
                e.preventDefault();
                const tab = $(this).attr('href').replace('#', '');
                $(`.archiver-tab[data-tab="${tab}"]`).trigger('click');
            });

            // 测试服务连接
            $('.test-service').on('click', (e) => {
                e.preventDefault();
                const $button = $(e.currentTarget);
                const service = $button.data('service');
                this.testServiceConnection(service, $button);
            });
        },

        /**
         * 初始化服务检查
         */
        initServiceChecks: function() {
            if ($('.archiver-service-card').length === 0) return;

            // 检查所有服务状态
            $('.archiver-service-card').each((index, element) => {
                const $card = $(element);
                const service = $card.find('.test-service').data('service');
                if (service) {
                    this.checkServiceStatus(service);
                }
            });
        },

        /**
         * 检查服务状态
         */
        checkServiceStatus: function(service) {
            const $status = $(`#service-status-${service}`);
            if (!$status.length) return;

            // 清除现有定时器
            if (this.serviceCheckTimers[service]) {
                clearTimeout(this.serviceCheckTimers[service]);
            }

            $status.removeClass('online offline').find('.status-text').text(archiver.i18n.checking);

            // 模拟检查延迟
            this.serviceCheckTimers[service] = setTimeout(() => {
                // 根据服务配置判断状态
                const isEnabled = archiver.enabled_services[service];
                const statusClass = isEnabled ? 'online' : 'offline';
                const statusText = isEnabled ? archiver.i18n.online : archiver.i18n.offline;

                $status.addClass(statusClass).find('.status-text').text(statusText);
            }, 1000);
        },

        /**
         * 测试服务连接
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
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message, 'success');
                        this.checkServiceStatus(service);
                    } else {
                        this.showNotification(response.data.message || archiver.i18n.test_failed, 'error');
                    }
                },
                error: () => {
                    this.showNotification(archiver.i18n.test_failed, 'error');
                },
                complete: () => {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * 延迟加载
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
         * 加载快照
         */
        loadSnapshots: function(url, $container, service = null) {
            // 检查本地缓存
            const cacheKey = this.getCacheKey(url, service);
            const cached = this.getCache(cacheKey);
            if (cached) {
                this.displaySnapshots(cached, $container);
                // 后台检查更新
                this.checkForUpdates(url, $container, service);
                return;
            }

            // 显示加载状态
            this.showLoading($container);

            // 从服务器获取
            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_get_snapshots',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url,
                    service: service
                },
                success: (response) => {
                    if (response.success) {
                        this.setCache(cacheKey, response.data);
                        this.displaySnapshots(response.data, $container);
                    } else {
                        this.showError($container, response.data.message);
                    }
                },
                error: () => {
                    this.showError($container, archiver.i18n.error);
                }
            });
        },

        /**
         * 显示快照
         */
        displaySnapshots: function(data, $container) {
            if (!data.html || data.html.includes('archiver-no-snapshots')) {
                $container.html(data.html || '<p class="archiver-no-snapshots">' + archiver.i18n.no_snapshots + '</p>');
                return;
            }

            $container.hide().html(data.html).fadeIn(this.config.fadeInDuration);

            // 添加淡入动画
            $container.find('li').each(function(index) {
                $(this).css({
                    opacity: 0,
                    transform: 'translateY(10px)'
                }).delay(index * 50).animate({
                    opacity: 1
                }, {
                    duration: 300,
                    step: function(now, fx) {
                        if (fx.prop === 'opacity' && now > 0.5) {
                            $(this).css('transform', 'translateY(0)');
                        }
                    }
                });
            });
        },

        /**
         * 触发快照
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
                success: (response) => {
                    if (response.success) {
                        $status.removeClass('error').addClass('success')
                            .html('<span class="dashicons dashicons-yes"></span> ' + response.data.message);

                        // 清除缓存并开始轮询
                        this.clearCache(this.getCacheKey(url, service));
                        if (response.data.refresh) {
                            this.startPolling(url, service);
                        }

                        // 触发自定义事件
                        $(document).trigger('archiver:snapshot:success', [url, service]);
                    } else {
                        $status.removeClass('success').addClass('error')
                            .html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                    }
                },
                error: () => {
                    $status.removeClass('success').addClass('error')
                        .html('<span class="dashicons dashicons-warning"></span> ' + archiver.i18n.error);
                },
                complete: () => {
                    $button.prop('disabled', false).removeClass('updating-message');
                    setTimeout(() => {
                        $status.fadeOut();
                    }, 5000);
                }
            });
        },

        /**
         * 管理栏触发快照
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
                error: () => {
                    $item.removeClass('archiver-active').addClass('archiver-failure');
                    this.showNotification(archiver.i18n.error, 'error');
                },
                complete: () => {
                    setTimeout(() => {
                        $item.removeClass('archiver-success archiver-failure');
                    }, 2000);
                }
            });
        },

        /**
         * 更新管理栏计数
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
                success: (response) => {
                    if (response.success && response.data.count !== undefined) {
                        const count = response.data.count >= 10 ? '10+' : response.data.count;
                        $countItem.text(archiver.i18n.view_all + ' (' + count + ')');
                    }
                }
            });
        },

        /**
         * 检查更新
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
         * 开始轮询
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
        },

        /**
         * 显示加载状态
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
         * 显示错误
         */
        showError: function($container, message) {
            $container.html('<p class="archiver-error">' + message + '</p>');
        },

        /**
         * 显示通知
         */
        showNotification: function(message, type = 'info') {
            // 如果在管理页面，使用 WordPress 通知
            if ($('body').hasClass('wp-admin')) {
                const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
                $('.wrap > h1').after($notice);

                // 自动关闭
                setTimeout(() => {
                    $notice.fadeOut(() => $notice.remove());
                }, 5000);
            }
        },

        /**
         * 缓存管理
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
                    // 5分钟缓存
                    if (Date.now() - data.timestamp < 300000) {
                        return data.content;
                    }
                }
            } catch (e) {
                console.warn('Cache read error:', e);
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
                console.warn('Cache write error:', e);
            }
        },

        clearCache: function(key) {
            if (!this.isStorageAvailable()) return;

            try {
                if (key) {
                    sessionStorage.removeItem(key);
                } else {
                    // 清除所有archiver缓存
                    const keys = [];
                    for (let i = 0; i < sessionStorage.length; i++) {
                        const k = sessionStorage.key(i);
                        if (k && k.startsWith(this.config.cachePrefix)) {
                            keys.push(k);
                        }
                    }
                    keys.forEach(k => sessionStorage.removeItem(k));
                }
            } catch (e) {
                console.warn('Cache clear error:', e);
            }
        },

        /**
         * 工具函数
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
         * 防抖函数
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
        }
    };

    // 初始化
    Archiver.init();

    // 暴露到全局
    window.WPArchiver = Archiver;

})(jQuery);
