<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PublicHomeController extends Controller
{
    public function __invoke(): View
    {
        if (config('features.public_index_enabled', true)) {
            return view('index');
        }

        return view('simulation.start');
    }
}
