<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class SmartCompanyController extends Controller
{
    public function index(): View
    {
        return view('smart-company.index');
    }
}
