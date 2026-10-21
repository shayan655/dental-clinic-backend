<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AppointmentScheduler
{
    public const SLOT_MINUTES = 30;

    public function activeDoctors(): Collection
    {
        return User::where('role', Role::Doctor)->where('is_active', true)->orderBy('id')->get();
    }

    public function hoursFor(CarbonInterface $date): ?WorkingHour
    {
        $hours = WorkingHour::where('day_of_week', $date->dayOfWeekIso)->first();

        return $hours && $hours->is_open ? $hours : null;
    }

    /** All start times on this day where a service of this length fits inside opening hours */
    public function candidateStarts(CarbonInterface $date, int $duration): array
    {
        $hours = $this->hoursFor($date);

        if (! $hours) {
            return [];
        }

        $open = $date->copy()->startOfDay()->setTimeFromTimeString($hours->open_time);
        $close = $date->copy()->startOfDay()->setTimeFromTimeString($hours->close_time);

        $starts = [];
        for ($t = $open->copy(); $t->copy()->addMinutes($duration)->lte($close); $t = $t->addMinutes(self::SLOT_MINUTES)) {
            $starts[] = $t->copy();
        }

        return $starts;
    }

    /** Free start times ("09:00", "09:30", ...) for a service on a day */
    public function slots(CarbonInterface $date, Service $service, ?User $doctor = null): array
    {
        $doctors = $doctor ? collect([$doctor]) : $this->activeDoctors();

        if ($doctors->isEmpty()) {
            return [];
        }

        $appointments = $this->dayAppointments($date, $doctors);
        $slots = [];

        foreach ($this->candidateStarts($date, $service->duration_minutes) as $start) {
            if ($start->lte(now())) {
                continue;
            }

            if ($this->freeDoctors($doctors, $appointments, $start, $service->duration_minutes)->isNotEmpty()) {
                $slots[] = $start->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * Validates the slot and returns the doctor who gets the appointment.
     * Must run inside a DB transaction: it locks the doctor rows so two
     * simultaneous bookings for the same doctor are handled one after the other.
     */
    public function reserve(
        CarbonInterface $start,
        Service $service,
        ?User $doctor,
        ?int $ignoreAppointmentId = null
    ): User {
        $this->assertSlotAllowed($start, $service);

        $candidateIds = ($doctor ? collect([$doctor]) : $this->activeDoctors())->pluck('id');

        $candidates = User::whereIn('id', $candidateIds)->orderBy('id')->lockForUpdate()->get();
        $appointments = $this->dayAppointments($start, $candidates);
        $free = $this->freeDoctors($candidates, $appointments, $start, $service->duration_minutes, $ignoreAppointmentId);

        if ($free->isEmpty()) {
            throw ValidationException::withMessages([
                'scheduled_at' => [$doctor
                    ? 'This doctor is not available at the selected time.'
                    : 'No doctor is available at the selected time.'],
            ]);
        }

        // "Any available doctor": give it to the doctor with the lightest day
        return $free
            ->sortBy(fn (User $d) => $appointments->where('doctor_id', $d->id)->count())
            ->first();
    }

    private function assertSlotAllowed(CarbonInterface $start, Service $service): void
    {
        $message = null;

        if ($start->lte(now())) {
            $message = 'Appointments must be booked in the future.';
        } elseif (! $this->hoursFor($start)) {
            $message = 'The clinic is closed on the selected day.';
        } else {
            $validStarts = array_map(
                fn (CarbonInterface $s) => $s->format('H:i'),
                $this->candidateStarts($start, $service->duration_minutes)
            );

            if (! in_array($start->format('H:i'), $validStarts, true)) {
                $message = 'The selected time is outside opening hours or not on a bookable slot.';
            }
        }

        if ($message) {
            throw ValidationException::withMessages(['scheduled_at' => [$message]]);
        }
    }

    private function dayAppointments(CarbonInterface $date, Collection $doctors): Collection
    {
        return Appointment::query()
            ->whereIn('doctor_id', $doctors->pluck('id'))
            ->whereBetween('scheduled_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->get();
    }

    private function freeDoctors(
        Collection $doctors,
        Collection $appointments,
        CarbonInterface $start,
        int $duration,
        ?int $ignoreAppointmentId = null
    ): Collection {
        $end = $start->copy()->addMinutes($duration);

        return $doctors->filter(function (User $doctor) use ($appointments, $start, $end, $ignoreAppointmentId) {
            return ! $appointments->contains(function (Appointment $a) use ($doctor, $start, $end, $ignoreAppointmentId) {
                if ((int) $a->doctor_id !== (int) $doctor->id) {
                    return false;
                }
                if ($ignoreAppointmentId && $a->id === $ignoreAppointmentId) {
                    return false;
                }

                $aStart = $a->scheduled_at;
                $aEnd = $aStart->copy()->addMinutes($a->duration_minutes);

                // Two appointments overlap when each starts before the other ends
                return $aStart->lt($end) && $aEnd->gt($start);
            });
        })->values();
    }
}