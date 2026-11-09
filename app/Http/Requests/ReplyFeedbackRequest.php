<?php

namespace App\Http\Requests;

use App\Models\Reply;

class ReplyFeedbackRequest extends OwnReplyRequest
{
    public function rules(): array
    {
        return [
            'feedback' => [
                'required',
                'in:'.Reply::FEEDBACK_UP.','.Reply::FEEDBACK_DOWN,
            ],
        ];
    }
}
