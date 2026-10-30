<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PreviewController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Foundation/Smoke');
    }

    public function redirect(): RedirectResponse
    {
        return redirect()->route('preview.foundation');
    }
}
