#!/bin/bash

set -ex

# Run CodeSniffer
phpcs --standard=phpcs.ruleset.xml $(find . -name '*.php' -not -path "./vendor/*" -not -path "./packages/*")

# Run the unit tests
vendor/bin/phpunit

BEHAT_TAGS=$(php ci/behat-tags.php)

if [[ $TEST_COMMANDS -ne 1 ]]; then
	# Run the functional tests
	vendor/bin/behat --format progress $BEHAT_TAGS --strict
fi

if [[ $TEST_COMMANDS -eq 1 || $TEST_COMMANDS -eq 2 ]]; then
	for R in vendor/wp-cli/*-command; do
		BEHAT_TAGS=$(cd $R && php ../../../ci/behat-tags.php); vendor/bin/behat --format progress $BEHAT_TAGS --strict $R/features
	done
fi
