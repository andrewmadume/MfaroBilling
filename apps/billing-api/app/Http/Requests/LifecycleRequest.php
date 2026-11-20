<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('organisation_role'), ['owner', 'administrator', 'billing_manager'], true);
    }

    public function rules(): array
    {
        return ['cycles' => 'sometimes|integer|min:1|max:12', 'immediate' => 'sometimes|boolean'];
    }

    public function operationKey(): string
    {
        $key = $this->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) > 0 && strlen($key) <= 120, 422, 'Idempotency-Key header required.');

        return $key;
    }
}
