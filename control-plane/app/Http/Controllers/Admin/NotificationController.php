<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\SuperadminNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        // Reject malformed source references before resolving or writing read state.
        // No arbitrary key, JSON payload or path can be selected by the client.
        $type = $request->input('source_type');
        $id = $request->input('source_id');
        abort_unless(
            is_string($type) && in_array($type, ['alert', 'delivery', 'audit'], true)
                && (is_int($id) || is_string($id))
                && ctype_digit((string) $id)
                && (int) $id > 0
                && (int) $id <= PHP_INT_MAX,
            422
        );
        abort_unless($feed->markRead($request->user(), $type, (int) $id), 404);

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
