#!/bin/bash

set -ex

# Run CodeSniffer
phpcs --standard=phpcs.ruleset.xml $(find . -name '*.php' -not -path "./vendor/*" -not -path "./packages/*")

# Run the unit tests
vendor/bin/phpunit

BEHAT_TAGS=$(php ci/behat-tags.php)

if [[ $TEST_COMMANDS -eq 0 || $((TEST_COMMANDS & 1)) > 0 ]]; then
	# Run the functional tests
	vendor/bin/behat --format progress $BEHAT_TAGS --strict
fi

if [[ $((TEST_COMMANDS & 2)) > 0 ]]; then
	for R in vendor/wp-cli/[a-h]*-command; do
		BEHAT_TAGS=$(cd $R && php ../../../ci/behat-tags.php); vendor/bin/behat --format progress $BEHAT_TAGS --strict $R/features
	done
fi

if [[ $((TEST_COMMANDS & 4)) > 0 ]]; then
	for R in vendor/wp-cli/[i-z]*-command; do
		BEHAT_TAGS=$(cd $R && php ../../../ci/behat-tags.php); vendor/bin/behat --format progress $BEHAT_TAGS --strict $R/features
	done
fi
