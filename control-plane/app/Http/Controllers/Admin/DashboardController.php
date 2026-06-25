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
    public function __invoke(): View
    {
        $now = now();

        $stats = [
            'active_sessions' => DbAccessSession::query()
                ->whereIn('status', ['starting', 'started'])
                ->count(),

            'active_resources' => DatabaseResource::query()
                ->where('is_active', true)
                ->count(),

            'open_connections' => DbConnection::query()
                ->where('status', 'opened')
                ->count(),

            'high_risk_24h' => DbQueryEvent::query()
                ->where('risk_level', 'high')
                ->where('occurred_at', '>=', $now->copy()->subDay())
                ->count(),
        ];

        $sessions = DbAccessSession::query()
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
