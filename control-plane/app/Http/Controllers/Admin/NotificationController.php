<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\SuperadminNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, SuperadminNotificationFeed $feed): View
    {
        return view('admin.notifications.index', [
            'feed' => $feed->feed($request->user(), 100),
        ]);
    }

    public function feed(Request $request, SuperadminNotificationFeed $feed): JsonResponse
    {
        return response()->json($feed->feed($request->user()));
    }

    public function read(Request $request, SuperadminNotificationFeed $feed): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['alert', 'delivery', 'audit'])],
            'source_id' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless($feed->markRead($request->user(), $data['source_type'], (int) $data['source_id']), 404);

        return $request->expectsJson()
            ? response()->json($feed->feed($request->user()))
            : back();
    }

    public function readAll(Request $request, SuperadminNotificationFeed $feed): JsonResponse|RedirectResponse
    {
        $feed->markAllRead($request->user());

        return $request->expectsJson()
            ? response()->json($feed->feed($request->user()))
            : back();
    }
}
