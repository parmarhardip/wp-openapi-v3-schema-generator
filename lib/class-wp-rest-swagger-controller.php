<?php

/**
 * Swagger base class.
 */
class WP_REST_Swagger_Controller extends WP_REST_Controller {

	/**
	 * Construct the API handler object.
	 */
	public function __construct() {
		$this->namespace = 'apigenerate';
	}

	/**
	 * Register the meta-related routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/swagger', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_swagger' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			),

			'schema' => '',
		) );
	}

	/**
	 * Retrieve custom swagger object.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Request|WP_Error Metaobject data on success, WP_Error otherwise
	 */
	public function get_swagger( $request ) {
		$basePath = parse_url( get_rest_url(), PHP_URL_PATH );
		$basePath = str_replace( 'index.php/', '', $basePath );
		$basePath = rtrim( $basePath, '/' );

		$swagger = array(
			'openapi'    => '3.1.0',
			'info'       => $this->get_info(),
			'servers'    => $this->get_server(),
			'paths'      => array(),
			'components' => $this->get_default_components(),
		);


		$restServer = rest_get_server();

		foreach ( $restServer->get_routes() as $endpointName => $endpoint ) {
			if ( $endpointName == '/' . $this->namespace . '/swagger' ) {
				continue;
			}

			if ( defined( 'COBEIA_API_SCHEMA' ) ) {
				if ( ! $this->shouldIncludeEndpoint( $endpointName ) ) {
					continue;
				}

				if ( defined( 'COBEIA_ENDPOINTS_SCHEMA' ) && ! $this->shouldIncludeSpecificEndpoint( $endpointName ) ) {
					continue;
				}
			}

			$route_options   = $restServer->get_route_options( $endpointName );
			$defaultidParams = array();
			//Replace endpoints var and add to the parameters required
			$endpointName   = preg_replace_callback(
				'#\(\?P<(\w+?)>.*?\)(\?\))?#',
				function ( $matches ) use ( &$defaultidParams ) {
					$defaultidParams[] = array(
						'name'     => $matches[1],
						'in'       => 'path',
						'required' => true,
						'schema'   => array(
							'type' => 'integer'
						)
					);

					return '{' . $matches[1] . '}';
				},
				$endpointName
			);
			$endpointName   = str_replace( $basePath, '', $endpointName );
			$openapi_schema = array();
			if ( ! empty( $route_options['schema'][1] ) ) {
				$schema         = call_user_func( array( $route_options['schema'][0], $route_options['schema'][1] ) );
				$openapi_schema = $this->processSchema( $schema, $swagger );
			}
			$this->processPaths( $swagger, $endpointName, $endpoint, $openapi_schema, $defaultidParams );
		}

		$response = rest_ensure_response( $swagger );

		return apply_filters( 'rest_prepare_meta_value', $response, $request );
	}

	// New helper methods
	private function shouldIncludeEndpoint( $endpointName ) {
		return ! ! array_filter( COBEIA_API_SCHEMA, function ( $filter_endpoint ) use ( $endpointName ) {
			return strpos( $endpointName, $filter_endpoint ) !== false;
		} );
	}

	/**
	 * // Todo: This added for filter some endpoints.
	 * // Todo: Render only required endpoints.
	 *
	 * @param $endpointName
	 *
	 * @since [BBAPPVERSION]
	 * @return bool
	 */
	private function shouldIncludeSpecificEndpoint( $endpointName ) {
		return ! ! array_filter( COBEIA_ENDPOINTS_SCHEMA, function ( $filter_endpoint ) use ( $endpointName ) {
			return $endpointName === $filter_endpoint;
		} );
	}

	private function processSchema( $schema, &$swagger ) {
		$openapi_schema                = $this->schemaIntoDefinition( $schema );
		$schema_tag_description        = $this->prepare_object_content( $openapi_schema );
		$schema['tags']['description'] = $schema['tags']['description'] . $schema_tag_description;
		$swagger['tags'][]             = $schema['tags'];

		return $openapi_schema;
	}

	private function processPaths( &$swagger, $endpointName, $endpoint, $openapi_schema, $defaultidParams ) {
		if ( empty( $swagger['paths'][ $endpointName ] ) ) {
			$swagger['paths'][ $endpointName ] = array();
		}

		foreach ( $endpoint as $endpointPart ) {
			foreach ( $endpointPart['methods'] as $methodName => $method ) {
				if ( in_array( $methodName, array( 'PUT', 'PATCH' ) ) ) {
					continue;
				} //duplicated by post

				$operationId = array_reduce( explode( '/', preg_replace( "/{(\w+)}/", 'by/${1}', $endpointName ) ), array( $this, "compose_operation_name" ) );
				$context     = 'view';
				if ( $methodName === 'POST' ) {
					$context     = 'edit';
					$operationId = ucfirst( strtolower( $methodName ) ) . $operationId;
				}

				$swagger['components']['schemas'][ $operationId ] = $this->componentSchema( $openapi_schema, $context );

				$summary     = '';
				$description = '';
				$tags        = '';
				if ( ! empty( $endpointPart['openapi_data'] ) ) {
					if ( isset( $endpointPart['openapi_data']['summary'] ) ) {
						$summary = $endpointPart['openapi_data']['summary'];
					}
					if ( isset( $endpointPart['openapi_data']['description'] ) ) {
						$description = $endpointPart['openapi_data']['description'];
					}
					if ( isset( $endpointPart['openapi_data']['tags'] ) ) {
						$tags = $endpointPart['openapi_data']['tags'];
					}
				}

				$parameters  = $this->getParameters( $endpointPart, $methodName, $defaultidParams );
				$requestBody = $this->requestBody( $endpointPart, $methodName );
				$security    = array( array( 'accessToken' => array() ) );
				$response    = $this->get_responses( $endpointName, $methodName, array( '$ref' => '#/components/schemas/' . $operationId ) );

				$swagger['paths'][ $endpointName ][ strtolower( $methodName ) ] = array(
					'summary'     => $summary,
					'description' => $description,
					'tags'        => $tags,
					'parameters'  => $parameters,
					'security'    => 'GET' !== $methodName ? $security : array(),
					'responses'   => $response,
					'operationId' => $operationId
				);

				if ( $methodName === 'POST' && ! empty( $requestBody ) ) {
					$swagger['paths'][ $endpointName ][ strtolower( $methodName ) ]['x-bb-request-body-name'] = 'body';
					$swagger['paths'][ $endpointName ][ strtolower( $methodName ) ]['requestBody']            = array(
						'content' => array(
							'application/json' => array(
								'schema' => array(
									'type'       => 'object',
									'title'      => ucfirst( strtolower( $methodName ) ) . array_reduce( explode( '/', preg_replace( "/{(\w+)}/", 'by/${1}', $endpointName ) ),
											array( $this, "compose_operation_name" ) ) . 'Input',
									'properties' => $requestBody
								)
							)
						)
					);
				}
			}
		}
	}

	private function componentSchema( $openapi_schema, $context = 'view' ) {
		foreach ( $openapi_schema['properties'] as $property_key => $property ) {
			if ( ! in_array( $context, $property['context'] ) ) {
				unset( $openapi_schema['properties'][ $property_key ] );
			}
		}

		return $openapi_schema;
	}

	private function requestBody( $endpointPart, $methodName ) {
		$requestBody = array();
		if ( $methodName === 'POST' && $endpointPart['args'] ) {
			foreach ( $endpointPart['args'] as $key => $value ) {
				$requestBody[ $key ] = array(
					'type' => $value['type']
				);
				if ( ! empty( $value['description'] ) ) {
					$requestBody[ $key ]['description'] = $value['description'];
				}
				if ( ! empty( $value['required'] ) ) {
					$requestBody[ $key ]['required'] = $value['required'];
				}

				if ( ! empty( $value['example'] ) ) {
					$requestBody[ $key ]['example'] = $value['example'];
				}

				if ( ! empty( $value['items'] ) ) {
					$requestBody[ $key ]['items'] = $value['items'];
				}
				if ( ! empty( $value['enum'] ) ) {
					$requestBody[ $key ]['enum'] = $value['enum'];
				}
				if ( ! empty( $value['properties'] ) ) {
					$requestBody[ $key ]['properties'] = $value['properties'];
				}
			}
		}

		return $requestBody;
	}

	private function getParameters( $endpointPart, $methodName, $defaultidParams ) {
		$parameters = $defaultidParams;

		$pathParamName = array_map( function ( $param ) {
			return $param['name'];
		}, $defaultidParams );

		$key_index = '';
		if ( ! empty( $endpointPart['args'] ) ) {
			foreach ( $endpointPart['args'] as $key => $value ) {
				$parameter = array(
					'name'    => $key,
					'in'      => $methodName == 'POST' ? 'formData' : 'query',
					'style'   => 'form',
					'explode' => 'false'
				);

				if ( ! empty( $value['description'] ) ) {
					$parameter['description'] = $value['description'];
				}

				if ( ! empty( $value['example'] ) ) {
					$parameter['example'] = $value['example'];
				}

				if ( ! empty( $value['required'] ) ) {
					$parameter['required'] = $value['required'];
				}

				if ( ! empty( $value['deprecated'] ) ) {
					$parameter['deprecated'] = $value['deprecated'];
				}

				if ( ! empty( $value['type'] ) ) {
					$parameter['schema']['type'] = $value['type'];
				}
				if ( ! empty( $value['format'] ) ) {
					$parameter['schema']['format'] = $value['format'];
				}
				if ( ! empty( $value['default'] ) ) {
					$parameter['schema']['default'] = $value['default'];
				}
				if ( ! empty( $value['enum'] ) ) {
					$parameter['schema']['enum'] = array_values( $value['enum'] );
				}

				if ( ! empty( $value['minimum'] ) ) {
					$parameter['schema']['minimum'] = $value['minimum'];
					$parameter['schema']['format']  = 'number';
				}
				if ( ! empty( $value['maximum'] ) ) {
					$parameter['schema']['maximum'] = $value['maximum'];
					$parameter['schema']['format']  = 'number';
				}
				if ( is_array( $value['type'] ) ) {
					if ( in_array( 'integer', $value['type'] ) ) {
						$value['type'] = 'integer';
					} elseif ( in_array( 'array', $value['type'] ) ) {
						$value['type'] = 'array';
					}
				}

				if ( ! empty( $value['type'] ) ) {
					if ( $value['type'] == 'array' ) {
						$parameter['schema']['type']  = $value['type'];
						$parameter['schema']['items'] = array( 'type' => 'string' );
						if ( isset( $value['items']['enum'] ) ) {
							$parameter['schema']['items']['enum'] = $value['items']['enum'];
						}
						if ( isset( $value['items']['properties'] ) && $value['items']['type'] == 'object' ) {
							$parameter['schema']['items']               = array(
								'type'       => 'object',
								'properties' => $value['items']['properties']
							);
							$parameter['schema']['items']['properties'] = $this->cleanParameter( $parameter['schema']['items']['properties'] );
						}
						if ( isset( $parameter['schema']['default'] ) && ! is_array( $parameter['schema']['default'] ) && $parameter['schema']['default'] != null ) {
							$parameter['schema']['default'] = isset( $parameter['default'] ) ? array( $parameter['default'] ) : array();
						}
					} elseif ( $value['type'] == 'object' ) {
						if ( isset( $value['properties'] ) || ! empty( $value['properties'] ) ) {
							$parameter['schema']['type']       = 'object';
							$parameter['schema']['properties'] = $value['properties'];
							$parameter['schema']['properties'] = $this->cleanParameter( $parameter['schema']['properties'] );
						} else {
							$parameter['schema']['type'] = 'string';
						}
						if ( empty( $value['properties'] ) ) {
							$parameter['schema']['type'] = 'string';
						}
					} elseif ( $value['type'] == 'date-time' ) {
						$parameter['schema']['type']   = 'string';
						$parameter['schema']['format'] = 'date-time';
					} elseif ( is_array( $value['type'] ) && in_array( 'string', $value['type'] ) ) {
						$parameter['schema']['type']   = 'string';
						$parameter['schema']['format'] = 'date-time';
					} elseif ( $value['type'] == 'null' ) {
						$parameter['schema']['type']     = 'string';
						$parameter['schema']['nullable'] = true;
					} else {
						$parameter['schema']['type'] = $value['type'];
					}
					if ( isset( $parameter['default'] ) && is_array( $parameter['default'] ) && $parameter['type'] == 'string' ) {
						$parameter['schema']['default'] = "";
					}
				}

				if ( ! in_array( $parameter['name'], $pathParamName ) ) {
					if ( $methodName === 'POST' ) {
						unset( $parameter['in'] );
						unset( $parameter['explode'] );
						unset( $parameter['style'] );
						unset( $parameter['required'] );
					} else {
						unset( $parameter['type'] );
						$parameters[] = $parameter;
					}
				} else {
					$key_index = array_search( $key, array_column( $parameters, 'name' ) );
					if ( isset( $parameters[ $key_index ] ) && $parameters[ $key_index ]['in'] == 'path' ) {
						if ( strpos( $key, 'id' ) !== false ) {
							$parameters[ $key_index ]["schema"]["type"] = "integer";
							$parameters[ $key_index ]["description"]    = $value['description'];
						}
					}
				}
			}
		}

		return $parameters;
	}

	private function prepare_object_content( $schema ) {
		$object_content = "\n\n## {$schema['tags']['name']} object \n\n {$schema['tags']['object_description']}";

		$object_properties = '<ol>';
		foreach ( $schema['properties'] as $property_key => $property ) {
			if ( isset( $property['skip_openapi'] ) && true === $property['skip_openapi'] ) {
				continue;
			}
			$type = is_array( $property['type'] ) ? implode( ' or ', $property['type'] ) : $property['type'];

			$deprecated = '';
			if ( isset( $property['deprecated'] ) ) {
				$deprecated = '<b style="color: red"><i>(Deprecated)</i></b>';
			}

			if ( $type == 'object' ) {
				$object_properties .= '<li><strong>' . $property_key . '</strong> (<span>' . $type . '</span>) '.$deprecated.': <p>' . $property['description'] . '</p>';
				$object_properties .= $this->read_properties( $property['properties'] ) . '</li>';
			} else {
				$object_properties .= '<li><strong>' . $property_key . '</strong> (<span>' . $type . '</span>) '.$deprecated.': <p>' . $property['description'] . '</p></li>';
			}
		}
		$object_properties .= '</ol>';

		return $object_content . $object_properties;
	}

	private function read_properties( $properties ) {
		$object_content = '';
		if ( isset( $properties ) ) {
			$object_content = '<ul>';
			foreach ( $properties as $key => $value ) {
				if ( $value['type'] == 'object' ) {
					$object_content .= '<li><strong>' . $key . '</strong> (<span>' . $value['type'] . '</span>): <p>' . $value['description'] . '</p>';
					$object_content .= $this->read_properties( $value['properties'] ) . '</li>';
				} else {
					if ( isset( $value['description'] ) ) {
						$type           = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];
						$object_content .= '<li><strong>' . $key . '</strong> (<span>' . $type . '</span>): <p>' . $value['description'] . '</p></li>';
					}
				}
			}
			$object_content .= '</ul>';
		}

		return $object_content;
	}


	private function get_responses( $endpointName, $methodName, $outputSchema ) {
		//If the endpoint is not grabbing a specific object then
		//assume it's returning a list
		$outputSchemaForMethod = $outputSchema;

		if ( $methodName == 'GET' && ! preg_match( '/}$/', $endpointName ) ) {
			if (
				! preg_match( '/activity\/{id}\/comment/', $endpointName ) &&
				! preg_match( '/members\/me/', $endpointName ) &&
				! preg_match( '/users\/me/', $endpointName ) &&
				! preg_match( '/messages\/search-thread/', $endpointName )
			) {
				$outputSchemaForMethod = array(
					'type'  => 'array',
					'items' => $outputSchemaForMethod
				);
			}
		}

		$responses = array(
			200       => array(
				'description' => "successful operation",
				'content'     => array(
					'application/json' => array(
						'schema' => $outputSchemaForMethod,
					)
				)
			),
			400       => array(
				'description' => "Invalid ID supplied",
				'content'     => array(
					'application/json' => array(
						'schema'  => array( '$ref' => '#/components/schemas/wp_error' ),
						'example' => array( '$ref' => '#/components/examples/400' )
					)
				)
			),
			404       => array(
				'description' => "object not found",
				'content'     => array(
					'application/json' => array(
						'schema'  => array( '$ref' => '#/components/schemas/wp_error' ),
						'example' => array( '$ref' => '#/components/examples/404' )
					)
				)
			),
			500       => array(
				'description' => "server error",
				'content'     => array(
					'application/json' => array(
						'schema'  => array( '$ref' => '#/components/schemas/wp_error' ),
						'example' => array( '$ref' => '#/components/examples/500' )
					)
				)
			),
			'default' => array(
				'description' => "error",
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/wp_error' )
					)
				)
			)
		);

		if ( in_array( $methodName, array( 'POST', 'PATCH', 'PUT' ) ) && ! preg_match( '/}$/', $endpointName ) ) {
			//This are actually 201's in the default API - but joy of joys this is unreliable
			$responses[201] = array(
				'description' => "successful operation",
				'content'     => array(
					'application/json' => array(
						'schema' => $outputSchemaForMethod
					)
				)
			);
		}

		return $responses;
	}

	private function get_info() {
		return array(
			'version'     => '1.0',
			'title'       => 'BuddyBoss App REST API',
			'description' => 'BuddyBoss App REST API Documentation for BuddyBoss Platform and BuddyBoss App.<p><strong>Purpose:</strong></p>

<ul>
	<li>Provides a way for mobile apps (built using React Native or other frameworks) to interact with BuddyBoss Platform features and data.</li>
	<li>Enables app developers to create custom mobile experiences for BuddyBoss communities.</li>
</ul>

<p><strong>Key Features:</strong></p>

<ul>
	<li><strong>Authentication:</strong>&nbsp;Handles user login,&nbsp;registration,&nbsp;and token management.</li>
	<li><strong>Data Retrieval:</strong>&nbsp;Fetches users,&nbsp;groups,&nbsp;activities,&nbsp;posts,&nbsp;forums,&nbsp;private messages,&nbsp;notifications,&nbsp;and more.</li>
	<li><strong>Content Creation:</strong>&nbsp;Allows users to create posts,&nbsp;comments,&nbsp;follow/unfollow,&nbsp;like/unlike,&nbsp;create groups,&nbsp;send messages,&nbsp;etc.</li>
	<li><strong>Profile Management:</strong>&nbsp;Facilitates profile updates,&nbsp;avatar changes,&nbsp;settings adjustments,&nbsp;and friend management.</li>
	<li><strong>Push Notifications:</strong>&nbsp;Supports sending push notifications to users through third-party services.</li>
</ul>

<p><strong>Endpoints (Examples):</strong></p>

<ul>
	<li><strong>Members:</strong>
		<ul>
		<li><code>/members</code></li>
		<li><code>/members/(id)</code></li>
		</ul>
	</li>
	<li><strong>Social groups:</strong>
		<ul>
		<li><code>/groups</code></li>
		<li><code>/groups/(id)</code></li>
		</ul>
	</li>
	<li><strong>Blog Posts:</strong>
		<ul>
		<li><code>/posts</code></li>
		<li><code>/posts/(id)</code></li> 
		</ul>
	</li>
</ul>',
		);
	}

	private function get_server() {
		return array(
			array(
				'url' => get_rest_url()
			)
		);
	}

	/**
	 * @since [BBAPPVERSION]
	 * @return array[][]
	 */
	private function get_default_components() {
		return array(
			'schemas'         => array(
				'wp_error' => array(
					'properties' => array(
						'code'    => array(
							'type' => 'string'
						),
						'message' => array(
							'type' => 'string'
						),
						'data'    => array(
							'type'       => 'object',
							'properties' => array(
								'status' => array(
									'type' => 'integer'
								),
							)
						)
					)
				)
			),
			'securitySchemes' => array(
				"accessToken" => array(
					"type"        => "apiKey",
					"name"        => "accessToken",
					"in"          => "header",
					"description" => "To generate token, submit a POST request to `{{rootUrl}}buddyboss-app/auth/v2/jwt/login` endpoint. With username and password as the parameters.
					It will validates the user credentials, and returns success response including a token if the authentication is correct or returns an error response if the authentication is failed."
				)
			),
			'examples'        => array(
				'400' => array(
					'code'    => 'rest_invalid_param',
					'message' => 'Invalid parameter(s): id',
					'data'    => array(
						'status' => 400
					)
				),
				'404' => array(
					'code'    => 'rest_no_route',
					'message' => 'No route was found matching the URL and request method.',
					'data'    => array(
						'status' => 404
					)
				),
				'500' => array(
					'code'    => 'rest_server_error',
					'message' => 'Server error.',
					'data'    => array(
						'status' => 500
					)
				),
			)
		);
	}

	private function compose_operation_name( $carry, $part ) {
		$carry .= ucfirst( strtolower( $part ) );

		return $carry;
	}

	private function cleanParameter( $properties ) {
		foreach ( (array) $properties as $key => $t ) {
			if ( $properties[ $key ]['type'] == 'array' ) {
				if ( $properties[ $key ]['items']['type'] == 'object' ) {
					$properties[ $key ]['items']['properties'] = $this->cleanParameter( $properties[ $key ]['items']['properties'] );
				}
			}
			if ( $properties[ $key ]['type'] == 'object' ) {
				if ( isset( $properties[ $key ]['context'] ) ) {
					unset( $properties[ $key ]['context'] );
				}
				if ( isset( $properties[ $key ]['properties'] ) || ! empty( $properties[ $key ]['properties'] ) ) {
					$properties[ $key ]['properties'] = $this->cleanParameter( $properties[ $key ]['properties'] );
				} else {
					$properties[ $key ]['type'] = 'string';
				}
			} else {
				if ( is_array( $t['type'] ) ) {
					$properties[ $key ]['type'] = 'string';
				}
				if ( $t['type'] == 'mixed' ) {
					$properties[ $key ]['type'] = 'string';
				}
				if ( $properties[ $key ]['type'] == 'null' ) {
					$properties[ $key ]['type']     = 'string';
					$properties[ $key ]['nullable'] = true;
				}
				if ( isset( $t['context'] ) ) {
					unset( $properties[ $key ]['context'] );
				}
				if ( isset( $t['sanitize_callback'] ) ) {
					unset( $properties[ $key ]['sanitize_callback'] );
				}
				if ( isset( $t['validate_callback'] ) ) {
					unset( $properties[ $key ]['validate_callback'] );
				}
				if ( isset( $t['required'] ) ) {
					unset( $properties[ $key ]['required'] );
				}
				if ( isset( $t['readonly'] ) ) {
					unset( $properties[ $key ] );
				}
			}
		}

		return $properties;
	}

	/**
	 * Turns the schema set up by the endpoint into a swagger definition.
	 *
	 * @param array $schema
	 *
	 * @return array Definition
	 */
	private function schemaIntoDefinition( $schema ) {
		if ( ! empty( $schema['$schema'] ) ) {
			unset( $schema['$schema'] );
		}
		if ( ! empty( $schema['links'] ) ) {
			unset( $schema['links'] );
		}
		if ( ! empty( $schema['readonly'] ) ) {
			unset( $schema['readonly'] );
		}
//		if ( ! empty( $schema['context'] ) ) {
//			unset( $schema['context'] );
//		}

		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		if ( isset( $schema['items'] ) ) {
			unset( $schema['items'] );
		}

		foreach ( $schema['properties'] as $name => &$prop ) {
			if ( ! empty( $prop['arg_options'] ) ) {
				unset( $prop['arg_options'] );
			}
			if ( ! empty( $prop['$schema'] ) ) {
				unset( $prop['$schema'] );
			}
			if ( ! empty( $prop['in'] ) ) {
				unset( $prop['in'] );
			}
			if ( ! empty( $prop['validate_callback'] ) ) {
				unset( $prop['validate_callback'] );
			}
//			if ( ! empty( $prop['context'] ) ) {
//				unset( $prop['context'] );
//			}
			if ( ! empty( $prop['readonly'] ) ) {
				unset( $prop['readonly'] );
			}
//			if ( ! empty( $prop['items']['context'] ) ) {
//				unset( $prop['items']['context'] );
//			}
			if ( isset( $prop['default'] ) && is_array( $prop['default'] ) ) {
				unset( $prop['default'] );
			}
			if ( is_array( $prop['type'] ) ) {
				$prop['type'] = $prop['type'][0];
			}
			if ( isset( $prop['default'] ) && empty( $prop['default'] ) ) {
				unset( $prop['default'] );
			}
			if ( $prop['type'] == 'mixed' ) {
				$prop['type'] = 'string';
			}
			if ( $prop['type'] == 'null' ) {
				$prop['type']     = 'string';
				$prop['nullable'] = true;
			}

			if ( ! empty( $prop['properties'] ) ) {
				$prop['type'] = 'object';
				unset( $prop['default'] );
				$prop = $this->schemaIntoDefinition( $prop );
			} elseif ( isset( $prop['properties'] ) ) {
				$prop['properties'] = array( 'id' => array( 'type' => 'integer' ) );
				// $prop['properties'] = new stdClass();
			}

			//-- Changes by Richi
			if ( ! empty( $prop['enum'] ) ) {
				if ( isset( $prop['enum'][0] ) && $prop['enum'][0] == "" ) {
					if ( count( $prop['enum'] ) > 1 ) {
						array_shift( $prop['enum'] );
					} else {
						$prop['enum'][0] = "NONE";
					}
				};
			}

			if ( ! empty( $prop['default'] ) && $prop['default'] == null ) {
				unset( $prop['default'] );
			}
			//--
			if ( $prop['type'] == 'object' && ( ! isset( $prop['properties'] ) || empty( $prop['properties'] ) ) ) {
				if ( ! empty( $prop['items'] ) ) {
					unset( $prop['items'] );
				}
				$prop['properties'] = array( 'id' => array( 'type' => 'integer' ) );
			}
			if ( $prop['type'] == 'array' ) {
				if ( isset( $prop['items']['type'] ) && $prop['items']['type'] === 'object' ) {
					$prop['items'] = $this->schemaIntoDefinition( $prop['items'] );
				} elseif ( isset( $prop['items']['type'] ) ) {
					if ( is_array( $prop['items']['type'] ) ) {
						$prop['items'] = array( 'type' => $prop['items']['type'][1] );
					} else {
						$prop['items'] = array( 'type' => $prop['items']['type'] );
					}
				} else {
					$prop['items'] = array( 'type' => 'string' );
				}
			} elseif ( $prop['type'] == 'date-time' ) {
				$prop['type']   = 'string';
				$prop['format'] = 'date-time';
			} elseif ( is_array( $prop['type'] ) && in_array( 'string', $prop['type'] ) ) {
				$prop['type']   = 'string';
				$prop['format'] = 'date-time';
			}
			if ( $prop['type'] == 'bool' ) {
				$prop['type'] = 'boolean';
			}
			if ( isset( $prop['enum'] ) ) {
				$prop['enum'] = array_values( $prop['enum'] );
			}
			if ( isset( $prop['required'] ) ) {
				unset( $prop['required'] );
			}
			if ( isset( $prop['readonly'] ) ) {
				unset( $prop['readonly'] );
			}
//			if ( isset( $prop['context'] ) ) {
//				unset( $prop['context'] );
//			}
		}

		return $schema;
	}

}
