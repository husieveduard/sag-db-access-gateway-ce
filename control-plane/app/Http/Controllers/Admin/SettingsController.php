<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\DisplayClock;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(Request $request, DisplayClock $clock): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.settings.index', [
            'displayTimezone' => $clock->timezone(),
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
        ]);
    }

    public function updateTimezone(Request $request, DisplayClock $clock): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ]);

        DB::transaction(function () use ($clock, $data, $request): void {
            $clock->change($data['timezone'], $request->user()->id);
        });

        return back()->with('success', __('ce.timezone.saved'));
    }
}
