<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class AllocationController extends Controller
{
    public function index()
    {
        return view('insights.allocation');
    }
}
