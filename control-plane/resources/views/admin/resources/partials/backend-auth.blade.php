<div class="full">
    <label for="backend_auth_mode">{{ __('resources.backend_auth.label') }}</label>
    <select id="backend_auth_mode" name="backend_auth_mode">
        @php($selectedBackendAuth = old('backend_auth_mode', isset($resource) && $resource->engine === 'mssql' ? $resource->backendAuthMode() : 'mssql_sql_auth'))
        <option value="mssql_sql_auth" @selected($selectedBackendAuth === 'mssql_sql_auth')>{{ __('resources.backend_auth.sql') }}</option>
        <option value="mssql_kerberos_service" @selected($selectedBackendAuth === 'mssql_kerberos_service')>{{ __('resources.backend_auth.windows') }}</option>
    </select>
    <div class="hint">{{ __('resources.backend_auth.notice') }}</div>
    @error('backend_auth_mode') <div class="field-error">{{ $message }}</div> @enderror
</div>
@if (count($kerberosProfiles ?? []) > 0)
<div class="full" id="kerberos-profile-field">
    <label for="kerberos_profile">{{ __('resources.backend_auth.profile') }}</label>
    <select id="kerberos_profile" name="kerberos_profile">
        <option value="">{{ __('resources.backend_auth.profile_choose') }}</option>
        @foreach ($kerberosProfiles as $alias)
            <option value="{{ $alias }}" @selected(old('kerberos_profile', isset($resource) ? data_get($resource->target_options, 'kerberos_profile') : '') === $alias)>{{ $alias }}</option>
        @endforeach
    </select>
    <div class="hint">{{ __('resources.backend_auth.profile_notice') }}</div>
    @error('kerberos_profile') <div class="field-error">{{ $message }}</div> @enderror
</div>
<script>
(() => {
    const mode = document.getElementById('backend_auth_mode');
    const engine = document.getElementById('engine');
    const field = document.getElementById('kerberos-profile-field');
    const profile = document.getElementById('kerberos_profile');
    const update = () => {
        const enabled = engine.value === 'mssql' && mode.value === 'mssql_kerberos_service';
        field.hidden = !enabled;
        profile.disabled = !enabled;
    };
    mode.addEventListener('change', update);
    engine.addEventListener('change', update);
    update();
})();
</script>
@endif
