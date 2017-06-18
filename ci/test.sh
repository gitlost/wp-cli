#!/bin/bash

set -ex

# Run CodeSniffer
phpcs --standard=phpcs.ruleset.xml $(find . -name '*.php' -not -path "./vendor/*" -not -path "./packages/*")

# Run the unit tests
vendor/bin/phpunit

BEHAT_TAGS=$(php ci/behat-tags.php)

# Run the functional tests
vendor/bin/behat --format progress $BEHAT_TAGS --strict

# Run the functional tests with opcache.save_comments disabled.
if [[ $TRAVIS_PHP_VERSION = '7.1' && $WP_VERSION = 'latest' ]]; then
	WP_CLI_PHP_ARGS='-dopcache.enable_cli=1 -dopcache.save_comments=0' vendor/bin/behat --format progress "$BEHAT_TAGS&&~@require-opcache-save-comments" --strict
fi
