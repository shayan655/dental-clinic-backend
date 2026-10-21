<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', Rule::exists('services', 'id')->whereNull('deleted_at')],
            // empty = "any available doctor"
            'doctor_id' => ['nullable', Rule::exists('users', 'id')
                ->where('role', Role::Doctor->value)->where('is_active', true)],
            // patients book for themselves, everyone else must say for whom
            'patient_id' => [Rule::requiredIf(! $this->user()->hasRole(Role::Patient)),
                'nullable', 'integer', 'exists:patients,id'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:s'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
