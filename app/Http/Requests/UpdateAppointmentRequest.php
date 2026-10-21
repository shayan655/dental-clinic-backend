<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    private function allowedFields(): array
    {
        $user = $this->user();

        return match (true) {
            $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist)
                => ['service_id', 'doctor_id', 'scheduled_at', 'status', 'notes'],
            $user->hasRole(Role::Doctor) => ['notes'],
            default => ['status'], // patients can only cancel
        };
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('view', $this->route('appointment'))) {
            return false;
        }

        return collect($this->all())->keys()->diff($this->allowedFields())->isEmpty();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $status = $this->user()->hasRole(Role::Patient)
            ? ['required', Rule::in([AppointmentStatus::Cancelled->value])]
            : ['sometimes', 'required', Rule::enum(AppointmentStatus::class)];

        return [
            'service_id' => ['sometimes', 'required',
                Rule::exists('services', 'id')->whereNull('deleted_at')],
            'doctor_id' => ['sometimes', 'required', Rule::exists('users', 'id')
                ->where('role', Role::Doctor->value)->where('is_active', true)],
            'scheduled_at' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:s'],
            'status' => $status,
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
