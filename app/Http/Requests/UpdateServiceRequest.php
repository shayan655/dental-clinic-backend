<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255',
                Rule::unique('services', 'name')
                    ->ignore($this->route('service'))
                    ->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:480'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999.99'],
        ];
    }
}
