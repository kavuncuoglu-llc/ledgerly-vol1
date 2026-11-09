<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function show(Request $request): View
    {
        return view('chat.show', [
            'user' => $request->user(),
            'config' => [
                'reverb' => [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('broadcasting.connections.reverb.options.host'),
                    'port' => (int) config('broadcasting.connections.reverb.options.port'),
                    'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                ],
                'websocketsEnabled' => config('broadcasting.default') === 'reverb',
                'tools' => config('ai.replies.tool_labels'),
            ],
        ]);
    }
}
