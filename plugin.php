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
		'description' => "<p>The WordPress REST API is a feature that allows developers to access and interact with WordPress sites remotely via HTTP requests. It enables you to retrieve, create, update, or delete content on a WordPress website using a standardized set of endpoints.</p>

<p>When it comes to working with posts specifically, the WordPress REST API offers various endpoints to manage posts. Here are some of the key endpoints for posts:</p>

<ol>
	<li>
	<p><strong>Retrieve Posts</strong>:</p>
	<ul>
		<li><code>GET /wp/v2/posts</code>: This endpoint retrieves a list of posts. You can use various parameters like <code>per_page</code>, <code>page</code>, <code>categories</code>, <code>tags</code>, etc., to filter the results.</li>
	</ul>
	</li>
	<li>
	<p><strong>Retrieve a Single Post</strong>:</p>
	<ul>
		<li><code>GET /wp/v2/posts/{id}</code>: This endpoint retrieves a single post by its ID.</li>
	</ul>
	</li>
	<li>
	<p><strong>Create a Post</strong>:</p>
	<ul>
		<li><code>POST /wp/v2/posts</code>: This endpoint allows you to create a new post by sending a POST request with the necessary data in the request body.</li>
	</ul>
	</li>
	<li>
	<p><strong>Update a Post</strong>:</p>
	<ul>
		<li><code>POST /wp/v2/posts/{id}</code> or <code>PUT /wp/v2/posts/{id}</code>: These endpoints update an existing post by its ID. You send a POST or PUT request with updated data in the request body.</li>
	</ul>
	</li>
	<li>
	<p><strong>Delete a Post</strong>:</p>
	<ul>
		<li><code>DELETE /wp/v2/posts/{id}</code>: This endpoint deletes a post by its ID.</li>
	</ul>
	</li>
</ol>

<p>Endpoints often return JSON data representing the posts in WordPress. The response includes information such as the post&#39;s ID, title, content, date, author, categories, tags, and more, depending on the specific request and the site&#39;s configuration.</p>

<p>To access these endpoints, you typically need authentication. WordPress REST API supports various authentication methods such as cookie authentication, application passwords, OAuth, and JWT authentication, depending on the security needs of the application.</p>

<p>Developers can utilize these endpoints to integrate WordPress content into various applications, build headless WordPress setups, create custom frontend experiences, and perform various other tasks involving WordPress posts.</p>
",
		'object_description' => "<p>Posts are the cornerstone of BuddyPress. They provide a way for users to organize themselves around a common interest or activity, and to discuss issues that are relevant to the group. Posts can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups. Members can be public, private or hidden. Public groups can be joined by anyone, and are indexed by search engines. Private groups are for members only, and hidden groups are not displayed in lists of groups.</p><p>Post object schema:</p>"
	);
	return $schema;
}
define( 'COBEIA_API_SCHEMA', array(
		'wp/v2',
		'buddyboss/v1',
	)
);


define( 'COBEIA_ENDPOINTS_SCHEMA', array(
	'/wp/v2/posts',
	'/wp/v2/posts/(?P<id>[\d]+)',
	'/buddyboss/v1/groups',
	'/buddyboss/v1/groups/(?P<id>[\d]+)',
	'/buddyboss/v1/members',
	'/buddyboss/v1/members/(?P<id>[\d]+)',
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
