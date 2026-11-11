# Internal-linking benchmark fixture

This directory provides a reproducible WordPress dataset for comparing CherryLink
with other internal-linking tools. It is **local-only**: all content and links
point at `http://localhost:8088`, and indexing uses the local API container.

## What is in the fixture

`setup.sh` creates the initial six articles. The two seed scripts extend that:

```bash
cd plugin/wp-dev

# 87 additional Russian articles across SEO, content, WordPress, hosting,
# e-commerce, analytics, law and marketing. Idempotent by slug.
python3 seed_articles.py

# 21 benchmark posts/pages with known-good and deliberately broken link patterns.
python3 seed_benchmark.py
```

Current local state after both scripts: **115 published posts/pages**, of which
**21 carry the `_cl_benchmark` meta key**. The exact IDs are printed by
`seed_benchmark.py`; use slugs rather than IDs when scripting comparisons.

### Reset and reindex

Destructive, local-only reset:

```bash
cd plugin/wp-dev
docker compose down -v
docker compose up -d
# wait until the wpcli setup service has installed WordPress and activated the plugin
python3 seed_articles.py
python3 seed_benchmark.py
```

Reindex through the plugin's normal background scan:

```bash
docker exec wp-dev-cron-1 wp --allow-root --path=/var/www/html eval '
  $o = new \CherryLink\Support\Options();
  $l = new \CherryLink\Support\License($o);
  $s = new \CherryLink\Indexing\SyncService($o, $l);
  echo json_encode($s->start(true));
'
# wait for status=done
docker exec wp-dev-cron-1 wp --allow-root --path=/var/www/html \
  option get cherrylink_sync_state --format=json
```

The `cron` sidecar processes Action Scheduler every five seconds, so the scan
advances without opening wp-admin.

## Ground-truth scenarios

| Slug / group | What it contains | What a good tool should do |
|---|---|---|
| `benchmark-target-guide` | Well-linked hub about internal linking | Be a top suggestion for semantically related articles; receive varied anchors |
| `benchmark-orphan` | Useful article with no incoming links | Appear in the orphan report |
| `benchmark-broken-links` | Two 404 targets plus a draft URL and one valid link | Report all three bad targets, not the valid one |
| `benchmark-repeated-links` | Same target linked twice from one source | Flag the repeated target |
| `benchmark-recursive-a` / `-b` | A links to B and B links back to A | Detect the two-way cycle if the tool claims cycle detection* |
| `benchmark-anchor-1…4` | Four articles link to the same target with the identical anchor “внутренняя перелинковка” | Surface anchor overuse / suggest variation |
| `benchmark-link-stuffing` | 15 outgoing links, mostly unrelated | Warn about excessive outgoing links |
| `benchmark-no-outgoing` | Article with zero outbound links | Show zero-outgoing status and useful suggestions |
| `benchmark-similar-a` / `-b` | Near-identical topic, no links between them | Recommend each to the other; rank the pair highly |
| `benchmark-duplicate-a` / `-b` | Near-duplicate body copy | Avoid treating duplicates as two independent strong targets |
| `benchmark-longread` | Long article with two natural links | Still return focused suggestions, not noise |
| `benchmark-english-internal-links` | English article | Test multilingual/semantic matching against the Russian archive |
| `benchmark-page` | A WordPress **page**, not a post | Test whether pages are indexed and offered in the editor |
| `benchmark-thin` | Very short note | Test thin-content handling and false-positive rate |

\* CherryLink's current `/v1/links/problems` report defines recursion as a
**self-link** (`source_url == target_url`). The A↔B cycle is deliberate: use it
to see whether a competitor detects two-way cycles that CherryLink currently does
not report.

The base 87 seed articles intentionally have few internal links. That means the
orphan report will contain many ordinary seed articles in addition to
`benchmark-orphan`; score against the known benchmark slugs, or add links between
seed articles if you want a lower-noise archive.

## Competitor feature matrix

The competitor entries below are **advertised/observed on their public sites**,
not independently verified. Re-check each claim in a trial before using it in
sales material. Pricing was quoted for reference only.

| Capability | CherryLink (current) | Link Whisper (advertised) | LinkBoss (advertised) | Linksy AI (advertised) | Internal Link Juicer |
|---|---|---|---|---|---|
| Semantic/AI relevance | Yes — meaning-based index | Keyword/context suggestions | Yes — closest semantic AI | Yes — AI suggestions | No — rule-based keyword auto-linking |
| Inbound/outbound suggestions | Yes | Yes | Yes | Yes | Mostly automatic rule output |
| Anchor generation | Yes — from current text | Suggestions + manual | Yes | Yes | Rule/template based |
| Orphan-page report | Yes — link-health report | Yes | Yes + orphan fixes | Link audits | Limited / rules |
| Broken internal links | Yes | Part of audit | Part of audit | Link audits | Not the core focus |
| Repeated target detection | Yes | Verify | Verify | Verify | Verify |
| Repeated anchor across pages | Partial — no dedicated report | Verify | Verify | Verify | Verify |
| Self-link detection | Yes | Verify | Verify | Verify | Verify |
| Two-way cycle detection | **No** in current problems report | Verify | Verify | Verify | Verify |
| Link-graph visualization | **No** (stats/CSV, not a graph) | Verify | Yes | Verify | Verify |
| Batch insertion | **No** — one link at a time | Automated linking | Yes | Bulk workflows | Automatic |
| Rollback / undo batch | **No** | Verify | Yes (advertised) | Verify | Verify |
| Silo creation | **No** | Verify | Yes | Verify | Verify |
| Classic editor | Yes | Yes | Yes | Yes | Yes |
| Gutenberg | Yes | Yes | Yes | Yes | Yes |
| Page-builder support | **No** | Verify | Verify | Verify | Verify |
| Multilingual content | Yes — multilingual embedding model, RU/EN UI | Verify | Verify | Verify | Verify |
| Background scan / progress | Yes | Yes | Yes | Verify | Yes |
| CSV/export | Yes | Verify | Verify | Verify | Verify |
| Free tier | Not part of this fixture | Advertised free plugin | Free audits + 50 credits | No permanent free tier advertised | Free-tier research |

## Competitor onboarding gotchas

- **Link Whisper 0.9.8:** before its setup wizard or initial scan, Link Whisper
  registers only the wizard page, but its Plugins-row “Settings” link still points
  at `admin.php?page=link_whisper_settings`. WordPress therefore shows the generic
  “Sorry, you are not allowed to access this page” message even for an
  administrator. Complete **Link Whisper → One Click Setup**, or for a local
  benchmark shortcut:
  ```bash
  docker exec wp-dev-cron-1 wp --allow-root --path=/var/www/html \
    option update wpil_has_run_installation_wizard 1
  ```
  Refresh and the settings page loads. This is competitor onboarding friction,
  not a WordPress role/permission defect; record it in the UX comparison.
- **LinkBoss / Linksy AI:** expect account, license or credit/onboarding gates.
  Record how many steps are required before the first suggestion is visible.
- **Internal Link Juicer:** rule-based setup; use it as the pricing/free-tier and
  automatic-link baseline, not as a semantic-quality comparison.

## Test protocol

Run each tool on a copy of the same WordPress database. Do not activate two
internal-linking plugins at once; they can inject links into the same posts and
invalidate the comparison.

1. **Snapshot/reset** the fixture before each tool.
2. Install and activate one tool; run its index/audit; record indexing time and
   errors.
3. Open `benchmark-similar-a` in the classic editor and Gutenberg. Record the top
   5 suggestions and whether `benchmark-similar-b` and `benchmark-target-guide`
   appear.
4. Open `benchmark-target-guide`; record anchor suggestions and whether they are
   natural phrases from the source text.
5. Run the orphan report. Check `benchmark-orphan`; count false positives among
   the known benchmark items.
6. Run the broken-link report. Expected: `/net-takoy-stranicy/`, `/staryj-adres/`
   and the draft URL from `benchmark-broken-links`; the valid target must not be
   reported.
7. Check `benchmark-repeated-links`, `benchmark-anchor-1…4`, recursive A/B, and
   `benchmark-link-stuffing`. Record what is detected and what is missed.
8. If the tool advertises batch insertion or rollback, insert links into 5–10
   posts, measure time/clicks, then undo and verify the posts are restored.
9. If it advertises a graph or silo view, capture it and check whether it matches
   the known A↔B and orphan cases.
10. Open `benchmark-english-internal-links` and `benchmark-page`; record whether
    they are indexed and suggested.
11. Record editor friction: clicks, page reloads, time to first useful suggestion,
    and whether a keyboard-only workflow is possible.
12. Scale test: duplicate the seed set to ~500 and ~1,000 posts, reindex, and
    record scan time and suggestion latency.

### Suggested scoring

Score each flow 1–5; keep quality and workflow separate.

| Dimension | What to record |
|---|---|
| Relevance precision@5 | Share of top-5 suggestions that a human marks relevant |
| Anchor quality | Naturalness, diversity, use of existing text |
| Issue recall | Known broken/orphan/repeated/cycle cases detected |
| False positives | Irrelevant suggestions or healthy links flagged |
| Workflow speed | Seconds and clicks to insert one link / ten links |
| Batch capability | Count, time, rollback success |
| Insight | Graph, silos, audit clarity, export usefulness |
| Reliability | Index failures, stale state, duplicate links |
| Compatibility | Classic, Gutenberg, pages, multilingual |

## What to compare for sales positioning

- **CherryLink strength:** semantic relevance and editor-side anchor suggestions,
  with deliberate human approval; no page-builder promises.
- **Likely competitor strengths to verify:** Link Whisper's mature workflow and
  free entry point; LinkBoss's graph, silo and batch/rollback; Linksy AI's bulk
  workflow; Internal Link Juicer's rule-based automation and pricing/free-tier
  position.
- **Do not claim parity** on graph visualization, silo creation, batch insertion
  or rollback until those are implemented and tested.
