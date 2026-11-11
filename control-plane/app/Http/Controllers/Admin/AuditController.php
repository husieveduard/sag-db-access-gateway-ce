<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Services\Settings\DisplayClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(Request $request, DisplayClock $clock): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:80'],
            'event_type' => ['nullable', 'string', 'max:128'],
            'user' => ['nullable', 'string', 'max:255'],
            'ip' => ['nullable', 'ip'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $scope = AuditEvent::query()
            ->when(!$user->isSuperAdmin(),
                fn (Builder $query) => $query->where('actor_user_id', $user->id));

        // Choices are scoped too: ordinary admins must not enumerate other users' audit activity.
        $categories = (clone $scope)->distinct()->orderBy('category')->pluck('category');
        $eventTypes = (clone $scope)->distinct()->orderBy('event_type')->pluck('event_type');

        $events = $scope->with('actor:id,name,email')
            ->when(!empty($data['from']), function (Builder $query) use ($data, $clock): void {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $clock->timezone())
                    ->startOfDay()->utc();
                $query->where('occurred_at', '>=', $start);
            })
            ->when(!empty($data['to']), function (Builder $query) use ($data, $clock): void {
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $clock->timezone())
                    ->addDay()->startOfDay()->utc();
                $query->where('occurred_at', '<', $end);
            })
            ->when(!empty($data['category']), fn (Builder $query) =>
                $query->where('category', $data['category']))
            ->when(!empty($data['event_type']), fn (Builder $query) =>
                $query->where('event_type', $data['event_type']))
            ->when(!empty($data['ip']), fn (Builder $query) =>
                $query->where('ip_address', $data['ip']))
            ->when(!empty($data['user']), fn (Builder $query) =>
                $query->whereHas('actor', fn (Builder $actor) =>
                    $actor->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['user']))])))
            ->when(!empty($data['q']), function (Builder $query) use ($data): void {
                // Search only indexed audit metadata, never sensitive event_data.
                $term = mb_strtolower(trim($data['q']));
                $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
                $like = '%'.$term.'%';
                $query->where(function (Builder $match) use ($like): void {
                    $match->whereRaw("LOWER(event_type) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(category) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(ip_address) LIKE ? ESCAPE '!'", [$like]);
                });
            })
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('admin.audit.index', compact('events', 'categories', 'eventTypes'));
    }
}
