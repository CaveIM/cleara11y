/**
 * ClearA11y Admin Bar JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        ClearA11yAdminBar.init();
    });
    
    var ClearA11yAdminBar = {
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            // Admin bar scan button
            $('#wpadminbar .cleara11y-admin-bar-scan').on('click', this.handleScan.bind(this));
        },
        
        handleScan: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var originalText = $button.text();
            
            if (!cleara11y_frontend.post_id) {
                this.showNotification('Error: No post ID found', 'error');
                return;
            }
            
            // Show loading state
            $button.text(cleara11y_frontend.strings.scanning);
            this.showNotification(cleara11y_frontend.strings.scanning, 'info');
            
            // Perform AJAX scan
            $.ajax({
                url: cleara11y_frontend.ajax_url,
                type: 'POST',
                data: {
                    action: 'cleara11y_frontend_scan',
                    post_id: cleara11y_frontend.post_id,
                    nonce: cleara11y_frontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        ClearA11yAdminBar.handleScanSuccess(response.data);
                    } else {
                        ClearA11yAdminBar.showNotification('Scan failed: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    ClearA11yAdminBar.showNotification('Network error: ' + error, 'error');
                },
                complete: function() {
                    $button.text(originalText);
                }
            });
        },
        
        handleScanSuccess: function(data) {
            var message;
            var type;
            
            if (data.violations === 0) {
                message = cleara11y_frontend.strings.no_violations;
                type = 'success';
            } else {
                message = data.violations + ' ' + cleara11y_frontend.strings.violations_found;
                type = 'warning';
                
                // Reload page to show new highlights
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            }
            
            this.showNotification(message, type);
        },
        
        showNotification: function(message, type) {
            // Remove existing notifications
            $('.cleara11y-notification').remove();
            
            // Create notification
            var $notification = $('<div class="cleara11y-notification cleara11y-notification-' + type + '">')
                .html('<p>' + message + '</p>')
                .appendTo('body');
            
            // Position notification
            $notification.css({
                position: 'fixed',
                top: '32px', // Below admin bar
                right: '20px',
                background: this.getNotificationColor(type),
                color: '#fff',
                padding: '10px 15px',
                borderRadius: '4px',
                boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
                zIndex: 100000,
                maxWidth: '300px',
                fontSize: '14px'
            });
            
            // Auto-hide notification
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, type === 'info' ? 2000 : 5000);
        },
        
        getNotificationColor: function(type) {
            switch (type) {
                case 'success':
                    return '#00a32a';
                case 'warning':
                    return '#ffb900';
                case 'error':
                    return '#d63638';
                case 'info':
                default:
                    return '#0073aa';
            }
        }
    };
    
    // Export for use in other scripts
    window.ClearA11yAdminBar = ClearA11yAdminBar;
    
})(jQuery);
