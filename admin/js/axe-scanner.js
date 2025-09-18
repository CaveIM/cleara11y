/**
 * ClearA11y Axe-Core Scanner JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        ClearA11yAxeScanner.init();
    });
    
    var ClearA11yAxeScanner = {
        
        axeLoaded: false,
        
        init: function() {
            this.loadAxeCore();
            this.bindEvents();
        },
        
        loadAxeCore: function() {
            var self = this;
            
            // Load axe-core library
            var script = document.createElement('script');
            script.src = cleara11y_ajax.plugin_url + 'assets/axe.min.js';
            script.onload = function() {
                self.axeLoaded = true;
                console.log('Axe-core loaded successfully');
            };
            script.onerror = function() {
                console.error('Failed to load axe-core library');
            };
            document.head.appendChild(script);
        },
        
        bindEvents: function() {
            // Enhanced scan button with axe-core
            $(document).on('click', '.cleara11y-axe-scan-btn', this.handleAxeScan.bind(this));
        },
        
        handleAxeScan: function(e) {
            e.preventDefault();
            
            if (!this.axeLoaded) {
                alert('Axe-core library is still loading. Please try again in a moment.');
                return;
            }
            
            var $button = $(e.currentTarget);
            var postId = $button.data('post-id');
            var $resultsContainer = $('#cleara11y-scan-results');
            
            if (!postId) {
                alert('Error: No post ID found');
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text('Scanning with Axe-core...');
            $resultsContainer.html('<div class="cleara11y-scan-loading"><div class="spinner is-active"></div><p>Preparing accessibility scan...</p></div>');
            
            // First, get the post content
            this.getPostContent(postId)
                .then(function(response) {
                    if (response.success) {
                        return ClearA11yAxeScanner.runAxeScan(response.data);
                    } else {
                        throw new Error(response.data);
                    }
                })
                .then(function(axeResults) {
                    return ClearA11yAxeScanner.saveAxeResults(postId, axeResults);
                })
                .then(function(response) {
                    if (response.success) {
                        ClearA11yAxeScanner.displayAxeResults(response.data, $resultsContainer);
                        ClearA11yAxeScanner.updateMetaBoxSummary(response.data);
                    } else {
                        throw new Error(response.data);
                    }
                })
                .catch(function(error) {
                    ClearA11yAxeScanner.displayError(error.message, $resultsContainer);
                })
                .finally(function() {
                    $button.prop('disabled', false).text('Scan with Axe-core');
                });
        },
        
        getPostContent: function(postId) {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: cleara11y_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cleara11y_axe_scan_post',
                        post_id: postId,
                        nonce: cleara11y_ajax.scan_nonce
                    },
                    success: resolve,
                    error: function(xhr, status, error) {
                        reject(new Error('Network error: ' + error));
                    }
                });
            });
        },
        
        runAxeScan: function(postData) {
            return new Promise(function(resolve, reject) {
                // Create a hidden iframe to run axe-core against
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.style.width = '1024px';
                iframe.style.height = '768px';
                document.body.appendChild(iframe);
                
                iframe.onload = function() {
                    try {
                        // Get axe configuration
                        var axeConfig = ClearA11yAxeScanner.getAxeConfig();
                        
                        // Run axe-core scan
                        iframe.contentWindow.axe.run(iframe.contentDocument, axeConfig, function(err, results) {
                            // Clean up iframe
                            document.body.removeChild(iframe);
                            
                            if (err) {
                                reject(new Error('Axe scan failed: ' + err.message));
                                return;
                            }
                            
                            resolve(results);
                        });
                    } catch (error) {
                        document.body.removeChild(iframe);
                        reject(new Error('Axe scan error: ' + error.message));
                    }
                };
                
                // Load the post content into iframe
                iframe.contentDocument.open();
                iframe.contentDocument.write(postData.content);
                iframe.contentDocument.close();
            });
        },
        
        saveAxeResults: function(postId, axeResults) {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: cleara11y_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cleara11y_axe_scan_content',
                        post_id: postId,
                        axe_results: JSON.stringify(axeResults),
                        nonce: cleara11y_ajax.scan_nonce
                    },
                    success: resolve,
                    error: function(xhr, status, error) {
                        reject(new Error('Failed to save results: ' + error));
                    }
                });
            });
        },
        
        getAxeConfig: function() {
            // Get configuration from WordPress settings
            var standard = cleara11y_ajax.accessibility_standard || 'wcag21aa';
            
            var config = {
                rules: {},
                tags: [],
                locale: cleara11y_ajax.locale || 'en'
            };
            
            // Configure tags based on accessibility standard
            switch (standard) {
                case 'wcag21aa':
                    config.tags = ['wcag2a', 'wcag2aa', 'wcag21aa'];
                    break;
                case 'wcag21aaa':
                    config.tags = ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21aa', 'wcag21aaa'];
                    break;
                case 'wcag22aa':
                    config.tags = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
                    break;
                case 'wcag22aaa':
                    config.tags = ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21aa', 'wcag21aaa', 'wcag22aa', 'wcag22aaa'];
                    break;
                default:
                    config.tags = ['wcag2a', 'wcag2aa'];
            }
            
            // Add best practice rules
            config.tags.push('best-practice');
            
            return config;
        },
        
        displayAxeResults: function(data, $container) {
            var html = '<div class="cleara11y-axe-results">';
            
            // Summary
            html += '<div class="cleara11y-results-summary ' + (data.violations > 0 ? 'has-violations' : 'no-violations') + '">';
            
            if (data.violations === 0) {
                html += '<h4>✅ No Accessibility Violations Found!</h4>';
                html += '<p>Excellent! Your content meets the selected accessibility standards.</p>';
            } else {
                html += '<h4>⚠️ ' + data.violations + ' Accessibility Violations Found</h4>';
                html += '<p>Scan completed in ' + (data.duration ? data.duration.toFixed(2) + ' seconds' : 'unknown time') + '</p>';
            }
            
            if (data.incomplete > 0) {
                html += '<p><strong>Note:</strong> ' + data.incomplete + ' items require manual review.</p>';
            }
            
            html += '<p><strong>Passes:</strong> ' + data.passes + ' rules passed</p>';
            html += '</div>';
            
            // Action buttons
            if (data.violations > 0 || data.incomplete > 0) {
                html += '<div class="cleara11y-action-buttons">';
                html += '<button type="button" class="button cleara11y-view-detailed-results" data-post-id="' + data.post_id + '">View Detailed Results</button>';
                html += '<button type="button" class="button cleara11y-export-results" data-post-id="' + data.post_id + '">Export Report</button>';
                html += '</div>';
            }
            
            html += '</div>';
            
            $container.html(html);
        },
        
        displayError: function(message, $container) {
            var html = '<div class="notice notice-error"><p><strong>Axe-core Scan Error:</strong> ' + message + '</p></div>';
            $container.html(html);
        },
        
        updateMetaBoxSummary: function(data) {
            var $lastScan = $('.cleara11y-last-scan');
            
            if ($lastScan.length === 0) {
                // Create last scan section if it doesn't exist
                var html = '<div class="cleara11y-last-scan">';
                html += '<h4>Latest Axe-core Scan</h4>';
                html += '<p><strong>Scanned:</strong> Just now</p>';
                
                if (data.violations > 0) {
                    html += '<p class="cleara11y-violations"><strong>' + data.violations + '</strong> violations found</p>';
                    if (data.incomplete > 0) {
                        html += '<p class="cleara11y-incomplete"><strong>' + data.incomplete + '</strong> items need manual review</p>';
                    }
                    html += '<button type="button" class="button cleara11y-view-detailed-results" data-post-id="' + data.post_id + '">View Details</button>';
                } else {
                    html += '<p class="cleara11y-success">✅ No violations found!</p>';
                }
                
                html += '</div>';
                
                $('#cleara11y-meta-box').append(html);
            } else {
                // Update existing summary
                $lastScan.find('h4').text('Latest Axe-core Scan');
                $lastScan.find('p:first').html('<strong>Scanned:</strong> Just now');
                
                // Remove old status
                $lastScan.find('.cleara11y-violations, .cleara11y-success, .cleara11y-incomplete').remove();
                $lastScan.find('.cleara11y-view-detailed-results').remove();
                
                if (data.violations > 0) {
                    $lastScan.append('<p class="cleara11y-violations"><strong>' + data.violations + '</strong> violations found</p>');
                    if (data.incomplete > 0) {
                        $lastScan.append('<p class="cleara11y-incomplete"><strong>' + data.incomplete + '</strong> items need manual review</p>');
                    }
                    $lastScan.append('<button type="button" class="button cleara11y-view-detailed-results" data-post-id="' + data.post_id + '">View Details</button>');
                } else {
                    $lastScan.append('<p class="cleara11y-success">✅ No violations found!</p>');
                }
            }
        }
    };
    
    // Export for use in other scripts
    window.ClearA11yAxeScanner = ClearA11yAxeScanner;
    
})(jQuery);
