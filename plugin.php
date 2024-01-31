<?php

/**
 * Plugin Name: wp-openapi-v3-schema-generator
 * Description: Cobeia Open API v3 generator for the WP REST API
 * Author: Vincent Bathellier
 * Author URI:
 * Version: 2.0.17
 * Plugin URI: https://github.com/Achilles4400/wp-api-swaggerui.git
 * License: Cobeia
 */

function swagger_rest_api_init() {
	if (
		class_exists( 'WP_REST_Controller' )
		&& ! class_exists( 'WP_REST_Swagger_Controller' )
	) {
		require_once dirname( __FILE__ ) . '/lib/class-wp-rest-swagger-controller.php';
	}


	$swagger_controller = new WP_REST_Swagger_Controller();
	$swagger_controller->register_routes();
}

add_action( 'rest_api_init', 'swagger_rest_api_init', 11 );

add_action( 'init', 'load_hooks' );
function load_hooks() {
	//add_filter( 'bp_rest_group_schema', 'cobeia_bp_rest_group_schema', 10, 1 );
	//add_filter( 'bp_rest_members_schema', 'cobeia_bp_rest_member_schema', 10, 1 );
	add_filter( 'rest_post_item_schema', 'cobeia_bp_rest_post_schema', 10, 1 );
	add_action( 'wp_enqueue_scripts', 'remove_starkers_styles', 20 );
	add_filter( 'rest_endpoints', 'add_rest_openapi_data' );
	//add_filter('bp_core_register_common_scripts', 'cobeia_bp_core_register_common_scripts', 10, 1);
}

//function cobeia_bp_rest_group_schema( $schema ) {
//	$schema['tags']        = array(
//		'name'        => 'Social groups',
//		'description' => 'Groups are the cornerstone of BuddyPress. They provide a way for users to organize themselves around a common interest or activity, and to discuss issues that are relevant to the group. Groups can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups.'
//	);
//
//	return $schema;
//}

//function cobeia_bp_rest_member_schema( $schema ) {
//	$schema['tags']        = array(
//		'name'        => 'Members',
//		'description' => 'Members are the cornerstone of BuddyPress. They provide a way for users to organize themselves around a common interest or activity, and to discuss issues that are relevant to the group. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups.'
//
//	);
//
//	return $schema;
//}

function cobeia_bp_rest_post_schema( $schema ) {
	$schema['tags']        = array(
		'name'        => 'WordPress posts',
		'description' => "<p>The WordPress REST API allows developers to access and manipulate posts within your WordPress site through programmatic means. Here&#39;s a breakdown of its functionalities:</p>

<p><strong>Endpoints:</strong></p>

<ul>
	<li><strong><code>/posts</code></strong>:&nbsp;Manage all posts - list,&nbsp;create,&nbsp;update,&nbsp;and delete.</li>
	<li><strong><code>/posts/(id)</code></strong>:&nbsp;Access specific post data based on its ID.</li>
</ul>

<p><strong>Data:</strong></p>

<ul>
	<li>Post title,&nbsp;content,&nbsp;excerpt,&nbsp;and slug.</li>
	<li>Post author,&nbsp;date,&nbsp;status,&nbsp;and visibility.</li>
	<li>Featured image and media details.</li>
	<li>Category and tag associations.</li>
	<li>Custom post meta fields.</li>
</ul>

<p><strong>Authentication:</strong></p>

<p>Requires valid API credentials (username and password) or authentication tokens depending on your setup.</p>
",
		'object_description' => ""
	);
	return $schema;
}
define( 'COBEIA_API_SCHEMA', array(
		'wp/v2',
		'buddyboss/v1',
		'buddyboss-web/v1',
	)
);


define( 'COBEIA_ENDPOINTS_SCHEMA', array(
	'/wp/v2/posts',
	'/wp/v2/posts/(?P<id>[\d]+)',
	'/buddyboss/v1/groups',
	'/buddyboss/v1/groups/(?P<id>[\d]+)',
	'/buddyboss/v1/members',
	'/buddyboss/v1/members/(?P<id>[\d]+)',
	'/buddyboss-web/v1/social-groups',
	'/buddyboss-web/v1/social-groups/(?P<id>[\d]+)',
	'/buddyboss-web/v1/members',
	'/buddyboss-web/v1/members/(?P<id>[\d]+)',
) );


define( 'COBEIA_ENDPOINTS_OPENAPI_DATA', array(
	'/wp/v2/posts',
	'/wp/v2/posts/(?P<id>[\d]+)',
) );



/**
 * Post Endpoints adding open api data.
 *
 * @param $endpoints
 *
 * @return mixed
 *
 * @todo This function we use to add open api data for post endpoints.
 */
function add_rest_openapi_data( $endpoints ) {
	foreach ( $endpoints as $route => $handler ) {
		// Todo: This added for filter some endpoints.
		if ( defined( 'COBEIA_ENDPOINTS_OPENAPI_DATA' ) ) {
			foreach ( COBEIA_ENDPOINTS_OPENAPI_DATA as $filter_endpoint ) {
				if ( strpos( $route, $filter_endpoint ) !== false ) {
					if ( $route === $filter_endpoint ) { // Todo: Render only required endpoints.
						foreach ( $handler as $handler_index => $method_handler ) {
							if ( isset( $method_handler['methods'] ) ) {
								switch ( $method_handler['methods'] ) {
									case 'GET':
										if ( '/wp/v2/posts' === $route ) {
											$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
												'description' => __( 'List WordPress posts', 'buddypress' ),
												'summary'     => __( 'List WordPress posts', 'buddypress' ),
												'tags' => array( 'WordPress posts' ),
											);
										} else {
											$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
												'description' => __( 'Get a Wordpress post', 'buddypress' ),
												'summary'     => __( 'Get a Wordpress post', 'buddypress' ),
												'tags' => array( 'WordPress posts' ),
											);
										}
										break;
									case 'POST':
										if ( '/wp/v2/posts' === $route ) {
											$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
												'description' => __( 'Create WordPress posts', 'buddypress' ),
												'summary'     => __( 'Create WordPress posts', 'buddypress' ),
												'tags' => array( 'WordPress posts' ),
											);
										} else {
											$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
												'description' => __( 'Update a WordPress post', 'buddypress' ),
												'summary'     => __( 'Update a WordPress post', 'buddypress' ),
												'tags' => array( 'WordPress posts' ),
											);
										}
										break;
									case 'PUT':
									case 'PATCH':
										$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
											'description' => __( 'Update WordPress posts', 'buddypress' ),
											'summary'     => __( 'Update WordPress posts', 'buddypress' ),
											'tags' => array( 'WordPress posts' ),
										);
										break;
									case 'DELETE':
										$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
											'description' => __( 'Delete WordPress posts', 'buddypress' ),
											'summary'     => __( 'Delete WordPress posts', 'buddypress' ),
											'tags' => array( 'WordPress posts' ),
										);
										break;
									default:
										$methods = explode( ',', $method_handler['methods'] );
										foreach ( $methods as $method ) {
											if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ) ) ) {
												$endpoints[ $route ][ $handler_index ]['openapi_data'] = array(
													'description' => __( 'Update a WordPress post', 'buddypress' ),
													'summary'     => __( 'Update a WordPress post', 'buddypress' ),
													'tags' => array( 'WordPress posts' ),
												);
											}
										}
										break;
								}
							}
						}
					}
				}
			}
		}
	}

	return $endpoints;
}


function remove_starkers_styles() {
	if ( ! empty( $_GET['doc-render'] ) ) {
		//Remove desired parent styles
		wp_dequeue_style( 'screen' );

// dequeue the Twenty Twenty-One parent style
		wp_dequeue_style( 'parent-style' );
		wp_dequeue_style( 'buddyboss_legacy' );
		wp_dequeue_style( 'bb_theme_block-buddypanel-style-css' );
		wp_dequeue_style( 'buddyboss-theme-fonts' );
		wp_dequeue_style( 'buddyboss-theme-css' );
		wp_dequeue_style( 'buddyboss-theme-template' );
		wp_dequeue_style( 'buddyboss-theme-buddypress' );
		wp_dequeue_style( 'buddyboss-theme-learndash' );
		wp_dequeue_script( 'bp-jquery-scroll-to-js' );
		wp_dequeue_script('screen');

	}
}
