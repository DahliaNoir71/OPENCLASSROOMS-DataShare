<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $token = auth('api')->login($user);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email],
        ], 201);
    }
}
