# MediaDC — Development Notes

## Quick Start

```bash
./dev-setup.sh
```

This destroys any existing dev container and creates a fresh one:
- Nextcloud 33 at http://localhost:8080 (admin/admin)
- MediaDC app mounted from local directory
- Python 3.13 + pip + ffmpeg + all Python deps installed
- File scanner run

## Development Workflow

### After changing PHP code

Restart Apache so the web server picks up changes (OPcache):

```bash
docker exec nextcloud-dev apache2ctl graceful
```

### After changing Python code

Clear bytecode cache — Python caches `.pyc` files and the mounted volume
shares them between host and container:

```bash
find . -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null
```

### After changing frontend code (src/)

```bash
npm run build          # production build
npm run watch          # or watch mode for development
```

### Checking logs

```bash
# Nextcloud PHP log
docker exec -u www-data nextcloud-dev php occ log:tail

# Python task execution log
docker exec nextcloud-dev cat $(docker exec nextcloud-dev find /var/www/html/data -path '*/mediadc/logs/output.log' 2>/dev/null)
```

### Re-enabling the app (triggers migration steps)

```bash
docker exec -u www-data nextcloud-dev php occ app:disable mediadc
docker exec -u www-data nextcloud-dev php occ app:enable mediadc
```

---

## What Works

- [x] App installs and enables on NC 33 with SQLite
- [x] Admin settings page loads
- [x] Task creation from MediaDC UI
- [x] Task creation from Files app ("Scan for duplicates" on folders)
- [x] Python task execution (image hashing, duplicate detection)
- [x] Task completion and results display
- [x] SQLite database support (PHP + Python)
- [x] No cloud_py_api dependency

## Known Issues

- **nc-py-api init noise**: The Python log shows many harmless errors during
  nc-py-api initialization (missing `oc_cloud_py_api_settings` table, SQLite
  parameter type errors, `pg_catalog` queries). These are non-fatal — nc-py-api
  tries MySQL/PostgreSQL-specific queries that fail on SQLite, but the app works.

- **Task notification fails**: After a task finishes, the `occ mediadc:collector:tasks:notify`
  command fails when called from the nohup Python process. The task completes
  successfully but the NC notification bell doesn't fire. Likely an environment
  issue with the nohup context.

---

## Remaining Work

### Medium Priority

- [ ] **Migrate docblock annotations to PHP 8 attributes**: Some methods in
      `lib/Controller/CollectorController.php` use `@NoAdminRequired` /
      `@NoCSRFRequired` docblock annotations. Migrate to `#[NoAdminRequired]`
      attributes for consistency.

- [ ] **Fix task notification from nohup**: The occ notify command fails when
      run from the background Python process. Investigate env/user context.

- [ ] **Vue `.sync` modifier**: 10+ components use Vue 2's `.sync` modifier
      which won't work after Vue 3 migration.

### Low Priority

- [ ] **Update dev tooling**: phpunit ^9.5 -> ^10.x, psalm ^5.15 -> latest
- [ ] **Update psalm baseline**: New files (PythonService, PythonUtilsService,
      nc_py_api_patch) aren't in the psalm baseline.
- [ ] **Update issue template**: `.github/ISSUE_TEMPLATE/bug_report.md` still
      references cloud_py_api.
- [ ] **npm audit**: ~46 vulnerabilities from transitive deps in NC build toolchain.

### Future

- [ ] **Vue 3 migration**: Required for NC 34+. 20 components, Vuex -> Pinia,
      vue-router 3 -> 4, `.sync` -> `v-model`.
- [ ] **AppAPI migration**: Convert Python scripts to a Docker-based ExApp
      using nc_py_api as a FastAPI service. Removes need for exec() and host
      Python.
- [ ] **Replace nc-py-api v0.0.11**: The current monkey-patching approach for
      SQLite support is fragile. Either vendor a fixed copy or rewrite the DB
      layer to use Python's sqlite3 directly without nc-py-api.

---

## Architecture Notes

### cloud_py_api removal

The original app depended on the `cloud_py_api` Nextcloud app for Python execution.
We replaced it with two local services:
- `lib/Service/PythonService.php` — executes Python via `exec()`/`nohup`
- `lib/Service/PythonUtilsService.php` — system detection, env helpers

### SQLite support

nc-py-api v0.0.11 only supports MySQL/PostgreSQL. We added SQLite support via
`python/nc_py_api_patch.py` which monkey-patches:
- `db_connectors.create_connection` — adds sqlite3 connector
- `db_api.internal_execute_fetchall/commit` — fixes parameter handling (`%s` -> `?`, empty tuple args)

The patch must run BEFORE `import nc_py_api` because nc-py-api loads storage
info at import time.

PHP passes `NC_*` environment variables (dbtype, datadirectory, dbtableprefix)
so nc-py-api doesn't need to call `occ` to discover the database config.
