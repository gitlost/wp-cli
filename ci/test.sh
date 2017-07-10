#!/bin/bash

set -ex

# Run CodeSniffer
phpcs --standard=phpcs.ruleset.xml $(find . -name '*.php' -not -path "./vendor/*" -not -path "./packages/*")

# Run the unit tests
vendor/bin/phpunit

BEHAT_TAGS=$(php ci/behat-tags.php)

# Run the functional tests
vendor/bin/behat --format progress $BEHAT_TAGS --strict

if [[ $TEST_COMMANDS == 1 ]]; then
	for R in vendor/wp-cli/*-command; do
		echo "\n" $R
		BEHAT_TAGS=$(cd $R && php ci/behat-tags.php)
		vendor/bin/behat --format progress $BEHAT_TAGS --strict $R/features
	done
fi
