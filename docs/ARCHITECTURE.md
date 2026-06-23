# Architecture

```text
DB client
   |
   | TCP, dynamic port 16000-17999
   v
SAG DB Gateway (Go)
   |
   | database protocol
   v
Target PostgreSQL / MySQL / experimental MSSQL
if [ ! -f "$PUBLIC_ROOT/db-gateway/main.go" ]; then
  rsync -a \
    --exclude='sag-db-gateway' \
    --exclude='sag-db-gateway.*' \
    --exclude='*.bak.*' \
    --exclude='*.log' \
    "$FULL_ROOT/db-gateway/" \
    "$PUBLIC_ROOT/db-gateway/"
fi

cat >> "$PUBLIC_ROOT/.gitignore" <<'EOF'

# Public CE Laravel control plane
/control-plane/.env
/control-plane/.env.*
!/control-plane/.env.example
/control-plane/vendor/
/control-plane/node_modules/
/control-plane/bootstrap/cache/*
!/control-plane/bootstrap/cache/.gitignore
/control-plane/storage/logs/*
/control-plane/storage/framework/*
!/control-plane/storage/framework/.gitignore
/control-plane/public/hot
/control-plane/public/build
/control-plane/database/*.sqlite
