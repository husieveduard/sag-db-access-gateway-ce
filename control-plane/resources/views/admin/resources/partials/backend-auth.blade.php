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
