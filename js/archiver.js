(($) => {
    'use strict';
    
    $(document).ready(() => {
        initAdminBarTrigger();
        initMetaboxButton();
        initTabSwitching();
    });

    const initAdminBarTrigger = () => {
        const $triggerButton = $('#wp-admin-bar-archiver-trigger a');
        
        if (!$triggerButton.length) return;

        $triggerButton.on('click', async (e) => {
            e.preventDefault();
            const $menuItem = $(e.target).closest('li');
            $menuItem.addClass('archiver-active');

            try {
                const response = await wp.apiRequest({
                    url: archiver.rest_url,
                    method: 'POST',
                    data: { url: archiver.url },
                    beforeSend: (xhr) => {
                        xhr.setRequestHeader('X-WP-Nonce', archiver.nonce);
                    }
                });

                $menuItem.removeClass('archiver-active');
                
                if (response.success) {
                    $menuItem.addClass('archiver-success');
                    console.log('Archiver:', response.message);
                } else {
                    throw new Error(response.message || wp.i18n.__('Unknown error occurred', 'archiver'));
                }
            } catch (error) {
                console.error('Archiver Error:', error);
                $menuItem.addClass('archiver-failure');
            }

            setTimeout(() => {
                $menuItem.removeClass('archiver-success archiver-failure');
            }, 2000);
        });
    };

    const initMetaboxButton = () => {
        const $immediateButton = $('#archiver-immediate-snapshot');
        
        $immediateButton.on('click', async (e) => {
            e.preventDefault();
            const $statusElement = $('#archiver-status');
            const url = $('#archiver-url').val();
            const nonce = $('#archiver_nonce').val();

            $immediateButton.prop('disabled', true);
            $statusElement.show().text(archiver.i18n.triggering).removeClass('error success').addClass('processing');

            try {
                const response = await $.ajax({
                    url: archiver.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'archiver_immediate_snapshot',
                        _ajax_nonce: nonce,
                        url: url
                    }
                });

                if (response.success) {
                    $statusElement.text(response.data.message).removeClass('processing').addClass('success');
                    if (response.data.snapshots) {
                        $('#archiver-snapshots ul').html(response.data.snapshots.join(''));
                    }
                } else {
                    throw new Error(response.data.message || 'Unknown error');
                }
            } catch (error) {
                console.error('Archiver Error:', error);
                $statusElement.text(`${archiver.i18n.error}: ${error.message || 'Request failed'}`)
                    .removeClass('processing success')
                    .addClass('error');
            } finally {
                setTimeout(() => {
                    $immediateButton.prop('disabled', false);
                    $statusElement.fadeOut(500, () => {
                        $statusElement.removeClass('processing error success').empty();
                    });
                }, 3000);
            }
        });
    };

    const initTabSwitching = () => {
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            $('.archiver-tab-content').hide();
            const tabId = $(this).data('tab');
            $('#' + tabId).show();
        });
    };

    if (!$('#archiver-spin-animation').length) {
        $('head').append(`
            <style id="archiver-spin-animation">
                @keyframes archiver-spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `);
    }

})(jQuery);