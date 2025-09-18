/**
 * ClearA11y Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        ClearA11yAdmin.init();
    });
    
    var ClearA11yAdmin = {
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            // Single post scan button
            $(document).on('click', '.cleara11y-scan-btn', this.handleSingleScan);
            
            // View results button
            $(document).on('click', '.cleara11y-view-results', this.handleViewResults);
            
            // Bulk scan form
            $(document).on('submit', '#cleara11y-bulk-scan-form', this.handleBulkScan);
            
            // Admin bar scan (if present)
            $(document).on('click', '.cleara11y-admin-bar-scan', this.handleAdminBarScan);
        },
        
        handleSingleScan: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var postId = $button.data('post-id');
            var $resultsContainer = $('#cleara11y-scan-results');
            
            if (!postId) {
                alert('Error: No post ID found');
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text(cleara11y_ajax.strings.scanning);
            $resultsContainer.html('<div class="cleara11y-scan-loading"><div class="spinner is-active"></div><p>' + cleara11y_ajax.strings.scanning + '</p></div>');
            
            // Perform AJAX scan
            $.ajax({
                url: cleara11y_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cleara11y_scan_post',
                    post_id: postId,
                    nonce: cleara11y_ajax.scan_nonce
                },
                success: function(response) {
                    if (response.success) {
                        ClearA11yAdmin.displayScanResults(response.data, $resultsContainer);
                        ClearA11yAdmin.updateMetaBoxSummary(response.data);
                    } else {
                        ClearA11yAdmin.displayError(response.data, $resultsContainer);
                    }
                },
                error: function(xhr, status, error) {
                    ClearA11yAdmin.displayError('Network error: ' + error, $resultsContainer);
                },
                complete: function() {
                    $button.prop('disabled', false).text('Scan for Issues');
                }
            });
        },
        
        handleViewResults: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var postId = $button.data('post-id');
            var $resultsContainer = $('#cleara11y-scan-results');
            
            if (!postId) {
                alert('Error: No post ID found');
                return;
            }
            
            // Show loading state
            $resultsContainer.html('<div class="cleara11y-scan-loading"><div class="spinner is-active"></div><p>Loading results...</p></div>');
            
            // Get existing scan results
            $.ajax({
                url: cleara11y_ajax.ajax_url,
                type: 'GET',
                data: {
                    action: 'cleara11y_get_scan_results',
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        ClearA11yAdmin.displayDetailedResults(response.data, $resultsContainer);
                    } else {
                        ClearA11yAdmin.displayError(response.data, $resultsContainer);
                    }
                },
                error: function(xhr, status, error) {
                    ClearA11yAdmin.displayError('Network error: ' + error, $resultsContainer);
                }
            });
        },
        
        handleBulkScan: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $progressContainer = $('#cleara11y-bulk-progress');
            var $submitButton = $form.find('button[type="submit"]');
            
            // Get selected post types
            var postTypes = [];
            $form.find('input[name="post_types[]"]:checked').each(function() {
                postTypes.push($(this).val());
            });
            
            if (postTypes.length === 0) {
                alert('Please select at least one post type to scan.');
                return;
            }
            
            // Show progress container
            $progressContainer.show();
            $submitButton.prop('disabled', true).text('Starting scan...');
            
            // Start bulk scan
            $.ajax({
                url: cleara11y_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cleara11y_bulk_scan',
                    post_types: postTypes.join(','),
                    post_status: $form.find('input[name="post_status"]:checked').val(),
                    nonce: cleara11y_ajax.bulk_scan_nonce
                },
                success: function(response) {
                    if (response.success) {
                        ClearA11yAdmin.monitorBulkProgress(response.data.batch_id);
                        $('.progress-text').text('Bulk scan initiated. Processing in background...');
                    } else {
                        alert('Error starting bulk scan: ' + response.data);
                        $progressContainer.hide();
                    }
                },
                error: function(xhr, status, error) {
                    alert('Network error: ' + error);
                    $progressContainer.hide();
                },
                complete: function() {
                    $submitButton.prop('disabled', false).text('Start Bulk Scan');
                }
            });
        },
        
        handleAdminBarScan: function(e) {
            e.preventDefault();
            
            // This would be handled by the frontend admin bar script
            // Placeholder for admin bar functionality
            console.log('Admin bar scan clicked');
        },
        
        displayScanResults: function(data, $container) {
            var html = '<div class="cleara11y-results-summary ' + (data.violations > 0 ? 'has-violations' : 'no-violations') + '">';
            
            if (data.violations === 0) {
                html += '<h4>' + cleara11y_ajax.strings.no_violations + '</h4>';
                html += '<p>Great job! No accessibility violations were found.</p>';
            } else {
                html += '<h4>' + data.violations + ' ' + cleara11y_ajax.strings.violations_found + '</h4>';
                html += '<p>Scan completed in ' + (data.duration ? data.duration.toFixed(2) + ' seconds' : 'unknown time') + '</p>';
                html += '<button type="button" class="button cleara11y-view-results" data-post-id="' + data.post_id + '">View Detailed Results</button>';
            }
            
            html += '</div>';
            
            $container.html(html);
        },
        
        displayDetailedResults: function(data, $container) {
            var html = '<div class="cleara11y-detailed-results">';
            
            // Summary
            html += '<div class="cleara11y-results-summary ' + (data.scan.total_violations > 0 ? 'has-violations' : 'no-violations') + '">';
            html += '<h4>Scan Results Summary</h4>';
            html += '<p><strong>Scanned:</strong> ' + new Date(data.scan.scan_date).toLocaleString() + '</p>';
            html += '<p><strong>Violations:</strong> ' + data.scan.total_violations + '</p>';
            html += '<p><strong>Passes:</strong> ' + data.scan.total_passes + '</p>';
            html += '</div>';
            
            // Violations
            if (data.violations && data.violations.length > 0) {
                html += '<h4>Accessibility Violations</h4>';
                
                data.violations.forEach(function(violation) {
                    html += '<div class="cleara11y-violation-item impact-' + violation.impact + '">';
                    html += '<div class="cleara11y-violation-title">' + violation.description + '</div>';
                    html += '<div class="cleara11y-violation-description">' + violation.help + '</div>';
                    
                    if (violation.help_url) {
                        html += '<div class="cleara11y-violation-help">';
                        html += '<a href="' + violation.help_url + '" target="_blank" rel="noopener">Learn more about this rule</a>';
                        html += '</div>';
                    }
                    
                    if (violation.failure_summary) {
                        html += '<div class="cleara11y-violation-summary"><strong>Issue:</strong> ' + violation.failure_summary + '</div>';
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            
            $container.html(html);
        },
        
        displayError: function(message, $container) {
            var html = '<div class="notice notice-error"><p><strong>Scan Error:</strong> ' + message + '</p></div>';
            $container.html(html);
        },
        
        updateMetaBoxSummary: function(data) {
            var $lastScan = $('.cleara11y-last-scan');
            
            if ($lastScan.length === 0) {
                // Create last scan section if it doesn't exist
                var html = '<div class="cleara11y-last-scan">';
                html += '<h4>Last Scan Results</h4>';
                html += '<p><strong>Scanned:</strong> Just now</p>';
                
                if (data.violations > 0) {
                    html += '<p class="cleara11y-violations"><strong>' + data.violations + '</strong> violations found</p>';
                    html += '<button type="button" class="button cleara11y-view-results" data-post-id="' + data.post_id + '">View Details</button>';
                } else {
                    html += '<p class="cleara11y-success">No violations found!</p>';
                }
                
                html += '</div>';
                
                $('#cleara11y-meta-box').append(html);
            } else {
                // Update existing summary
                $lastScan.find('p:first').html('<strong>Scanned:</strong> Just now');
                
                if (data.violations > 0) {
                    $lastScan.find('.cleara11y-violations, .cleara11y-success').remove();
                    $lastScan.find('.cleara11y-view-results').remove();
                    $lastScan.append('<p class="cleara11y-violations"><strong>' + data.violations + '</strong> violations found</p>');
                    $lastScan.append('<button type="button" class="button cleara11y-view-results" data-post-id="' + data.post_id + '">View Details</button>');
                } else {
                    $lastScan.find('.cleara11y-violations, .cleara11y-success').remove();
                    $lastScan.find('.cleara11y-view-results').remove();
                    $lastScan.append('<p class="cleara11y-success">No violations found!</p>');
                }
            }
        },
        
        monitorBulkProgress: function(batchId) {
            // This would monitor the progress of bulk scanning
            // For now, just show a message
            $('.progress-text').text('Bulk scan is running in the background. You will receive an email notification when complete.');
            $('.progress-fill').css('width', '100%');
        }
    };
    
    // Export for use in other scripts
    window.ClearA11yAdmin = ClearA11yAdmin;
    
})(jQuery);
