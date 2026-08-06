#!/bin/sh
set -eu

cd /var/www/html

WORDPRESS_DEV_URL="${WORDPRESS_URL:-http://localhost:8888}"

mkdir -p /commandhistory /root/.npm /root/.codex /root/.local/share/opencode /root/.local/state/opencode /root/.config/opencode
touch /commandhistory/.bash_history

db_attempt=1
db_max_attempts=15

until timeout 3 wp db query 'SELECT 1' --skip-column-names --quiet --allow-root >/dev/null 2>&1; do
	if [ "$db_attempt" -ge "$db_max_attempts" ]; then
		printf 'WordPress could not connect to the database after %s attempts.\n' "$db_max_attempts" >&2
		printf 'Database connection details: host=%s database=%s user=%s\n' \
			"${WORDPRESS_DB_HOST:-not set}" \
			"${WORDPRESS_DB_NAME:-not set}" \
			"${WORDPRESS_DB_USER:-not set}" >&2
		if ! getent hosts "${WORDPRESS_DB_HOST%%:*}" >/dev/null 2>&1; then
			printf 'The database host cannot be resolved from the WordPress container. Rebuild the devcontainer to restore its Compose network.\n' >&2
		fi
		timeout 5 wp db query 'SELECT 1' --skip-column-names --allow-root || true
		exit 1
	fi

	printf 'Waiting for WordPress database...\n'
	sleep 1
	db_attempt=$((db_attempt + 1))
done

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
	wp core install \
		--url="$WORDPRESS_DEV_URL" \
		--title="ClearA11y Dev" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email \
		--allow-root
fi

wp option update home "$WORDPRESS_DEV_URL" --allow-root >/dev/null
wp option update siteurl "$WORDPRESS_DEV_URL" --allow-root >/dev/null

wp plugin activate cleara11y --allow-root
wp rewrite structure '/%postname%/' --allow-root
