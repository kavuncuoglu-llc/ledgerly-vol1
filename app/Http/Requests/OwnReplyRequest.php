<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OwnReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('reply')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }
}
