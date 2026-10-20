<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['status' => ['nullable', Rule::in(['new', 'acknowledged', 'investigating', 'resolved'])]]);
        $user = $request->user();

        $alerts = Alert::query()->with('rule', 'resource', 'owner')
            ->when(!$user->isSuperAdmin(), fn ($query) => $query->where('owner_user_id', $user->id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('last_seen_at')->paginate(50)->withQueryString();

        return view('admin.alerts.index', compact('alerts'));
    }

    public function updateStatus(Request $request, Alert $alert): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin()
            || (int) $alert->owner_user_id === (int) $request->user()->id, 404);

        $data = $request->validate(['status' => ['required', Rule::in(['acknowledged', 'investigating', 'resolved'])]]);
        $alert->forceFill([
            'status' => $data['status'],
            'assigned_user_id' => $request->user()->id,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ])->save();

        return back()->with('success', __('alerts.updated'));
    }

    public function rules(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $rules = AlertRule::query()->orderBy('id')->get();

        return view('admin.alerts.rules', compact('rules'));
    }

    public function updateRule(Request $request, AlertRule $rule): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'severity' => ['required', Rule::in(['info', 'low', 'medium', 'high', 'critical'])],
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        $rule->update($data);

        return back()->with('success', __('alerts.updated'));
    }
}
