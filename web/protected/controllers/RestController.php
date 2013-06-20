<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
use Kisma\Core\Utility\Option;
use Platform\Exceptions\BadRequestException;
use Platform\Utility\RestResponse;
use Platform\Utility\ServiceHandler;
use Platform\Utility\SwaggerUtilities;

/**
 * RestController
 * REST API router and controller
 */
class RestController extends Controller
{
	//*************************************************************************
	//	Members
	//*************************************************************************

	/**
	 * @var string Default response format, either 'json' or 'xml'
	 */
	protected $format = 'json';
	/**
	 * @var string service to direct call to
	 */
	protected $service = '';
	/**
	 * @var string resource to be handled by service
	 */
	protected $resource = '';
	/**
	 * @var bool Swagger controlled get
	 */
	protected $swagger = false;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * /rest/index
	 */
	public function actionIndex()
	{
		try
		{
			if ( $this->swagger )
			{
				$result = SwaggerUtilities::getSwagger();
				RestResponse::sendResults( $result, RestResponse::Ok, 'json', $this->format );
			}
			else
			{
				/** @var CDbConnection $_db */
				$_db = Yii::app()->db;
				/** @var CDbCommand $command */
				$command = $_db->createCommand();
				$result = $command->select( 'api_name,name' )
					->from( 'df_sys_service' )
					->order( 'api_name' )
					->queryAll();
				// add non-service managers
				$services = array(
					array( 'api_name' => 'user', 'name' => 'User Login' ),
					array( 'api_name' => 'system', 'name' => 'System Configuration' )
				);
				$result = array( 'service' => array_merge( $services, $result ) );
				RestResponse::sendResults( $result, RestResponse::Ok, null, $this->format );
			}
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 *
	 */
	public function actionGet()
	{
		try
		{
			if ( $this->swagger )
			{
				$result = SwaggerUtilities::getSwaggerForService( $this->service );
				RestResponse::sendResults( $result, RestResponse::Ok, 'json', $this->format );
			}
			else
			{
				$svcObj = ServiceHandler::getServiceObject( $this->service );
				$result = $svcObj->processRequest( $this->resource, 'GET' );
				RestResponse::sendResults( $result, RestResponse::Ok, null, $this->format );
			}
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 *
	 */
	public function actionPost()
	{
		try
		{
			// check for verb tunneling
			$tunnel_method = Option::get( $_SERVER, 'HTTP_X_HTTP_METHOD', '' );
			if ( empty( $tunnel_method ) )
			{
				$tunnel_method = Option::get( $_REQUEST, 'method', '' );
			}
			if ( !empty( $tunnel_method ) )
			{
				switch ( strtolower( $tunnel_method ) )
				{
					case 'get': // complex retrieves, non-standard
						$this->actionGet();
						break;
					case 'post': // in case they use it in the header as well
						break;
					case 'put':
						$this->actionPut();
						break;
					case 'merge':
					case 'patch':
						$this->actionMerge();
						break;
					case 'delete':
						$this->actionDelete();
						break;
					default:
						if ( !empty( $tunnel_method ) )
						{
							throw new BadRequestException( "Unknown tunneling verb '$tunnel_method' in REST request." );
						}
						break;
				}
			}
			$svcObj = ServiceHandler::getServiceObject( $this->service );
			$result = $svcObj->processRequest( $this->resource, 'POST' );
			$code = RestResponse::Created;
			RestResponse::sendResults( $result, $code, null, $this->format );
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 *
	 */
	public function actionMerge()
	{
		try
		{
			$svcObj = ServiceHandler::getServiceObject( $this->service );
			$result = $svcObj->processRequest( $this->resource, 'MERGE' );
			RestResponse::sendResults( $result, RestResponse::Ok, null, $this->format );
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 *
	 */
	public function actionPut()
	{
		try
		{
			$svcObj = ServiceHandler::getServiceObject( $this->service );
			$result = $svcObj->processRequest( $this->resource, 'PUT' );
			RestResponse::sendResults( $result, RestResponse::Ok, null, $this->format );
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 *
	 */
	public function actionDelete()
	{
		try
		{
			$svcObj = ServiceHandler::getServiceObject( $this->service );
			$result = $svcObj->processRequest( $this->resource, 'DELETE' );
			RestResponse::sendResults( $result, RestResponse::Ok, null, $this->format );
		}
		catch ( Exception $ex )
		{
			RestResponse::sendErrors( $ex );
		}
	}

	/**
	 * Override base method to do some processing of incoming requests
	 *
	 * @param CAction $action
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected function beforeAction( $action )
	{
		$temp = strtolower( Option::get( $_REQUEST, 'format', '' ) );
		if ( !empty( $temp ) )
		{
			$this->format = $temp;
		}

		// determine application if any
		$appName = Option::get( $_SERVER, 'HTTP_X_DREAMFACTORY_APPLICATION_NAME', '' );
		if ( empty( $appName ) )
		{
			// old non-name-spaced header
			$appName = Option::get( $_SERVER, 'HTTP_X_APPLICATION_NAME', '' );
			if ( empty( $appName ) )
			{
				$appName = Option::get( $_REQUEST, 'app_name', '' );
			}
		}
		if ( empty( $appName ) )
		{
			// check for swagger documentation request
			$appName = Option::get( $_REQUEST, 'swagger_app_name', '' );
			if ( !empty( $appName ) )
			{
				$this->swagger = true;
			}
			else
			{
				$ex = new BadRequestException( "No application name header or parameter value in REST request." );
				RestResponse::sendErrors( $ex );
			}
		}
		$GLOBALS['app_name'] = $appName;

//        'rest/<service:[_0-9a-zA-Z-]+>/<resource:[_0-9a-zA-Z-\/. ]+>'
		$path = Option::get( $_GET, 'path', '' );
		$slashIndex = strpos( $path, '/' );
		if ( false === $slashIndex )
		{
			$this->service = $path;
		}
		else
		{
			$this->service = substr( $path, 0, $slashIndex );
			$this->resource = substr( $path, $slashIndex + 1 );
			// fix removal of trailing slashes from resource
			if ( !empty( $this->resource ) )
			{
				$requestUri = Yii::app()->request->requestUri;
				if ( ( false === strpos( $requestUri, '?' ) &&
					   '/' === substr( $requestUri, strlen( $requestUri ) - 1, 1 ) ) ||
					 ( '/' === substr( $requestUri, strpos( $requestUri, '?' ) - 1, 1 ) )
				)
				{
					$this->resource .= '/';
				}
			}
		}

		return parent::beforeAction( $action );
	}
}