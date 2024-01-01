<?php

/**
 * Swagger base class.
 */
class WP_REST_Swagger_Controller extends WP_REST_Controller {

	private $all_tags = array();


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
			// don't include self - that's a bit meta
			if ( $endpointName == '/' . $this->namespace . '/swagger' ) {
				continue;
			}

			if ( defined( 'COBEIA_API_SCHEMA' ) ) {
				if ( ! array_filter( COBEIA_API_SCHEMA, function ( $filter_endpoint ) use ( $endpointName ) {
					if ( strpos( $endpointName, $filter_endpoint ) !== false ) {
						return $endpointName;
					}

					return false;
				} ) ) {
					continue;
				}

				// Todo: This added for filter some endpoints.
				if ( defined( 'COBEIA_ENDPOINTS_SCHEMA' ) ) {
					if ( ! array_filter( COBEIA_ENDPOINTS_SCHEMA, function ( $filter_endpoint ) use ( $endpointName ) {
						if ( strpos( $endpointName, $filter_endpoint ) !== false ) {
							if ( $endpointName === $filter_endpoint ) { // Todo: Render only required endpoints.
								return $endpointName;
							}
						}
					} ) ) {
						continue;
					}
				}
			}

			$route_options = $restServer->get_route_options( $endpointName );

			if ( ! empty( $route_options['schema'][1] ) ) {
				$schema = call_user_func( array( $route_options['schema'][0], $route_options['schema'][1] ) );
				if ( isset( $schema['title'] ) && $schema['title'] ) {
					$schema['title'] = str_replace( "/", "_", $route_options['namespace'] ) . '_' . str_replace( " ", "_", $schema['title'] );

					$swagger['components']['schemas'][ $schema['title'] ] = $this->schemaIntoDefinition( $schema );
					$outputSchema                                         = array( '$ref' => '#/components/schemas/' . $schema['title'] );
				}

				if ( isset( $schema['tags'] ) && $schema['tags'] ) {
					$tags                          = array( $schema['tags']['name'] );
					$object_content                = $this->prepare_object_content( $schema );
					$schema['tags']['description'] = $schema['tags']['description'] . '<br><br>' . $object_content;
					$this->all_tags[]              = $schema['tags'];
				} else {
					$tags = explode( '/', $endpointName );
					$tags = array( $tags[1] );
				}
			} else {
				//if there is no schema then it's a safe bet that this API call
				//will not work - move to the next one.
				continue;
			}


			$defaultidParams = array();
			//Replace endpoints var and add to the parameters required
			$endpointName = preg_replace_callback(
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
			// $endpointName = str_replace(site_url(), '',rest_url($endpointName));
			$endpointName = str_replace( $basePath, '', $endpointName );

			if ( empty( $swagger['paths'][ $endpointName ] ) ) {
				$swagger['paths'][ $endpointName ] = array();
			}

			foreach ( $endpoint as $endpointPart ) {
				foreach ( $endpointPart['methods'] as $methodName => $method ) {
					if ( in_array( $methodName, array( 'PUT', 'PATCH' ) ) ) {
						continue;
					} //duplicated by post

					$parameters   = $defaultidParams;
					$param_schema = array();
					$get_data     = $this->get_parameters_and_request_body_schema( $endpointPart, $methodName, $parameters, $defaultidParams, $param_schema );
					$parameters   = $get_data['parameter'];
					$param_schema = $get_data['schema'];

					if ( $methodName === 'POST' && ! empty( $param_schema ) ) {
						$this->removeDuplicates( $param_schema );
						$properties = $this->get_properties( $param_schema );
					} else {
						$this->removeDuplicates( $parameters );
					}

					$security = array(
						array( 'accessToken' => array() )
					);


					$paths_args = array(
						'endpointName' => $endpointName,
						'methodName'   => $methodName,
						'tags'         => $tags,
						'parameters'   => $parameters,
						'security'     => 'GET' !== $methodName ? $security : array(),
						'responses'    => $this->get_responses( $endpointName, $methodName, $outputSchema ),
						'properties'   => $properties,
						'param_schema' => $param_schema
					);

					$swagger['paths'] = $this->get_paths( $swagger['paths'], $endpointPart, $paths_args );
				}
			}
		}


		$swagger['tags'] = $this->get_tags();


		$response = rest_ensure_response( $swagger );

		return apply_filters( 'rest_prepare_meta_value', $response, $request );
	}

	private function prepare_object_content( $schema ) {
		$object_content = "\n\n## {$schema['tags']['name']} object \n\n {$schema['tags']['object_description']} \n\n ";
		$object_content .= '<ul>';
		foreach ( $schema['properties'] as $key => $value ) {
			$type           = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];
			$object_content .= '<li><strong>' . $key . '</strong> (<span>' . $type . '</span>) - ' . $value['description'] . '</li>';
			if ( $value['properties'] ) {
				$object_content .= '<ul>';
				foreach ( $value['properties'] as $key => $value ) {
					$type           = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];
					$object_content .= '<li><strong>' . $key . '</strong> (<span>' . $type . '</span>) - ' . $value['description'] . '</li>';
				}
				$object_content .= '</ul>';
				continue;
			}
		}

		return $object_content;
	}

	private function get_paths( $paths, $endpointPart, $paths_args ) {
		extract( $paths_args );

		$operationId = ucfirst( strtolower( $methodName ) ) . array_reduce( explode( '/', preg_replace( "/{(\w+)}/", 'by/${1}', $endpointName ) ),
				array( $this, "compose_operation_name" ) );

		$summary     = '';
		$description = '';
		if ( ! empty( $endpointPart['openapi_data'] ) ) {
			if ( isset( $endpointPart['openapi_data']['summary'] ) ) {
				$summary = $endpointPart['openapi_data']['summary'];
			}
			if ( isset( $endpointPart['openapi_data']['description'] ) ) {
				$description = $endpointPart['openapi_data']['description'];
			}
		}

		$paths[ $endpointName ][ strtolower( $methodName ) ] = array(
			'summary'     => $summary,
			'description' => $description,
			'tags'        => $tags,
			'parameters'  => $parameters,
			'security'    => $security,
			'responses'   => $responses,
			'operationId' => $operationId
		);
		if ( $methodName === 'POST' && ! empty( $param_schema ) ) {
			$paths[ $endpointName ][ strtolower( $methodName ) ]['x-bb-request-body-name'] = 'body';
			$paths[ $endpointName ][ strtolower( $methodName ) ]['requestBody']            = array(
				'content' => array(
					'application/json' => array(
						'schema' => array(
							'type'       => 'object',
							'title'      => ucfirst( strtolower( $methodName ) ) . array_reduce( explode( '/', preg_replace( "/{(\w+)}/", 'by/${1}', $endpointName ) ),
									array( $this, "compose_operation_name" ) ) . 'Input',
							'properties' => $properties
						)
					)
				)
			);
		}

		return $paths;
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
						'schema' => $outputSchemaForMethod
					)
				)
			),
			400       => array(
				'description' => "Invalid ID supplied",
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/wp_error' )
					)
				)
			),
			404       => array(
				'description' => "object not found",
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/wp_error' )
					)
				)
			),
			500       => array(
				'description' => "server error",
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/wp_error' )
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
			'description' => 'The BuddyBoss App REST API provides an interface for the mobile app to interact with WordPress by sending and receiving objects.

When you request content from or send content to the API, the response will be returned in JSON format. Sending content is done in two formats, depending on the endpoint: "application/json" or "multipart/form-data". For how to use both formats you can check MDN Web Docs.

Using the BuddyBoss App REST API you can develop additional screens and functionality in your app. For making custom requests from the BuddyBoss App, make sure you check the Fetching Data from APIs tutorial where we provide a function that will make this task easier.
1.0.0 ',
		);
	}

	private function get_title() {
		$title = get_bloginfo( 'name' );
		$host  = parse_url( site_url( '/' ), PHP_URL_HOST ) . ':' . parse_url( site_url( '/' ), PHP_URL_PORT );
		if ( empty( $title ) ) {
			$title = $host;
		}

		return $title;
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
								)
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
			)
		);
	}

	/**
	 * List of tags.
	 *
	 *
	 * @since [BBAPPVERSION]
	 * @return array
	 */
	private function get_tags() {
		// Convert each array element to a string representation and filter out duplicates
		$uniqueTags = array_map( "unserialize", array_unique( array_map( "serialize", $this->all_tags ) ) );

		// Convert the serialized strings back to arrays
		return array_values( $uniqueTags );
	}


	private function get_properties( $schema ) {
		$properties = array();
		foreach ( $schema as $index => $t ) {
			$properties[ $t['name'] ] = $t;
			if ( empty( $properties[ $t['name'] ]['type'] ) ) {
				if ( ! empty( $properties[ $t['name'] ]['schema']['type'] ) ) {
					$properties[ $t['name'] ]['type'] = $properties[ $t['name'] ]['schema']['type'];
				} else {
					$properties[ $t['name'] ]['type'] = 'string';
				}
			}
			if ( ! empty( $properties[ $t['name'] ]['schema']['items'] ) ) {
				$properties[ $t['name'] ]['items'] = $properties[ $t['name'] ]['schema']['items'];
			}
			if ( ! empty( $properties[ $t['name'] ]['schema']['enum'] ) ) {
				$properties[ $t['name'] ]['enum'] = $properties[ $t['name'] ]['schema']['enum'];
			}
			if ( ! empty( $properties[ $t['name'] ]['schema']['properties'] ) ) {
				$properties[ $t['name'] ]['properties'] = $properties[ $t['name'] ]['schema']['properties'];
			}
			unset( $properties[ $t['name'] ]['schema']['type'] );
			unset( $properties[ $t['name'] ]['name'] );
			unset( $properties[ $t['name'] ]['schema'] );
		}

		return $properties;
	}

	private function get_parameters_and_request_body_schema( $endpointPart, $methodName, $parameters, $defaultidParams = array(), $schema = array() ) {
		$pathParamName = array_map( function ( $param ) {
			return $param['name'];
		}, $defaultidParams );

		//Clean up parameters
		foreach ( $endpointPart['args'] as $pname => $pdetails ) {
			if ( isset( $parameters[ $key ] ) && $parameters[ $key ]['in'] == 'path' ) {
				if ( strpos( $pname, 'id' ) !== false ) {
					$parameters[ $key ]["schema"]["type"] = "integer";
				}
			}
			$parameter = array(
				'name'    => $pname,
				'in'      => $methodName == 'POST' ? 'formData' : 'query',
				'style'   => 'form',
				'explode' => 'false'
			);
			$key       = array_search( $pname, array_column( $parameters, 'name' ) );
			if ( ! empty( $pdetails['description'] ) ) {
				$parameter['description'] = $pdetails['description'];
			}
			if ( ! empty( $pdetails['format'] ) ) {
				$parameter['schema']['format'] = $pdetails['format'];
			}
			if ( ! empty( $pdetails['default'] ) ) {
				$parameter['schema']['default'] = $pdetails['default'];
			}
			if ( ! empty( $pdetails['enum'] ) ) {
				$parameter['schema']['enum'] = array_values( $pdetails['enum'] );
			}
			if ( ! empty( $pdetails['required'] ) ) {
				$parameter['required'] = $pdetails['required'];
			}
			if ( ! empty( $pdetails['minimum'] ) ) {
				$parameter['schema']['minimum'] = $pdetails['minimum'];
				$parameter['schema']['format']  = 'number';
			}
			if ( ! empty( $pdetails['maximum'] ) ) {
				$parameter['schema']['maximum'] = $pdetails['maximum'];
				$parameter['schema']['format']  = 'number';
			}
			if ( is_array( $pdetails['type'] ) ) {
				if ( in_array( 'integer', $pdetails['type'] ) ) {
					$pdetails['type'] = 'integer';
				} elseif ( in_array( 'array', $pdetails['type'] ) ) {
					$pdetails['type'] = 'array';
				}
			}
			if ( ! empty( $pdetails['type'] ) ) {
				if ( $pdetails['type'] == 'array' ) {
					$parameter['schema']['type']  = $pdetails['type'];
					$parameter['schema']['items'] = array( 'type' => 'string' );
					if ( isset( $pdetails['items']['enum'] ) ) {
						$parameter['schema']['items']['enum'] = $pdetails['items']['enum'];
					}
					if ( $pdetails['items']['type'] == 'object' && isset( $pdetails['items']['properties'] ) ) {
						$parameter['schema']['items']               = array(
							'type'       => 'object',
							'properties' => $pdetails['items']['properties']
						);
						$parameter['schema']['items']['properties'] = $this->cleanParameter( $parameter['schema']['items']['properties'] );
					}
					if ( isset( $parameter['schema']['default'] ) && ! is_array( $parameter['schema']['default'] ) && $parameter['schema']['default'] != null ) {
						$parameter['schema']['default'] = array( $parameter['default'] );
					}
				} elseif ( $pdetails['type'] == 'object' ) {
					if ( isset( $pdetails['properties'] ) || ! empty( $pdetails['properties'] ) ) {
						$parameter['schema']['type']       = 'object';
						$parameter['schema']['properties'] = $pdetails['properties'];
						$parameter['schema']['properties'] = $this->cleanParameter( $parameter['schema']['properties'] );
					} else {
						$parameter['schema']['type'] = 'string';
					}
					if ( ! isset( $pdetails['properties'] ) || empty( $pdetails['properties'] ) ) {
						$parameter['schema']['type'] = 'string';
					}
				} elseif ( $pdetails['type'] == 'date-time' ) {
					$parameter['schema']['type']   = 'string';
					$parameter['schema']['format'] = 'date-time';
				} elseif ( is_array( $pdetails['type'] ) && in_array( 'string', $pdetails['type'] ) ) {
					$parameter['schema']['type']   = 'string';
					$parameter['schema']['format'] = 'date-time';
				} elseif ( $pdetails['type'] == 'null' ) {
					$parameter['schema']['type']     = 'string';
					$parameter['schema']['nullable'] = true;
				} else {
					$parameter['schema']['type'] = $pdetails['type'];
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
					array_push( $schema, $parameter );
				} else {
					unset( $parameter['type'] );
					$parameters[] = $parameter;
				}
			}
		}

		return array( 'parameter' => $parameters, 'schema' => $schema );
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

	private function removeDuplicates( $params ) {
		$isdupblicate = array();
		foreach ( $params as $index => $t ) {
			if ( isset( $isdupblicate[ $t["name"] ] ) ) {
				array_splice( $params, $index, 1 );
				continue;
			}
			$isdupblicate[ $t["name"] ] = true;
		}

		$isdupblicate2 = array();
		foreach ( $params as $index => $t ) {
			if ( isset( $isdupblicate2[ $t["name"] ] ) ) {
				array_splice( $params, $index, 1 );
				continue;
			}
			$isdupblicate2[ $t["name"] ] = true;
		}
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
		if ( ! empty( $schema['context'] ) ) {
			unset( $schema['context'] );
		}

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
			if ( ! empty( $prop['context'] ) ) {
				unset( $prop['context'] );
			}
			if ( ! empty( $prop['readonly'] ) ) {
				unset( $prop['readonly'] );
			}
			if ( ! empty( $prop['items']['context'] ) ) {
				unset( $prop['items']['context'] );
			}
			if ( is_array( $prop['default'] ) ) {
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
			if ( isset( $prop['context'] ) ) {
				unset( $prop['context'] );
			}
		}

		return $schema;
	}


}
