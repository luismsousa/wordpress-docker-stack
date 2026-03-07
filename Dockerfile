FROM wordpress:latest

RUN curl -sSLf https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php -o /tmp/datadog-setup.php \
    && php /tmp/datadog-setup.php --php-bin=all --enable-appsec --enable-profiling 2>&1 \
    && rm -f /tmp/datadog-setup.php

RUN pecl install redis \
    && docker-php-ext-enable redis
