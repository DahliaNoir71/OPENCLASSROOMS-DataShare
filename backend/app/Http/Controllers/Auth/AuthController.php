<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
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
}
