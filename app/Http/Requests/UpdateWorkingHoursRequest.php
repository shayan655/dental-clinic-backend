<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\WorkingHour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWorkingHoursRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole(Role::SuperAdmin, Role::ClinicManager);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day' => ['required', 'distinct', Rule::in(WorkingHour::DAYS)],
            'hours.*.is_open' => ['required', 'boolean'],
            'hours.*.open_time' => ['required', 'date_format:H:i'],
            'hours.*.close_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->input('hours', []) as $i => $day) {
                    if (($day['is_open'] ?? false)
                        && isset($day['open_time'], $day['close_time'])
                        && $day['close_time'] <= $day['open_time']) {
                        $validator->errors()->add(
                            "hours.$i.close_time",
                            ($day['day'] ?? 'This day') . ' must close after it opens.'
                        );
                    }
                }
            },
        ];
    }
}
