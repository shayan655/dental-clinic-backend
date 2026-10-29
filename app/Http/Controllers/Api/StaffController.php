<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index() {
        Gate::authorize('viewAny', User::class);

        return UserResource::collection(
            User::whereIn('role', Role::staffValues())->orderBy('role')->orderBy('name')->get()
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse {
        $data = $request->validated();

        $user = User::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Role::from($data['role']),
            'is_active' => true,
        ]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateStaffRequest $request, User $staff): UserResource {
        $data = $request->validated();
        $changesActive = array_key_exists('is_active', $data);

        if ($changesActive) {
            $data['is_active']
                ? $this->assertNoOtherActiveManager($staff)
                : $this->assertCanDeactivate($staff);
        }

        $staff->fill(collect($data)->only(['name', 'email', 'password'])->all());

        if ($changesActive) {
            $staff->is_active = $data['is_active'];
        }

        $staff->save();

        if (isset($data['password']) || ($changesActive && ! $data['is_active'])) {
            $this->endSessions($staff);
        }

        return new UserResource($staff);
    }

    public function destroy(User $staff): Response {
        $this->assertCanDeactivate($staff);

        $staff->is_active = false;
        $staff->save();
        $this->endSessions($staff);

        return response()->noContent();
    }

    private function assertCanDeactivate(User $staff): void
    {
        Gate::authorize('delete', $staff);

        if ($staff->role !== Role::Doctor) {
            return;
        }

        $upcoming = Appointment::where('doctor_id', $staff->id)
            ->where('scheduled_at', '>', now())
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::Completed->value])
            ->count();

        abort_if(
            $upcoming > 0,
            422,
            "This doctor still has {$upcoming} upcoming appointment(s). Reassign or cancel them first."
        );
    }

    private function assertNoOtherActiveManager(User $staff): void
    {
        if ($staff->role !== Role::ClinicManager) {
            return;
        }

        $otherExists = User::where('role', Role::ClinicManager)
            ->where('is_active', true)
            ->whereKeyNot($staff->id)
            ->exists();

        if ($otherExists) {
            throw ValidationException::withMessages([
                'is_active' => ['An active clinic manager account already exists.'],
            ]);
        }
    }

    private function endSessions(User $staff): void
    {
        DB::table('sessions')->where('user_id', $staff->id)->delete();
    }
}
