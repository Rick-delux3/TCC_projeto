<?php

namespace App\Http\Controllers;

use App\Models\Corretor;
use App\Models\User;
use App\Services\Dashboard\LeadNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadNotificationController extends Controller
{
    public function __invoke(Request $request, LeadNotifications $notifications): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof Corretor || ($viewer instanceof User && $viewer->company_id), 403);

        $notifications->markAsRead($viewer);

        $dashboard = $viewer instanceof Corretor ? 'Dashboard-Admin' : 'company.dashboard';

        return redirect()->to(route($dashboard).'#leads-section', 303);
    }
}
