<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AppointmentController extends Controller
{
    private const RELATIONS = ['patient', 'doctor', 'service'];

    public function index(Request $request)
    {
        $query = Appointment::visibleTo($request->user())
            ->with(self::RELATIONS)
            ->orderByDesc('scheduled_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->integer('patient_id'));
        }

        return AppointmentResource::collection($query->get());
    }

    public function store(StoreAppointmentRequest $request, AppointmentScheduler $scheduler): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $patient = $this->resolvePatient($user, $data['patient_id'] ?? null);
        $service = Service::findOrFail($data['service_id']);
        $start = Carbon::createFromFormat('Y-m-d\TH:i:s', $data['scheduled_at']);

        // Doctors always book with themselves; empty doctor_id means "any available doctor"
        $preferred = $user->hasRole(Role::Doctor)
            ? $user
            : (isset($data['doctor_id']) ? User::find($data['doctor_id']) : null);

        $appointment = DB::transaction(function () use ($scheduler, $start, $service, $preferred, $patient, $user, $data) {
            $doctor = $scheduler->reserve($start, $service, $preferred);

            return Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'duration_minutes' => $service->duration_minutes,
                'status' => $user->hasRole(Role::Patient)
                    ? AppointmentStatus::Pending
                    : AppointmentStatus::Confirmed,
                'scheduled_at' => $start,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return (new AppointmentResource($appointment->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource($appointment->load(self::RELATIONS));
    }

    public function update(
        UpdateAppointmentRequest $request,
        Appointment $appointment,
        AppointmentScheduler $scheduler
    ): AppointmentResource {
        $user = $request->user();
        $data = $request->validated();

        abort_if($appointment->status->isLocked(), 422, 'Completed or cancelled appointments cannot be changed.');

        if ($user->hasRole(Role::Patient)) {
            abort_unless(
                in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true),
                422,
                'Only pending or confirmed appointments can be cancelled.'
            );
        }

        DB::transaction(function () use ($appointment, $scheduler, $data) {
            $changes = collect($data)->only(['status', 'notes'])->all();

            // Changing the service, doctor or time re-validates the slot
            if (isset($data['service_id']) || isset($data['doctor_id']) || isset($data['scheduled_at'])) {
                $service = isset($data['service_id']) ? Service::findOrFail($data['service_id']) : $appointment->service;
                $doctor = User::findOrFail($data['doctor_id'] ?? $appointment->doctor_id);
                $start = isset($data['scheduled_at'])
                    ? Carbon::createFromFormat('Y-m-d\TH:i:s', $data['scheduled_at'])
                    : $appointment->scheduled_at;

                $changed = $service->id !== $appointment->service_id
                    || $doctor->id !== $appointment->doctor_id
                    || ! $start->equalTo($appointment->scheduled_at);

                if ($changed) {
                    $assigned = $scheduler->reserve($start, $service, $doctor, $appointment->id);

                    $changes += [
                        'service_id' => $service->id,
                        'doctor_id' => $assigned->id,
                        'scheduled_at' => $start,
                        'duration_minutes' => $service->duration_minutes,
                    ];
                }
            }

            $appointment->update($changes);
        });

        return new AppointmentResource($appointment->refresh()->load(self::RELATIONS));
    }

    private function resolvePatient(User $user, ?int $patientId): Patient
    {
        if ($user->hasRole(Role::Patient)) {
            return $user->patient ?? abort(403, 'No patient profile is linked to this account.');
        }

        $patient = Patient::findOrFail($patientId);

        // Doctors may only book for their own patients
        Gate::authorize('view', $patient);

        return $patient;
    }
}