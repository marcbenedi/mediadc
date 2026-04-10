# MediaDC — Development Notes

## Quick Start

```bash
./dev-setup.sh
```

This destroys any existing dev container and creates a fresh one:
- MariaDB 11 + Nextcloud 33 at http://localhost:8080 (admin/admin)
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

- [x] App installs and enables on NC 33 with MariaDB
- [x] Admin settings page loads
- [x] Task creation from MediaDC UI
- [x] Task creation from Files app ("Scan for duplicates" on folders)
- [x] Python task execution (image hashing, duplicate detection)
- [x] Task completion and results display
- [x] No cloud_py_api dependency

## Known Issues

None currently.

---

## Remaining Work

### Low Priority

- [ ] **Update dev tooling**: phpunit ^9.5 -> ^10.x, psalm ^5.15 -> latest
- [ ] **Update psalm baseline**: New files (PythonService, PythonUtilsService)
      aren't in the psalm baseline.
- [ ] **Update issue template**: `.github/ISSUE_TEMPLATE/bug_report.md` still
      references cloud_py_api.
- [ ] **npm audit**: ~46 vulnerabilities from transitive deps in NC build toolchain.

### Future

- [ ] **Vue 3 migration**: Required for NC 34+. 20 components, Vuex -> Pinia,
      vue-router 3 -> 4, `.sync` -> `v-model`.
- [ ] **AppAPI migration**: Convert Python scripts to a Docker-based ExApp
      using nc_py_api as a FastAPI service. Removes need for exec() and host
      Python.

---

## Architecture Notes

### cloud_py_api removal

The original app depended on the `cloud_py_api` Nextcloud app for Python execution.
We replaced it with two local services:
- `lib/Service/PythonService.php` — executes Python via `exec()`/`nohup env`
- `lib/Service/PythonUtilsService.php` — system detection, env helpers

PHP passes `NC_*` environment variables (dbtype, datadirectory, dbtableprefix, etc.)
so nc-py-api can find the database without calling `occ`.
