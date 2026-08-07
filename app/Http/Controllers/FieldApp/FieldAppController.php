<?php

namespace App\Http\Controllers\FieldApp;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class FieldAppController extends Controller
{
    /**
     * Livewire 4 + Tailwind v4 건설 현장 커맨드 앱 전용 진입점
     */
    public function index(): View
    {
        return view('field-app.index');
    }
}
