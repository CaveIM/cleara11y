# ClearA11y Devcontainer

This devcontainer runs a disposable WordPress site for plugin development.

- WordPress: http://localhost:8888
- phpMyAdmin: http://localhost:8081
- Admin user: `admin`
- Admin password: `password`
- Database user/password/name: `wordpress` / `wordpress` / `wordpress`

The plugin repo is mounted into WordPress at:

```text
/var/www/html/wp-content/plugins/cleara11y
```

The same repo is also available as the devcontainer workspace at:

```text
/workspaces/cleara11y
```

Useful commands from inside the devcontainer:

```sh
node --version
npm --version
wp plugin status cleara11y --allow-root
wp plugin activate cleara11y --allow-root
wp option get siteurl --allow-root
```

Node.js 20 and npm are installed for plugin build steps and OpenCode/Nifty plugin tooling. The npm cache is persisted in a Docker volume mounted at:

```text
/root/.npm
```

OpenCode is installed in the container. Its auth, sessions, state, config, and installed plugin data are persisted in Docker volumes mounted at:

```text
/root/.config/opencode
/root/.local/share/opencode
/root/.local/state/opencode
```

To reset the disposable WordPress site, remove the Compose volumes for this devcontainer and rebuild/reopen it.
