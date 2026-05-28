FROM wordpress:php8.2-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		default-mysql-client \
		git \
		jq \
		less \
		unzip \
	&& rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
	&& apt-get install -y --no-install-recommends nodejs \
	&& npm --version \
	&& node --version \
	&& rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp \
	&& wp --info --allow-root

RUN curl -fsSL https://opencode.ai/install | bash \
	&& ln -sf /root/.opencode/bin/opencode /usr/local/bin/opencode

ENV PATH="/root/.opencode/bin:${PATH}"
