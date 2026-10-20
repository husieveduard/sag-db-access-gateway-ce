<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\DbConnection;
use App\Models\DbQueryEvent;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request): View
    {
        $now = now();
        $user = $request->user();
        $scopeSession = fn ($q) => $q->when(!$user->isSuperAdmin(), fn ($q) => $q->where('owner_user_id', $user->id));
        $scopeResource = fn ($q) => $q->when(!$user->isSuperAdmin(), fn ($q) => $q->where('created_by_user_id', $user->id));

        $stats = [
            'active_sessions' => $scopeSession(DbAccessSession::query())
                ->whereIn('status', ['starting', 'started'])
                ->count(),

            'active_resources' => $scopeResource(DatabaseResource::query())
                ->where('is_active', true)
                ->count(),

            'open_connections' => DbConnection::query()
                ->when(!$user->isSuperAdmin(), fn ($q) => $q->where('owner_user_id', $user->id))
                ->where('status', 'opened')
                ->count(),

            'high_risk_24h' => DbQueryEvent::query()
                ->when(!$user->isSuperAdmin(), fn ($q) => $q->where('owner_user_id', $user->id))
                ->where('risk_level', 'high')
                ->where('occurred_at', '>=', $now->copy()->subDay())
                ->count(),
        ];

        $sessions = $scopeSession(DbAccessSession::query())
            ->with([
                'resource:id,name,engine,target_host,target_port,target_database',
                'owner:id,name,email',
            ])
            ->withCount([
                'connections as open_connections_count' => fn ($query) => $query
                    ->where('status', 'opened'),
            ])
            ->latest('id')
            ->limit(30)
            ->get();

        $queries = DbQueryEvent::query()
            ->when(!$user->isSuperAdmin(), fn ($q) => $q->where('owner_user_id', $user->id))
            ->with([
                'resource:id,name,engine',
                'session:id,public_id',
                'owner:id,name',
            ])
            ->latest('id')
            ->limit(30)
            ->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'sessions' => $sessions,
            'queries' => $queries,
        ]);
    }
}
