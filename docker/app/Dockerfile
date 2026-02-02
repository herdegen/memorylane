FROM docker.io/library/php:8.3-fpm-alpine

# Arguments
ARG UID=1000
ARG GID=1000

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    imagemagick-dev \
    imagemagick \
    ffmpeg \
    redis \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    gd \
    zip \
    exif \
    pcntl \
    bcmath \
    intl \
    opcache

# Install Redis extension
RUN pecl install redis && \
    docker-php-ext-enable redis

# Install Imagick extension
RUN pecl install imagick && \
    docker-php-ext-enable imagick

# Install Composer (compatible avec Podman)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# Install Node.js and npm
RUN apk add --no-cache nodejs npm

# Create application user
RUN addgroup -g ${GID} -S memorylane && \
    adduser -u ${UID} -S memorylane -G memorylane

# Set working directory
WORKDIR /var/www/html

# Set proper permissions
RUN chown -R memorylane:memorylane /var/www/html

# Switch to application user
USER memorylane

# Expose port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]
