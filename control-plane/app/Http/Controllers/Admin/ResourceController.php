<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ResourceController extends Controller
{
    private const ENGINES = [
        'mysql' => 'MySQL / MariaDB',
        'postgresql' => 'PostgreSQL',
        'mssql' => 'Microsoft SQL Server (experimental)',
    ];

    private const ACTIVE_GATEWAY_STATUSES = [
        'starting',
        'started',
    ];

    public function index(): View
    {
        $resources = DatabaseResource::query()
            ->with('createdBy:id,name,email')
            ->withCount([
                'sessions',
                'sessions as active_sessions_count' => fn ($query) => $query
                    ->whereIn('status', self::ACTIVE_GATEWAY_STATUSES),
                'connections as open_connections_count' => fn ($query) => $query
                    ->where('status', 'opened'),
            ])
            ->orderBy('name')
            ->get();

        return view('admin.resources.index', compact('resources'));
    }

    public function create(): View
    {
        return view('admin.resources.create', [
            'engines' => self::ENGINES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateResource($request);

        $resource = DB::transaction(function () use ($data, $request): DatabaseResource {
            $resource = DatabaseResource::query()->create([
                ...$this->resourceAttributes($data),
                'target_tls_mode' => 'prefer',
                'auth_mode' => 'client_passthrough',
                'query_audit_enabled' => true,
                'is_active' => true,
                'target_options' => [
                    'created_via' => 'web_console',
                    'credential_storage' => 'none',
                ],
                'created_by_user_id' => $request->user()->id,
            ]);

            $this->writeAudit(
                $request,
                $resource,
                'access.db.resource.created',
                'info',
                [
                    'resource' => $this->snapshot($resource),
                    'credential_storage' => 'none',
                    'source' => 'web_console',
                ],
            );

            return $resource;
        });

        return redirect()
            ->route('admin.resources.show', $resource)
            ->with('success', "DB resource «{$resource->name}» створено.");
    }

    public function show(DatabaseResource $resource): View
    {
        $resource->load('createdBy:id,name,email');

        $resource->loadCount([
            'sessions',
            'sessions as active_sessions_count' => fn ($query) => $query
                ->whereIn('status', self::ACTIVE_GATEWAY_STATUSES),
            'connections as open_connections_count' => fn ($query) => $query
                ->where('status', 'opened'),
        ]);

        $sessions = DbAccessSession::query()
            ->with('owner:id,name,email')
            ->where('resource_id', $resource->id)
            ->withCount([
                'connections as open_connections_count' => fn ($query) => $query
                    ->where('status', 'opened'),
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        $auditEvents = AuditEvent::query()
            ->with('actor:id,name,email')
            ->where('resource_id', $resource->id)
            ->latest('id')
            ->limit(100)
            ->get();

        return view('admin.resources.show', compact(
            'resource',
            'sessions',
            'auditEvents',
        ));
    }

    public function edit(DatabaseResource $resource): View
    {
        $resource->loadCount([
            'sessions as active_sessions_count' => fn ($query) => $query
                ->whereIn('status', self::ACTIVE_GATEWAY_STATUSES),
        ]);

        return view('admin.resources.edit', [
            'resource' => $resource,
            'engines' => self::ENGINES,
        ]);
    }

    public function update(
        Request $request,
        DatabaseResource $resource,
    ): RedirectResponse {
        $data = $this->validateResource($request, $resource);

        try {
            DB::transaction(function () use ($data, $request, $resource): void {
                $locked = DatabaseResource::query()
                    ->whereKey($resource->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $before = $this->snapshot($locked);
                $attributes = $this->resourceAttributes($data);

                $targetFields = [
                    'engine',
                    'target_host',
                    'target_port',
                    'target_database',
                ];

                $targetChanged = collect($targetFields)->contains(
                    fn (string $field): bool =>
                        (string) ($before[$field] ?? '')
                        !== (string) ($attributes[$field] ?? '')
                );

                $activeGatewaySessions = DbAccessSession::query()
                    ->where('resource_id', $locked->id)
                    ->whereIn('status', self::ACTIVE_GATEWAY_STATUSES)
                    ->count();

                if ($targetChanged && $activeGatewaySessions > 0) {
                    throw new DomainException(
                        'Неможливо змінити engine або target, поки існують активні gateway sessions.'
                    );
                }

                $locked->forceFill([
                    ...$attributes,
                    'target_options' => array_merge(
                        is_array($locked->target_options)
                            ? $locked->target_options
                            : [],
                        ['updated_via' => 'web_console'],
                    ),
                ])->save();

                $after = $this->snapshot($locked);
                $changes = [];

                foreach ($after as $field => $value) {
                    if (($before[$field] ?? null) !== $value) {
                        $changes[$field] = [
                            'before' => $before[$field] ?? null,
                            'after' => $value,
                        ];
                    }
                }

                if ($changes !== []) {
                    $this->writeAudit(
                        $request,
                        $locked,
                        'access.db.resource.updated',
                        $targetChanged ? 'medium' : 'info',
                        [
                            'changes' => $changes,
                            'active_gateway_sessions' => $activeGatewaySessions,
                            'source' => 'web_console',
                        ],
                    );
                }
            });
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['resource' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.resources.show', $resource)
            ->with('success', 'DB resource оновлено.');
    }

    public function deactivate(
        Request $request,
        DatabaseResource $resource,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($data, $request, $resource): void {
                $locked = DatabaseResource::query()
                    ->whereKey($resource->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$locked->is_active) {
                    throw new DomainException('Resource уже деактивований.');
                }

                $activeGatewaySessions = DbAccessSession::query()
                    ->where('resource_id', $locked->id)
                    ->whereIn('status', self::ACTIVE_GATEWAY_STATUSES)
                    ->count();

                $locked->forceFill([
                    'is_active' => false,
                    'target_options' => array_merge(
                        is_array($locked->target_options)
                            ? $locked->target_options
                            : [],
                        [
                            'deactivated_via' => 'web_console',
                            'deactivated_at' => now()->toIso8601String(),
                        ],
                    ),
                ])->save();

                $this->writeAudit(
                    $request,
                    $locked,
                    'access.db.resource.deactivated',
                    'high',
                    [
                        'reason' => trim($data['reason']),
                        'active_gateway_sessions_preserved' => $activeGatewaySessions,
                        'effect' => 'blocks_new_sessions_and_gateway_start',
                        'source' => 'web_console',
                    ],
                );
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['resource' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.resources.show', $resource)
            ->with('success', 'Resource деактивовано.');
    }

    public function activate(
        Request $request,
        DatabaseResource $resource,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($data, $request, $resource): void {
                $locked = DatabaseResource::query()
                    ->whereKey($resource->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->is_active) {
                    throw new DomainException('Resource уже активний.');
                }

                $locked->forceFill([
                    'is_active' => true,
                    'target_options' => array_merge(
                        is_array($locked->target_options)
                            ? $locked->target_options
                            : [],
                        [
                            'activated_via' => 'web_console',
                            'activated_at' => now()->toIso8601String(),
                        ],
                    ),
                ])->save();

                $this->writeAudit(
                    $request,
                    $locked,
                    'access.db.resource.activated',
                    'info',
                    [
                        'reason' => trim($data['reason']),
                        'source' => 'web_console',
                    ],
                );
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['resource' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.resources.show', $resource)
            ->with('success', 'Resource активовано.');
    }

    private function validateResource(
        Request $request,
        ?DatabaseResource $resource = null,
    ): array {
        $nameRule = Rule::unique('database_resources', 'name');

        if ($resource) {
            $nameRule->ignore($resource->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160', $nameRule],
            'description' => ['nullable', 'string', 'max:5000'],
            'engine' => ['required', Rule::in(array_keys(self::ENGINES))],
            'target_host' => [
                'required',
                'string',
                'max:253',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $host = trim((string) $value);

                    if (
                        $host === ''
                        || preg_match('/[\s\/\\\\:@?#]/', $host)
                    ) {
                        $fail('Вкажіть IP-адресу або DNS-ім’я без протоколу, порту чи шляху.');

                        return;
                    }

                    $normalized = rtrim($host, '.');

                    if (
                        filter_var($normalized, FILTER_VALIDATE_IP)
                        || preg_match(
                            '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/',
                            $normalized,
                        )
                    ) {
                        return;
                    }

                    $fail('Вкажіть коректне DNS-ім’я або IP-адресу.');
                },
            ],
            'target_port' => ['required', 'integer', 'between:1,65535'],
            'target_database' => ['nullable', 'string', 'max:128'],
        ]);
    }

    private function resourceAttributes(array $data): array
    {
        $host = trim((string) $data['target_host']);

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $host = strtolower(rtrim($host, '.'));
        }

        return [
            'name' => trim((string) $data['name']),
            'description' => filled($data['description'] ?? null)
                ? trim((string) $data['description'])
                : null,
            'engine' => (string) $data['engine'],
            'target_host' => $host,
            'target_port' => (int) $data['target_port'],
            'target_database' => filled($data['target_database'] ?? null)
                ? trim((string) $data['target_database'])
                : null,
        ];
    }

    private function snapshot(DatabaseResource $resource): array
    {
        return [
            'name' => $resource->name,
            'description' => $resource->description,
            'engine' => $resource->engine,
            'target_host' => $resource->target_host,
            'target_port' => $resource->target_port,
            'target_database' => $resource->target_database,
            'target_tls_mode' => $resource->target_tls_mode,
            'auth_mode' => $resource->auth_mode,
            'query_audit_enabled' => (bool) $resource->query_audit_enabled,
            'is_active' => (bool) $resource->is_active,
        ];
    }

    private function writeAudit(
        Request $request,
        DatabaseResource $resource,
        string $eventType,
        string $severity,
        array $eventData,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $request->user()->id,
            'resource_id' => $resource->id,
            'category' => 'db_access',
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit(
                (string) $request->userAgent(),
                500,
                '',
            ),
            'event_data' => $eventData,
            'occurred_at' => now(),
        ]);
    }
}
