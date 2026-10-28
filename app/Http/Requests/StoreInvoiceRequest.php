<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            // optional: must be this patient's appointment, and not invoiced yet
            'appointment_id' => ['nullable',
                Rule::exists('appointments', 'id')->where('patient_id', $this->input('patient_id')),
                Rule::unique('invoices', 'appointment_id')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
        ];
    }
}
