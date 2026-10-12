<?php

namespace App\Http\Controllers;

use App\Services\Impersonation;

class ImpersonationController extends Controller
{
    /** Bandeau « Revenir à mon compte ». */
    public function leave(Impersonation $impersonation)
    {
        return $impersonation->stop()
            ? redirect('/admin/users')
            : redirect()->route('login');
    }
}
