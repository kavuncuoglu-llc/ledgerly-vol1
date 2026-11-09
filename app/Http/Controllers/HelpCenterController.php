<?php

namespace App\Http\Controllers;

use App\Support\HelpCenter;
use Illuminate\Contracts\View\View;

class HelpCenterController extends Controller
{
    public function __construct(
        private readonly HelpCenter $helpCenter,
    ) {}

    public function index(): View
    {
        return view('help.index', [
            'categories' => $this->helpCenter->byCategory(),
        ]);
    }

    public function show(string $category, string $slug): View
    {
        return view('help.show', [
            'article' => $this->helpCenter->find($category, $slug),
        ]);
    }
}
