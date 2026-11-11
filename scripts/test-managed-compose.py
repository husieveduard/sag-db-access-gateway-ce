#!/usr/bin/env python3
"""Disposable, production-image acceptance for SQL-password managed brokers."""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import selectors
import shutil
import subprocess
import tempfile
import time


REPO = Path(__file__).resolve().parents[1]
IMAGES = {"mysql": "mysql:8.4", "mariadb": "mariadb:11.4",
          "mssql": "mcr.microsoft.com/mssql/server:2022-latest"}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--engine", choices=IMAGES, required=True)
    parser.add_argument("--image", required=True, help="already built CE runtime image")
    parser.add_argument("--probe", type=Path, required=True, help="static managed_compose_probe_main.go binary")
    args = parser.parse_args()
    if not args.probe.is_file():
        parser.error("build the static Go probe first")
    project = "sag-accept-" + secrets.token_hex(4)
    work = Path(tempfile.mkdtemp(prefix=project + "."))
    target = project + "-target"
    worker = project + "-worker-1"
    admin = "Aa!" + secrets.token_hex(20)
    backend = secrets.token_hex(24)
    secret_values = [admin, backend]
    client = None

    def run(command, payload=None, check=True, timeout=240):
        result = subprocess.run(command, input=payload, capture_output=True, timeout=timeout, cwd=REPO)
        if check and result.returncode:
            message = (result.stdout + result.stderr).decode(errors="replace")[-4000:]
            for value in secret_values:
                message = message.replace(value, "[redacted]")
            raise RuntimeError(f"{command[0]} failed ({result.returncode}): {message}")
        return result

    compose = ["docker", "compose", "-p", project, "--project-directory", str(REPO),
               "--env-file", str(work / "compose.env"), "-f", "deploy/compose.yaml",
               "-f", "deploy/compose.managed-candidate.yaml", "-f", str(work / "images.yaml")]

    def fixture(action, **data):
        payload = json.dumps({"action": action, **data}).encode()
        result = run(["docker", "exec", "-i", "-e", "SAG_COMPOSE_ACCEPTANCE=1", worker,
                      "php", "/tmp/managed-compose.php"], payload)
        return json.loads(result.stdout)

    def probe_arguments(username, database, address, ca, *extra):
        return ["docker", "exec", "-i", worker, "/tmp/managed-compose-probe",
                "--engine=" + args.engine, "--username=" + username,
                "--database=" + database, "--address=" + address, "--ca=" + ca, *extra]

    def wait_for(call, predicate, timeout=40):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            result = call()
            if predicate(result):
                return result
            time.sleep(0.5)
        raise RuntimeError("acceptance readiness deadline exceeded")

    try:
        print(f"{args.engine}: preparing isolated Compose runtime", flush=True)
        run(["docker", "image", "inspect", args.image])
        managed, certs, public = work / "managed", work / "target", work / "public"
        for directory in (managed, certs, public):
            directory.mkdir(mode=0o755)
        # The executor may use umask 077. Nginx workers need directory
        # traversal just as they do in the packaged nginx image.
        public.chmod(0o755)
        shutil.copyfile(REPO / "control-plane/public/index.php", public / "index.php")
        (public / "index.php").chmod(0o644)
        for directory, hostname in ((managed, "localhost"), (certs, "target")):
            run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                 "-subj", "/CN=" + hostname, "-addext", "subjectAltName=DNS:" + hostname,
                 "-keyout", str(directory / "server.key"), "-out", str(directory / "server.crt")])
        shutil.copyfile(managed / "server.key", managed / "frontend.key")
        shutil.copyfile(managed / "server.crt", managed / "frontend.crt")
        for engine in ("postgresql", "mysql", "mssql"):
            shutil.copyfile(certs / "server.crt", managed / (engine + "-ca.crt"))
        # Target images mount individual files; the host parent remains private.
        certs.chmod(0o700)
        for filename in ("server.crt", "server.key"):
            (certs / filename).chmod(0o644)
        app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        vault_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        internal_token = secrets.token_hex(24)
        control_password, redis_password = secrets.token_hex(24), secrets.token_hex(24)
        secret_values.extend([app_key, vault_key, internal_token, control_password, redis_password])
        environment = {
            "SAG_CE_APP_KEY": app_key, "SAG_CE_CREDENTIAL_KEY": vault_key,
            "SAG_CE_INTERNAL_TOKEN": internal_token, "SAG_CE_POSTGRES_PASSWORD": control_password,
            "SAG_CE_REDIS_PASSWORD": redis_password, "SAG_CE_POSTGRES_DB": "sag_ce_accept_" + project[-8:],
            "SAG_CE_POSTGRES_USER": "sag_accept", "SAG_CE_PUBLIC_URL": "https://localhost",
            "SAG_CE_GATEWAY_PUBLIC_HOST": "localhost", "SAG_CE_DB_GATEWAY_PORT_MIN": "16700",
            "SAG_CE_DB_GATEWAY_PORT_MAX": "16709", "SAG_CE_SESSION_SECURE_COOKIE": "true",
            "SAG_CE_DOCKER_SUBNET": f"172.29.{secrets.randbelow(200) + 20}.0/24",
            "SAG_CE_MAIL_FROM_ADDRESS": "acceptance@example.invalid", "SAG_CE_HTTP_BIND": "127.0.0.1",
            "SAG_CE_HTTP_PORT": "18092", "SAG_CE_MANAGED_CERT_DIR": str(managed),
        }
        env_file = work / "compose.env"
        env_file.write_text("".join(f"{key}={value}\n" for key, value in environment.items()))
        env_file.chmod(0o600)
        (work / "images.yaml").write_text(
            "services:\n"
            f"  app:\n    image: {json.dumps(args.image)}\n    build: !reset null\n"
            f"  worker:\n    image: {json.dumps(args.image)}\n    build: !reset null\n    ports: !reset []\n"
            "  nginx:\n    image: nginx:1.27-alpine\n    build: !reset null\n    ports: !reset []\n    volumes:\n"
            f"      - {json.dumps(str(public) + ':/var/www/html/public:ro')}\n"
            f"      - {json.dumps(str(REPO / 'deploy/nginx/default.conf.template') + ':/etc/nginx/templates/default.conf.template:ro')}\n")
        run(["docker", "run", "--rm", "--user", "root", "-v", str(managed) + ":/certs",
             "nginx:1.27-alpine", "sh", "-c", "chown -R 33:33 /certs; chmod 700 /certs; chmod 600 /certs/frontend.key"])
        run(compose + ["up", "-d", "postgres", "redis", "app"])
        run(compose + ["exec", "-T", "app", "php", "artisan", "migrate", "--force", "--no-interaction"])
        run(compose + ["up", "-d", "worker", "nginx"])
        wait_for(lambda: run(["docker", "exec", worker, "test", "-S", "/run/sag-db-gateway/ipc/worker.sock"], check=False),
                 lambda result: result.returncode == 0)
        for source, destination in ((args.probe.resolve(), "/tmp/managed-compose-probe"),
                                    (REPO / "control-plane/tests/Fixtures/managed-compose.php", "/tmp/managed-compose.php")):
            run(["docker", "cp", str(source), worker + ":" + destination])
        run(["docker", "exec", "-u", "root", worker, "chmod", "0755", "/tmp/managed-compose-probe"])
        run(["docker", "exec", "-u", "root", worker, "chmod", "0644", "/tmp/managed-compose.php"])
        target_env = work / "target.env"
        if args.engine == "mssql":
            target_env.write_text(f"ACCEPT_EULA=Y\nMSSQL_PID=Developer\nMSSQL_SA_PASSWORD={admin}\nMSSQL_MEMORY_LIMIT_MB=2048\n")
            (certs / "mssql.conf").write_text("[network]\nforceencryption = 1\ntlscert = /var/opt/mssql/test-server.crt\ntlskey = /var/opt/mssql/test-server.key\ntlsprotocols = 1.2\n")
            (certs / "mssql.conf").chmod(0o644)
            mounts = ["-v", str(certs / "server.crt") + ":/var/opt/mssql/test-server.crt:ro",
                      "-v", str(certs / "server.key") + ":/var/opt/mssql/test-server.key:ro",
                      "-v", str(certs / "mssql.conf") + ":/var/opt/mssql/mssql.conf:ro"]
            options, port, admin_user, admin_db = [], 1433, "sa", "master"
        else:
            prefix = "MYSQL" if args.engine == "mysql" else "MARIADB"
            target_env.write_text(f"{prefix}_ROOT_PASSWORD={admin}\n{prefix}_ROOT_HOST=%\n")
            mounts = []
            for filename in ("server.crt", "server.key"):
                mounts.extend(["-v", str(certs / filename) + ":/etc/mysql/test-" + filename + ":ro"])
            options = ["--ssl-ca=/etc/mysql/test-server.crt", "--ssl-cert=/etc/mysql/test-server.crt",
                       "--ssl-key=/etc/mysql/test-server.key", "--require-secure-transport=ON"]
            port, admin_user, admin_db = 3306, "root", ""
        target_env.chmod(0o600)
        print(f"{args.engine}: starting verified-TLS target", flush=True)
        run(["docker", "run", "-d", "--name", target, "--network", project + "_sag_internal",
             "--network-alias", "target", "--env-file", str(target_env), *mounts, IMAGES[args.engine], *options], timeout=480)
        admin_args = probe_arguments(admin_user, admin_db, f"target:{port}", "/etc/sag/managed/mysql-ca.crt", "--tls-name=target")
        wait_for(lambda: run(admin_args, admin.encode(), check=False), lambda result: result.returncode == 0, timeout=120)
        run(admin_args + ["--provision"], json.dumps({"admin": admin, "backend": backend}).encode())
        setup = fixture("setup", engine=args.engine, backend_password=backend)
        secret_values.append(setup["temporary"]["password"])
        session = setup["public_id"]
        state = wait_for(lambda: fixture("status", public_id=session, backend_password=backend),
                         lambda state: state["operation"] in ("succeeded", "failed"))
        if (state["operation"] != "succeeded" or state["session"] != "started"
                or not all(state[key] for key in ("bootstrap_consumed", "environment_safe", "process_bound", "argv_safe"))):
            raise RuntimeError("managed launch or process environment check failed: " + json.dumps(state))
        if not fixture("foreign_bootstrap", public_id=session)["denied"]:
            raise RuntimeError("foreign process replay accepted")
        probe = probe_arguments(setup["temporary"]["username"], "sag_compose_e2e",
                                f"127.0.0.1:{state['port']}", "/etc/sag/managed/frontend.crt", "--hold-for-revoke")
        client = subprocess.Popen(probe, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        client.stdin.write(setup["temporary"]["password"].encode())
        client.stdin.close()
        with selectors.DefaultSelector() as selector:
            selector.register(client.stdout, selectors.EVENT_READ)
            if not selector.select(20) or client.stdout.readline().strip() != b"SELECT 1 = 1":
                client.kill()
                client.wait()
                diagnostic = client.stderr.read().decode(errors="replace")[-1000:]
                for value in secret_values:
                    diagnostic = diagnostic.replace(value, "[redacted]")
                raise RuntimeError("independent verified-TLS SELECT 1 failed: " + diagnostic)
        print(f"{args.engine}: SELECT 1 = 1; foreign bootstrap denied; process environment safe", flush=True)
        fixture("revoke", public_id=session)
        state = wait_for(lambda: fixture("status", public_id=session),
                         lambda state: state["session"] == "terminated" and state["operation"] == "succeeded")
        client.wait(timeout=20)
        if client.returncode != 0 or b"revoked_connection_closed" not in client.stdout.read():
            raise RuntimeError("revoked SQL connection stayed usable")
        audit = fixture("audit", public_id=session, backend_password=backend)
        if not audit["logs_safe"] or not audit["listener_closed"]:
            raise RuntimeError("secret or listener acceptance failed")
        run(admin_args + ["--count-backend"], admin.encode())
        print(f"{args.engine}: queued revoke closed active SQL and listener; backend sessions=0; logs safe", flush=True)
    except Exception:
        for name in (worker, project + "-nginx-1", target):
            logs = run(["docker", "logs", "--tail", "20", name], check=False)
            diagnostic = (logs.stdout + logs.stderr).decode(errors="replace")
            for value in secret_values:
                diagnostic = diagnostic.replace(value, "[redacted]")
            print(name + ": " + diagnostic, flush=True)
        raise
    finally:
        if client is not None and client.poll() is None:
            client.kill()
            client.wait()
        run(["docker", "rm", "-fv", target], check=False)
        if (work / "images.yaml").exists():
            run(compose + ["down", "--volumes", "--remove-orphans"], check=False)
        run(["docker", "run", "--rm", "--user", "root", "-v", str(work) + ":/fixture",
             "nginx:1.27-alpine", "chown", "-R", f"{os.getuid()}:{os.getgid()}", "/fixture"], check=False)
        shutil.rmtree(work)


if __name__ == "__main__":
    main()
