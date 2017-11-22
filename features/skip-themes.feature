Feature: Skipping themes

  Scenario: Skipping themes via global flag
    Given a WP install
    And download:
      | path                            | url                                                       |
      | {CACHE_DIR}/classic.1.6.zip     | https://downloads.wordpress.org/theme/classic.1.6.zip     |
      | {CACHE_DIR}/espied.1.2.2.zip    | https://downloads.wordpress.org/theme/espied.1.2.2.zip    |
    And I run `wp theme install {CACHE_DIR}/classic.1.6.zip`
    And I run `wp theme install {CACHE_DIR}/espied.1.2.2.zip --activate`

    When I run `wp eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      true
      """

    # The specified theme should be skipped
    When I run `wp --skip-themes=espied eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """
    
    # All themes should be skipped
    When I run `wp --skip-themes eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """
    
    # Skip another theme
    When I run `wp --skip-themes=classic eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      true
      """
    
    # The specified theme should still show up as an active theme
    When I run `wp --skip-themes theme status espied`
    Then STDOUT should contain:
      """
      Active
      """

    # Skip several themes
    When I run `wp --skip-themes=classic,espied eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """

  Scenario: Skip parent and child themes
    Given a WP install
    And download:
      | path                            | url                                                       |
      | {CACHE_DIR}/espied.1.2.2.zip    | https://downloads.wordpress.org/theme/espied.1.2.2.zip    |
      | {CACHE_DIR}/sidespied.1.0.3.zip | https://downloads.wordpress.org/theme/sidespied.1.0.3.zip |
    And I run `wp theme install {CACHE_DIR}/espied.1.2.2.zip {CACHE_DIR}/sidespied.1.0.3.zip`

    When I run `wp theme activate espied`
    When I run `wp eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      true
      """

    When I run `wp --skip-themes=espied eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """

    When I run `wp theme activate sidespied`
    When I run `wp eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      true
      """

    When I run `wp eval 'var_export( function_exists( "sidespied_scripts" ) );'`
    Then STDOUT should be:
      """
      true
      """

    When I run `wp --skip-themes=sidespied eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """

    When I run `wp --skip-themes=sidespied eval 'var_export( function_exists( "sidespied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """

    When I run `wp --skip-themes=sidespied eval 'echo get_template_directory();'`
    Then STDOUT should contain:
      """
      wp-content/themes/espied
      """

    When I run `wp --skip-themes=sidespied eval 'echo get_stylesheet_directory();'`
    Then STDOUT should contain:
      """
      wp-content/themes/sidespied
      """

  Scenario: Skipping multiple themes via config file
    Given a WP install
    And download:
      | path                            | url                                                       |
      | {CACHE_DIR}/classic.1.6.zip     | https://downloads.wordpress.org/theme/classic.1.6.zip     |
      | {CACHE_DIR}/espied.1.2.2.zip    | https://downloads.wordpress.org/theme/espied.1.2.2.zip    |
    And a wp-cli.yml file:
      """
      skip-themes:
        - classic
        - espied
      """
    And I run `wp theme install {CACHE_DIR}/classic.1.6.zip --activate`
    And I run `wp theme install {CACHE_DIR}/espied.1.2.2.zip`
    
    # The classic theme should show up as an active theme
    When I run `wp theme status`
    Then STDOUT should contain:
      """
      A classic
      """

    # The espied theme should show up as an installed theme
    When I run `wp theme status`
    Then STDOUT should contain:
      """
      I espied
      """
    
    And I run `wp theme activate espied`

    # The espied theme should be skipped
    When I run `wp eval 'var_export( function_exists( "espied_scripts" ) );'`
    Then STDOUT should be:
      """
      false
      """
