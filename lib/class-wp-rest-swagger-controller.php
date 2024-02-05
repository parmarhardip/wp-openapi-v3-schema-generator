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
			'openapi'      => '3.1.0',
			'info'         => $this->get_info(),
			'servers'      => $this->get_server(),
			'paths'        => array(),
			'components'   => $this->get_default_components(),
			'externalDocs' => array(
				'description' => 'This is extranal docs description for buddyboss app.',
				'url'         => 'https://buddyboss.gitbook.io/buddyboss-app/'
			)
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
		$openapi_schema         = $this->schemaIntoDefinition( $schema );
		$schema_tag_description = $this->prepare_object_content( $openapi_schema );
		if ( ! empty( $schema['tags'] ) ) {
			$schema['tags']['description'] = $schema['tags']['description'] . "<div>{$schema_tag_description}</div>";
			$swagger['tags'][]             = $schema['tags'];
		}

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

				// Generate embed schema.
				if ( ! isset( $swagger['components']['schemas'][ $operationId . 'Embed' ] ) ) {
					$swagger['components']['schemas'][ $operationId . 'Embed' ] = $this->componentSchema( $openapi_schema, 'embed' );
				}

				$context = 'view';
				if ( $methodName === 'POST' ) {
					$context     = 'edit';
					$operationId = ucfirst( strtolower( $methodName ) ) . $operationId;
				}
				if ( ! isset( $swagger['components']['schemas'][ $operationId ] ) ) {
					$swagger['components']['schemas'][ $operationId ] = $this->componentSchema( $openapi_schema, $context );
				}


				$summary      = '';
				$description  = '';
				$tags         = array( 'No Schema' );
				$externalDocs = '';
				if ( isset( $openapi_schema['title'] ) ) {
					$tags = array( $openapi_schema['title'] );
				}
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
					if ( isset( $endpointPart['openapi_data']['externalDocs'] ) ) {
						$externalDocs = $endpointPart['openapi_data']['externalDocs'];
					}
				}

				$parameters  = $this->getParameters( $endpointPart, $methodName, $defaultidParams );
				$requestBody = $this->requestBody( $endpointPart, $methodName );
				$security    = array( array( 'accessToken' => array() ) );
				$response    = $this->get_responses( $endpointName, $methodName, array( '$ref' => '#/components/schemas/' . $operationId ) );

				$swagger['paths'][ $endpointName ][ strtolower( $methodName ) ] = array(
					'summary'      => $summary,
					'description'  => $description,
					'tags'         => $tags,
					'parameters'   => $parameters,
					'security'     => 'GET' !== $methodName ? $security : array(),
					'responses'    => $response,
					'operationId'  => ucfirst( strtolower( $methodName ) ) . $operationId,
					"externalDocs" => $externalDocs
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
									'properties' => $requestBody,
									'required'   => array_keys(
										array_filter(
											$requestBody,
											function ( $item ) {
												return ! empty( $item['required'] );
											}
										)
									),

								)
							)
						)
					);
				}
			}
		}
	}

	private function componentSchema( $openapi_schema, $context = 'view' ) {
		if ( ! empty( $openapi_schema['properties'] ) ) {
			foreach ( $openapi_schema['properties'] as $property_key => $property ) {
				if ( ! empty( $property['context'] ) && ! in_array( $context, $property['context'] ) ) {
					unset( $openapi_schema['properties'][ $property_key ] );
				}
			}
		}

		return $openapi_schema;
	}

	private function requestBody( $endpointPart, $methodName ) {
		$requestBody = array();
		if ( $methodName === 'POST' && $endpointPart['args'] ) {
			foreach ( $endpointPart['args'] as $key => $value ) {
				$requestBody[ $key ] = ! empty( $value['type'] ) ? array( 'type' => $value['type'] ) : array();
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
				if ( isset( $value['type'] ) && is_array( $value['type'] ) ) {
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

	private function prepare_object_content( $schema, $parentObjectName = '' ) {
		if ( ! isset( $schema['tags']['name'] ) ) {
			return '';
		}

		$object_content = "\n\n## {$schema['tags']['name']} schema \n\n {$schema['tags']['object_description']}";

		$object_properties = '<ol style="list-style-type: decimal; padding-left: 20px;">';

		foreach ( $schema['properties'] as $property_key => $property ) {
			if ( isset( $property['skip_openapi'] ) && true === $property['skip_openapi'] ) {
				continue;
			}

			$type = is_array( $property['type'] ) ? implode( ' or ', $property['type'] ) : $property['type'];

			$context    = isset( $property['context'] ) ? implode( ', ', $property['context'] ) : '';
			$enum       = isset( $property['enum'] ) ? implode( ', ', $property['enum'] ) : '';
			$dependency = isset( $property['dependency'] ) ? implode( ', ', $property['dependency'] ) : '';

			$object_properties .= "<li style='margin-bottom: 10px;'><strong>{$property_key}</strong>";
			$object_properties .= "<ul style='list-style-type: disc; margin-top: 5px;'>";
			$object_properties .= "<li><strong>Type:</strong> {$type}</li>";
			$object_properties .= "<li><strong>Description:</strong> {$property['description']}</li>";

			if ( isset( $property['deprecated'] ) ) {
				$object_properties .= "<li style='color: red;'><strong>Deprecated:</strong> Yes</li>";
			}

			if ( ! empty( $context ) ) {
				$object_properties .= "<li><strong>Context:</strong> {$context}</li>";
			}

			if ( ! empty( $enum ) ) {
				$object_properties .= "<li><strong>Enum:</strong> {$enum}</li>";
			}

			if ( ! empty( $dependency ) ) {
				$object_properties .= "<li><strong>Dependency:</strong> {$dependency}</li>";
			}
			if ( isset( $property['readonly'] ) ) {
				$object_properties .= "<li><strong>Read-only:</strong> Yes</li>";
			}
			if ( $type == 'object' && isset( $property['properties'] ) && ! empty( $property['properties'] ) ) {
				$object_properties .= "<li><strong>Properties:</strong>";
				$object_properties .= $this->read_properties_list( $property['properties'], $property_key );
				$object_properties .= "</li>";
			}
			$object_properties .= "</ul></li>";
		}

		$object_properties .= '</ol>';

		return $object_content . $object_properties;
	}

	private function read_properties_list( $properties, $parentObjectName = '' ) {
		$object_content = '<ul style="list-style-type: circle; margin-top: 5px;">';

		foreach ( $properties as $key => $value ) {
			$type = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];

			$context    = isset( $value['context'] ) ? implode( ', ', $value['context'] ) : '';
			$enum       = isset( $value['enum'] ) ? implode( ', ', $value['enum'] ) : '';
			$dependency = isset( $value['dependency'] ) ? implode( ', ', $value['dependency'] ) : '';

			$object_content .= "<li style='margin-bottom: 10px;'><strong>{$key}</strong>";
			$object_content .= "<ul style='list-style-type: disc; margin-top: 5px;'>";
			$object_content .= "<li><strong>Type:</strong> {$type}</li>";
			$object_content .= "<li><strong>Description:</strong> {$value['description']} </li>";

			if ( isset( $value['deprecated'] ) ) {
				$object_content .= "<li style='color: red;'><strong>Deprecated:</strong> Yes</li>";
			}

			if ( ! empty( $context ) ) {
				$object_content .= "<li><strong>Context:</strong> {$context}</li>";
			}

			if ( ! empty( $enum ) ) {
				$object_content .= "<li><strong>Enum:</strong> {$enum}</li>";
			}

			if ( ! empty( $dependency ) ) {
				$object_content .= "<li><strong>Dependency:</strong> {$dependency}</li>";
			}

			if ( isset( $value['readonly'] ) ) {
				$object_content .= "<li><strong>Read-only:</strong> Yes</li>";
			}

			if ( $type == 'object' && isset( $value['properties'] ) && ! empty( $value['properties'] ) ) {
				$object_content .= "<li><strong>Properties:</strong>";
				$object_content .= $this->read_properties_list( $value['properties'], $key );
				$object_content .= "</li>";
			}
			$object_content .= "</ul></li>";
		}

		$object_content .= '</ul>';

		return $object_content;
	}


//	private function prepare_object_content( $schema, $parentObjectName = '' ) {
//		if ( ! isset( $schema['tags']['name'] ) ) {
//			return '';
//		}
//
//		$object_content = "\n\n## {$schema['tags']['name']} schema \n\n {$schema['tags']['object_description']}";
//
//		$object_properties = '<ul>';
//
//		foreach ( $schema['properties'] as $property_key => $property ) {
//			if ( isset( $property['skip_openapi'] ) && true === $property['skip_openapi'] ) {
//				continue;
//			}
//
//			$type = is_array( $property['type'] ) ? implode( ' or ', $property['type'] ) : $property['type'];
//
//			$context    = isset( $property['context'] ) ? implode( ', ', $property['context'] ) : '';
//			$enum       = isset( $property['enum'] ) ? implode( ', ', $property['enum'] ) : '';
//			$dependency = isset( $property['dependency'] ) ? implode( ', ', $property['dependency'] ) : '';
//
//			$object_properties .= "<li><strong>{$property_key}</strong>:";
//			$object_properties .= "<ul>";
//			$object_properties .= "<li><strong>Type:</strong> {$type}</li>";
//			$object_properties .= "<li><strong>Description:</strong> {$property['description']}</li>";
//			$object_properties .= "<li><strong>Context:</strong> {$context}</li>";
//			if ( isset( $property['deprecated'] ) ) {
//				$object_properties .= "<li><strong>Deprecated:</strong> Yes</li>";
//			}
//			if ( ! empty( $enum ) ) {
//				$object_properties .= "<li><strong>Enum:</strong> {$enum}</li>";
//			}
//			if ( ! empty( $dependency ) ) {
//				$object_properties .= "<li><strong>Dependency:</strong> {$dependency}</li>";
//			}
//			$object_properties .= "</ul></li>";
//
//			if ( $type == 'object' && isset( $property['properties'] ) && ! empty( $property['properties'] ) ) {
//				$object_properties .= $this->read_properties_list( $property['properties'], $property_key );
//			}
//		}
//
//		$object_properties .= '</ul>';
//
//		return $object_content . $object_properties;
//	}
//
//	private function read_properties_list( $properties, $parentObjectName = '' ) {
//		$object_content = '<ul>';
//
//		foreach ( $properties as $key => $value ) {
//			$type = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];
//
//			$deprecated = isset( $value['deprecated'] ) ? 'Yes' : '-';
//			$context    = isset( $value['context'] ) ? implode( ', ', $value['context'] ) : '';
//			$enum       = isset( $value['enum'] ) ? implode( ', ', $value['enum'] ) : '';
//			$dependency = isset( $value['dependency'] ) ? implode( ', ', $value['dependency'] ) : '';
//
//			$object_content .= "<li><strong>{$parentObjectName}.{$key}</strong>:";
//			$object_content .= "<ul>";
//			$object_content .= "<li><strong>Type:</strong> {$type}</li>";
//			$object_content .= "<li><strong>Description:</strong> {$value['description']}</li>";
//			$object_content .= "<li><strong>Context:</strong> {$context}</li>";
//			if ( $deprecated != '-' ) {
//				$object_content .= "<li><strong>Deprecated:</strong> {$deprecated}</li>";
//			}
//			if ( ! empty( $enum ) ) {
//				$object_content .= "<li><strong>Enum:</strong> {$enum}</li>";
//			}
//			if ( ! empty( $dependency ) ) {
//				$object_content .= "<li><strong>Dependency:</strong> {$dependency}</li>";
//			}
//			$object_content .= "</ul></li>";
//
//			if ( $type == 'object' && isset( $value['properties'] ) && ! empty( $value['properties'] ) ) {
//				$object_content .= $this->read_properties_list( $value['properties'], "{$parentObjectName}.{$key}" );
//			}
//		}
//
//		$object_content .= '</ul>';
//
//		return $object_content;
//	}


	private function _prepare_object_content( $schema, $parentObjectName = '' ) {
		if ( ! isset( $schema['tags']['name'] ) ) {
			return '';
		}

		$object_content = "\n\n## {$schema['tags']['name']} schema \n\n {$schema['tags']['object_description']}";

		$object_properties = '<table border="1"><tr><th>Property</th><th>Type</th><th>Description</th><th>Context</th><th>Deprecated</th><th>Enum</th><th>Dependency</th></tr>';

		foreach ( $schema['properties'] as $property_key => $property ) {
			if ( isset( $property['skip_openapi'] ) && true === $property['skip_openapi'] ) {
				continue;
			}

			$type = is_array( $property['type'] ) ? implode( ' or ', $property['type'] ) : $property['type'];

			$deprecated = isset( $property['deprecated'] ) ? 'Yes' : '-';
			$context    = isset( $property['context'] ) ? implode( ', ', $property['context'] ) : '';
			$enum       = isset( $property['enum'] ) ? implode( ', ', $property['enum'] ) : '';
			$dependency = isset( $property['dependency'] ) ? implode( ', ', $property['dependency'] ) : '';

			$object_properties .= '<tr>';
			$object_properties .= "<td><strong>{$property_key}</strong></td>";
			$object_properties .= "<td>{$type}</td>";
			$object_properties .= "<td>{$property['description']}</td>";
			$object_properties .= "<td>{$context}</td>";
			$object_properties .= "<td>{$deprecated}</td>";
			$object_properties .= "<td>{$enum}</td>";
			$object_properties .= "<td>{$dependency}</td>";
			$object_properties .= '</tr>';

			if ( $type == 'object' && isset( $property['properties'] ) && ! empty( $property['properties'] ) ) {
				$object_properties .= $this->read_properties_table( $property['properties'], $property_key );
			}
		}

		$object_properties .= '</table>';

		return $object_content . $object_properties;
	}

	private function _read_properties_table( $properties, $parentObjectName = '' ) {
		$object_content = '';

		foreach ( $properties as $key => $value ) {
			$type = is_array( $value['type'] ) ? implode( ' or ', $value['type'] ) : $value['type'];

			$deprecated = isset( $value['deprecated'] ) ? 'Yes' : '-';
			$context    = isset( $value['context'] ) ? implode( ', ', $value['context'] ) : '';
			$enum       = isset( $value['enum'] ) ? implode( ', ', $value['enum'] ) : '';
			$dependency = isset( $value['dependency'] ) ? implode( ', ', $value['dependency'] ) : '';

			$object_content .= '<tr>';
			$object_content .= "<td><strong>{$parentObjectName}.{$key}</strong></td>";
			$object_content .= "<td>{$type}</td>";
			$object_content .= "<td>{$value['description']}</td>";
			$object_content .= "<td>{$context}</td>";
			$object_content .= "<td>{$deprecated}</td>";
			$object_content .= "<td>{$enum}</td>";
			$object_content .= "<td>{$dependency}</td>";
			$object_content .= '</tr>';

			if ( $type == 'object' && isset( $value['properties'] ) && ! empty( $value['properties'] ) ) {
				$object_content .= $this->read_properties_table( $value['properties'], "{$parentObjectName}.{$key}" );
			}
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
			'version'     => '2.0',
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
</ul>',
			//'description' => "# Introduction\n[comment]: <> (x-product-description-placeholder)\nThe Rebilly API is built on HTTP and is RESTful.\nIt has predictable resource URLs and returns HTTP response codes to indicate errors.\nIt also accepts and returns JSON in the HTTP body.\nUse your favorite HTTP/REST library in your programming language when using this API,\nor use one of the Rebilly SDKs,\nwhich are available in [PHP](https://github.com/Rebilly/rebilly-php) and [JavaScript](https://github.com/Rebilly/rebilly-js-sdk).\n\nEvery action in the [Rebilly UI](https://app.rebilly.com) is supported by an API which is documented and available for use, so that you may automate any necessary workflows or processes.\nThis API reference documentation contains the most commonly integrated resources.\n\n# Authentication\n\nThis topic describes the different forms of authentication that are available in the Rebilly API, and how to use them.\n\nRebilly offers four forms of authentication: secret key, publishable key, JSON Web Tokens, and public signature key.\n\n- Secret API key: Use to make requests from the server side. Never share these keys. Keep them guarded and secure.\n- Publishable API key: Use in your client-side code to tokenize payment information.\n- JWT: Use to make short-life tokens that expire after a set period of time.\n\n<!-- ReDoc-Inject: <security-definitions> -->\n\n## Manage API keys\n\nTo create or manage API keys, select one of the following:\n\n- Use the Rebilly UI: see [Manage API keys](https://www.rebilly.com/docs/dev-docs/api-keys/#manage-api-keys)\n- Use the Rebilly API: see the [API key operations](https://www.rebilly.com/catalog/all/API-keys).\n\nFor more information on API keys, see [API keys](https://www.rebilly.com/docs/concepts-and-features/concept/api-keys).\n\n# Errors\nRebilly follows the error response format proposed in [RFC 9457](https://tools.ietf.org/html/rfc9457), which is also known as Problem Details for HTTP APIs. As with any API responses, your client must be prepared to gracefully handle additional members of the response.\n\n# SDKs\n\nRebilly provides a JavaScript SDK and a PHP SDK to help interact with the Rebilly API.\nHowever, no SDK is required to use the API.\n\nRebilly also provides [FramePay](https://www.rebilly.com/docs/developer-docs/framepay/),\na client-side iFrame-based solution, to help create payment tokens while minimizing PCI DSS compliance burdens\nand maximizing your customization ability.\n[FramePay](https://www.rebilly.com/docs/developer-docs/framepay/) interacts with the [payment tokens creation operation](https://www.rebilly.com/catalog/all/Payment-tokens/PostToken).\n\n## JavaScript SDK\n\nFor installation and usage instructions, see [SDKs](https://www.rebilly.com/docs/dev-docs/sdks/).\nAll JavaScript SDK code examples are included in the API reference documentation.\n\n## PHP SDK\n\nFor installation and usage instructions, see [SDKs](https://www.rebilly.com/docs/dev-docs/sdks/).\nAll SDK code examples are included in the API reference documentation.\nTo use them, you must configure the `$client` as follows:\n\n```php\n$client = new Rebilly\\Client([\n    'apiKey' => 'YourApiKeyHere',\n    'baseUrl' => 'https://api.rebilly.com',\n]);\n```\n\n# Using filter with collections\n\nRebilly provides collections filtering. Use the `?filter` parameter on collections to define which records should be shown in the response.\n\nFormat description:\n\n- Fields and values in the filter are separated with `:`: `?filter=firstName:John`.\n\n- Sub-fields are separated with `.`: `?filter=billingAddress.country:US`.\n\n- Multiple filters are separated with `;`: `?filter=firstName:John;lastName:Doe`. \\\n  They are joined with `AND` logic. Example: `firstName:John` AND `lastName:Doe`.\n\n- To use multiple values, use `,` as values separators: `?filter=firstName:John,Bob`. \\\n  Multiple values specified for a field are joined with `OR` logic. Example: `firstName:John` OR `firstName:Bob`.\n\n- To negate the filter, use `!`: `?filter=firstName:!John`.\n\n- To negate multiple values, use: `?filter=firstName:!John,!Bob`.\n  This filter rule excludes all `Johns` and `Bobs` from the response.\n\n- To use range filters, use: `?filter=amount:1..10`.\n\n- To use a gte (greater than or equals) filter, use: `?filter=amount:1..`.\n  This also works for datetime-based fields.\n\n- To use a lte (less than or equals) filter, use: `?filter=amount:..10`.\n  This also works for datetime-based fields.\n\n- To create [specified values lists](https://www.rebilly.com/catalog/all/Lists) and use them in filters, use: `?filter=firstName:@yourListName`. \\\n  You can also exclude list values: `?filter=firstName:!@yourListName`. \\\n  Use value lists to compare against a list of data when setting conditions for rules or binds,\n  or applying filters to data table segments.\n  Commonly used lists contain values related to conditions that target specific properties such as: customers, transactions, or BINs.\n\n- Datetime-based fields accept values formatted using RFC 3339. Example: `?filter=createdTime:2021-02-14T13:30:00Z`.\n\n# Expand to include embedded objects\n\nRebilly provides the ability to pre-load additional objects with a request.\n\nYou can use the `?expand` parameter on most requests to expand and include embedded objects within the `_embedded` property of the response.\nThe `_embedded` property contains an array of objects keyed by the expand parameter values.\nTo expand multiple objects, pass them as a comma-separated list of objects.\n\nExample request containing multiple objects:\n\n```\n?expand=recentInvoice,customer\n```\n\nExample response:\n\n```\n\"_embedded\": [\n    \"recentInvoice\": {...},\n    \"customer\": {...}\n]\n```\n\nExpand may be used on `GET`, `PATCH`, `POST`, `PUT` requests.\n\n# Limit on collections offset\n\nFor performance reasons, take note that we have a `1000` limit on `?offset=...`.\nFor example, attempting to retrieve a collection using `?offset=1001` or `?offset=2000` returns the same results as if you used `?offset=1000`.\n\nVisit our [Data Exports API](https://www.rebilly.com/catalog/all/Data-exports) for an asynchronous solution.\n\n# Get started\n\nThe full [Rebilly API](https://www.rebilly.com/catalog/all/) has over 500 operations.\nThis is likely more than you may need to implement your use cases.\nIf you would like to implement a particular use case,\n[contact Rebilly](https://www.rebilly.com/support/) for guidance and feedback on the best API operations to use for the task.\n\nTo integrate Rebilly, and learn about related resources and concepts,\nsee [Get started](https://www.rebilly.com/docs/dev-docs/get-started/).\n",
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
//				if ( isset( $t['readonly'] ) ) {
//					unset( $properties[ $key ] );
//				}
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
//		if ( ! empty( $schema['readonly'] ) ) {
//			unset( $schema['readonly'] );
//		}
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
//			if ( ! empty( $prop['readonly'] ) ) {
//				unset( $prop['readonly'] );
//			}
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

//			if ( $prop['type'] == 'object' && ( ! isset( $prop['properties'] ) || empty( $prop['properties'] ) ) ) {
//				if ( ! empty( $prop['items'] ) ) {
//					unset( $prop['items'] );
//				}
//				//$prop['properties'] = array( 'id' => array( 'type' => 'integer' ) );
//			}

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
//			if ( isset( $prop['readonly'] ) ) {
//				unset( $prop['readonly'] );
//			}


//			if ( isset( $prop['context'] ) ) {
//				unset( $prop['context'] );
//			}
		}

		return $schema;
	}

}
