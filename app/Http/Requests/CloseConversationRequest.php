<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('conversation')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }
}
