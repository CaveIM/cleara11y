/**
 * ClearA11y Frontend JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        ClearA11yFrontend.init();
    });
    
    var ClearA11yFrontend = {
        
        violations: [],
        highlightsVisible: false,
        panel: null,
        tooltip: null,
        
        init: function() {
            this.loadScanData();
            this.createToggleButton();
            this.createPanel();
            this.bindEvents();
        },
        
        loadScanData: function() {
            if (typeof cleara11y_scan_data !== 'undefined' && cleara11y_scan_data.violations) {
                this.violations = cleara11y_scan_data.violations;
            }
        },
        
        createToggleButton: function() {
            if (this.violations.length === 0) {
                return;
            }
            
            var $toggle = $('<button class="cleara11y-toggle" title="Toggle Accessibility Issues">')
                .html('⚠')
                .appendTo('body');
            
            this.$toggle = $toggle;
        },
        
        createPanel: function() {
            if (this.violations.length === 0) {
                return;
            }
            
            var panelHtml = this.buildPanelHtml();
            var $panel = $(panelHtml).appendTo('body');
            
            this.panel = $panel;
        },
        
        buildPanelHtml: function() {
            var html = '<div class="cleara11y-panel">';
            
            // Header
            html += '<div class="cleara11y-panel-header">';
            html += '<h2 class="cleara11y-panel-title">Accessibility Issues</h2>';
            html += '<button class="cleara11y-panel-close" title="Close Panel">&times;</button>';
            html += '</div>';
            
            // Content
            html += '<div class="cleara11y-panel-content">';
            
            // Summary
            var violationCount = this.violations.length;
            html += '<div class="cleara11y-panel-summary ' + (violationCount > 0 ? 'has-violations' : 'no-violations') + '">';
            
            if (violationCount > 0) {
                html += '<h3>' + violationCount + ' Accessibility Issues Found</h3>';
                html += '<p>Click on an issue below to highlight it on the page.</p>';
            } else {
                html += '<h3>No Issues Found</h3>';
                html += '<p>Great job! No accessibility violations were detected.</p>';
            }
            
            html += '</div>';
            
            // Violations list
            if (violationCount > 0) {
                html += '<ul class="cleara11y-panel-violations">';
                
                this.violations.forEach(function(violation, index) {
                    html += '<li class="cleara11y-panel-violation impact-' + violation.impact + '" data-violation-index="' + index + '">';
                    html += '<div class="cleara11y-panel-violation-title">' + violation.description + '</div>';
                    html += '<div class="cleara11y-panel-violation-description">' + violation.help + '</div>';
                    html += '<span class="cleara11y-panel-violation-impact impact-' + violation.impact + '">' + violation.impact + '</span>';
                    html += '</li>';
                });
                
                html += '</ul>';
            }
            
            html += '</div>';
            html += '</div>';
            
            return html;
        },
        
        bindEvents: function() {
            var self = this;
            
            // Toggle button click
            if (this.$toggle) {
                this.$toggle.on('click', function() {
                    self.togglePanel();
                });
            }
            
            // Panel close button
            if (this.panel) {
                this.panel.find('.cleara11y-panel-close').on('click', function() {
                    self.closePanel();
                });
                
                // Violation item click
                this.panel.find('.cleara11y-panel-violation').on('click', function() {
                    var index = $(this).data('violation-index');
                    self.highlightViolation(index);
                });
            }
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Escape key to close panel
                if (e.key === 'Escape' && self.panel && self.panel.hasClass('open')) {
                    self.closePanel();
                }
                
                // Alt + A to toggle panel
                if (e.altKey && e.key === 'a') {
                    e.preventDefault();
                    self.togglePanel();
                }
            });
            
            // Mouse events for tooltips
            $(document).on('mouseenter', '.cleara11y-highlight', function(e) {
                self.showTooltip($(this), e);
            });
            
            $(document).on('mouseleave', '.cleara11y-highlight', function() {
                self.hideTooltip();
            });
        },
        
        togglePanel: function() {
            if (!this.panel) {
                return;
            }
            
            if (this.panel.hasClass('open')) {
                this.closePanel();
            } else {
                this.openPanel();
            }
        },
        
        openPanel: function() {
            if (!this.panel) {
                return;
            }
            
            this.panel.addClass('open');
            this.$toggle.addClass('panel-open');
            
            // Show highlights
            this.showHighlights();
        },
        
        closePanel: function() {
            if (!this.panel) {
                return;
            }
            
            this.panel.removeClass('open');
            this.$toggle.removeClass('panel-open');
            
            // Hide highlights
            this.hideHighlights();
        },
        
        showHighlights: function() {
            var self = this;
            
            this.violations.forEach(function(violation, index) {
                if (violation.targets && violation.targets.length > 0) {
                    violation.targets.forEach(function(target) {
                        try {
                            var $elements = $(target);
                            $elements.addClass('cleara11y-highlight impact-' + violation.impact)
                                    .attr('data-violation-index', index);
                        } catch (e) {
                            console.warn('Could not highlight element with selector:', target);
                        }
                    });
                }
            });
            
            this.highlightsVisible = true;
        },
        
        hideHighlights: function() {
            $('.cleara11y-highlight').removeClass('cleara11y-highlight impact-critical impact-serious impact-moderate impact-minor')
                                     .removeAttr('data-violation-index');
            
            this.highlightsVisible = false;
            this.hideTooltip();
        },
        
        highlightViolation: function(index) {
            var violation = this.violations[index];
            
            if (!violation || !violation.targets) {
                return;
            }
            
            // Remove previous focus highlights
            $('.cleara11y-highlight-focus').removeClass('cleara11y-highlight-focus');
            
            // Add focus highlight to this violation's elements
            violation.targets.forEach(function(target) {
                try {
                    var $elements = $(target);
                    $elements.addClass('cleara11y-highlight-focus');
                    
                    // Scroll to first element
                    if ($elements.length > 0) {
                        $elements.first()[0].scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                } catch (e) {
                    console.warn('Could not focus element with selector:', target);
                }
            });
            
            // Update panel selection
            this.panel.find('.cleara11y-panel-violation').removeClass('selected');
            this.panel.find('.cleara11y-panel-violation[data-violation-index="' + index + '"]').addClass('selected');
        },
        
        showTooltip: function($element, event) {
            var violationIndex = $element.attr('data-violation-index');
            
            if (!violationIndex || !this.violations[violationIndex]) {
                return;
            }
            
            var violation = this.violations[violationIndex];
            
            // Create tooltip if it doesn't exist
            if (!this.tooltip) {
                this.tooltip = $('<div class="cleara11y-tooltip">').appendTo('body');
            }
            
            // Build tooltip content
            var tooltipHtml = '<div class="cleara11y-tooltip-title">' + violation.description + '</div>';
            tooltipHtml += '<div class="cleara11y-tooltip-description">' + violation.help + '</div>';
            
            if (violation.helpUrl) {
                tooltipHtml += '<div class="cleara11y-tooltip-help">Click for more information</div>';
            }
            
            this.tooltip.html(tooltipHtml);
            
            // Position tooltip
            var mouseX = event.pageX || event.originalEvent.pageX;
            var mouseY = event.pageY || event.originalEvent.pageY;
            
            this.tooltip.css({
                left: mouseX + 10,
                top: mouseY - this.tooltip.outerHeight() - 10
            }).addClass('show');
        },
        
        hideTooltip: function() {
            if (this.tooltip) {
                this.tooltip.removeClass('show');
            }
        }
    };
    
    // Add CSS for focus highlighting
    $('<style>')
        .prop('type', 'text/css')
        .html('.cleara11y-highlight-focus { animation: cleara11y-pulse 2s infinite; } @keyframes cleara11y-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }')
        .appendTo('head');
    
    // Export for use in other scripts
    window.ClearA11yFrontend = ClearA11yFrontend;
    
})(jQuery);
