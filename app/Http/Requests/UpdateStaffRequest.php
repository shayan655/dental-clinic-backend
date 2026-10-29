<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('staff'));
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($staff)],
            // The role is accepted so the existing form keeps working, but it can't change
            'role' => ['sometimes', function ($attribute, $value, $fail) use ($staff) {
                if ($value !== $staff->role->value) {
                    $fail('The role of an account cannot be changed.');
                }
            }],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}