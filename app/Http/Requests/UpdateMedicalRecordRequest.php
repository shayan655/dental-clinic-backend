<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicalRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $record = $this->route('record');

        if (! $this->user()->can('update', $record)) {
            return false;
        }

        abort_unless($record->patient_id === $this->route('patient')->id, 404);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')
                ->where('patient_id', $this->route('patient')->id)
                ->where('doctor_id', $this->user()->id)],
            'diagnosis' => ['sometimes', 'required', 'string', 'max:5000'],
            'treatment' => ['sometimes', 'required', 'string', 'max:5000'],
            'prescription' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
