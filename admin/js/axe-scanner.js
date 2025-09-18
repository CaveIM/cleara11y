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
            // Comprehensive scan button with axe-core
            $(document).on('click', '.cleara11y-comprehensive-scan-btn', this.handleComprehensiveScan.bind(this));
            
            // Tab switching
            $(document).on('click', '.cleara11y-tab-btn', this.handleTabSwitch.bind(this));
        },
        
        handleTabSwitch: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var tabName = $button.data('tab');
            
            // Update active tab button
            $('.cleara11y-tab-btn').removeClass('active');
            $button.addClass('active');
            
            // Update active tab pane
            $('.cleara11y-tab-pane').removeClass('active');
            $('#cleara11y-tab-' + tabName).addClass('active');
            
            // Load content for specific tabs
            if (tabName === 'violations') {
                this.loadViolations();
            } else if (tabName === 'history') {
                this.loadHistory();
            }
        },
        
        handleComprehensiveScan: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var postId = $button.data('post-id');
            var $resultsContainer = $('#cleara11y-scan-results');
            
            if (!postId) {
                alert('Error: No post ID found');
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text('Scanning for Issues...');
            $resultsContainer.html('<div class="cleara11y-scan-loading"><div class="spinner is-active"></div><p>Running comprehensive accessibility scan...</p></div>');
            
            // Get the post URL and scan it directly
            this.getPostUrl(postId)
                .then(function(response) {
                    if (response.success) {
                        return ClearA11yAxeScanner.runAxeScanOnUrl(response.data.url);
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
                    $button.prop('disabled', false).text('Scan for Accessibility Issues');
                });
        },
        
        getPostUrl: function(postId) {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: cleara11y_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cleara11y_get_post_url',
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
        
        runAxeScanOnUrl: function(url) {
            return new Promise(function(resolve, reject) {
                // Create a hidden iframe to load the actual page
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.style.width = '1024px';
                iframe.style.height = '768px';
                iframe.src = url;
                document.body.appendChild(iframe);
                
                iframe.onload = function() {
                    try {
                        // Load axe-core into the iframe
                        var axeScript = iframe.contentDocument.createElement('script');
                        axeScript.src = cleara11y_ajax.plugin_url + 'assets/axe.min.js';
                        
                        axeScript.onload = function() {
                            try {
                                // Wait a moment for axe to initialize
                                setTimeout(function() {
                                    if (!iframe.contentWindow.axe) {
                                        document.body.removeChild(iframe);
                                        reject(new Error('Axe-core failed to load in iframe'));
                                        return;
                                    }
                                    
                                    // Get axe configuration
                                    var axeConfig = ClearA11yAxeScanner.getAxeConfig();
                                    
                                    // Run axe-core scan on the fully rendered page
                                    iframe.contentWindow.axe.run(iframe.contentDocument, axeConfig, function(err, results) {
                                        // Clean up iframe
                                        document.body.removeChild(iframe);
                                        
                                        if (err) {
                                            reject(new Error('Axe scan failed: ' + err.message));
                                            return;
                                        }
                                        
                                        resolve(results);
                                    });
                                }, 1000); // Wait 1 second for page and axe to fully load
                            } catch (error) {
                                document.body.removeChild(iframe);
                                reject(new Error('Axe scan error: ' + error.message));
                            }
                        };
                        
                        axeScript.onerror = function() {
                            document.body.removeChild(iframe);
                            reject(new Error('Failed to load axe-core library into iframe'));
                        };
                        
                        // Add the script to iframe head
                        iframe.contentDocument.head.appendChild(axeScript);
                        
                    } catch (error) {
                        document.body.removeChild(iframe);
                        reject(new Error('Iframe setup error: ' + error.message));
                    }
                };
                
                iframe.onerror = function() {
                    document.body.removeChild(iframe);
                    reject(new Error('Failed to load page: ' + url));
                };
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
                        // Load axe-core into the iframe
                        var axeScript = iframe.contentDocument.createElement('script');
                        axeScript.src = cleara11y_ajax.plugin_url + 'assets/axe.min.js';
                        
                        axeScript.onload = function() {
                            try {
                                // Wait a moment for axe to initialize
                                setTimeout(function() {
                                    if (!iframe.contentWindow.axe) {
                                        document.body.removeChild(iframe);
                                        reject(new Error('Axe-core failed to load in iframe'));
                                        return;
                                    }
                                    
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
                                }, 500); // Wait 500ms for axe to initialize
                            } catch (error) {
                                document.body.removeChild(iframe);
                                reject(new Error('Axe scan error: ' + error.message));
                            }
                        };
                        
                        axeScript.onerror = function() {
                            document.body.removeChild(iframe);
                            reject(new Error('Failed to load axe-core library into iframe'));
                        };
                        
                        // Add the script to iframe head
                        iframe.contentDocument.head.appendChild(axeScript);
                        
                    } catch (error) {
                        document.body.removeChild(iframe);
                        reject(new Error('Iframe setup error: ' + error.message));
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
                
                // Show action to view violations
                html += '<p><strong>→ Switch to the "Violations" tab to see detailed issues and remediation steps.</strong></p>';
            }
            
            if (data.incomplete > 0) {
                html += '<p><strong>Note:</strong> ' + data.incomplete + ' items require manual review.</p>';
            }
            
            html += '<p><strong>Passes:</strong> ' + data.passes + ' rules passed</p>';
            html += '</div>';
            
            html += '</div>';
            
            $container.html(html);
            
            // Auto-switch to violations tab if violations found
            if (data.violations > 0) {
                setTimeout(function() {
                    $('.cleara11y-tab-btn[data-tab="violations"]').click();
                }, 2000);
            }
        },
        
        displayError: function(message, $container) {
            var html = '<div class="notice notice-error"><p><strong>Axe-core Scan Error:</strong> ' + message + '</p></div>';
            $container.html(html);
        },
        
        loadViolations: function() {
            var postId = $('.cleara11y-comprehensive-scan-btn').data('post-id');
            var $container = $('#cleara11y-violations-list');
            
            if (!postId) {
                $container.html('<p>Error: No post ID found</p>');
                return;
            }
            
            $container.html('<p>Loading violations...</p>');
            
            $.ajax({
                url: cleara11y_ajax.ajax_url,
                type: 'GET',
                data: {
                    action: 'cleara11y_get_scan_results',
                    post_id: postId,
                    nonce: cleara11y_ajax.scan_nonce
                },
                success: function(response) {
                    if (response.success && response.data.violations) {
                        ClearA11yAxeScanner.displayDetailedViolations(response.data.violations, $container);
                    } else {
                        $container.html('<p>No violations found. Run a scan to check for accessibility issues.</p>');
                    }
                },
                error: function() {
                    $container.html('<p>Error loading violations. Please try again.</p>');
                }
            });
        },
        
        displayDetailedViolations: function(violations, $container) {
            if (!violations || violations.length === 0) {
                $container.html('<p>No violations found. Great job!</p>');
                return;
            }
            
            var html = '';
            
            violations.forEach(function(violation) {
                html += '<div class="cleara11y-violation-item ' + violation.impact + '">';
                html += '<div class="cleara11y-violation-title">' + violation.description + '</div>';
                html += '<div class="cleara11y-violation-description">' + violation.help + '</div>';
                
                if (violation.help_url) {
                    html += '<a href="' + violation.help_url + '" target="_blank" class="cleara11y-violation-help">Learn more →</a>';
                }
                
                if (violation.target_selector) {
                    html += '<div class="cleara11y-violation-target">Target: ' + violation.target_selector + '</div>';
                }
                
                if (violation.failure_summary) {
                    html += '<div class="cleara11y-violation-target">Issue: ' + violation.failure_summary + '</div>';
                }
                
                html += '</div>';
            });
            
            $container.html(html);
        },
        
        loadHistory: function() {
            var postId = $('.cleara11y-comprehensive-scan-btn').data('post-id');
            var $container = $('#cleara11y-scan-history');
            
            $container.html('<p>Loading scan history...</p>');
            
            // This would load scan history - for now show placeholder
            setTimeout(function() {
                $container.html('<p>Scan history feature coming soon!</p>');
            }, 500);
        },
        
        updateMetaBoxSummary: function(data) {
            var $lastScan = $('.cleara11y-last-scan');
            
            if ($lastScan.length === 0) {
                // Create last scan section if it doesn't exist
                var html = '<div class="cleara11y-last-scan">';
                html += '<h4>Latest Accessibility Scan</h4>';
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
                $lastScan.find('h4').text('Latest Accessibility Scan');
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
