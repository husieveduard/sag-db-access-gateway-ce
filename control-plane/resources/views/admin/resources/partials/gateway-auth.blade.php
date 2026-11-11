<div class="full">
    <label for="auth_mode">{{ __('resources.gateway_auth.label') }}</label>
    <select id="auth_mode" name="auth_mode">
        @php($selectedGatewayAuth = old('auth_mode', isset($resource) ? $resource->auth_mode : 'client_passthrough'))
        <option value="client_passthrough" @selected($selectedGatewayAuth === 'client_passthrough')>{{ __('resources.auth.client_passthrough') }}</option>
        <option value="managed" @selected($selectedGatewayAuth === 'managed')>{{ __('resources.auth.managed') }}</option>
    </select>
    <div class="hint">{{ __('resources.gateway_auth.notice') }}</div>
    @error('auth_mode') <div class="field-error">{{ $message }}</div> @enderror
</div>
