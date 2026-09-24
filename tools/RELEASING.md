# Publishing Cleara11y

GitHub Actions publishes to https://plugins.svn.wordpress.org/cleara11y when
a `vMAJOR.MINOR.PATCH` tag is pushed. Ordinary branch pushes do not publish.
The action updates `trunk`, copies it to the versioned SVN tag, and uploads
the banners, icons and screenshots from `wordpress-org` to SVN `assets`.

## One-time credentials

Enable two-factor authentication on the `cleara11y` WordPress.org account,
then create its separate SVN password in Account & Security. Store that
password in 1Password and in this repository's GitHub Actions secret
`SVN_PASSWORD`. Set `SVN_USERNAME` to `cleara11y`. Never commit credentials.

## Each release

1. Update the plugin header/version constant and `readme.txt` stable tag and
   changelog. Update `tools/release-files.json` if production files change.
2. Run appropriate regression checks and `python3 tools/build-release.py`.
   Test that exact ZIP with Plugin Check and a disposable WordPress site.
3. Commit the source, ZIP, checksum and manifest. The workflow refuses to
   publish if the ZIP differs from the source or production file allowlist.
4. Optionally run **Publish to WordPress.org** manually on the release branch
   with **dry_run** checked to preview SVN changes without publishing.
5. Push the release commit and a matching tag, for example `v1.6.2`.
6. Check the Actions run and https://wordpress.org/plugins/cleara11y/.
   Directory caches and search indexing may take time to update.

A failed deployment can be rerun from Actions. A manual live run must target
the release tag and have **dry_run** unchecked. The deploy action skips SVN
versions that already exist; never overwrite an existing released version.

The workflow uses pinned action revisions and read-only GitHub permissions.
Its deployment directory contains only the validated ZIP contents; tests,
development files, and credentials are excluded by the production allowlist.
