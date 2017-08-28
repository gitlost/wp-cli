#!/bin/bash

set -ex

# Run CodeSniffer
phpcs

# Run the unit tests
phpunit

BEHAT_TAGS=$(php ci/behat-tags.php)

if [[ $TEST_COMMANDS -eq 0 || $((TEST_COMMANDS & 1)) > 0 ]]; then
	# Run the functional tests
	vendor/bin/behat --format progress $BEHAT_TAGS --strict
fi

if [[ $((TEST_COMMANDS & 2)) > 0 ]]; then
	# Run the functional tests of commands.
	TEST_COMMANDS_GLOB=${TEST_COMMANDS_GLOB-*-command}
	for R in vendor/wp-cli/$TEST_COMMANDS_GLOB; do
		if [[ -f "$R/behat.yml" ]]; then BEHAT_YML="--config $R/behat.yml"; fi
		BEHAT_TAGS=$(cd $R && php ../../../ci/behat-tags.php)
		vendor/bin/behat --format progress $BEHAT_YML $BEHAT_TAGS --strict $R/features
	done
fi
