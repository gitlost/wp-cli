<?php

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Event\SuiteEvent;

use \WP_CLI\Process;
use \WP_CLI\Utils;

use Symfony\Component\Filesystem\Filesystem,
	Symfony\Component\Filesystem\Exception\IOExceptionInterface;

// Inside a community package
if ( file_exists( __DIR__ . '/utils.php' ) ) {
	require_once __DIR__ . '/utils.php';
	require_once __DIR__ . '/Process.php';
	require_once __DIR__ . '/ProcessRun.php';
	$project_composer = dirname( dirname( dirname( __FILE__ ) ) ) . '/composer.json';
	if ( file_exists( $project_composer ) ) {
		$composer = json_decode( file_get_contents( $project_composer ) );
		if ( ! empty( $composer->autoload->files ) ) {
			$contents = 'require:' . PHP_EOL;
			foreach( $composer->autoload->files as $file ) {
				$contents .= '  - ' . dirname( dirname( dirname( __FILE__ ) ) ) . '/' . $file . PHP_EOL;
			}
			@mkdir( sys_get_temp_dir() . '/wp-cli-package-test/' );
			$project_config = sys_get_temp_dir() . '/wp-cli-package-test/config.yml';
			file_put_contents( $project_config, $contents );
			putenv( 'WP_CLI_CONFIG_PATH=' . $project_config );
		}
	}
// Inside WP-CLI
} else {
	require_once __DIR__ . '/../../php/utils.php';
	require_once __DIR__ . '/../../php/WP_CLI/Process.php';
	require_once __DIR__ . '/../../php/WP_CLI/ProcessRun.php';
	if ( file_exists( __DIR__ . '/../../vendor/autoload.php' ) ) {
		require_once __DIR__ . '/../../vendor/autoload.php';
	} else if ( file_exists( __DIR__ . '/../../../../autoload.php' ) ) {
		require_once __DIR__ . '/../../../../autoload.php';
	}
}

/**
 * Features context.
 */
class FeatureContext extends BehatContext implements ClosuredContextInterface {

	private static $cache_dir, $suite_cache_dir, $install_cache_dir, $composer_local_repository, $fs;

	private static $scenario_run_times = array();

	private static $db_settings = array(
		'dbname' => 'wp_cli_test',
		'dbuser' => 'wp_cli_test',
		'dbpass' => 'password1',
		'dbhost' => '127.0.0.1',
	);

	private $running_procs = array();

	public $variables = array();

	/**
	 * Get the environment variables required for launched `wp` processes
	 * @BeforeSuite
	 */
	private static function get_process_env_variables() {
		// Ensure we're using the expected `wp` binary
		$bin_dir = getenv( 'WP_CLI_BIN_DIR' ) ?: realpath( __DIR__ . '/../../bin' );
		$vendor_dir = realpath( __DIR__ . '/../../vendor/bin' );
		$env = array(
			'PATH' =>  $bin_dir . ':' . $vendor_dir . ':' . getenv( 'PATH' ),
			'BEHAT_RUN' => 1,
			'HOME' => '/tmp/wp-cli-home',
		);
		if ( $config_path = getenv( 'WP_CLI_CONFIG_PATH' ) ) {
			$env['WP_CLI_CONFIG_PATH'] = $config_path;
		}
		if ( $term = getenv( 'TERM' ) ) {
			$env['TERM'] = $term;
		}
		if ( $php_args = getenv( 'WP_CLI_PHP_ARGS' ) ) {
			$env['WP_CLI_PHP_ARGS'] = $php_args;
		}
		if ( $travis_build_dir = getenv( 'TRAVIS_BUILD_DIR' ) ) {
			$env['TRAVIS_BUILD_DIR'] = $travis_build_dir;
		}
		if ( $github_token = getenv( 'GITHUB_TOKEN' ) ) {
			$env['GITHUB_TOKEN'] = $github_token;
		}
		return $env;
	}

	// We cache the results of `wp core download` to improve test performance
	// Ideally, we'd cache at the HTTP layer for more reliable tests
	private static function cache_wp_files() {
		self::$cache_dir = sys_get_temp_dir() . '/wp-cli-test-core-download-cache';

		if ( is_readable( self::$cache_dir . '/wp-config-sample.php' ) )
			return;

		$cmd = Utils\esc_cmd( 'wp core download --force --path=%s', self::$cache_dir );
		if ( getenv( 'WP_VERSION' ) ) {
			$cmd .= Utils\esc_cmd( ' --version=%s', getenv( 'WP_VERSION' ) );
		}
		Process::create( $cmd, null, self::get_process_env_variables() )->run_check();
	}

	/**
	 * @BeforeSuite
	 */
	public static function prepare( SuiteEvent $event ) {
		$result = Process::create( 'wp cli info', null, self::get_process_env_variables() )->run_check();
		echo PHP_EOL;
		echo $result->stdout;
		echo PHP_EOL;
		self::cache_wp_files();
		$result = Process::create( Utils\esc_cmd( 'wp core version --path=%s', self::$cache_dir ) , null, self::get_process_env_variables() )->run_check();
		echo 'WordPress ' . $result->stdout;
		echo PHP_EOL;
	}

	/**
	 * @AfterSuite
	 */
	public static function afterSuite( SuiteEvent $event ) {
		if ( self::$suite_cache_dir ) {
			self::$fs->remove( self::$suite_cache_dir );
		}

		if ( self::$composer_local_repository ) {
			self::$fs->remove( self::$composer_local_repository );
		}

		if ( getenv( 'WP_CLI_TEST_LOG_RUN_TIMES' ) ) {

			list( $time, $calls ) = array_reduce( Process::$run_times, function ( $carry, $item ) {
				return array( $carry[0] + $item[0], $carry[1] + $item[1] );
			}, array( 0, 0 ) );
			$mins = floor( $time / 60 );
			$secs = round( $time - ( $mins * 60 ), 3 );
			$log = "\n\nTotal process run time=" . round( $time, 3 ) . " ({$mins}m{$secs}s), calls=$calls, unique=" . count( Process::$run_times );

			uasort( Process::$run_times, function ( $a, $b ) {
				return $a[0] === $b[0] ? 0 : ( $a[0] < $b[0] ? 1 : -1 ); // Reverse sort.
			} );
			$top = getenv( 'TRAVIS' ) ? 10 : 40;
			$tops = array_slice( Process::$run_times, 0, $top, true );
			$log .= "\n\nTop $top process run times\n" . implode( "\n", array_map( function ( $k, $v, $i ) {
				return sprintf( '%2d. %6.3f %2d %s', $i + 1, round( $v[0], 3 ), $v[1], $k );
			}, array_keys( $tops ), $tops, array_keys( array_keys( $tops ) ) ) );

			arsort( self::$scenario_run_times );
			$top = getenv( 'TRAVIS' ) ? 10 : 20;
			$tops = array_slice( self::$scenario_run_times, 0, $top, true );
			$log .= "\n\nTop $top scenario run times\n" . implode( "\n", array_map( function ( $k, $v, $i ) {
				return sprintf( '%2d. %6.3f %s', $i + 1, round( $v, 3 ), $k );
			}, array_keys( $tops ), $tops, array_keys( array_keys( $tops ) ) ) );

			if ( getenv( 'TRAVIS' ) ) {
				echo $log;
			} else {
				error_log( $log );
			}
		}
	}

	/**
	 * @BeforeScenario
	 */
	public function beforeScenario( $event ) {
		if ( getenv( 'WP_CLI_TEST_LOG_RUN_TIMES' ) && method_exists( $event, 'getScenario' ) ) {
			$scenario = $event->getScenario();
			self::$scenario_run_times[ basename( $scenario->getFile() ) . ':' . $scenario->getLine() ] = -microtime( true );
		}

		$this->variables['SRC_DIR'] = realpath( __DIR__ . '/../..' );
	}

	/**
	 * @AfterScenario
	 */
	public function afterScenario( $event ) {
		if ( isset( $this->variables['RUN_DIR'] ) ) {
			// remove altered WP install, unless there's an error
			if ( $event->getResult() < 4 && 0 === strpos( $this->variables['RUN_DIR'], sys_get_temp_dir() ) ) {
				self::$fs->remove( $this->variables['RUN_DIR'] );
			}
		}

		// Remove WP-CLI package directory
		if ( isset( $this->variables['PACKAGE_PATH'] ) ) {
			self::$fs->remove( $this->variables['PACKAGE_PATH'] );
		}

		foreach ( $this->running_procs as $proc ) {
			$status = proc_get_status( $proc );
			self::terminate_proc( $status['pid'] );
		}

		if ( getenv( 'WP_CLI_TEST_LOG_RUN_TIMES' ) && method_exists( $event, 'getScenario' ) ) {
			$scenario = $event->getScenario();
			self::$scenario_run_times[ basename( $scenario->getFile() ) . ':' . $scenario->getLine() ] += microtime( true );
			if ( count( self::$scenario_run_times ) > 20 ) {
				arsort( self::$scenario_run_times );
				array_pop( self::$scenario_run_times );
			}
		}
	}

	/**
	 * Terminate a process and any of its children.
	 */
	private static function terminate_proc( $master_pid ) {

		$output = `ps -o ppid,pid,command | grep $master_pid`;

		foreach ( explode( PHP_EOL, $output ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\s+(\d+)/', $line, $matches ) ) {
				$parent = $matches[1];
				$child = $matches[2];

				if ( $parent == $master_pid ) {
					self::terminate_proc( $child );
				}
			}
		}

		if ( ! posix_kill( (int) $master_pid, 9 ) ) {
			$errno = posix_get_last_error();
			// Ignore "No such process" error as that's what we want.
			if ( 3 /*ESRCH*/ !== $errno ) {
				throw new RuntimeException( posix_strerror( $errno ) );
			}
		}
	}

	public static function create_cache_dir() {
		self::$suite_cache_dir = sys_get_temp_dir() . '/' . uniqid( "wp-cli-test-suite-cache-", TRUE );
		mkdir( self::$suite_cache_dir );
		return self::$suite_cache_dir;
	}

	/**
	 * Initializes context.
	 * Every scenario gets it's own context object.
	 *
	 * @param array $parameters context parameters (set them up through behat.yml)
	 */
	public function __construct( array $parameters ) {
		if ( getenv( 'WP_CLI_TEST_DBUSER' ) ) {
			self::$db_settings['dbuser'] = getenv( 'WP_CLI_TEST_DBUSER' );
		}

		if ( false !== getenv( 'WP_CLI_TEST_DBPASS' ) ) {
			self::$db_settings['dbpass'] = getenv( 'WP_CLI_TEST_DBPASS' );
		}

		if ( getenv( 'WP_CLI_TEST_DBHOST' ) ) {
			self::$db_settings['dbhost'] = getenv( 'WP_CLI_TEST_DBHOST' );
		}

		$this->drop_db();
		$this->set_cache_dir();
		$this->variables['CORE_CONFIG_SETTINGS'] = Utils\assoc_args_to_str( self::$db_settings );
		if ( ! self::$fs ) {
			self::$fs = new FileSystem;
		}
	}

	public function getStepDefinitionResources() {
		return glob( __DIR__ . '/../steps/*.php' );
	}

	public function getHookDefinitionResources() {
		return array();
	}

	public function replace_variables( $str ) {
		$ret = preg_replace_callback( '/\{([A-Z_]+)\}/', array( $this, '_replace_var' ), $str );
		if ( false !== strpos( $str, '{WP_VERSION-' ) ) {
			$ret = $this->_replace_wp_versions( $ret );
		}
		return $ret;
	}

	private function _replace_var( $matches ) {
		$cmd = $matches[0];

		foreach ( array_slice( $matches, 1 ) as $key ) {
			$cmd = str_replace( '{' . $key . '}', $this->variables[ $key ], $cmd );
		}

		return $cmd;
	}

	// Substitute "{WP_VERSION-version-latest}" variables.
	private function _replace_wp_versions( $str ) {
		static $wp_versions = null;
		if ( null === $wp_versions ) {
			$wp_versions = array();

			$response = Requests::get( 'https://api.wordpress.org/core/version-check/1.7/', null, array( 'timeout' => 30 ) );
			if ( 200 === $response->status_code && ( $body = json_decode( $response->body ) ) && is_object( $body ) && isset( $body->offers ) && is_array( $body->offers ) ) {
				// Latest version alias.
				$wp_versions["{WP_VERSION-latest}"] = count( $body->offers ) ? $body->offers[0]->version : '';
				foreach ( $body->offers as $offer ) {
					$sub_ver = preg_replace( '/(^[0-9]+\.[0-9]+)\.[0-9]+$/', '$1', $offer->version );
					$sub_ver_key = "{WP_VERSION-{$sub_ver}-latest}";

					$main_ver = preg_replace( '/(^[0-9]+)\.[0-9]+$/', '$1', $sub_ver );
					$main_ver_key = "{WP_VERSION-{$main_ver}-latest}";

					if ( ! isset( $wp_versions[ $main_ver_key ] ) ) {
						$wp_versions[ $main_ver_key ] = $offer->version;
					}
					if ( ! isset( $wp_versions[ $sub_ver_key ] ) ) {
						$wp_versions[ $sub_ver_key ] = $offer->version;
					}
				}
			}
		}
		return strtr( $str, $wp_versions );
	}

	public function create_run_dir() {
		if ( !isset( $this->variables['RUN_DIR'] ) ) {
			$this->variables['RUN_DIR'] = sys_get_temp_dir() . '/' . uniqid( "wp-cli-test-run-", TRUE );
			mkdir( $this->variables['RUN_DIR'] );
		}
	}

	public function build_phar( $version = 'same', $build = '' ) {
		$this->variables['PHAR_PATH'] = $this->variables['RUN_DIR'] . '/' . uniqid( "wp-cli-build-", TRUE ) . '.phar';

		// Test running against a package installed as a WP-CLI dependency
		// WP-CLI installed as a project dependency
		$make_phar_path = __DIR__ . '/../../../../../utils/make-phar.php';
		if ( ! file_exists( $make_phar_path ) ) {
			// Test running against WP-CLI proper
			$make_phar_path = __DIR__ . '/../../utils/make-phar.php';
			if ( ! file_exists( $make_phar_path ) ) {
				// WP-CLI as a dependency of this project
				$make_phar_path = __DIR__ . '/../../vendor/wp-cli/wp-cli/utils/make-phar.php';
			}
		}

		$this->proc( Utils\esc_cmd(
			'php -dphar.readonly=0 %1$s %2$s --version=%3$s --quiet --build=%4$s && chmod +x %2$s',
			$make_phar_path,
			$this->variables['PHAR_PATH'],
			$version,
			$build
		) )->run_check();
	}

	public function download_phar( $version = 'same' ) {
		if ( 'same' === $version ) {
			$version = WP_CLI_VERSION;
		}

		$download_url = sprintf(
			'https://github.com/wp-cli/wp-cli/releases/download/v%1$s/wp-cli-%1$s.phar',
			$version
		);

		$this->variables['PHAR_PATH'] = $this->variables['RUN_DIR'] . '/'
		                                . uniqid( 'wp-cli-download-', true )
		                                . '.phar';

		Process::create( \WP_CLI\Utils\esc_cmd(
			'curl -sSfL %1$s > %2$s && chmod +x %2$s',
			$download_url,
			$this->variables['PHAR_PATH']
		) )->run_check();
	}

	private function set_cache_dir() {
		$path = sys_get_temp_dir() . '/wp-cli-test-cache';
		if ( ! file_exists( $path ) ) {
			mkdir( $path, 0777, true );
		}
		$this->variables['CACHE_DIR'] = $path;
	}

	private static function run_sql( $sql ) {
		Utils\run_mysql_command( '/usr/bin/env mysql --no-defaults', array(
			'execute' => $sql,
			'host' => self::$db_settings['dbhost'],
			'user' => self::$db_settings['dbuser'],
			'pass' => self::$db_settings['dbpass'],
		) );
	}

	public function create_db() {
		$dbname = self::$db_settings['dbname'];
		self::run_sql( "CREATE DATABASE IF NOT EXISTS $dbname" );
	}

	public function drop_db() {
		$dbname = self::$db_settings['dbname'];
		self::run_sql( "DROP DATABASE IF EXISTS $dbname" );
	}

	public function proc( $command, $assoc_args = array(), $path = '' ) {
		if ( !empty( $assoc_args ) )
			$command .= Utils\assoc_args_to_str( $assoc_args );

		$env = self::get_process_env_variables();
		if ( isset( $this->variables['SUITE_CACHE_DIR'] ) ) {
			$env['WP_CLI_CACHE_DIR'] = $this->variables['SUITE_CACHE_DIR'];
		}

		if ( isset( $this->variables['RUN_DIR'] ) ) {
			$cwd = "{$this->variables['RUN_DIR']}/{$path}";
		} else {
			$cwd = null;
		}

		return Process::create( $command, $cwd, $env );
	}

	/**
	 * Start a background process. Will automatically be closed when the tests finish.
	 */
	public function background_proc( $cmd, $sleep = 1 ) {
		$descriptors = array(
			0 => STDIN,
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$proc = proc_open( $cmd, $descriptors, $pipes, $this->variables['RUN_DIR'], self::get_process_env_variables() );

		if ( false === $proc ) {
			throw new RuntimeException( stream_get_contents( $pipes[2] ) );
		} else {
			$this->running_procs[] = $proc;
		}

		if ( $sleep ) {
			sleep( $sleep );
		}
	}

	public function move_files( $src, $dest ) {
		rename( $this->variables['RUN_DIR'] . "/$src", $this->variables['RUN_DIR'] . "/$dest" );
	}

	/**
	 * Remove a directory (recursive).
	 */
	public function remove_dir( $dir ) {
		self::$fs->remove( $dir );
	}

	public function add_line_to_wp_config( &$wp_config_code, $line ) {
		$token = "/* That's all, stop editing!";

		$wp_config_code = str_replace( $token, "$line\n\n$token", $wp_config_code );
	}

	public function download_wp( $subdir = '' ) {
		$dest_dir = $this->variables['RUN_DIR'];

		if ( $subdir ) {
			$dest_dir = $this->variables['RUN_DIR'] . "/$subdir";
			mkdir( $dest_dir );
		}

		self::$fs->mirror( self::$cache_dir, $dest_dir );

		// disable emailing
		mkdir( $dest_dir . '/wp-content/mu-plugins' );
		copy( __DIR__ . '/../extra/no-mail.php', $dest_dir . '/wp-content/mu-plugins/no-mail.php' );
	}

	public function create_config( $subdir = '', $extra_php = false ) {
		$params = self::$db_settings;

		// Replaces all characters that are not alphanumeric or an underscore into an underscore.
		$params['dbprefix'] = $subdir ? preg_replace( '#[^a-zA-Z\_0-9]#', '_', $subdir ) : 'wp_';

		$params['skip-salts'] = true;
		$params['skip-check'] = true;

		if( false !== $extra_php ) {
			$params['extra-php'] = $extra_php;
		}

		$config_cache_path = '';
		if ( self::$install_cache_dir ) {
			$config_cache_path = self::$install_cache_dir . '/config_' . md5( implode( ':', $params ) . ':subdir=' . $subdir );
			$run_dir = '' !== $subdir ? ( $this->variables['RUN_DIR'] . "/$subdir" ) : $this->variables['RUN_DIR'];
		}

		if ( $config_cache_path && file_exists( $config_cache_path ) ) {
			copy( $config_cache_path, $run_dir . '/wp-config.php' );
		} else {
			$this->proc( 'wp core config', $params, $subdir )->run_check();
			if ( $config_cache_path && file_exists( $run_dir . '/wp-config.php' ) ) {
				copy( $run_dir . '/wp-config.php', $config_cache_path );
			}
		}
	}

	public function install_wp( $subdir = '' ) {
		self::$install_cache_dir = sys_get_temp_dir() . '/wp-cli-test-core-install-cache';
		if ( ! file_exists( self::$install_cache_dir ) ) {
			mkdir( self::$install_cache_dir );
		}

		$subdir = $this->replace_variables( $subdir );

		$this->create_db();
		$this->create_run_dir();
		$this->download_wp( $subdir );
		$this->create_config( $subdir );

		$install_args = array(
			'url' => 'http://example.com',
			'title' => 'WP CLI Site',
			'admin_user' => 'admin',
			'admin_email' => 'admin@example.com',
			'admin_password' => 'password1'
		);

		$install_cache_path = '';
		if ( self::$install_cache_dir ) {
			$install_cache_path = self::$install_cache_dir . '/install_' . md5( implode( ':', $install_args ) . ':subdir=' . $subdir );
			$run_dir = '' !== $subdir ? ( $this->variables['RUN_DIR'] . "/$subdir" ) : $this->variables['RUN_DIR'];
		}

		if ( $install_cache_path && file_exists( $install_cache_path ) ) {
			self::$fs->mirror( $install_cache_path, $run_dir );
			$cmd = Utils\esc_cmd(
				'/usr/bin/env mysql --no-defaults -q -s -u%s -p%s -e%s %s',
				self::$db_settings['dbuser'], self::$db_settings['dbpass'], "source {$install_cache_path}.sql;", self::$db_settings['dbname']
			);
			Process::create( $cmd, null, self::get_process_env_variables() )->run_check();
		} else {
			$this->proc( 'wp core install', $install_args, $subdir )->run_check();
			if ( $install_cache_path ) {
				mkdir( $install_cache_path );
				self::dir_diff_cp( $run_dir, self::$cache_dir, $install_cache_path );
				$cmd = Utils\esc_cmd(
					'/usr/bin/env mysqldump --no-defaults -u%s -p%s %s > %s',
					self::$db_settings['dbuser'], self::$db_settings['dbpass'], self::$db_settings['dbname'], $install_cache_path . '.sql'
				);
				Process::create( $cmd, null, self::get_process_env_variables() )->run_check();
			}
		}
	}

	/**
	 * Copy files in updated directory that are not in source directory to copy directory. ("Incremental backup".)
	 *
	 * @param string $upd_dir The directory to search looking for files/directories not in `$src_dir`.
	 * @param string $src_dir The directory to be compared to `$upd_dir`.
	 * @param string $cop_dir Where to copy any files/directories in `$upd_dir` but not in `$src_dir` to.
	 */
	private static function dir_diff_cp( $upd_dir, $src_dir, $cop_dir ) {
		if ( false === ( $fd = opendir( $upd_dir ) ) ) {
			throw new RuntimeException( $upd_dir );
		}
		while ( false !== ( $file = readdir( $fd ) ) ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			$upd_file = $upd_dir . '/' . $file;
			$src_file = $src_dir . '/' . $file;
			$cop_file = $cop_dir . '/' . $file;
			if ( ! file_exists( $src_file ) ) {
				if ( is_dir( $upd_file ) ) {
					self::$fs->mirror( $upd_file, $cop_file );
				} else {
					copy( $upd_file, $cop_file );
				}
			} elseif ( is_dir( $upd_file ) ) {
				self::dir_diff_cp( $upd_file, $src_file, $cop_file );
			}
		}
		closedir( $fd );
	}

	public function install_wp_with_composer() {
		$this->create_run_dir();
		$this->create_db();

		$yml_path = $this->variables['RUN_DIR'] . "/wp-cli.yml";
		file_put_contents( $yml_path, 'path: wordpress' );

		$this->proc( 'composer init --name="wp-cli/composer-test" --type="project" --no-interaction' )->run_check();
		$this->proc( 'composer require johnpbloch/wordpress --optimize-autoloader --no-interaction' )->run_check();

		$config_extra_php = "require_once dirname(__DIR__) . '/vendor/autoload.php';";
		$this->create_config( 'wordpress', $config_extra_php );

		$install_args = array(
			'url' => 'http://localhost:8080',
			'title' => 'WP CLI Site with both WordPress and wp-cli as Composer dependencies',
			'admin_user' => 'admin',
			'admin_email' => 'admin@example.com',
			'admin_password' => 'password1'
		);

		$this->proc( 'wp core install', $install_args )->run_check();
	}

	public function composer_add_wp_cli_local_repository() {
		if ( ! self::$composer_local_repository ) {
			self::$composer_local_repository = sys_get_temp_dir() . '/' . uniqid( "wp-cli-composer-local-", TRUE );

			$env = self::get_process_env_variables();
			$src = isset( $env['TRAVIS_BUILD_DIR'] ) ? $env['TRAVIS_BUILD_DIR'] : realpath( __DIR__ . '/../../' );

			self::$fs->mirror( $src, self::$composer_local_repository );
			self::$fs->remove( self::$composer_local_repository . '/.git' );
			self::$fs->remove( self::$composer_local_repository . '/vendor' );

			$this->proc( 'composer config repositories.wp-cli \'{"type": "path", "url": "' . self::$composer_local_repository. '/", "options": {"symlink": false}}\'' )->run_check();
		}
	}

	public function composer_require_current_wp_cli() {
		$this->composer_add_wp_cli_local_repository();
		$this->proc( 'composer require wp-cli/wp-cli:dev-master --optimize-autoloader --no-interaction' )->run_check();
	}

	public function get_php_binary() {
		if ( getenv( 'WP_CLI_PHP_USED' ) )
			return getenv( 'WP_CLI_PHP_USED' );

		if ( getenv( 'WP_CLI_PHP' ) )
			return getenv( 'WP_CLI_PHP' );

		if ( defined( 'PHP_BINARY' ) )
			return PHP_BINARY;

		return 'php';
	}

	public function start_php_server() {
		$cmd = Utils\esc_cmd( '%s -S %s -t %s -c %s %s',
			$this->get_php_binary(),
			'localhost:8080',
			$this->variables['RUN_DIR'] . '/wordpress/',
			get_cfg_var( 'cfg_file_path' ),
			$this->variables['RUN_DIR'] . '/vendor/wp-cli/server-command/router.php'
		);
		$this->background_proc( $cmd );
	}

}
