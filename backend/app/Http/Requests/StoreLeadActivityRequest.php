<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    /** System types (status_change, score, assignment, quote, created) are written by the app only. */
    public const USER_TYPES = ['note', 'call', 'email', 'meeting', 'site_visit'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(self::USER_TYPES)],
            'title' => ['required', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
