<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication Controller
 * 
 * Handles user authentication operations including registration, login, logout, and user profile retrieval.
 * 
 * @package App\Http\Controllers\API\Auth
 */
class AuthController extends Controller
{
    use ApiResponse;

    private const TOKEN_NAME = 'auth_token';
    private const TOKEN_TYPE = 'Bearer';

    public function __construct(
        private readonly RegisterUserAction $registerUserAction,
        private readonly LoginUserAction $loginUserAction,
        private readonly TokenService $tokenService
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/register",
     *     operationId="registerUser",
     *     tags={"Authentication"},
     *     summary="Register a new user",
     *     description="Creates a new user account and returns an authentication token.",
     *     @OA\RequestBody(
     *         required=true,
     *         description="User registration data",
     *         @OA\JsonContent(
     *             required={"email","password","password_confirmation"},
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Successful registration",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Auth_Success")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Error_Validation")
     *     )
     * )
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registerUserAction->execute($request->validated());
        $tokenData = $this->tokenService->createTokenForUser($user, self::TOKEN_NAME);

        return $this->success(
            message: 'Registration successful. You are now logged in.',
            data: $this->formatAuthResponse($tokenData, $user),
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/login",
     *     operationId="loginUser",
     *     tags={"Authentication"},
     *     summary="Login an existing user",
     *     description="Authenticates a user and returns an authentication token.",
     *     @OA\RequestBody(
     *         required=true,
     *         description="User credentials",
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful login",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Auth_Success")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid Credentials",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Error_Unauthorized")
     *     )
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->loginUserAction->execute($request->validated());

        if (!$user) {
            return $this->error(
                message: 'Invalid credentials',
                statusCode: Response::HTTP_UNAUTHORIZED
            );
        }

        $tokenData = $this->tokenService->createTokenForUser($user, self::TOKEN_NAME);

        return $this->success(
            message: 'Login successful',
            data: $this->formatAuthResponse($tokenData, $user)
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/logout",
     *     operationId="logoutUser",
     *     tags={"Authentication"},
     *     summary="Logout the current user",
     *     description="Invalidates the user's current authentication token.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful logout",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Logout_Success")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Error_Unauthorized")
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->success(message: 'Logout successful');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/user",
     *     operationId="getAuthenticatedUser",
     *     tags={"Authentication"},
     *     summary="Get authenticated user data",
     *     description="Returns the data of the currently logged-in user.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User data retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Response_User_Success")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(ref="#/components/schemas/Response_Error_Unauthorized")
     *     )
     * )
     */
    public function user(Request $request): JsonResponse
    {
        return $this->success(
            message: 'User data retrieved successfully',
            data: new UserResource($request->user())
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->error('User not found.', Response::HTTP_NOT_FOUND);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return $this->success('Password berhasil diubah.');
    }

    public function deleteAccount(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check($request->input('password'), $user->password)) {
            return $this->error(
                message: 'Password is incorrect.',
                statusCode: Response::HTTP_UNAUTHORIZED
            );
        }

        // Hapus akun & token
        $user->tokens()->delete(); // Revoke all tokens (Sanctum)
        $user->forceDelete();

        return $this->success(
            message: 'Your account has been deleted successfully.'
        );
    }

    /**
     * Format authentication response data
     */
    private function formatAuthResponse(array $tokenData, $user): array
    {
        return [
            'access_token' => $tokenData['token'],
            'token_type' => self::TOKEN_TYPE,
            'expires_at' => $tokenData['expires_at'] ?? null,
            'user' => new UserResource($user),
        ];
    }
}
