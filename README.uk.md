
[🇬🇧 English](README.md) | [🇺🇦 Українська](README.uk.md)

# SAG DB Access Gateway CE

> **Pilot / pre-release.** Self-hosted gateway для контрольованого й аудитованого доступу до PostgreSQL, MySQL та експериментально Microsoft SQL Server.

SAG DB Access Gateway CE — це легкий control plane для тимчасового та постійного доступу до баз даних. Рішення призначене для команд, яким потрібні контрольоване DB-підключення, SQL audit, керування життєвим циклом сесій і обов’язкове MFA без розгортання повної PAM-платформи.

## Документація

- [Інструкція з розгортання](docs/DEPLOYMENT.md)
- [Шаблон host Nginx reverse proxy](deploy/reverse-proxy/nginx-host.conf.example)


## Основні можливості

* Тимчасові DB-сесії з налаштованим TTL.
* Постійні сесії для моніторингу, інтеграцій, звітності та довготривалих задач.
* Динамічний gateway-порт для кожної сесії.
* Підтримка PostgreSQL і MySQL.
* Експериментальна підтримка Microsoft SQL Server.
* Обмеження джерела підключення за IP/CIDR.
* Аудит початку та завершення DB-підключень.
* SQL audit із базовою класифікацією ризику.
* Ручне завершення сесій.
* Автоматичне завершення тимчасових сесій після TTL.
* Один локальний CE Operator.
* Обов’язкове TOTP MFA з QR-налаштуванням і recovery codes.
* Аварійне скидання MFA та пароля через локальні консольні команди.
* Docker Compose deployment для чистого сервера.

## Модель безпеки

SAG DB Access Gateway CE навмисно залишається простим.

* Одночасно може існувати лише один активний CE Operator.
* Початковий Operator створюється інтерактивно після інсталяції.
* Облікові дані Operator-а не зберігаються у Git, Docker Compose або `deploy/.env`.
* Налаштування MFA є обов’язковим під час першого web-входу.
* TOTP secrets шифруються Laravel.
* Recovery codes зберігаються лише у вигляді hash і показуються один раз.
* Паролі цільових баз даних не зберігаються control plane.
* Операції lifecycle gateway і TTL cleanup працюють в одному worker container.
* Internal gateway API доступний лише з Docker network.
* CE web console прив’язана до localhost і має публікуватися через HTTPS reverse proxy.

Community Edition не містить складних RBAC-матриць, workflow погодження, LDAP, Active Directory, SSO, Vault, SSH/RDP access, Guacamole або інтеграцій з Incident Manager.

## Архітектура

```text
Browser
  |
  | HTTPS :443
  v
Host Nginx / Reverse Proxy
  |
  | HTTP 127.0.0.1:18081
  v
Docker Nginx
  |
  v
Laravel Control Plane
  |
  +--> PostgreSQL
  +--> Redis
  +--> Worker + Scheduler + Gateway child processes
          |
          | Dynamic TCP ports 16000-17999
          v
      Go DB Gateway
          |
          v
Target PostgreSQL / MySQL / experimental MSSQL
```

## Runtime-сервіси

Production deployment запускає:

* `postgres` — база даних control plane.
* `redis` — cache та runtime-підтримка.
* `app` — Laravel PHP-FPM application.
* `worker` — lifecycle worker, scheduler і дочірні Go DB gateway-процеси.
* `nginx` — внутрішній web frontend, прив’язаний до localhost.

## Вимоги

* Linux server.
* Docker Engine і Docker Compose v2.
* `git`, `openssl` і Bash.
* DNS-ім’я для консолі, наприклад `db-ce.example.com`.
* Host Nginx або інший reverse proxy для HTTPS.
* Мережеве з’єднання gateway-сервера з цільовими базами даних.
* Firewall rules для діапазону динамічних gateway-портів.

## Швидка інсталяція

Клонуй репозиторій:

```bash
cd /opt

git clone https://github.com/husieveduard/sag-db-access-gateway-ce.git \
  sag-db-access-gateway-ce

cd sag-db-access-gateway-ce
```

Для production рекомендується розгортати конкретний release tag:

```bash
git fetch --tags
git checkout <release-tag>
```

Запусти installer:

```bash
chmod +x scripts/install.sh
sudo ./scripts/install.sh
```

Installer запитає:

```text
Public console URL [https://server-name]:
Gateway public DNS/IP [server-name]:
```

Приклад:

```text
Public console URL: https://db-ce.example.com
Gateway public DNS/IP: db-ce.example.com
```

Installer автоматично:

1. Створює `deploy/.env` з правами `0600`.
2. Генерує Laravel `APP_KEY`.
3. Генерує internal gateway API token.
4. Генерує паролі PostgreSQL і Redis.
5. Збирає Docker images.
6. Запускає CE services.
7. Виконує database migrations.
8. Запускає інтерактивне створення початкового Operator-а.

## Початковий CE Operator

Під час інсталяції запускається команда:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-create-admin
```

Команда запитає:

```text
Administrator email:
Operator display name:
Initial password:
Confirm initial password:
```

Пароль має містити щонайменше 14 символів, великі та малі літери й цифру.

Дозволено лише одного активного CE Operator-а. Команда відмовиться створювати ще одного активного користувача.

## Перший вхід і MFA

Після налаштування HTTPS:

1. Відкрий public console URL.
2. Увійди з email і паролем Operator-а.
3. Відскануй QR-код у TOTP application.
4. Введи шестизначний verification code.
5. Збережи recovery codes офлайн.

Підходять Microsoft Authenticator, Google Authenticator, 1Password та інші TOTP-compatible applications.

MFA є обов’язковим для кожного наступного входу до консолі.

## HTTPS reverse proxy

Docker web frontend прив’язаний до localhost на порту `18081`. Не відкривай цей порт назовні.

Приклад host Nginx configuration:

```nginx
server {
    listen 80;
    server_name db-ce.example.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name db-ce.example.com;

    ssl_certificate     /etc/letsencrypt/live/db-ce.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/db-ce.example.com/privkey.pem;

    client_max_body_size 10m;

    location / {
        proxy_pass http://127.0.0.1:18081;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }
}
```

Перевір і перезавантаж Nginx:

```bash
nginx -t
systemctl reload nginx
```

## Firewall model

Відкривай лише необхідні порти.

| Порт або діапазон  | Призначення                | Доступ                            |
| ------------------ | -------------------------- | --------------------------------- |
| `443/TCP`          | Web console                | Мережа Operator-а                 |
| `80/TCP`           | HTTP redirect або ACME     | Опційно                           |
| `16000-17999/TCP`  | Динамічні DB gateway-порти | Лише погоджені DB client networks |
| `18081/TCP`        | Internal Docker frontend   | Лише localhost                    |
| PostgreSQL / Redis | Internal services          | Ніколи не публікувати             |

Gateway port range змінюється у `deploy/.env`:

```env
SAG_CE_DB_GATEWAY_PORT_MIN=16000
SAG_CE_DB_GATEWAY_PORT_MAX=17999
```

## Health та операційні команди

Перевірка deployment status:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  ps
```

Перевірка application health:

```bash
curl -fsS http://127.0.0.1:18081/api/health
```

Перегляд worker logs:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  logs -f worker
```

Ручний запуск migrations:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan migrate --force
```

Ручний запуск expiration cleanup:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:cleanup-expired
```

Ручне завершення DB session:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:db-terminate-session \
  <SESSION_UID> \
  --reason=manual_revoke
```

Lifecycle-команди, які запускають або завершують gateway-процеси, мають виконуватися в `worker` container.

## Відновлення доступу Operator-а

Скидання пароля Operator-а:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-set-password \
  operator@example.com
```

Аварійне скидання MFA:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-reset-mfa \
  operator@example.com \
  --reason='Emergency MFA recovery' \
  --confirm
```

Після MFA reset наступний web-вхід вимагатиме нового QR/TOTP enrollment.

## Backup

PostgreSQL database містить сесії, audit events, resources, users, MFA configuration і зашифровані TOTP secrets.

Створення database backup:

```bash
set -a
. deploy/.env
set +a

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec -T postgres \
  pg_dump \
    -U "$SAG_CE_POSTGRES_USER" \
    "$SAG_CE_POSTGRES_DB" \
  > "sag-db-ce-$(date +%F-%H%M%S).sql"
```

Зберігай backups у зашифрованому вигляді та, за можливості, поза gateway-сервером.

Окремо і безпечно зберігай `deploy/.env`. Він містить Laravel `APP_KEY`; без нього неможливо відновити зашифровані MFA secrets після restoration.

## Upgrade

```bash
git fetch --tags
git checkout <release-tag>

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  up -d --build

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan migrate --force
```

> Upgrade перезапускає worker container. Активні DB gateway sessions будуть перервані. Плануй maintenance window для production upgrade.

## Перевірки перед публікацією

Перед публікацією змін:

```bash
./scripts/preflight-public.sh

docker compose \
  --project-directory "$PWD" \
  --env-file deploy/.env.example \
  -f deploy/compose.yaml \
  config -q
```

Не додавай у Git:

* `deploy/.env`;
* Laravel `.env` files;
* паролі, tokens, certificates або private keys;
* database backups;
* generated logs;
* локальні build artifacts.

## Scope та обмеження

Включено:

* PostgreSQL gateway.
* MySQL gateway.
* Експериментальний MSSQL gateway.
* Тимчасові та постійні DB sessions.
* SQL audit і базову SQL risk classification.
* Manual termination і automatic TTL cleanup.
* Обов’язкове QR/TOTP MFA.
* Docker Compose runtime.

Не включено:

* SSH, RDP і web-access gateway.
* Складні multi-user RBAC policy matrices.
* Approval workflows.
* LDAP, Active Directory, SSO і SCIM.
* Vault і managed credential rotation.
* Incident Manager integration.
* Proprietary company integrations.

## Security

Повідомляй про security-sensitive issues приватно. Не публікуй credentials, target database details, access tokens, internal address ranges або exploit proof-of-concepts у public issues.

Дивись `SECURITY.md` для security policy.

## License

Дивись `LICENSE-DECISION.md`.
