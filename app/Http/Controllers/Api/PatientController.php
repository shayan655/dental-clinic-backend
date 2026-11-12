<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Patient::class);

        return PatientResource::collection(
            Patient::visibleTo($request->user())->orderBy('name')->get()
        );
    }

    public function store(StorePatientRequest $request): JsonResponse {
        $patient = Patient::create($request->validated());

        return (new PatientResource($patient))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Patient $patient): PatientResource
    {
        Gate::authorize('view', $patient);

        return new PatientResource($patient);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): PatientResource {
        $patient->update($request->validated());

        return new PatientResource($patient);
    }

    public function createAccount(Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);

        abort_if($patient->user_id !== null, 422, 'This patient already has a login.');

        if (User::where('email', $patient->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['A login with this email address already exists.'],
            ]);
        }

        $temporaryPassword = Str::password(12, symbols: false);

        DB::transaction(function () use ($patient, $temporaryPassword) {
            $user = User::forceCreate([
                'name' => $patient->name,
                'email' => $patient->email,
                'password' => $temporaryPassword,
                'role' => Role::Patient,
                'is_active' => true,
            ]);

            $patient->user_id = $user->id;
            $patient->save();
        });

        return (new PatientResource($patient->refresh()))
            ->additional(['temporary_password' => $temporaryPassword])
            ->response()
            ->setStatusCode(201);
    }
}
