<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AppointmentScheduler $scheduler): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'service_id' => ['required', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'doctor_id' => ['nullable', Rule::exists('users', 'id')
                ->where('role', Role::Doctor->value)->where('is_active', true)],
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();
        $service = Service::findOrFail($data['service_id']);
        $doctor = isset($data['doctor_id']) ? User::find($data['doctor_id']) : null;

        return response()->json(['data' => $scheduler->slots($date, $service, $doctor)]);
    }
}