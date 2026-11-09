<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondToToolApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('pendingToolCall')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }
}
