# ClearA11y WordPress Plugin

A comprehensive accessibility checker for WordPress that helps you identify and fix accessibility issues on your website. ClearA11y provides both local scanning capabilities and integration with remote SaaS services for advanced monitoring.

## Features

### Local Accessibility Scanning
- **One-off Scanning**: Scan individual pages and posts directly from the editor
- **Bulk Scanning**: Scan multiple posts/pages at once with background processing
- **Real-time Results**: Get detailed accessibility violation reports with remediation guidance
- **Visual Highlighting**: See accessibility issues highlighted directly on your frontend pages
- **Multiple Standards**: Support for WCAG 2.1 AA/AAA and WCAG 2.2 AA/AAA

### User-Friendly Interface
- **Post Editor Integration**: Scan button directly in the post/page editor sidebar
- **Admin Bar Integration**: Quick scan access from the frontend admin bar
- **Admin Dashboard**: Comprehensive overview of site accessibility status
- **Results History**: Keep track of scan results over time (configurable retention period)

### Flexible Configuration
- **Configurable Post Types**: Choose which post types to scan
- **Permission Controls**: Control who can perform accessibility scans
- **Retention Settings**: Configure how long to keep scan results
- **Frontend Highlighting**: Enable/disable visual issue highlighting

## Installation

1. Upload the `cleara11y` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings under **ClearA11y > Settings**

## Usage

### Scanning Individual Posts/Pages

#### From the Post Editor
1. Edit any published post or page
2. Look for the "Accessibility Scanner" meta box in the sidebar
3. Click "Scan for Issues" to run an accessibility check
4. View results and click "View Details" for comprehensive violation information

#### From the Frontend (Admin Bar)
1. While logged in and viewing a published post/page
2. Click "Scan Accessibility" in the admin bar
3. Results will appear as a notification
4. Page will reload to show visual highlighting of issues

### Bulk Scanning
1. Go to **ClearA11y > Bulk Scanner**
2. Select post types to scan
3. Choose post status (published only or all)
4. Click "Start Bulk Scan"
5. Scan runs in background - you'll receive email notification when complete

### Viewing Results
1. Go to **ClearA11y > Scan Results** for detailed reports
2. Check the A11y Status column in your post/page lists
3. Use the main dashboard for site-wide accessibility statistics

### Frontend Issue Highlighting
When enabled, users with edit permissions can:
- See accessibility issues highlighted on frontend pages
- Hover over highlighted elements for quick issue descriptions
- Use the accessibility panel (toggle button on right side) to navigate between issues
- Use keyboard shortcut `Alt + A` to toggle the accessibility panel

## Configuration

### Settings Page
Access settings at **ClearA11y > Settings**:

- **Accessibility Standard**: Choose WCAG version and compliance level
- **Post Types to Scan**: Select which post types should be scannable
- **Results Retention**: How many days to keep old scan results
- **Frontend Highlighting**: Enable/disable visual issue highlighting

### Default Settings
- **Standard**: WCAG 2.1 AA
- **Post Types**: Pages and Posts
- **Retention**: 30 days
- **Frontend Highlighting**: Enabled

## Technical Details

### Database Tables
The plugin creates two custom tables:
- `wp_cleara11y_scans`: Stores scan metadata and results
- `wp_cleara11y_violations`: Stores detailed violation information

### Accessibility Scanning
Currently uses a custom scanning engine that checks for:
- Missing alt text on images
- Improper heading hierarchy
- Form elements without labels
- Color contrast issues (planned)
- Keyboard navigation issues (planned)

*Note: Full axe-core integration is planned for future releases to provide comprehensive WCAG compliance checking.*

### Performance Considerations
- Scans are performed asynchronously to avoid blocking the admin interface
- Bulk scans run in background using WordPress cron system
- Results are cached to avoid repeated scans of unchanged content
- Old scan results are automatically cleaned up based on retention settings

## Permissions

### Default Permissions
- **Single Scans**: Users who can edit the specific post/page
- **Bulk Scans**: Users with `manage_options` capability (typically administrators)
- **Settings**: Users with `manage_options` capability

### Custom Permissions
The plugin respects WordPress's built-in capability system and can be extended with custom role management plugins.

## Hooks and Filters

### Actions
- `cleara11y_scan_complete`: Fired after a successful scan
- `cleara11y_bulk_scan_complete`: Fired after bulk scan completion

### Filters
- `cleara11y_scan_post_types`: Modify which post types are available for scanning
- `cleara11y_scan_results`: Filter scan results before saving
- `cleara11y_violation_severity`: Modify violation impact levels

## Roadmap

### Planned Features
- **Full axe-core Integration**: Complete WCAG compliance checking
- **Advanced Reporting**: PDF reports and detailed analytics
- **SaaS Integration**: Connect with ClearA11y remote service
- **Automated Scanning**: Schedule regular scans
- **Color Contrast Checking**: Advanced color accessibility analysis
- **Keyboard Navigation Testing**: Automated keyboard accessibility checks

### Future SaaS Features
- Daily automated scanning
- Historical trend analysis
- Multi-site management
- Advanced reporting and analytics
- Manual accessibility auditing
- Priority support

## Support

For support, feature requests, or bug reports:
- Create an issue on our GitHub repository
- Contact support at support@cleara11y.com
- Visit our documentation at https://docs.cleara11y.com

## Contributing

We welcome contributions! Please see our contributing guidelines and submit pull requests to our GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### Version 1.0.0
- Initial release
- Local accessibility scanning
- Post editor integration
- Admin bar integration
- Bulk scanning capabilities
- Frontend issue highlighting
- Configurable settings
- Results history and retention
