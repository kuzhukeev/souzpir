#!/usr/bin/env bash
set -euo pipefail

docker run --detach --name souzpir-ci-postgres --network host \
  -e POSTGRES_DB=souzpir_test_ci -e POSTGRES_USER=postgres \
  -e POSTGRES_PASSWORD=ci-only-password postgres:16 \
  -c listen_addresses=127.0.0.1
for attempt in {1..30}; do
  if docker exec souzpir-ci-postgres pg_isready -h 127.0.0.1 -U postgres -d souzpir_test_ci; then break; fi
  sleep 1
done
docker exec souzpir-ci-postgres pg_isready -h 127.0.0.1 -U postgres -d souzpir_test_ci
actual=$(PGPASSWORD=ci-only-password psql -X -A -t -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "SELECT host(inet_server_addr()), inet_server_port();")
test "$actual" = '127.0.0.1|5432'
mkdir -p .local/tmp
cp .env.example .env.testing
php -r 'file_put_contents(".env.testing", "\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n", FILE_APPEND);'
php -r 'file_put_contents(".env.testing", "INVITATION_ISSUANCE_HMAC_KEY=base64:".base64_encode(random_bytes(32))."\n", FILE_APPEND);'

export PGPASSWORD=ci-only-password
psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "CREATE ROLE souzpir_ci LOGIN PASSWORD 'ci-only-password' NOSUPERUSER CREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;"
psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "ALTER DATABASE souzpir_test_ci OWNER TO souzpir_ci;"
psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "REVOKE CONNECT ON DATABASE souzpir_test_ci FROM PUBLIC;"
for entry in 'sp_invitation_ci_app souzpir_test_sp_invitation_ci' 'sp_e1_05_app souzpir_test_sp_e1_05' 'sp_e1_08_app souzpir_test_sp_e1_08' 'sp_e101g_app souzpir_test_sp_e1_01g' 'sp_mfa_ci_app souzpir_test_mfa_ci' 'sp_e8_02_app souzpir_test_sp_e8_02' 'sp_e8_02_local_app souzpir_test_sp_e8_02_local' 'sp_e8_03_app souzpir_test_sp_e8_03'; do
  read -r role database <<< "$entry"
  psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "CREATE ROLE $role LOGIN PASSWORD 'ci-only-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;"
  psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "CREATE DATABASE $database OWNER $role;"
  psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres -d postgres -c "REVOKE CONNECT ON DATABASE $database FROM PUBLIC;"
done
actual=$(psql -X -A -t -v ON_ERROR_STOP=1 -h 127.0.0.1 -U souzpir_ci -d souzpir_test_ci -c "SELECT current_database(), current_user, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user;")
test "$actual" = 'souzpir_test_ci|souzpir_ci|f|t|f'
