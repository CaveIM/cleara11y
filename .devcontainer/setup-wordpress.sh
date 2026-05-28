#!/bin/sh
set -eu

cd /var/www/html

WORDPRESS_DEV_URL="${WORDPRESS_URL:-http://localhost:8888}"

mkdir -p /commandhistory /root/.npm /root/.local/share/opencode /root/.local/state/opencode /root/.config/opencode
touch /commandhistory/.bash_history

until wp db check --allow-root >/dev/null 2>&1; do
	printf 'Waiting for WordPress database...\n'
	sleep 2
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
