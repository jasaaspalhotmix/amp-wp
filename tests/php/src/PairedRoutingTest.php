<?php

namespace AmpProject\AmpWP\Tests;

use AmpProject\AmpWP\Admin\ReaderThemes;
use AmpProject\AmpWP\Option;
use AmpProject\AmpWP\PairedRouting;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\PairedUrl;
use AmpProject\AmpWP\PairedUrlStructure\LegacyReaderUrlStructure;
use AmpProject\AmpWP\PairedUrlStructure\LegacyTransitionalUrlStructure;
use AmpProject\AmpWP\PairedUrlStructure\PathSuffixUrlStructure;
use AmpProject\AmpWP\PairedUrlStructure\QueryVarUrlStructure;
use AmpProject\AmpWP\Tests\Helpers\AssertContainsCompatibility;
use AMP_Options_Manager;
use AMP_Theme_Support;
use AmpProject\AmpWP\Tests\Fixture\DummyPairedUrlStructure;
use AmpProject\AmpWP\Tests\Helpers\PrivateAccess;
use WP_Query;
use WP_Rewrite;
use Exception;

/** @coversDefaultClass \AmpProject\AmpWP\PairedRouting */
class PairedRoutingTest extends DependencyInjectedTestCase {

	use AssertContainsCompatibility;
	use PrivateAccess;

	/** @var PairedRouting */
	private $instance;

	public function setUp() {
		parent::setUp();
		unset( $_SERVER['HTTPS'] );
		$this->instance = $this->injector->make( PairedRouting::class );
	}

	public function tearDown() {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
		unregister_taxonomy( amp_get_slug() );
		unregister_post_type( amp_get_slug() );
	}

	/** @covers ::__construct() */
	public function test__construct() {
		$this->assertInstanceOf( PairedRouting::class, $this->instance );
		$this->assertInstanceOf( Service::class, $this->instance );
		$this->assertInstanceOf( Registerable::class, $this->instance );
	}

	/**
	 * @covers ::register()
	 */
	public function test_register() {
		remove_all_actions( 'plugins_loaded' ); // @todo This is needed because the instance already got registered.
		$this->instance->register();
		$this->assertEquals( 10, has_filter( 'amp_rest_options_schema', [ $this->instance, 'filter_rest_options_schema' ] ) );
		$this->assertEquals( 10, has_filter( 'amp_rest_options', [ $this->instance, 'filter_rest_options' ] ) );
		$this->assertEquals( 10, has_filter( 'amp_default_options', [ $this->instance, 'filter_default_options' ] ) );
		$this->assertEquals( 10, has_filter( 'amp_options_updating', [ $this->instance, 'sanitize_options' ] ) );
		$this->assertEquals( 9, has_action( 'template_redirect', [ $this->instance, 'redirect_extraneous_paired_endpoint' ] ) );
		$this->assertEquals( 7, has_action( 'plugins_loaded', [ $this->instance, 'initialize_paired_request' ] ) );
	}

	/** @return array */
	public function get_data_for_test_get_paired_url_structure() {
		return [
			'query_var'           => [
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				QueryVarUrlStructure::class,
			],
			'path_suffix'         => [
				Option::PAIRED_URL_STRUCTURE_PATH_SUFFIX,
				PathSuffixUrlStructure::class,
			],
			'legacy_transitional' => [
				Option::PAIRED_URL_STRUCTURE_LEGACY_TRANSITIONAL,
				LegacyTransitionalUrlStructure::class,
			],
			'legacy_reader'       => [
				Option::PAIRED_URL_STRUCTURE_LEGACY_READER,
				LegacyReaderUrlStructure::class,
			],
			'bogus'               => [
				'bogus',
				QueryVarUrlStructure::class,
			],
		];
	}

	/**
	 * @covers ::get_paired_url_structure()
	 * @dataProvider get_data_for_test_get_paired_url_structure
	 * @param string $option_value    Option value.
	 * @param string $structure_class Expected structure.
	 */
	public function test_get_paired_url_structure( $option_value, $structure_class ) {
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, $option_value );
		$structure = $this->instance->get_paired_url_structure();
		$this->assertInstanceOf( $structure_class, $structure );
	}

	/** @covers ::get_paired_url_structure() */
	public function test_get_paired_url_structure_custom_filtered() {
		add_filter(
			'amp_custom_paired_url_structure',
			static function () {
				return DummyPairedUrlStructure::class;
			}
		);
		$structure = $this->instance->get_paired_url_structure();
		$this->assertInstanceOf( DummyPairedUrlStructure::class, $structure );
	}

	/** @covers ::filter_rest_options_schema() */
	public function test_filter_rest_options_schema() {
		$existing = [
			'foo' => [
				'type' => 'string',
			],
		];

		$filtered = $this->instance->filter_rest_options_schema( $existing );
		$this->assertArrayHasKey( 'foo', $filtered );
		$this->assertArrayHasKey( Option::PAIRED_URL_STRUCTURE, $filtered );
		$this->assertArrayHasKey( PairedRouting::PAIRED_URL_EXAMPLES, $filtered );
		$this->assertArrayHasKey( PairedRouting::AMP_SLUG, $filtered );
		$this->assertArrayHasKey( PairedRouting::ENDPOINT_PATH_SLUG_CONFLICTS, $filtered );
		$this->assertArrayHasKey( PairedRouting::REWRITE_USING_PERMALINKS, $filtered );
	}

	/** @covers ::filter_rest_options() */
	public function test_filter_rest_options() {
		$existing = [
			'foo' => 'bar',
		];

		$options = $this->instance->filter_rest_options( $existing );

		$this->assertEquals( amp_get_slug(), $options[ PairedRouting::AMP_SLUG ] );
		$this->assertEquals(
			AMP_Options_Manager::get_option( Option::PAIRED_URL_STRUCTURE ),
			$options[ Option::PAIRED_URL_STRUCTURE ]
		);
		$this->assertEquals( $this->instance->get_paired_url_examples(), $options[ PairedRouting::PAIRED_URL_EXAMPLES ] );
		$this->assertEquals( $this->instance->get_custom_paired_structure_sources(), $options[ PairedRouting::CUSTOM_PAIRED_ENDPOINT_SOURCES ] );
		$this->assertEquals( $this->instance->get_endpoint_path_slug_conflicts(), $options[ PairedRouting::ENDPOINT_PATH_SLUG_CONFLICTS ] );
		$this->assertEquals( $this->instance->is_using_permalinks(), $options[ PairedRouting::REWRITE_USING_PERMALINKS ] );
	}

	/** @covers ::get_endpoint_path_slug_conflicts() */
	public function test_get_endpoint_path_slug_conflicts() {
		$this->assertCount( 0, $this->instance->get_endpoint_path_slug_conflicts() );

		// Posts.
		self::factory()->post->create( [ 'post_name' => amp_get_slug() ] );
		$this->assertEquals(
			[ 'posts' ],
			array_keys( $this->instance->get_endpoint_path_slug_conflicts() )
		);

		// Terms.
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => amp_get_slug(),
			]
		);
		$this->assertEquals(
			[ 'posts', 'terms' ],
			array_keys( $this->instance->get_endpoint_path_slug_conflicts() )
		);

		// Users.
		self::factory()->user->create(
			[
				'user_login' => 'amp',
			]
		);
		$this->assertEquals(
			[ 'posts', 'terms', 'users' ],
			array_keys( $this->instance->get_endpoint_path_slug_conflicts() )
		);

		// Post types.
		register_post_type( amp_get_slug() );
		$this->assertEquals(
			[ 'posts', 'terms', 'users', 'post_types' ],
			array_keys( $this->instance->get_endpoint_path_slug_conflicts() )
		);

		// Taxonomies.
		register_taxonomy( amp_get_slug(), 'post' );
		$this->assertEquals(
			[ 'posts', 'terms', 'users', 'post_types', 'taxonomies' ],
			array_keys( $this->instance->get_endpoint_path_slug_conflicts() )
		);
	}

	/** @return array */
	public function get_data_for_test_paired_requests() {
		return [
			'query_var_reader_mode_amp'             => [
				AMP_Theme_Support::READER_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				'/?amp=1',
				true,
			],
			'query_var_reader_mode_non_amp'         => [
				AMP_Theme_Support::READER_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				'/',
				false,
			],
			'url_with_empty_query_var'              => [
				AMP_Theme_Support::READER_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				'/?foo=',
				false,
			],
			'path_suffix_transitional_mode_amp'     => [
				AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_PATH_SUFFIX,
				'/amp/',
				true,
			],
			'path_suffix_transitional_mode_non_amp' => [
				AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_PATH_SUFFIX,
				'/',
				false,
			],
			'legacy_reader_mode_amp'                => [
				AMP_Theme_Support::READER_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_LEGACY_READER,
				'/amp/',
				true,
			],
			'legacy_transitional_mode_amp'          => [
				AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_LEGACY_TRANSITIONAL,
				'/?amp=1',
				true,
			],
			'standard_mode'                         => [
				AMP_Theme_Support::STANDARD_MODE_SLUG,
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				'/',
				null,
			],
		];
	}

	/**
	 * Test initialize_paired_request, integrated with other methods.
	 *
	 * @covers ::initialize_paired_request()
	 * @covers ::detect_endpoint_in_environment()
	 * @covers ::extract_endpoint_from_environment_before_parse_request()
	 * @covers ::filter_request_after_endpoint_extraction()
	 * @covers ::restore_path_endpoint_in_environment()
	 *
	 * @dataProvider get_data_for_test_paired_requests
	 *
	 * @param string $mode
	 * @param string $structure
	 * @param string $request_uri
	 * @param bool $did_request_endpoint
	 */
	public function test_initialize_paired_request_integration( $mode, $structure, $request_uri, $did_request_endpoint ) {
		global $wp;
		$post_id = self::factory()->post->create();
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, $mode );
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, $structure );

		$permalink   = get_permalink( $post_id );
		$request_uri = rtrim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' ) . $request_uri;

		$request_uri_during_parse_request = null;
		add_filter(
			'request',
			function ( $query_vars ) use ( &$request_uri_during_parse_request ) {
				$request_uri_during_parse_request = $_SERVER['REQUEST_URI'];
				return $query_vars;
			}
		);

		$_SERVER['REQUEST_URI'] = $request_uri;
		$this->instance->initialize_paired_request();
		$this->go_to( $request_uri );

		if ( $did_request_endpoint ) {
			$this->assertNotEmpty( $request_uri_during_parse_request );
			$this->assertNotEquals( $request_uri_during_parse_request, $request_uri );
			$this->assertEquals(
				$this->instance->get_paired_url_structure()->remove_endpoint( $request_uri ),
				$request_uri_during_parse_request
			);
		}

		$this->assertSame( $did_request_endpoint, $this->get_private_property( $this->instance, 'did_request_endpoint' ) );
		$this->assertSame( $request_uri, $_SERVER['REQUEST_URI'] );
		$this->assertEquals(
			trim( strtok( $request_uri, '?' ), '/' ),
			$wp->request
		);
		if ( $did_request_endpoint ) {
			$this->assertTrue( get_query_var( amp_get_slug() ) );
		} else {
			$this->assertEquals( '', get_query_var( amp_get_slug() ) );
		}
	}

	/** @return array */
	public function get_data_for_test_initialize_paired_request() {
		return [
			'query_var'   => [
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
				false,
			],
			'path_suffix' => [
				Option::PAIRED_URL_STRUCTURE_PATH_SUFFIX,
				true,
			],
		];
	}

	/**
	 * @covers ::initialize_paired_request()
	 *
	 * @dataProvider get_data_for_test_initialize_paired_request
	 * @param string $structure
	 * @param bool $filtering_unique_post_slug
	 */
	public function test_initialize_paired_request( $structure, $filtering_unique_post_slug ) {
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, $structure );
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		$this->instance->initialize_paired_request();
		$this->assertFalse( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );
		$this->assertEquals( 10, has_filter( 'do_parse_request', [ $this->instance, 'extract_endpoint_from_environment_before_parse_request' ] ) );
		$this->assertEquals( 10, has_filter( 'request', [ $this->instance, 'filter_request_after_endpoint_extraction' ] ) );
		$this->assertEquals( 10, has_action( 'parse_request', [ $this->instance, 'restore_path_endpoint_in_environment' ] ) );
		if ( $filtering_unique_post_slug ) {
			$this->assertEquals( 10, has_filter( 'wp_unique_post_slug', [ $this->instance, 'filter_unique_post_slug' ] ) );
		} else {
			$this->assertFalse( has_filter( 'wp_unique_post_slug', [ $this->instance, 'filter_unique_post_slug' ] ) );
		}

		$this->assertEquals( 10, has_action( 'parse_query', [ $this->instance, 'correct_query_when_is_front_page' ] ) );
		$this->assertEquals( 10, has_action( 'wp', [ $this->instance, 'add_paired_request_hooks' ] ) );
		$this->assertEquals( 10, has_action( 'admin_notices', [ $this->instance, 'add_permalink_settings_notice' ] ) );
	}

	/** @covers ::initialize_paired_request() */
	public function test_initialize_paired_request_in_standard_mode() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, Option::PAIRED_URL_STRUCTURE_QUERY_VAR );
		$this->instance->initialize_paired_request();
		$this->assertNull( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );
		$this->assertFalse( has_filter( 'do_parse_request', [ $this->instance, 'extract_endpoint_from_environment_before_parse_request' ] ) );
	}

	/** @covers ::detect_endpoint_in_environment() */
	public function test_detect_endpoint_in_environment() {
		unset( $_SERVER['REQUEST_URI'] );
		$this->instance->detect_endpoint_in_environment();
		$this->assertFalse( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );

		$_SERVER['REQUEST_URI'] = $this->instance->remove_endpoint( '/' );
		$this->instance->detect_endpoint_in_environment();
		$this->assertFalse( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );

		$_SERVER['REQUEST_URI'] = $this->instance->add_endpoint( '/' );
		$this->instance->detect_endpoint_in_environment();
		$this->assertTrue( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );

		$_SERVER['REQUEST_URI'] = '/?foo=';
		$this->instance->detect_endpoint_in_environment();
		$this->assertFalse( $this->get_private_property( $this->instance, 'did_request_endpoint' ) );
	}

	/** @return array */
	public function get_data_for_test_filter_unique_post_slug() {
		return [
			'foo'  => [
				'foo',
				[],
				'foo',
			],
			'amp'  => [
				amp_get_slug(),
				[],
				amp_get_slug() . '-2',
			],
			'amp3' => [
				amp_get_slug(),
				[
					amp_get_slug() . '-2',
					amp_get_slug() . '-3',
				],
				amp_get_slug() . '-4',
			],
		];
	}

	/**
	 * @covers ::filter_unique_post_slug()
	 * @dataProvider get_data_for_test_filter_unique_post_slug
	 * @param string $post_name
	 * @param string[] $other_existing_post_names
	 * @param string $expected_slug
	 */
	public function test_filter_unique_post_slug( $post_name, $other_existing_post_names, $expected_slug ) {
		$post = self::factory()->post->create_and_get( [ 'post_name' => $post_name ] );
		foreach ( $other_existing_post_names as $other_existing_post_name ) {
			self::factory()->post->create( [ 'post_name' => $other_existing_post_name ] );
		}

		$actual_slug = $this->instance->filter_unique_post_slug(
			$post_name,
			$post->ID,
			$post->post_status,
			$post->post_type
		);

		$this->assertSame( $expected_slug, $actual_slug );
	}

	/** @covers ::add_paired_request_hooks() */
	public function test_add_paired_request_hooks_when_does_have_endpoint() {
		$post = self::factory()->post->create();
		$this->go_to( amp_get_permalink( $post ) );
		$this->instance->add_paired_request_hooks();
		$this->assertTrue( $this->instance->has_endpoint() );
		$this->assertEquals( 1000, has_filter( 'old_slug_redirect_url', [ $this->instance, 'maybe_add_paired_endpoint' ] ) );
		$this->assertEquals( 1000, has_filter( 'redirect_canonical', [ $this->instance, 'maybe_add_paired_endpoint' ] ) );
		$this->assertFalse( has_action( 'wp_head', 'amp_add_amphtml_link' ) );
	}

	/** @covers ::add_paired_request_hooks() */
	public function test_add_paired_request_hooks_when_not_has_endpoint() {
		$post = self::factory()->post->create();
		$this->go_to( get_permalink( $post ) );
		$this->assertFalse( $this->instance->has_endpoint() );
		$this->instance->add_paired_request_hooks();
		$this->assertEquals( 10, has_action( 'wp_head', 'amp_add_amphtml_link' ) );
		$this->assertFalse( has_filter( 'old_slug_redirect_url', [ $this->instance, 'maybe_add_paired_endpoint' ] ) );
		$this->assertFalse( has_filter( 'redirect_canonical', [ $this->instance, 'maybe_add_paired_endpoint' ] ) );
	}

	/** @covers ::add_permalink_settings_notice() */
	public function test_add_permalink_settings_notice() {
		set_current_screen( 'options' );
		$this->assertEmpty( get_echo( [ $this->instance, 'add_permalink_settings_notice' ] ) );

		set_current_screen( 'options-permalink' );
		$this->assertStringContains( 'notice-info', get_echo( [ $this->instance, 'add_permalink_settings_notice' ] ) );
	}

	/** @covers ::is_using_permalinks() */
	public function test_is_using_permalinks() {
		$this->set_permalink_structure( '' );
		$this->assertFalse( $this->instance->is_using_permalinks() );

		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$this->assertTrue( $this->instance->is_using_permalinks() );
	}

	/** @return array */
	public function get_data_for_test_filter_default_options() {
		return [
			'default'                   => [
				[
					Option::VERSION       => AMP__VERSION,
					Option::THEME_SUPPORT => AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
					Option::READER_THEME  => ReaderThemes::DEFAULT_READER_THEME,
				],
				Option::PAIRED_URL_STRUCTURE_QUERY_VAR,
			],
			'old_version_transitional'  => [
				[
					Option::VERSION       => '2.0.0',
					Option::THEME_SUPPORT => AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
					Option::READER_THEME  => ReaderThemes::DEFAULT_READER_THEME,
				],
				Option::PAIRED_URL_STRUCTURE_LEGACY_TRANSITIONAL,
			],
			'old_version_reader_legacy' => [
				[
					Option::VERSION       => '2.0.0',
					Option::THEME_SUPPORT => AMP_Theme_Support::READER_MODE_SLUG,
					Option::READER_THEME  => ReaderThemes::DEFAULT_READER_THEME,
				],
				Option::PAIRED_URL_STRUCTURE_LEGACY_READER,
			],
			'old_version_reader_theme'  => [
				[
					Option::VERSION       => '2.0.0',
					Option::THEME_SUPPORT => AMP_Theme_Support::READER_MODE_SLUG,
					Option::READER_THEME  => 'twentytwenty',
				],
				Option::PAIRED_URL_STRUCTURE_LEGACY_TRANSITIONAL,
			],
		];
	}

	/**
	 * @covers ::filter_default_options()
	 * @dataProvider get_data_for_test_filter_default_options
	 *
	 * @param array $options
	 * @param string $expected_structure
	 */
	public function test_filter_default_options( $options, $expected_structure ) {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$this->assertEquals(
			$expected_structure,
			$this->instance->filter_default_options(
				[],
				$options
			)[ Option::PAIRED_URL_STRUCTURE ]
		);
	}

	/** @covers ::sanitize_options() */
	public function test_sanitize_options() {
		$this->assertEmpty(
			$this->instance->sanitize_options(
				[],
				[ Option::PAIRED_URL_STRUCTURE => 'bogus' ]
			)
		);

		foreach ( array_keys( PairedRouting::PAIRED_URL_STRUCTURES ) as $paired_url_structure ) {
			$this->assertEquals(
				[ Option::PAIRED_URL_STRUCTURE => $paired_url_structure ],
				$this->instance->sanitize_options(
					[],
					[ Option::PAIRED_URL_STRUCTURE => $paired_url_structure ]
				)
			);
		}
	}

	/** @return array */
	public function get_data_for_test_has_endpoint() {
		return [
			'provided_non_amp_url'         => [
				null,
				false,
				static function () {
					return home_url( '/' );
				},
			],

			'provided_amp_url'             => [
				null,
				true,
				function ( PairedRouting $instance ) {
					return $instance->add_endpoint( home_url( '/' ) );
				},
			],

			'non_amp_page_requested'       => [
				function () {
					$this->go_to( home_url( '/' ) );
				},
				false,
				'',
			],

			'yes_amp_page_requested'       => [
				function ( PairedRouting $instance ) {
					$this->go_to( $instance->add_endpoint( home_url( '/' ) ) );
				},
				true,
				'',
			],

			'did_request_endpoint'         => [
				function ( PairedRouting $instance ) {
					$this->set_private_property( $instance, 'did_request_endpoint', true );
				},
				true,
				'',
			],

			'has_query_var_set'            => [
				function () {
					set_query_var( amp_get_slug(), true );
				},
				true,
				'',
			],

			'is_admin_without_query_param' => [
				function () {
					set_current_screen( 'index' );
				},
				false,
				'',
			],

			'is_admin_with_query_param'    => [
				function () {
					set_current_screen( 'index' );
					$_GET[ amp_get_slug() ] = 1;
				},
				true,
				'',
			],
		];
	}

	/**
	 * @covers ::has_endpoint()
	 * @dataProvider get_data_for_test_has_endpoint
	 *
	 * @param callable|null $setup_callback
	 * @param bool $expected_has_endpoint
	 * @param callable|null $url_callback
	 */
	public function test_has_endpoint( $setup_callback, $expected_has_endpoint, $url_callback ) {
		if ( $setup_callback ) {
			$setup_callback( $this->instance );
		}
		$url = $url_callback ? $url_callback( $this->instance ) : '';
		$this->assertEquals( $expected_has_endpoint, $this->instance->has_endpoint( $url ) );
	}

	/**
	 * @covers ::add_endpoint()
	 * @covers ::remove_endpoint()
	 */
	public function test_add_has_remove_endpoint() {
		$base  = home_url( '/' );
		$added = $this->instance->add_endpoint( $base );
		$this->assertNotEquals( $base, $added );
		$removed = $this->instance->remove_endpoint( $added );
		$this->assertEquals( $base, $removed );
	}

	/** @covers ::has_custom_paired_url_structure() */
	public function test_has_custom_paired_url_structure() {
		$this->assertFalse( $this->instance->has_custom_paired_url_structure() );
		add_filter(
			'amp_custom_paired_url_structure',
			static function () {
				return DummyPairedUrlStructure::class;
			}
		);
		$this->assertTrue( $this->instance->has_custom_paired_url_structure() );
	}

	/** @covers ::get_all_structure_paired_urls() */
	public function test_get_all_structure_paired_urls() {
		$urls = $this->instance->get_all_structure_paired_urls( home_url( '/foo/' ) );
		$this->assertEqualSets(
			array_keys( PairedRouting::PAIRED_URL_STRUCTURES ),
			array_keys( $urls )
		);

		add_filter(
			'amp_custom_paired_url_structure',
			static function () {
				return DummyPairedUrlStructure::class;
			}
		);
		$urls = $this->instance->get_all_structure_paired_urls( home_url( '/bar/' ) );
		$this->assertEqualSets(
			array_merge(
				array_keys( PairedRouting::PAIRED_URL_STRUCTURES ),
				[ 'custom' ]
			),
			array_keys( $urls )
		);
	}

	/** @covers ::get_paired_url_examples() */
	public function test_get_paired_url_examples() {
		$this->factory()->post->create( [ 'post_type' => 'post' ] );
		$this->factory()->post->create( [ 'post_type' => 'page' ] );

		add_filter(
			'amp_custom_paired_url_structure',
			static function () {
				return DummyPairedUrlStructure::class;
			}
		);

		$examples = $this->instance->get_paired_url_examples();

		$this->assertEqualSets(
			array_merge(
				array_keys( PairedRouting::PAIRED_URL_STRUCTURES ),
				[ 'custom' ]
			),
			array_keys( $examples )
		);

		foreach ( $examples as $example_set ) {
			$this->assertCount( 2, $example_set );
		}
	}

	/** @covers ::get_custom_paired_structure_sources() */
	public function test_get_custom_paired_structure_sources() {
		$this->assertEquals( [], $this->instance->get_custom_paired_structure_sources() );

		add_filter(
			'amp_custom_paired_url_structure',
			static function () {
				return DummyPairedUrlStructure::class;
			}
		);

		$sources = $this->instance->get_custom_paired_structure_sources();

		$this->assertCount( 1, $sources );
		$this->assertEquals(
			[
				'type' => 'plugin',
				'slug' => 'amp',
				'name' => 'AMP',
			],
			current( $sources )
		);
	}

	/** @return array */
	public function get_data_for_test_correct_query_when_is_front_page() {
		return [
			'non_amp_blog_request'            => [
				null,
				false,
			],
			'amp_front_page'                  => [
				function ( WP_Query $query ) {
					$query->set( amp_get_slug(), true );
				},
				true,
			],
			'amp_front_page_with_other_query' => [
				function ( WP_Query $query ) {
					$query->set( amp_get_slug(), true );
					$query->query = [ 'foo' => 'bar' ];
				},
				false,
			],
		];
	}

	/**
	 * @covers ::correct_query_when_is_front_page()
	 * @dataProvider get_data_for_test_correct_query_when_is_front_page
	 * @param callable $setup_callback
	 * @param bool $expected_is_front_page
	 */
	public function test_correct_query_when_is_front_page( $setup_callback, $expected_is_front_page ) {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		global $wp_the_query, $wp_query;
		$wp_query     = new WP_Query();
		$wp_the_query = $wp_query;
		$this->assertTrue( $wp_query->is_main_query() );
		$wp_query->is_home = true;

		if ( $setup_callback ) {
			$setup_callback( $wp_query );
		}

		$this->instance->correct_query_when_is_front_page( $wp_query );

		if ( $expected_is_front_page ) {
			$this->assertFalse( $wp_query->is_home );
			$this->assertTrue( $wp_query->is_page );
			$this->assertTrue( $wp_query->is_singular );
			$this->assertEquals( $page_id, $wp_query->get( 'page_id' ) );
		} else {
			$this->assertTrue( $wp_query->is_home );
			$this->assertFalse( $wp_query->is_page );
			$this->assertFalse( $wp_query->is_singular );
			$this->assertNotEquals( $page_id, $wp_query->get( 'page_id' ) );
		}
	}

	/** @covers ::maybe_add_paired_endpoint() */
	public function test_maybe_add_paired_endpoint() {
		$this->assertSame( '', $this->instance->maybe_add_paired_endpoint( '' ) );

		$home_url = home_url( '/' );
		$this->assertSame(
			$this->instance->add_endpoint( $home_url ),
			$this->instance->maybe_add_paired_endpoint( $home_url )
		);
	}

	/** @covers ::redirect_extraneous_paired_endpoint() */
	public function test_redirect_extraneous_paired_endpoint_canonical_404_due_to_suffix() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$path_suffix_structure = $this->injector->make( PathSuffixUrlStructure::class );

		$permalink_url    = get_permalink( self::factory()->post->create() );
		$amp_endpoint_url = $path_suffix_structure->add_endpoint( $permalink_url );
		$this->go_to( $amp_endpoint_url );

		$this->assertTrue( amp_is_canonical() );
		$this->assertTrue( is_404() );

		$redirected_url = null;
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				return false;
			}
		);
		$this->instance->redirect_extraneous_paired_endpoint();
		$this->assertEquals( $permalink_url, $redirected_url );
	}

	/** @covers ::redirect_extraneous_paired_endpoint() */
	public function test_redirect_extraneous_paired_endpoint_canonical_extraneous_query_var() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$query_var_structure = $this->injector->make( QueryVarUrlStructure::class );

		$permalink_url    = get_permalink( self::factory()->post->create() );
		$amp_endpoint_url = $query_var_structure->add_endpoint( $permalink_url );
		$this->go_to( $amp_endpoint_url );

		$this->assertTrue( amp_is_canonical() );
		$this->assertTrue( ! is_404() );

		$redirected_url = null;
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				return false;
			}
		);
		$this->instance->redirect_extraneous_paired_endpoint();
		$this->assertEquals( $permalink_url, $redirected_url );
	}

	/** @covers ::redirect_extraneous_paired_endpoint() */
	public function test_redirect_extraneous_paired_endpoint_path_suffix_404() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, Option::PAIRED_URL_STRUCTURE_QUERY_VAR );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$path_suffix_structure = $this->injector->make( PathSuffixUrlStructure::class );
		$paired_url            = $this->injector->make( PairedUrl::class );

		$permalink_url    = get_permalink( self::factory()->post->create() );
		$amp_endpoint_url = $path_suffix_structure->add_endpoint( $permalink_url );
		$this->go_to( $amp_endpoint_url );

		$this->assertFalse( amp_is_canonical() );
		$this->assertTrue( is_404() );

		$redirected_url = null;
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				return false;
			}
		);
		$this->instance->redirect_extraneous_paired_endpoint();
		$this->assertEquals(
			$paired_url->add_query_var( $path_suffix_structure->remove_endpoint( $amp_endpoint_url ) ),
			$redirected_url
		);
	}

	/** @covers ::redirect_extraneous_paired_endpoint() */
	public function test_redirect_extraneous_paired_endpoint_slug_redirect() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, Option::PAIRED_URL_STRUCTURE_QUERY_VAR );
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		AMP_Options_Manager::update_option( Option::SUPPORTED_TEMPLATES, [ 'is_singular' ] );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );

		$post_id             = self::factory()->post->create( [ 'post_name' => 'first' ] );
		$first_permalink_url = get_permalink( $post_id );

		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'second',
			]
		);
		$second_permalink_url = get_permalink( $post_id );

		$this->assertNotEquals( $first_permalink_url, $second_permalink_url );

		$this->go_to( $this->instance->add_endpoint( $first_permalink_url ) );

		$this->assertTrue( is_404() );

		$redirected_url = null;
		try {
			// Throwing an exception si needed because wp_old_slug_redirect() does exit after wp_redirect().
			add_filter(
				'wp_redirect',
				static function ( $url ) {
					throw new Exception( $url );
				}
			);
			$this->instance->redirect_extraneous_paired_endpoint();
		} catch ( Exception $exception ) {
			$redirected_url = $exception->getMessage();
		}

		$this->assertEquals(
			$this->instance->add_endpoint( $second_permalink_url ),
			$redirected_url
		);
	}

	/** @covers ::redirect_extraneous_paired_endpoint() */
	public function test_redirect_extraneous_paired_endpoint_unavailable_template() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		AMP_Options_Manager::update_option( Option::PAIRED_URL_STRUCTURE, Option::PAIRED_URL_STRUCTURE_QUERY_VAR );
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		AMP_Options_Manager::update_option( Option::SUPPORTED_TEMPLATES, [ 'is_singular' ] );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );

		$post_id          = self::factory()->post->create();
		$date_archive_url = trailingslashit( dirname( get_permalink( $post_id ) ) );

		$amp_endpoint_url = $this->instance->add_endpoint( $date_archive_url );
		$this->go_to( $amp_endpoint_url );

		$this->assertFalse( amp_is_canonical() );
		$this->assertTrue( is_date() );
		$this->assertFalse( amp_is_available() );

		$redirected_url = null;
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$redirected_url ) {
				$redirected_url = $url;
				return false;
			}
		);
		$this->instance->redirect_extraneous_paired_endpoint();
		$this->assertEquals(
			$date_archive_url,
			$redirected_url
		);
	}
}
