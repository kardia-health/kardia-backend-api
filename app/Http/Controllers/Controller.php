<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 * version="1.0.0",
 * title="Kardia API Documentation",
 * description="API Documentation for Kardia Application",
 * @OA\Contact(
 * email="muhana.naufal17@gmail.com"
 * )
 * )
 *
 * @OA\Server(
 * url=L5_SWAGGER_CONST_HOST,
 * description="Demo API Server"
 * )
 * * @OA\SecurityScheme(
 * securityScheme="bearerAuth",
 * in="header",
 * name="Authorization",
 * type="http",
 * scheme="bearer",
 * bearerFormat="JWT",
 * description="Enter token in format (Bearer <token>)"
 * )
 * * @OA\Schema(
 * schema="Response_Error_Validation",
 * title="Response_Error_Validation",
 * @OA\Property(property="success", type="boolean", example=false),
 * @OA\Property(property="message", type="string", example="The given data was invalid."),
 * @OA\Property(
 * property="errors",
 * type="object",
 * example={
 * "email": {
 * "The email field is required."
 * },
 * "password": {
 * "The password field is required."
 * }
 * }
 * )
 * )
 *
 * @OA\Schema(
 * schema="Response_Error_Unauthorized",
 * title="Response_Error_Unauthorized",
 * @OA\Property(property="success", type="boolean", example=false),
 * @OA\Property(property="message", type="string", example="Unauthenticated.")
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
