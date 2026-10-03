<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
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

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $name = trim($data['first_name'] . ' ' . $data['last_name']);

        // A staff-created profile with this email already exists: don't auto-link it
        if (Patient::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['A patient record with this email already exists. Please contact the clinic to link it to an account.'],
            ]);
        }

        $user = DB::transaction(function () use ($data, $name) {
            $user = User::forceCreate([
                'name' => $name,
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Patient,
                'is_active' => true,
            ]);

            $user->patient()->create([
                'name' => $name,
                'email' => $data['email'],
                'phone' => $data['phone'],
                'date_of_birth' => $data['date_of_birth'],
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
