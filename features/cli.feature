Feature: `wp cli` tasks

  Scenario: Ability to detect a WP-CLI registered command
    Given a WP installation

    When I run `wp package install wp-cli/scaffold-package-command`
    When I run `wp cli has-command scaffold package`
    Then the return code should be 0

    When I run `wp package uninstall wp-cli/scaffold-package-command`
    When I try `wp cli has-command scaffold package`
    Then the return code should be 1

  Scenario: Ability to detect a command which is registered by plugin
    Given a WP installation
    And a wp-content/mu-plugins/test-cli.php file:
      """
      <?php
      // Plugin Name: Test CLI Help

      class TestCommand {
      }

      WP_CLI::add_command( 'test-command', 'TestCommand' );
      """

    When I run `wp cli has-command test-command`
    Then the return code should be 0

  Scenario: Ability to set a custom version when building
    Given an empty directory
    And save the {SRC_DIR}/VERSION file as {TRUE_VERSION}
    And a new Phar with version "1.2.3" and cli build

    When I run `{PHAR_PATH} cli version`
    Then STDOUT should be:
    """
    WP-CLI 1.2.3
    """
    And the {SRC_DIR}/VERSION file should be:
    """
    {TRUE_VERSION}
    """

  @github-api
  Scenario: Check for updates
    Given an empty directory
    And a new Phar with version "0.0.0" and cli build

    When I run `{PHAR_PATH} cli check-update`
    Then STDOUT should contain:
    """
    package_url
    """

  @github-api
  Scenario: Do WP-CLI Update
    Given an empty directory
    And a new Phar with version "0.0.0" and cli build

    When I run `{PHAR_PATH} --info`
    Then STDOUT should contain:
      """
      WP-CLI version
      """
    And STDOUT should contain:
      """
      0.0.0
      """

    When I run `{PHAR_PATH} cli update --yes`
    Then STDOUT should contain:
      """
      md5 hash verified:
      """
    And STDOUT should contain:
    """
    Success:
    """

    When I run `{PHAR_PATH} --info`
    Then STDOUT should contain:
      """
      WP-CLI version
      """
    And STDOUT should not contain:
      """
      0.0.0
      """

    When I run `{PHAR_PATH} cli update`
    Then STDOUT should be:
      """
      Success: WP-CLI is at the latest version.
      """

  @github-api
  Scenario: Patch update from 0.14.0 to 0.14.1
    Given an empty directory
    And a new Phar with version "0.14.0" and cli build

    When I run `{PHAR_PATH} --version`
    Then STDOUT should be:
      """
      WP-CLI 0.14.0
      """

    When I run `{PHAR_PATH} cli update --patch --yes`
    Then STDOUT should contain:
      """
      md5 hash verified: 3f5fa2fda8457a9a5dc9875f17a3716d
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to 0.14.1
      """

    When I run `{PHAR_PATH} --version`
    Then STDOUT should be:
      """
      WP-CLI 0.14.1
      """

  @github-api
  Scenario: Not a patch update from 0.14.0
    Given an empty directory
    And a new Phar with version "0.14.0" and cli build

    When I run `{PHAR_PATH} cli update --no-patch --yes`
    Then STDOUT should contain:
    """
    Success:
    """
    And STDOUT should not contain:
    """
    0.14.1
    """

  @github-api
  Scenario: Install WP-CLI nightly
    Given an empty directory
    And a new Phar with version "0.14.0" and cli build

    When I run `{PHAR_PATH} cli update --nightly --yes`
    Then STDOUT should contain:
      """
      md5 hash verified:
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to the latest nightly release.
      """

  @github-api
  Scenario: Install WP-CLI stable
    Given an empty directory
    And a new Phar with version "0.14.0" and cli build
    And a session file:
      """
      y
      """

    When I run `{PHAR_PATH} cli check-update --field=version | head -1`
    Then STDOUT should not be empty
    And save STDOUT as {UPDATE_VERSION}

    When I run `{PHAR_PATH} cli update --stable < session`
    Then STDOUT should contain:
      """
      You have version 0.14.0. Would you like to update to the latest stable release? [y/n]
      """
    And STDOUT should contain:
      """
      md5 hash verified:
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to the latest stable release.
      """

    When I run `{PHAR_PATH} cli check-update`
    Then STDOUT should be:
      """
      Success: WP-CLI is at the latest version.
      """

    When I run `{PHAR_PATH} cli version`
    Then STDOUT should be:
      """
      WP-CLI {UPDATE_VERSION}
      """

  @github-api
  Scenario: Install WP-CLI via auto-update
    Given an empty directory
    And an empty cache
    And a new Phar with version "0.14.0" and cli build
    And a session_no file:
      """
      n
      """
    And a session_yes_no file:
      """
      y
      n
      """
    And a session_yes_yes file:
      """
      y
      y
      """

    When I run `{PHAR_PATH} cli check-update --field=version | head -1`
    Then STDOUT should not be empty
    And save STDOUT as {UPDATE_VERSION}

    Given a {SUITE_CACHE_DIR}/wp-cli-update-check file:
      """
      1
      """
    When I run `SHELL_PIPE=0 {PHAR_PATH} help < session_no`
    Then STDOUT should contain:
      """
      You have version 0.14.0. Would you like to check if an update of WP-CLI is available? [y/n]
      """
    And STDOUT should contain:
      """
      Continuing with your current command 'help'...
      """
    And STDOUT should contain:
      """
      wp <command>
      """
    And STDOUT should not contain:
      """
      Checking for an update
      """

    Given a {SUITE_CACHE_DIR}/wp-cli-update-check file:
      """
      1
      """
    When I run `SHELL_PIPE=0 {PHAR_PATH} help < session_yes_no`
    Then STDOUT should contain:
      """
      You have version 0.14.0. Would you like to check if an update of WP-CLI is available? [y/n]
      """
    And STDOUT should contain:
      """
      Checking for an update of WP-CLI...
      Update found - invoking 'cli update' (please note that if you decide to update, your current command 'help' will not complete)...
      """
    And STDOUT should contain:
      """
      You have version 0.14.0. Would you like to update to {UPDATE_VERSION}? [y/n]
      """
    And STDOUT should contain:
      """
      Continuing with your current command 'help'...
      """
    And STDOUT should contain:
      """
      wp <command>
      """
    And STDOUT should not contain:
      """
      Downloading
      """

    Given a {SUITE_CACHE_DIR}/wp-cli-update-check file:
      """
      1
      """
    When I run `SHELL_PIPE=0 {PHAR_PATH} help < session_yes_yes`
    Then STDOUT should contain:
      """
      You have version 0.14.0. Would you like to check if an update of WP-CLI is available? [y/n]
      """
    And STDOUT should contain:
      """
      Checking for an update of WP-CLI...
      Update found - invoking 'cli update' (please note that if you decide to update, your current command 'help' will not complete)...
      You have version 0.14.0. Would you like to update to {UPDATE_VERSION}? [y/n]
      """
    And STDOUT should contain:
      """
      Downloading from https://github.com/wp-cli/wp-cli/releases/download/v{UPDATE_VERSION}/wp-cli-{UPDATE_VERSION}.phar...
      """
    And STDOUT should contain:
      """
      New version works. Proceeding to replace.
      """
    And STDOUT should contain:
      """
      Success:
      """
    And STDOUT should not contain:
      """
      Continuing
      """
    And STDOUT should not contain:
      """
      wp <command>
      """

    When I run `{PHAR_PATH} cli check-update`
    Then STDOUT should be:
      """
      Success: WP-CLI is at the latest version.
      """

  Scenario: Dump the list of global parameters with values
    Given a WP installation

    When I run `wp cli param-dump --with-values | grep -o '"current":' | uniq -c | tr -d ' '`
    Then STDOUT should be:
      """
      17"current":
      """
