Feature: Skipping themes

  Scenario: Skipping themes via global flag
    Given a WP install
    And download:
      | path                    | url                                                     |
      | {CACHE_DIR}/classic.zip | https://downloads.wordpress.org/theme/classic.1.6.zip   |
      | {CACHE_DIR}/default.zip | https://downloads.wordpress.org/theme/default.1.7.2.zip |
    And I run `wp theme install {CACHE_DIR}/classic.zip`
    And I run `wp theme install {CACHE_DIR}/default.zip --activate`

    When I run `wp eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      true
      """
    And STDERR should be empty

    # The specified theme should be skipped
    When I run `wp --skip-themes=default eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty
    
    # All themes should be skipped
    When I run `wp --skip-themes eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty
    
    # Skip another theme
    When I run `wp --skip-themes=classic eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      true
      """
    And STDERR should be empty
    
    # The specified theme should still show up as an active theme
    When I run `wp --skip-themes theme status default`
    Then STDOUT should contain:
      """
      Active
      """
    And STDERR should be empty

    # Skip several themes
    When I run `wp --skip-themes=classic,default eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty

  Scenario: Skip parent and child themes
    Given a WP install
    And download:
      | path                          | url                                                     |
      | {CACHE_DIR}/default.zip       | https://downloads.wordpress.org/theme/default.1.7.2.zip |
      | {CACHE_DIR}/default-child.zip | https://gitlostbonger.com/behat-data/default-child.zip  |
    And I run `wp theme install {CACHE_DIR}/default.zip {CACHE_DIR}/default-child.zip`

    When I run `wp theme activate default`
    When I run `wp eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      true
      """
    And STDERR should be empty

    When I run `wp --skip-themes=default eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty

    When I run `wp theme activate default-child`
    When I run `wp eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      true
      """
    And STDERR should be empty

    When I run `wp eval 'var_export( function_exists( "default_child_theme_setup" ) );'`
    Then STDOUT should be:
      """
      true
      """
    And STDERR should be empty

    When I run `wp --skip-themes=default-child eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty

    When I run `wp --skip-themes=default-child eval 'var_export( function_exists( "default_child_theme_setup" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty

    When I run `wp --skip-themes=default-child eval 'echo get_template_directory();'`
    Then STDOUT should contain:
      """
      wp-content/themes/default
      """
    And STDERR should be empty

    When I run `wp --skip-themes=default-child eval 'echo get_stylesheet_directory();'`
    Then STDOUT should contain:
      """
      wp-content/themes/default-child
      """
    And STDERR should be empty

  Scenario: Skipping multiple themes via config file
    Given a WP install
    And download:
      | path                    | url                                                     |
      | {CACHE_DIR}/classic.zip | https://downloads.wordpress.org/theme/classic.1.6.zip   |
      | {CACHE_DIR}/default.zip | https://downloads.wordpress.org/theme/default.1.7.2.zip |
    And a wp-cli.yml file:
      """
      skip-themes:
        - classic
        - default
      """
    And I run `wp theme install {CACHE_DIR}/classic.zip --activate`
    And I run `wp theme install {CACHE_DIR}/default.zip`
    
    # The classic theme should show up as an active theme
    When I run `wp theme status`
    Then STDOUT should contain:
      """
      A classic
      """
    And STDERR should be empty

    # The default theme should show up as an installed theme
    When I run `wp theme status`
    Then STDOUT should contain:
      """
      I default
      """
    And STDERR should be empty
    
    And I run `wp theme activate default`

    # The default theme should be skipped
    When I run `wp eval 'var_export( function_exists( "kubrick_head" ) );'`
    Then STDOUT should be:
      """
      false
      """
    And STDERR should be empty
