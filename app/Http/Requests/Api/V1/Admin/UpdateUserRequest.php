<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\NotDemotingSelf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $targetUserId = $this->route('user')?->id;

        return [
            'username' => ['sometimes', 'required', 'string', 'regex:/^[a-z]+[0-9]+[a-z]$/', Rule::unique('users', 'username')->ignore($targetUserId)],
            'forenames' => ['sometimes', 'required', 'string', 'max:255'],
            'surname' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($targetUserId)],
            'is_admin' => ['sometimes', 'boolean', new NotDemotingSelf($this->route('user')?->id === $this->user()->id)],
        ];
    }
}
