<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * A failed sign-in says only that the pair is wrong. Distinguishing
     * "unknown email" from "wrong password" would turn the endpoint into an
     * account enumeration oracle: anyone could probe which addresses hold an
     * account here, which is itself personal information.
     */
    private const INVALID_CREDENTIALS = 'Identifiants invalides.';

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $token = auth('api')->login($user);

        // Audit trail, not debugging. Logged from the controller rather than
        // from a model observer: what is worth a line is the business action
        // taken through the API, and a factory or a seeder creating a row is
        // not a registration. Numeric id only — the email is personal data.
        Log::info('User registered', ['user_id' => $user->id]);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = auth('api')->attempt($request->validated());

        if ($token === false) {
            // No email in this line, and no mention of which half of the pair
            // failed: the log file must not become the enumeration oracle the
            // response refuses to be.
            Log::warning('Login failed', ['ip' => $request->ip()]);

            return response()->json(['message' => self::INVALID_CREDENTIALS], 401);
        }

        $user = auth('api')->user();

        Log::info('User logged in', ['user_id' => $user->id]);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Read the id before invalidating: once the token is blacklisted the
        // guard no longer resolves a user from it.
        $userId = $request->user()->id;

        auth('api')->logout();

        Log::info('User logged out', ['user_id' => $userId]);

        return response()->json(['message' => 'Déconnexion effectuée.']);
    }
}
