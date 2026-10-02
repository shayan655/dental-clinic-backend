<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): UserResource {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // is_active => true means deactivated staff can't login
        $attempt = Auth::guard('web')->attempt(
            [...$credentials, 'is_active' => true],
            $request->boolean('remember')
        );

        if (! $attempt) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Prevents session fixation attacks
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function user(Request $request): UserResource {
        return new UserResource($request->user());
    }
}
