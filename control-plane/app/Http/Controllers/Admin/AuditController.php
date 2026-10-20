<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $events = AuditEvent::query()
            ->with('actor:id,name,email')
            ->when(!$user->isSuperAdmin(), fn ($query) => $query->where('actor_user_id', $user->id))
            ->latest('id')
            ->paginate(50);

        return view('admin.audit.index', compact('events'));
    }
}
