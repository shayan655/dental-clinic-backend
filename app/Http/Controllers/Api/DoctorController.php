<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request) {
        abort_if($request->user()->hasRole(Role::Patient), 403);

        return UserResource::collection(
            User::where('role', Role::Doctor)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }
}