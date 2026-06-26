<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DbAccessOperation;
use App\Models\DbAccessSession;
use App\Models\DbConnection;
use App\Models\DbQueryEvent;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SessionController extends Controller
{
    private const STATUSES = [
        'created',
        'starting',
        'started',
        'ended',
        'terminated',
        'expired',
        'failed',
    ];

    private const MODES = [
        'temporary',
        'persistent',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'mode' => ['nullable', 'in:'.implode(',', self::MODES)],
        ]);

        $sessionTable = (new DbAccessSession())->getTable();

        $sessions = DbAccessSession::query()
            ->select($sessionTable.'.*')
            ->addSelect([
                'last_failed_operation_uid' => DbAccessOperation::query()
                    ->select('operation_uid')
                    ->whereColumn('session_id', $sessionTable.'.id')
                    ->where('status', 'failed')
                    ->latest('id')
                    ->limit(1),

                'last_failed_operation_error' => DbAccessOperation::query()
                    ->select('error_message')
                    ->whereColumn('session_id', $sessionTable.'.id')
                    ->where('status', 'failed')
                    ->latest('id')
                    ->limit(1),
            ])
            ->with([
                'resource:id,name,engine,target_host,target_port,target_database',
                'owner:id,name,email',
            ])
            ->withCount([
                'connections as open_connections_count' => fn ($query) => $query
                    ->where('status', 'opened'),
                'operations as pending_operations_count' => fn ($query) => $query
                    ->whereIn('status', ['queued', 'running']),
            ])
            ->when(
                !empty($filters['status']),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                !empty($filters['mode']),
                fn ($query) => $query->where('mode', $filters['mode'])
            )
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.sessions.index', [
            'sessions' => $sessions,
            'statuses' => self::STATUSES,
            'modes' => self::MODES,
        ]);
    }

    public function create(): View
    {
        $resources = DatabaseResource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'engine',
                'target_host',
                'target_port',
                'target_database',
            ]);

        return view('admin.sessions.create', [
            'resources' => $resources,
            'modes' => self::MODES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'resource_id' => ['required', 'integer'],
            'mode' => ['required', Rule::in(self::MODES)],
            'ttl_seconds' => [
                'nullable',
                'required_if:mode,temporary',
                'integer',
                'min:60',
                'max:604800',
            ],
            'source_cidr' => [
                'required',
                'string',
                'max:128',
                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ): void {
                    if (!$this->isValidIpOrCidr((string) $value)) {
                        $fail('Вкажіть коректну IP-адресу або CIDR, наприклад 10.10.10.1/32.');
                    }
                },
            ],
            'db_username' => ['required', 'string', 'max:128'],
        ]);

        $dbUsername = trim((string) $data['db_username']);

        if ($dbUsername === '') {
            return back()
                ->withInput()
                ->withErrors([
                    'db_username' => 'Вкажіть DB username для аудиту.',
                ]);
        }

        try {
            $session = DB::transaction(function () use (
                $request,
                $data,
                $dbUsername,
            ): DbAccessSession {
                $resource = DatabaseResource::query()
                    ->whereKey($data['resource_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$resource || !$resource->is_active) {
                    throw new DomainException(
                        'Вибраний DB resource не існує або неактивний.'
                    );
                }

                do {
                    $publicId = 'SAG-CE-DB-'
                        .strtoupper((string) $resource->engine)
                        .'-'.now()->format('Ymd-His')
                        .'-'.Str::upper(Str::random(8));
                } while (
                    DbAccessSession::query()
                        ->where('public_id', $publicId)
                        ->exists()
                );

                $mode = $data['mode'];
                $ttlSeconds = $mode === 'temporary'
                    ? (int) $data['ttl_seconds']
                    : null;

                $session = DbAccessSession::query()->create([
                    'public_id' => $publicId,
                    'resource_id' => $resource->id,
                    'owner_user_id' => $request->user()->id,
                    'created_by_user_id' => $request->user()->id,
                    'mode' => $mode,
                    'status' => 'created',
                    'allowed_source_cidr' => trim((string) $data['source_cidr']),
                    'target_db_username' => $dbUsername,
                    'query_audit_enabled' => (bool) $resource->query_audit_enabled,
                    'expires_at' => $ttlSeconds
                        ? now()->addSeconds($ttlSeconds)
                        : null,
                    'metadata' => [
                        'db' => [
                            'engine' => $resource->engine,
                            'credential_storage' => 'none',
                            'created_via' => 'web_console',
                        ],
                    ],
                ]);

                AuditEvent::query()->create([
                    'actor_user_id' => $request->user()->id,
                    'resource_id' => $resource->id,
                    'session_id' => $session->id,
                    'category' => 'db_access',
                    'event_type' => 'access.db.session.created',
                    'severity' => 'info',
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit(
                        (string) $request->userAgent(),
                        500,
                        '',
                    ),
                    'event_data' => [
                        'mode' => $mode,
                        'ttl_seconds' => $ttlSeconds,
                        'allowed_source_cidr' => $session->allowed_source_cidr,
                        'db_engine' => $resource->engine,
                        'db_username' => $dbUsername,
                        'credential_storage' => 'none',
                        'source' => 'web_console',
                    ],
                    'occurred_at' => now(),
                ]);

                return $session;
            });
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'resource_id' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('admin.sessions.show', $session)
            ->with(
                'success',
                "Session {$session->public_id} створено. Для відкриття gateway натисни Start session."
            );
    }

    public function show(DbAccessSession $session): View
    {
        $session->load([
            'resource',
            'owner:id,name,email',
            'createdBy:id,name,email',
            'terminatedBy:id,name,email',
        ]);

        $connections = DbConnection::query()
            ->where('session_id', $session->id)
            ->latest('id')
            ->limit(100)
            ->get();

        $riskFilter = (string) request()->query('risk', 'all');

        $allowedRiskFilters = [
            'all',
            'critical',
            'high',
            'medium',
            'low',
        ];

        if (! in_array($riskFilter, $allowedRiskFilters, true)) {
            $riskFilter = 'all';
        }

        $riskSummaryRow = DbQueryEvent::query()
            ->where('session_id', $session->id)
            ->selectRaw("
                COUNT(*) AS total_count,
                SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) AS critical_count,
                SUM(CASE WHEN risk_level = 'high' THEN 1 ELSE 0 END) AS high_count,
                SUM(CASE WHEN risk_level = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                SUM(CASE WHEN risk_level = 'low' THEN 1 ELSE 0 END) AS low_count
            ")
            ->first();

        $riskSummary = [
            'total' => (int) ($riskSummaryRow->total_count ?? 0),
            'critical' => (int) ($riskSummaryRow->critical_count ?? 0),
            'high' => (int) ($riskSummaryRow->high_count ?? 0),
            'medium' => (int) ($riskSummaryRow->medium_count ?? 0),
            'low' => (int) ($riskSummaryRow->low_count ?? 0),
        ];

        $queriesQuery = DbQueryEvent::query()
            ->where('session_id', $session->id);

        if ($riskFilter !== 'all') {
            $queriesQuery->where('risk_level', $riskFilter);
        }

        $queries = $queriesQuery
            ->orderByRaw("
                CASE risk_level
                    WHEN 'critical' THEN 4
                    WHEN 'high' THEN 3
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 1
                    ELSE 0
                END DESC
            ")
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $operations = DbAccessOperation::query()
            ->with('requestedBy:id,name,email')
            ->where('session_id', $session->id)
            ->latest('id')
            ->limit(50)
            ->get();

        $auditEvents = AuditEvent::query()
            ->with('actor:id,name,email')
            ->where('session_id', $session->id)
            ->latest('id')
            ->limit(100)
            ->get();

        return view('admin.sessions.show', [
            'session' => $session,
            'connections' => $connections,
            'queries' => $queries,
            'riskFilter' => $riskFilter,
            'riskSummary' => $riskSummary,
            'operations' => $operations,
            'auditEvents' => $auditEvents,
        ]);
    }

    private function isValidIpOrCidr(string $value): bool
    {
        [$ip, $prefix] = array_pad(
            explode('/', trim($value), 2),
            2,
            null,
        );

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6,
        ) ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
