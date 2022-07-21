#!/bin/sh

# Get dependencies
curl https://getcomposer.org/download/2.3.10/composer.phar -o /tmp/composer.phar
chmod +x /tmp/composer.phar

/tmp/composer.phar install --no-interaction --no-progress --no-plugins

# Run PHP Insights
./vendor/bin/phpinsights analyse --no-ansi --no-interaction --format='RafaelYon\PhpInsightsReviewer\GithubFormatter' src/