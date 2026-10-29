<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request): UserResource {
        $user = $request->user();
        $patient = $user->hasRole(Role::Patient) ? $user->patient : null;

        $emailRules = ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)];

        if ($patient) {
            $emailRules[] = Rule::unique('patients', 'email')->ignore($patient->id);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
        ]);

        DB::transaction(function () use ($user, $patient, $data) {
            $user->forceFill($data)->save();
            $patient?->update($data);
        });

        return new UserResource($user);
    }

    public function updatePassword(Request $request) {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ]);

        $user = $request->user();
        $user->password = $data['password'];
        $user->save();

        Auth::guard('web')->logoutOtherDevices($data['password']);

        return response()->noContent();
    }
}
