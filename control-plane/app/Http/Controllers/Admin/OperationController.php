<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\DbAccessOperation;
use App\Models\DbAccessSession;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationController extends Controller
{
    public function start(
        Request $request,
        DbAccessSession $session,
    ): RedirectResponse {
        return $this->queueOperation(
            request: $request,
            session: $session,
            operationType: 'start_session',
            reason: null,
        );
    }

    public function terminate(
        Request $request,
        DbAccessSession $session,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return $this->queueOperation(
            request: $request,
            session: $session,
            operationType: 'terminate_session',
            reason: trim($data['reason']),
        );
    }

    private function queueOperation(
        Request $request,
        DbAccessSession $session,
        string $operationType,
        ?string $reason,
    ): RedirectResponse {
        try {
            $operation = DB::transaction(function () use (
                $request,
                $session,
                $operationType,
                $reason,
            ): DbAccessOperation {
                $lockedSession = DbAccessSession::query()
                    ->with('resource')
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $pending = DbAccessOperation::query()
                    ->where('session_id', $lockedSession->id)
                    ->whereIn('status', ['queued', 'running'])
                    ->exists();

                if ($pending) {
                    throw new DomainException(
                        __('messages.operations.already_pending')
                    );
                }

                if ($operationType === 'start_session') {
                    if (!in_array(
                        $lockedSession->status,
                        ['created', 'failed'],
                        true,
                    )) {
                        throw new DomainException(
                            __('messages.operations.start_unavailable')
                        );
                    }

                    if (!$lockedSession->resource?->is_active) {
                        throw new DomainException(
                            __('messages.operations.resource_inactive')
                        );
                    }
                }

                if ($operationType === 'terminate_session') {
                    if (in_array(
                        $lockedSession->status,
                        ['terminated', 'expired', 'ended'],
                        true,
                    )) {
                        throw new DomainException(
                            __('messages.operations.already_finished')
                        );
                    }
                }

                do {
                    $operationUid = 'OP-'
                        .now()->format('Ymd-His')
                        .'-'.Str::upper(Str::random(8));
                } while (
                    DbAccessOperation::query()
                        ->where('operation_uid', $operationUid)
                        ->exists()
                );

                $operation = DbAccessOperation::query()->create([
                    'operation_uid' => $operationUid,
                    'session_id' => $lockedSession->id,
                    'requested_by_user_id' => $request->user()->id,
                    'operation_type' => $operationType,
                    'status' => 'queued',
                    'reason' => $reason,
                    'request_payload' => [
                        'source' => 'web_console',
                        'request_ip' => $request->ip(),
                        'user_agent' => Str::limit(
                            (string) $request->userAgent(),
                            500,
                            '',
                        ),
                    ],
                    'requested_at' => now(),
                ]);

                AuditEvent::query()->create([
                    'actor_user_id' => $request->user()->id,
                    'resource_id' => $lockedSession->resource_id,
                    'session_id' => $lockedSession->id,
                    'category' => 'db_access',
                    'event_type' => 'access.db.operation.queued',
                    'severity' => $operationType === 'terminate_session'
                        ? 'medium'
                        : 'info',
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit(
                        (string) $request->userAgent(),
                        500,
                        '',
                    ),
                    'event_data' => [
                        'operation_uid' => $operation->operation_uid,
                        'operation_type' => $operationType,
                        'reason' => $reason,
                        'source' => 'web_console',
                    ],
                    'occurred_at' => now(),
                ]);

                return $operation;
            });

            return redirect()
                ->route('admin.sessions.show', $session)
                ->with(
                    'success',
                    __('messages.operations.queued', ['operation_uid' => $operation->operation_uid])
                );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'operation' => $exception->getMessage(),
                ]);
        }
    }
}
