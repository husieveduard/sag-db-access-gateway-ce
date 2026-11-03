#!/usr/bin/env python3
"""Isolated Samba AD + native TDS Kerberos acceptance, never an existing domain."""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ad-image', required=True)
    parser.add_argument('--probe', type=Path, required=True)
    parser.add_argument('--managed-tests', type=Path, help='static Go managed Windows acceptance test binary')
    parser.add_argument('--managed-broker', type=Path, help='native MSSQL broker for managed acceptance')
    parser.add_argument('--php-image', default='php:8.3-cli-alpine')
    parser.add_argument('--kdc-only', action='store_true', help='test real AD service tickets without SQL Server')
    args = parser.parse_args()
    if not args.probe.is_file():
        parser.error('build the static native Kerberos probe first')
    if bool(args.managed_tests) != bool(args.managed_broker) or (args.managed_tests and args.kdc_only):
        parser.error('managed acceptance requires both binaries and the full SQL lab')
    if args.managed_tests and (not args.managed_tests.is_file() or not args.managed_broker.is_file()):
        parser.error('build the managed acceptance binaries first')
    work = Path(tempfile.mkdtemp(prefix='sag-kerberos-'))
    prefix = 'sag-kerberos-' + secrets.token_hex(4)
    network, dc, sql = prefix + '-net', prefix + '-dc', prefix + '-sql'
    client_container = prefix + '-client'
    subnet = f'172.27.{secrets.randbelow(200)+20}'
    dc_ip, sql_ip = subnet + '.10', subnet + '.20'
    passwords = {name: 'Aa!' + secrets.token_hex(20) for name in
                 ('admin_password', 'sqlservice_password', 'gateway_password', 'sa_password')}

    def run(command, payload=None, check=True, timeout=180):
        result = subprocess.run(command, input=payload, capture_output=True, timeout=timeout)
        if check and result.returncode:
            detail = (result.stdout + result.stderr).decode(errors='replace')[-4000:]
            for secret in passwords.values():
                detail = detail.replace(secret, '[redacted]')
            raise RuntimeError(f'{command[0]} failed ({result.returncode}): {detail}')
        return result

    def wait_for(call, predicate, timeout=120):
        until = time.monotonic() + timeout
        while time.monotonic() < until:
            result = call()
            if predicate(result):
                return result
            time.sleep(1)
        raise RuntimeError('Kerberos laboratory readiness deadline exceeded')

    def probe(mode='kerberos', extra=(), payload=None, check=True):
        # Material mode has no keytab/config mounts or filename arguments.
        mounts, profile_args = [], []
        if mode not in ('kerberos-material', 'ticket-material'):
            mounts = ['-v', str(work / 'gateway.keytab') + ':/run/gateway.keytab:ro',
                      '-v', str(work / 'krb5.conf') + ':/run/krb5.conf:ro']
            profile_args = ['--keytab=/run/gateway.keytab', '--config=/run/krb5.conf']
        return run(['docker', 'run', '--rm', '--name', client_container, '--user', '33:33', '--network', network,
                    '-v', str(args.probe.resolve()) + ':/run/native-probe:ro',
                    '-v', str(work / 'server.crt') + ':/run/ca.crt:ro',
                    '-v', str(work / 'wrong.crt') + ':/run/wrong.crt:ro',
                    *mounts, '--entrypoint', '/run/native-probe', '-i', args.ad_image,
                    '--mode=' + mode, *profile_args, '--ca=/run/ca.crt', *extra],
                   payload, check=check, timeout=40)

    def denied(mode, extra, payload=None):
        result = probe(mode, extra, payload=payload, check=False)
        if result.returncode == 0:
            raise RuntimeError('invalid Kerberos authentication unexpectedly succeeded')

    try:
        print('Provisioning isolated SAG.TEST AD; passwords enter stdin only', flush=True)
        run(['docker', 'network', 'create', '--internal', '--subnet', subnet + '.0/24', network])
        run(['docker', 'run', '-d', '--name', dc, '--hostname', 'dc.sag.test', '--network', network,
             '--ip', dc_ip, '--network-alias', 'dc.sag.test', '--dns', '127.0.0.1',
             '-e', 'SAG_KERBEROS_LAB=1', args.ad_image])
        run(['docker', 'exec', '-i', dc, '/usr/bin/python3', '/usr/local/bin/provision-ad.py'],
            json.dumps({**passwords, 'dc_ip': dc_ip, 'sql_ip': sql_ip}).encode())
        wait_for(lambda: run(['docker', 'exec', dc, 'dig', '@127.0.0.1', '_kerberos._tcp.sag.test', 'SRV', '+short'], check=False),
                 lambda result: result.returncode == 0 and b'dc.sag.test' in result.stdout)
        run(['docker', 'exec', '-i', dc, '/usr/bin/python3', '/usr/local/bin/configure-dns.py'],
            json.dumps({**passwords, 'dc_ip': dc_ip, 'sql_ip': sql_ip}).encode())
        for query, expected in ((['sag.sag.test', 'A'], dc_ip), (['-x', dc_ip], 'dc.sag.test')):
            result = run(['docker', 'exec', dc, 'dig', '@127.0.0.1', '+short', *query])
            if expected.encode() not in result.stdout:
                raise RuntimeError('AD NetBIOS or reverse DNS failed')
        # Capture only in runner memory; never print keytab bytes or encode them in argv/env.
        gateway_keytab = run(['docker', 'exec', '-u', 'root', dc, 'cat',
                              '/var/lib/samba/private/sag-keys/gateway.keytab']).stdout
        for name in ('gateway.keytab', 'sql.keytab'):
            run(['docker', 'cp', dc + ':/var/lib/samba/private/sag-keys/' + name, str(work / name)])
        (work / 'krb5.conf').write_text('[libdefaults]\n default_realm = SAG.TEST\n default_keytab_name = /var/opt/mssql/secrets/mssql.keytab\n default_ccache_name = ""\n dns_lookup_kdc = false\n dns_lookup_realm = false\n[realms]\n SAG.TEST = {\n  kdc = dc.sag.test\n  admin_server = dc.sag.test\n  default_domain = SAG.TEST\n }\n[domain_realm]\n .sag.test = SAG.TEST\n sag.test = SAG.TEST\n')
        (work / 'krb5.conf').chmod(0o644)
        for name, hostname in (('server', 'sql.sag.test'), ('wrong', 'untrusted.sag.test')):
            run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                 '-subj', '/CN=' + hostname, '-addext', 'subjectAltName=DNS:' + hostname,
                 '-keyout', str(work / (name + '.key')), '-out', str(work / (name + '.crt'))])
            (work / (name + '.crt')).chmod(0o644)
        (work / 'mssql.conf').write_text('[network]\nforceencryption = 1\ntlscert = /var/opt/mssql/server.crt\ntlskey = /var/opt/mssql/server.key\ntlsprotocols = 1.2\nkerberoskeytabfile = /var/opt/mssql/secrets/mssql.keytab\nprivilegedadaccount = sqlservice\nenablekdcfromkrb5conf = true\n')
        (work / 'mssql.conf').chmod(0o644)
        (work / 'resolv.conf').write_text('search sag.test\nnameserver ' + dc_ip + '\noptions timeout:1 attempts:2\n')
        (work / 'resolv.conf').chmod(0o644)
        (work / 'logger.ini').write_text('[Output:security]\nType = File\nFilename = /var/opt/mssql/log/security.log\n[Logger]\nLevel = Silent\n[Logger:security.kerberos]\nLevel = Error\nOutputs = security\n[Logger:security.ldap]\nLevel = Error\nOutputs = security\n')
        (work / 'logger.ini').chmod(0o644)
        run(['docker', 'run', '--rm', '--user', 'root', '--entrypoint', '/bin/sh',
             '-v', str(work) + ':/fixture', args.ad_image, '-c',
             'chown 0:0 /fixture/krb5.conf; chown 33:33 /fixture/gateway.keytab; chmod 600 /fixture/gateway.keytab; chown 10001:0 /fixture/sql.keytab /fixture/server.key; chmod 600 /fixture/sql.keytab /fixture/server.key'])
        material = json.dumps({'kind': 'kerberos_keytab', 'profile_id': 'isolated-ad',
                               'username': 'gateway', 'realm': 'SAG.TEST',
                               'spn': 'MSSQLSvc/sql.sag.test:1433',
                               'config_b64': base64.b64encode((work / 'krb5.conf').read_bytes()).decode(),
                               'keytab_b64': base64.b64encode(gateway_keytab).decode()}).encode()
        probe('ticket-material', payload=material)
        probe('ticket')
        print('Native Kerberos client obtained an AES service ticket from real AD', flush=True)
        denied('ticket', ['--keytab=/run/missing.keytab'])
        denied('ticket', ['--spn=MSSQLSvc/other.sag.test:1433'])
        denied('ticket', ['--target=missing.sag.test:1433', '--spn=MSSQLSvc/missing.sag.test:1433'])
        if not args.kdc_only:
            print('Starting isolated SQL Server with service keytab and verified TLS', flush=True)
            envfile = work / 'sql.env'
            envfile.write_text('ACCEPT_EULA=Y\nMSSQL_PID=Developer\nMSSQL_MEMORY_LIMIT_MB=2048\nMSSQL_SA_PASSWORD=' + passwords['sa_password'] + '\n')
            envfile.chmod(0o600)
            run(['docker', 'run', '-d', '--name', sql, '--hostname', 'sql.sag.test', '--network', network,
                 '--ip', sql_ip, '--network-alias', 'sql.sag.test', '--dns', dc_ip, '--dns-search', 'sag.test',
                 '--env-file', str(envfile),
                 '-v', str(work / 'sql.keytab') + ':/var/opt/mssql/secrets/mssql.keytab:ro',
                 '-v', str(work / 'server.key') + ':/var/opt/mssql/server.key:ro',
                 '-v', str(work / 'server.crt') + ':/var/opt/mssql/server.crt:ro',
                 '-v', str(work / 'mssql.conf') + ':/var/opt/mssql/mssql.conf:ro',
                 '-v', str(work / 'krb5.conf') + ':/etc/krb5.conf:ro',
                 '-v', str(work / 'resolv.conf') + ':/etc/resolv.conf:ro',
                 '-v', str(work / 'logger.ini') + ':/var/opt/mssql/logger.ini:ro',
                 'mcr.microsoft.com/mssql/server:2022-latest'], timeout=480)
            wait_for(lambda: probe('sql-ready', payload=passwords['sa_password'].encode(), check=False),
                     lambda result: result.returncode == 0, timeout=180)
            probe('disposable-provision', payload=passwords['sa_password'].encode())
            result = probe()
            if b'kerberos_principal=SAG\\gateway; SELECT 1 = 1' not in result.stdout:
                raise RuntimeError('SQL Server did not confirm Kerberos backend identity')
            print(result.stdout.decode().strip(), flush=True)
            memory_result = probe('kerberos-material', payload=material)
            if b'kerberos_principal=SAG\\gateway; SELECT 1 = 1' not in memory_result.stdout:
                raise RuntimeError('in-memory keytab SQL identity was not confirmed')
            print('In-memory keytab without file mounts: AD principal and SELECT 1 confirmed', flush=True)
            invalid = dict(json.loads(material), keytab_b64='')
            denied('kerberos-material', [], payload=json.dumps(invalid).encode())
            invalid = dict(json.loads(material), spn='MSSQLSvc/other.sag.test:1433')
            denied('kerberos-material', [], payload=json.dumps(invalid).encode())
            denied('kerberos-material', ['--ca=/run/wrong.crt'], payload=material)
            denied('kerberos', ['--ca=/run/wrong.crt'])
        if args.managed_tests:
            print('Verifying temporary SAG login, Laravel runtime lifecycle, IPC and AD SQL', flush=True)
            repository = Path(__file__).resolve().parents[1]
            managed = run(['docker', 'run', '--rm', '--name', client_container, '--user', '33:33',
                           '--network', network, '--dns', dc_ip, '--dns-search', 'sag.test',
                           '-v', str(repository) + ':/src:ro', '-w', '/src/db-gateway',
                           '-v', str(args.managed_tests.resolve()) + ':/run/managed-tests:ro',
                           '-v', str(args.managed_broker.resolve()) + ':/run/sag-mssql-broker:ro',
                           '-v', str(work / 'gateway.keytab') + ':/run/gateway.keytab:ro',
                           '-v', str(work / 'krb5.conf') + ':/run/krb5.conf:ro',
                           '-v', str(work / 'server.crt') + ':/run/ca.crt:ro',
                           '-e', 'SAG_E2E_MSSQL_KERBEROS=1',
                           '-e', 'SAG_E2E_MSSQL_BROKER_BINARY=/run/sag-mssql-broker',
                           '-e', 'SAG_E2E_MSSQL_CA=/run/ca.crt',
                           '--entrypoint', '/run/managed-tests', '-i', args.php_image,
                           '-test.v', '-test.run=^TestMSSQLKerberosManagedSQL$', '-test.timeout=8m'],
                          json.dumps({'sa_password': passwords['sa_password']}).encode(), timeout=520)
            output = managed.stdout.decode(errors='replace')
            if '--- PASS: TestMSSQLKerberosManagedSQL ' not in output or '--- SKIP:' in output:
                raise RuntimeError('managed Windows SQL acceptance did not execute')
            print(output, flush=True)
            print('Managed Windows SQL: principal, replay denial, active revoke/expiry and zero backend sessions passed', flush=True)
        run(['docker', 'stop', dc])
        denied('ticket', [])
        denied('ticket-material', [], payload=material)
        print('Missing keytab, wrong/unknown SPN and KDC loss fail closed; no SQL fallback', flush=True)
    except Exception:
        diagnostic = run(['docker', 'exec', sql, 'sh', '-c', 'getent hosts SAG sag.test dc.sag.test; tail -40 /var/opt/mssql/log/security.log'], check=False)
        message = (diagnostic.stdout + diagnostic.stderr).decode(errors='replace')
        for secret in passwords.values():
            message = message.replace(secret, '[redacted]')
        print('SQL AD lookup diagnostics (Error level only): ' + message, flush=True)
        for container in (dc, sql):
            logs = run(['docker', 'logs', '--tail', '30', container], check=False)
            message = (logs.stdout + logs.stderr).decode(errors='replace')
            for secret in passwords.values():
                message = message.replace(secret, '[redacted]')
            print(container + ': ' + message, flush=True)
        raise
    finally:
        run(['docker', 'rm', '-fv', client_container, sql, dc], check=False)
        run(['docker', 'network', 'rm', network], check=False)
        run(['docker', 'run', '--rm', '--user', 'root', '--entrypoint', '/bin/sh', '-v', str(work) + ':/fixture',
             args.ad_image, '-c', 'chown -R ' + str(os.getuid()) + ':' + str(os.getgid()) + ' /fixture; chmod -R u+rwX /fixture'], check=False)
        shutil.rmtree(work)


if __name__ == '__main__':
    main()
