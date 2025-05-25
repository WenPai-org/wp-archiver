/**
 * WP Archiver 前端脚本
 */

(function($) {
    'use strict';

    const Archiver = {

        config: {
            checkInterval: 5000,
            maxChecks: 12,
            fadeInDuration: 300,
            cachePrefix: 'archiver_cache_'
        },

        init: function() {
            $(document).ready(() => {
                this.initMetabox();
                this.initAdminBar();
                this.initAdminPage();
                this.initLazyLoading();
            });
        },

        /**
         * 初始化元框
         */
        initMetabox: function() {
            const $container = $('#archiver-snapshots');
            if (!$container.length) return;

            const url = $container.data('url') || $('#archiver-url').val();
            if (!url) return;

            // 延迟加载快照
            this.loadSnapshots(url, $container);

            // 立即存档按钮
            $('#archiver-immediate-snapshot').on('click', (e) => {
                e.preventDefault();
                this.triggerSnapshot(url);
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
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                const $tab = $(this);
                const tabId = $tab.data('tab');

                $('.nav-tab').removeClass('nav-tab-active');
                $tab.addClass('nav-tab-active');

                $('.archiver-tab-content').hide();
                $('#' + tabId).fadeIn(300);

                // 保存用户偏好
                if (typeof(Storage) !== "undefined") {
                    localStorage.setItem('archiver_active_tab', tabId);
                }
            });

            // 恢复上次的标签
            const savedTab = localStorage.getItem('archiver_active_tab');
            if (savedTab) {
                $(`.nav-tab[data-tab="${savedTab}"]`).trigger('click');
            }
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
                            if (url && !$element.data('loaded')) {
                                this.loadSnapshots(url, $element);
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
        loadSnapshots: function(url, $container) {
            // 检查本地缓存
            const cached = this.getCache(url);
            if (cached) {
                this.displaySnapshots(cached, $container);
                // 后台检查更新
                this.checkForUpdates(url, $container);
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
                    url: url
                },
                success: (response) => {
                    if (response.success) {
                        this.setCache(url, response.data);
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
            if (!data.html) {
                $container.html('<p>' + archiver.i18n.no_snapshots + '</p>');
                return;
            }

            $container.hide().html(data.html).fadeIn(this.config.fadeInDuration);

            // 添加动画
            $container.find('li').each(function(index) {
                $(this).css({
                    opacity: 0,
                    transform: 'translateY(10px)'
                }).delay(index * 50).animate({
                    opacity: 1,
                    transform: 'translateY(0)'
                }, 300);
            });
        },

        /**
         * 触发快照
         */
        triggerSnapshot: function(url) {
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
                    url: url
                },
                success: (response) => {
                    if (response.success) {
                        $status.removeClass('error').addClass('success')
                            .html('<span class="dashicons dashicons-yes"></span> ' + response.data.message);

                        // 清除缓存并开始轮询
                        this.clearCache(url);
                        if (response.data.refresh) {
                            this.startPolling(url);
                        }
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
                    url: archiver.url
                },
                success: (response) => {
                    $item.removeClass('archiver-active');
                    if (response.success) {
                        $item.addClass('archiver-success');
                        this.clearCache(archiver.url);
                        this.updateAdminBarCount(archiver.url);
                    } else {
                        $item.addClass('archiver-failure');
                    }
                },
                error: () => {
                    $item.removeClass('archiver-active').addClass('archiver-failure');
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
                    url: url
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
        checkForUpdates: function(url, $container) {
            $.ajax({
                url: archiver.ajax_url,
                type: 'POST',
                data: {
                    action: 'archiver_check_cache_update',
                    _ajax_nonce: archiver.ajax_nonce,
                    url: url
                },
                success: (response) => {
                    if (response.success && response.data.has_update) {
                        this.clearCache(url);
                        this.displaySnapshots(response.data, $container);
                    }
                }
            });
        },

        /**
         * 开始轮询
         */
        startPolling: function(url) {
            let checkCount = 0;
            const $container = $('#archiver-snapshots');

            const interval = setInterval(() => {
                checkCount++;

                if (checkCount > this.config.maxChecks) {
                    clearInterval(interval);
                    return;
                }

                this.checkForUpdates(url, $container);
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
         * 缓存管理
         */
        getCache: function(url) {
            if (!this.isStorageAvailable()) return null;

            try {
                const key = this.config.cachePrefix + this.hashCode(url);
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

        setCache: function(url, data) {
            if (!this.isStorageAvailable()) return;

            try {
                const key = this.config.cachePrefix + this.hashCode(url);
                const cacheData = {
                    content: data,
                    timestamp: Date.now()
                };
                sessionStorage.setItem(key, JSON.stringify(cacheData));
            } catch (e) {
                console.warn('Cache write error:', e);
            }
        },

        clearCache: function(url) {
            if (!this.isStorageAvailable()) return;

            try {
                const key = this.config.cachePrefix + this.hashCode(url);
                sessionStorage.removeItem(key);
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
        }
    };

    // 初始化
    Archiver.init();

    // 暴露到全局
    window.WPArchiver = Archiver;

})(jQuery);
