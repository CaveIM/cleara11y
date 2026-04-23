# JavaScript Libraries

This directory contains third-party JavaScript libraries used by ClearA11y.

## Local Libraries

### axe-core
- **File**: `axe.min.js`
- **Version**: 4.10.2
- **Source**: https://github.com/dequelabs/axe-core
- **License**: Mozilla Public License v2.0
- **Purpose**: Accessibility testing engine

### Updating Libraries

To update axe-core to a new version:

1. Download from: https://github.com/dequelabs/axe-core/releases
2. Extract `axe.min.js` from the release
3. Replace the file in this directory
4. Update the version number in:
   - `src/Frontend/Scanner.php` (wp_enqueue_script call)
   - Any comments referencing the version

### Why Local Copies?

- No external CDN dependencies (works offline)
- Better privacy (no requests to external servers)
- Version control (you control when to update)
- Performance (no DNS lookup, already on your server)
- Compliance (works in environments that block CDNs)
