#!/usr/bin/env bash
# Synthetic WordPress/MySQL acceptance checks; never connects to production.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
PT_INSTANCE="${PANDATASK_RECURRENCE_TEST_INSTANCE:-$(date +%s)-$$}"
PT_NETWORK="pandatask-recurrence-test-net-${PT_INSTANCE}"
PT_VOLUME="pandatask-recurrence-test-wp-${PT_INSTANCE}"
PT_DB="pandatask-recurrence-test-db-${PT_INSTANCE}"
PT_WP="pandatask-recurrence-test-wp-${PT_INSTANCE}"
PT_CLI="pandatask-recurrence-test-cli-${PT_INSTANCE}"
# Pinned WordPress/PHP 8.2, WP-CLI/PHP 8.2 and MySQL 8.0 images.
PT_WP_IMAGE="wordpress@sha256:f59df18245de087cd90a27a0ec559bd7439bc9d0bd4f1378e8ffa3b236616ce2"
PT_CLI_IMAGE="wordpress@sha256:837d55d02196b5f4c92d236317c6d089ab1471348b31d1708888d444a0390979"
PT_DB_IMAGE="mysql@sha256:4af1f8815716546f5b12410f7621f37f93db8dd11a184706ef59111930b8c2ff"

docker info >/dev/null
for image in "${PT_WP_IMAGE}" "${PT_CLI_IMAGE}" "${PT_DB_IMAGE}"; do
    if ! docker image inspect "${image}" >/dev/null 2>&1; then
        echo "Required image is missing. Run: docker pull ${image}" >&2
        exit 2
    fi
done
for resource in "${PT_NETWORK}" "${PT_VOLUME}" "${PT_DB}" "${PT_WP}" "${PT_CLI}"; do
    if docker network inspect "${resource}" >/dev/null 2>&1 || \
       docker volume inspect "${resource}" >/dev/null 2>&1 || \
       docker container inspect "${resource}" >/dev/null 2>&1; then
        echo "Refusing to reuse an existing resource: ${resource}" >&2
        exit 2
    fi
done

PT_TEMP="$(mktemp -d)"
cleanup() {
    result=$?
    trap - EXIT
    docker rm -fv "${PT_CLI}" "${PT_WP}" "${PT_DB}" >/dev/null 2>&1 || true
    docker volume rm "${PT_VOLUME}" >/dev/null 2>&1 || true
    docker network rm "${PT_NETWORK}" >/dev/null 2>&1 || true
    rm -rf -- "${PT_TEMP}"
    exit "${result}"
}
trap cleanup EXIT
PT_DB_PASSWORD="$(openssl rand -hex 24)"
PT_ADMIN_PASSWORD="$(openssl rand -hex 24)"
docker network create "${PT_NETWORK}" >/dev/null
docker volume create "${PT_VOLUME}" >/dev/null
docker run --detach --name "${PT_DB}" --network "${PT_NETWORK}" \
    --env "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)" \
    --env MYSQL_DATABASE=pandatask_test --env MYSQL_USER=pandatask_test \
    --env "MYSQL_PASSWORD=${PT_DB_PASSWORD}" "${PT_DB_IMAGE}" >/dev/null
PT_WP_ENV=(--env "WORDPRESS_DB_HOST=${PT_DB}:3306" --env WORDPRESS_DB_NAME=pandatask_test
    --env WORDPRESS_DB_USER=pandatask_test --env "WORDPRESS_DB_PASSWORD=${PT_DB_PASSWORD}")
docker run --detach --name "${PT_WP}" --network "${PT_NETWORK}" \
    "${PT_WP_ENV[@]}" --volume "${PT_VOLUME}:/var/www/html" "${PT_WP_IMAGE}" >/dev/null

echo 'Waiting for the synthetic database and WordPress files...'
for attempt in $(seq 1 90); do
    if docker exec --env "MYSQL_PWD=${PT_DB_PASSWORD}" "${PT_DB}" \
        mysql --protocol=TCP --ssl-mode=REQUIRED --host=127.0.0.1 \
        --user=pandatask_test pandatask_test -e 'SELECT 1' >/dev/null 2>&1 && \
       docker exec "${PT_WP}" test -f /var/www/html/wp-config.php; then
        break
    fi
    if [[ "${attempt}" == 90 ]]; then
        echo 'Synthetic database did not become ready.' >&2
        exit 1
    fi
    sleep 1
done

# Copy through Docker rather than bind-mounting a host path: this also works
# when the Docker daemon runs outside the workspace's filesystem namespace.
docker run --detach --name "${PT_CLI}" --network "${PT_NETWORK}" --user 33:33 \
    "${PT_WP_ENV[@]}" --env PANDATASK_SYNTHETIC_RECURRENCE_TEST=1 \
    --volume "${PT_VOLUME}:/var/www/html" --entrypoint sh \
    "${PT_CLI_IMAGE}" -c 'sleep 3600' >/dev/null
tar -czf "${PT_TEMP}/plugin.tgz" -C "${REPO_ROOT}" \
    assets build includes src stubs templates composer.json composer.lock pandatask.php README.txt
docker cp "${PT_TEMP}/plugin.tgz" "${PT_CLI}:/tmp/plugin.tgz"
docker exec --user 0 "${PT_CLI}" mkdir -p /var/www/html/wp-content/plugins/pandatask /workspace/tests
docker exec --user 0 "${PT_CLI}" tar -xzf /tmp/plugin.tgz -C /var/www/html/wp-content/plugins/pandatask
docker cp "${REPO_ROOT}/tests/task-recurrence-integration.php" "${PT_CLI}:/workspace/tests/task-recurrence-integration.php"
docker exec --user 0 "${PT_CLI}" chown -R 33:33 /var/www/html/wp-content /workspace
run_wp() { docker exec "${PT_CLI}" wp --path=/var/www/html "$@"; }
run_wp core install --url=http://pandatask-fixture.invalid --title='Synthetic recurrence fixture' \
    --admin_user=pandatask_test_admin --admin_password="${PT_ADMIN_PASSWORD}" \
    --admin_email=pandatask_test_admin@example.invalid --skip-email >/dev/null
run_wp user create pandatask_test_user pandatask_test_user@example.invalid \
    --role=subscriber --user_pass="$(openssl rand -hex 24)" >/dev/null
run_wp plugin activate pandatask >/dev/null
PT_TEST=/workspace/tests/task-recurrence-integration.php
run_wp eval-file "${PT_TEST}"
run_wp eval-file "${PT_TEST}" race-create
run_wp eval-file "${PT_TEST}" race-worker >"${PT_TEMP}/worker-one.log" 2>&1 &
PT_WORKER_ONE=$!
run_wp eval-file "${PT_TEST}" race-worker >"${PT_TEMP}/worker-two.log" 2>&1 &
PT_WORKER_TWO=$!
PT_RACE_FAILED=0
wait "${PT_WORKER_ONE}" || PT_RACE_FAILED=1
wait "${PT_WORKER_TWO}" || PT_RACE_FAILED=1
cat "${PT_TEMP}/worker-one.log" "${PT_TEMP}/worker-two.log"
[[ "${PT_RACE_FAILED}" == 0 ]]
run_wp eval-file "${PT_TEST}" race-verify
run_wp eval-file "${PT_TEST}" upgrade-prepare
run_wp eval-file "${PT_TEST}" upgrade-verify
echo 'RECURRENCE DATABASE ACCEPTANCE PASS (disposable resources will be removed)'
