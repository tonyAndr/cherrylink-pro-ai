# Release checklist (plugin)

Pre-flight for a version tag. Run from `plugin/`.

## 1. Quality gates

```bash
./dev/qa.sh all            # PHPCS + PHPStan level 6 + PHPUnit on PHP 7.4
# PHP 8.2 leg (CI matrix) — unit suite only:
docker run --rm -v "$PWD":/plugin -w /plugin --user root -e HOME=/tmp \
  --entrypoint php wordpress:cli-php8.2 dev/vendor/bin/phpunit -c phpunit.xml.dist
cd gutenberg-src && npm test -- --watchAll=false
```

## 2. WordPress smokes (need Docker, not in GitHub CI)

The unit suite mocks WordPress, so it cannot catch strict_types/option-type
faults or AJAX-shape regressions. Run both:

```bash
./dev/wp-smoke/run.sh      # editor AJAX path with real options
./dev/outbox-smoke/run.sh  # outbox schema + delivery against real MySQL
```

`./dev/async-e2e/run.sh` additionally drives real WordPress -> API acceptance
(needs ~2 GB and the pinned images); it is optional for a sync-only release.

## 3. Manual check in wp-dev

```bash
cd api && docker compose -p cherry-vector-api -f docker-compose.yml up -d
cd ../plugin/wp-dev && docker compose up -d     # http://localhost:8088, admin/admin
```

- Settings → readiness card renders, "Test connection" works, licence card shows.
- Scan tab: start a scan, watch progress, close the tab and reopen (resumes).
- Editor: sidebars list related articles; no console errors.
- Switch the site language and confirm the plugin cards re-render.
- Plugin row → "Скачать файл настроек" downloads a file (no public file in the
  plugin directory).

## 4. Packaging

```bash
git archive --format=zip --prefix=cherrylink-pro-ai/ -o /tmp/cherrylink.zip HEAD
unzip -l /tmp/cherrylink.zip | grep -E 'dev/|tests/|specs/|gutenberg-src/'   # must be empty
```

## 5. Metadata

- `Version:` in `cherrylink_main.php` == `Stable tag:` in `readme.txt` == tag.
- `readme.txt` changelog describes this release; no removed features advertised.
- `languages/*.pot` regenerated (`msgcat` of the per-source extractions) and
  `.po`/`.mo`/JS JSON compile (`msgfmt --check`).

## 6. Tag

Only with explicit owner approval:

```bash
git tag v1.2.0 && git push origin v1.2.0
```

`.github/workflows/release.yml` builds the sanitized zip and publishes the
GitHub release the updater consumes.
