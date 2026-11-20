<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'regex:/^\d{1,10}\.\d{2}$/'], 'reason' => 'required|string|min:3|max:255', 'quote_hash' => 'sometimes|string|size:64'];
    }
}
