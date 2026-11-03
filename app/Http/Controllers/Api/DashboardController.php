<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\AppointmentScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function manager(Request $request, AppointmentScheduler $scheduler): JsonResponse {
        abort_unless($request->user()->hasRole(Role::SuperAdmin, Role::ClinicManager), 403);

        return response()->json([
            'stats' => [
                'appointmentsToday' => $this->today()->where('status', '!=', AppointmentStatus::Cancelled->value)->count(),
                'totalPatients' => Patient::count(),
                'pendingInvoices' => Invoice::where('status', InvoiceStatus::Pending->value)->count(),
                'doctorsOnDuty' => $scheduler->hoursFor(now()) ? $scheduler->activeDoctors()->count() : 0,
            ],
            'todaysAppointments' => $this->todayList($this->today()),
        ]);
    }

    public function doctor(Request $request): JsonResponse {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Doctor), 403);

        $mine = fn (): Builder => $this->today()->where('doctor_id', $user->id);

        return response()->json([
            'stats' => [
                'myAppointmentsToday' => $mine()->where('status', '!=', AppointmentStatus::Cancelled->value)->count(),
                'myTotalPatients' => Patient::visibleTo($user)->count(),
                'completedToday' => $mine()->where('status', AppointmentStatus::Completed->value)->count(),
                // still to do today
                'pendingToday' => $mine()->whereIn('status', [
                    AppointmentStatus::Pending->value,
                    AppointmentStatus::Confirmed->value,
                    AppointmentStatus::InProgress->value,
                ])->count(),
            ],
            'todaysAppointments' => $this->todayList($mine()),
        ]);
    }

    public function receptionist(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Role::Receptionist), 403);

        return response()->json([
            'stats' => [
                'appointmentsToday' => $this->today()->where('status', '!=', AppointmentStatus::Cancelled->value)->count(),
                // arrived = in the chair or already finished
                'checkedInCount' => $this->today()->whereIn('status', [
                    AppointmentStatus::InProgress->value,
                    AppointmentStatus::Completed->value,
                ])->count(),
                'pendingInvoices' => Invoice::where('status', InvoiceStatus::Pending->value)->count(),
                'newPatientsToday' => Patient::whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            ],
            'todaysAppointments' => $this->todayList($this->today()),
        ]);
    }

    public function patient(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Patient), 403);

        $patient = $user->patient;

        if (! $patient) {
            return response()->json(['nextAppointment' => null, 'recentVisits' => []]);
        }

        $next = Appointment::where('patient_id', $patient->id)
            ->where('scheduled_at', '>', now())
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->with(['doctor', 'service'])
            ->orderBy('scheduled_at')
            ->first();

        $visits = Appointment::where('patient_id', $patient->id)
            ->where('status', AppointmentStatus::Completed->value)
            ->with(['service', 'medicalRecord'])
            ->orderByDesc('scheduled_at')
            ->limit(3)
            ->get();

        return response()->json([
            'nextAppointment' => $next ? [
                'id' => $next->id,
                'date' => $next->scheduled_at->format('l, j F Y'),
                'time' => $next->scheduled_at->format('H:i'),
                'service' => $next->service->name,
                'doctorName' => $next->doctor->name,
                'status' => $next->status->value,
            ] : null,
            'recentVisits' => $visits->map(fn (Appointment $a) => [
                'id' => $a->id,
                'date' => $a->scheduled_at->format('j M Y'),
                'service' => $a->service->name,
                // the patient's own treatment summary, never internal appointment notes
                'notes' => Str::limit($a->medicalRecord?->treatment ?? '', 160),
            ])->all(),
        ]);
    }

    private function today(): Builder
    {
        return Appointment::query()->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    private function todayList(Builder $query): array
    {
        return $query->with(['patient:id,name', 'service'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'patientName' => $a->patient->name,
                'time' => $a->scheduled_at->format('H:i'),
                'service' => $a->service->name,
                'status' => $a->status->value,
            ])
            ->all();
    }
}