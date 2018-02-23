Feature: Runner WP-CLI

  Scenario: Path argument should be slashed correctly
  When I mistakenly try `wp no-such-command --path=/foo --debug`
  Then STDERR should contain:
    """
    ABSPATH defined: /foo/
    """

  When I mistakenly try `wp no-such-command --path=/foo/ --debug`
  Then STDERR should contain:
    """
    ABSPATH defined: /foo/
    """

  When I mistakenly try `wp no-such-command --path=/foo\\ --debug`
  Then STDERR should contain:
    """
    ABSPATH defined: /foo/
    """
  And the return code should be 1

