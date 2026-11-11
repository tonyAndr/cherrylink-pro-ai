#!/usr/bin/env python3
"""Create a deterministic internal-linking benchmark fixture in wp-dev.

The fixture complements seed_articles.py with known-good and deliberately broken
link patterns, so CherryLink and competitor plugins can be compared on the same
WordPress content.

Usage:
    python3 wp-dev/seed_benchmark.py
    WP_CONTAINER=wp-dev-cron-1 python3 wp-dev/seed_benchmark.py

Every benchmark post/page carries the `_cl_benchmark` meta key. The script is
idempotent: existing benchmark slugs are updated in place.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys

CONTAINER = os.environ.get("WP_CONTAINER", "wp-dev-cron-1")
WP_BASE = ["wp", "--allow-root", "--path=/var/www/html"]
ORIGIN = "http://localhost:8088"
BENCH_CATEGORY = "benchmark"


def run_wp(args: list[str]) -> tuple[int, str, str]:
    result = subprocess.run(
        ["docker", "exec", CONTAINER, *WP_BASE, *args],
        capture_output=True,
        text=True,
    )
    return result.returncode, result.stdout.strip(), result.stderr.strip()


def must(args: list[str]) -> str:
    code, out, err = run_wp(args)
    if code != 0:
        raise RuntimeError(f"WP-CLI failed: {' '.join(args)}\n{err}")
    return out


def find_id(slug: str, post_type: str = "post") -> int | None:
    code, out, _ = run_wp([
        "post", "list", f"--name={slug}", f"--post_type={post_type}",
        "--post_status=any", "--format=ids",
    ])
    if code != 0 or not out.strip():
        return None
    try:
        return int(out.split()[0])
    except ValueError:
        return None


def ensure_category() -> None:
    code, out, _ = run_wp(["term", "list", "category", f"--slug={BENCH_CATEGORY}", "--format=ids"])
    if code == 0 and out.strip():
        return
    run_wp(["term", "create", "category", "Benchmark", f"--slug={BENCH_CATEGORY}", "--porcelain"])


def save(
    slug: str,
    title: str,
    content: str,
    *,
    post_type: str = "post",
    status: str = "publish",
    date: str | None = None,
    category: bool = True,
) -> int:
    """Create or update one benchmark item and return its ID."""
    args = [
        "post", "create" if find_id(slug, post_type) is None else "update",
    ]
    existing = find_id(slug, post_type)
    if existing is not None:
        args.append(str(existing))
    args += [
        f"--post_title={title}",
        f"--post_name={slug}",
        f"--post_status={status}",
        f"--post_type={post_type}",
        f"--post_content={content}",
    ]
    if date:
        args.append(f"--post_date={date}")
    out = must(args)
    post_id = existing or int(out.split()[-1].rstrip("."))
    run_wp(["post", "meta", "update", str(post_id), "_cl_benchmark", "1"])
    if category and post_type == "post":
        run_wp(["post", "term", "set", str(post_id), "category", BENCH_CATEGORY])
    return post_id


def link(slug: str, anchor: str, *, raw: str | None = None) -> str:
    href = raw if raw is not None else f"{ORIGIN}/{slug}/"
    return f'<a href="{href}">{anchor}</a>'


def p(text: str) -> str:
    return f"<p>{text}</p>"


def main() -> int:
    ensure_category()

    target = find_id("vnutrennyaya-perelinkkovka") or find_id("seo-chto-eto")
    if target is None:
        print("Run seed_articles.py first: target articles are missing.", file=sys.stderr)
        return 1
    target_slug = "vnutrennyaya-perelinkkovka" if find_id("vnutrennyaya-perelinkkovka") == target else "seo-chto-eto"

    links: dict[str, int] = {}

    # Target article: a natural, well-linked hub with varied anchors.
    links["target"] = save(
        "benchmark-target-guide",
        "Гид по внутренней перелинковке для больших сайтов",
        p("Это опорная статья про внутреннюю перелинковку: как связывать старый и новый контент, "
          "как выбирать анкоры и как не переспамить ссылками.")
        + p(f"Полезные материалы: {link(target_slug, 'внутренняя перелинковка')}, "
            f"{link('seo-chto-eto', 'основы SEO')} и "
            f"{link('kontent-marketing', 'контент-маркетинг')}."),
        date="2026-01-15 10:00:00",
    )

    # 1. Orphan: nothing links to it.
    links["orphan"] = save(
        "benchmark-orphan",
        "Одинокая статья: контент-маркетинг для экспертов",
        p("Эта статья про контент-маркетинг и экспертный контент намеренно не получает входящих "
          "ссылок. Она нужна для проверки отчёта об orphan-страницах.")
        + p("Материал полезен читателю, но сайт не связывает его с остальным архивом."),
        date="2026-02-01 11:00:00",
    )

    # 2. Broken links + a link to a draft.
    draft_id = save(
        "benchmark-draft-target",
        "Черновик, на который ссылаются",
        p("Этот материал остаётся черновиком и не должен быть доступен читателю."),
        status="draft",
        date="2026-02-02 12:00:00",
    )
    links["broken"] = save(
        "benchmark-broken-links",
        "Статья с битыми внутренними ссылками",
        p("Здесь намеренно собраны проблемы, которые должен находить аудит ссылок.")
        + p("Старая страница: " + link("", "нет такой страницы", raw=f"{ORIGIN}/net-takoy-stranicy/") + ".")
        + p("Ещё один несуществующий адрес: " + link("", "старый адрес", raw=f"{ORIGIN}/staryj-adres/") + ".")
        + p("Ссылка на черновик: " + link("", "черновик", raw=f"{ORIGIN}/?p={draft_id}") + ".")
        + p("Рабочая ссылка: " + link("benchmark-target-guide", "гид по перелинковке") + "."),
        date="2026-02-03 13:00:00",
    )

    # 3. Recursive pair + self link.
    a_id = save(
        "benchmark-recursive-a",
        "Петля ссылок: часть A",
        p("Первая часть намеренной петли. Пока здесь только ссылка на себя.")
        + p("Ссылка на саму себя: " + link("benchmark-recursive-a", "эта же статья") + "."),
        date="2026-02-04 14:00:00",
    )
    b_id = save(
        "benchmark-recursive-b",
        "Петля ссылок: часть B",
        p("Вторая часть петли ссылается обратно на первую часть.")
        + p("Вернуться к части A: " + link("benchmark-recursive-a", "часть A") + "."),
        date="2026-02-05 15:00:00",
    )
    save(
        "benchmark-recursive-a",
        "Петля ссылок: часть A",
        p("Первая часть намеренной петли. Она ссылается на себя и на вторую часть.")
        + p("Ссылка на саму себя: " + link("benchmark-recursive-a", "эта же статья") + ".")
        + p("Ссылка на часть B: " + link("benchmark-recursive-b", "часть B") + "."),
        date="2026-02-04 14:00:00",
    )
    links["recursive_a"], links["recursive_b"] = a_id, b_id

    # 4. Too many outgoing links (link stuffing).
    stuff_slugs = [
        "seo-chto-eto", "semanticheskoe-yadro", "kontent-marketing",
        "optimizaciya-skorosti-wordpress", "ssylochnaya-massa",
        "tekhnicheskiy-seo-audit", "kak-pisat-stati-dlya-bloga",
        "plaginy-wordpress-obyazatelno", "bezopasnost-wordpress",
        "gutenberg-ili-klassicheskiy-redaktor", "woocommerce-nastroyka",
        "lokalnoe-seo", "lsi-kopiraytling", "zagolovki-h1-h6",
        "virusnyy-kontent",
    ]
    stuff_links = " ".join(
        link(slug, f"материал {i + 1}") for i, slug in enumerate(stuff_slugs)
        if find_id(slug) is not None
    )
    links["stuffed"] = save(
        "benchmark-link-stuffing",
        "Статья с избытком исходящих ссылок",
        p("Эта страница намеренно перегружена внутренними ссылками. Хороший аудит должен "
          "показать превышение исходящих ссылок и возможный спам.")
        + p(stuff_links),
        date="2026-02-06 16:00:00",
    )

    # 5. No outgoing links.
    links["no_outgoing"] = save(
        "benchmark-no-outgoing",
        "Статья без исходящих ссылок",
        p("В этом материале нет ни одной ссылки. Он полезен для проверки сценария "
          "«ноль исходящих» и для сравнения рекомендаций в тупиковой статье.")
        + p("Тема — контент-план и редакционный процесс."),
        date="2026-02-07 17:00:00",
    )

    # 6. Repeated identical anchors to the same target.
    for i in range(1, 5):
        links[f"anchor_{i}"] = save(
            f"benchmark-anchor-{i}",
            f"Одинаковый анкор: статья {i}",
            p("Небольшая статья для проверки разнообразия анкорного текста.")
            + p("Подробнее про " + link("benchmark-target-guide", "внутренняя перелинковка") + "."),
            date=f"2026-02-{7 + i:02d} 10:00:00",
        )

    # 7a. Same target linked twice from one source (has_repeats).
    links["repeated"] = save(
        "benchmark-repeated-links",
        "Повторные ссылки на одну и ту же статью",
        p("В этой статье одна и та же цель указана дважды разными анкорами.")
        + p("Первый раз: " + link("benchmark-target-guide", "гид") + ".")
        + p("Второй раз: " + link("benchmark-target-guide", "подробный гид") + "."),
        date="2026-02-10 10:00:00",
    )

    # 7. Semantically close pair with no links between them.
    links["similar_a"] = save(
        "benchmark-similar-a",
        "Как связать старые статьи с новыми материалами",
        p("Внутренняя перелинковка помогает связать старые статьи с новыми публикациями. "
          "Начните с тематических кластеров: выберите опорную статью и добавьте ссылки "
          "из смежных материалов. Проверяйте, чтобы анкоры были естественными.")
        + p("Отдельно проверьте статьи без входящих ссылок и добавьте контекстные переходы."),
        date="2026-02-12 10:00:00",
    )
    links["similar_b"] = save(
        "benchmark-similar-b",
        "Связывание старых и новых публикаций в блоге",
        p("Чтобы связать старые публикации с новыми, соберите тематические кластеры. "
          "Опорная статья должна получать ссылки из смежных материалов, а новые статьи — "
          "ссылки на проверенные старые руководства. Следите за естественностью анкоров.")
        + p("Проверяйте orphan-страницы и добавляйте контекстные переходы вручную."),
        date="2026-02-13 11:00:00",
    )

    # 8. Near-duplicate pair.
    duplicate_text = (
        "Дублированный контент — это близкие по смыслу страницы, которые конкурируют "
        "за одни и те же запросы. Найдите дубли, выберите основную версию и объедините "
        "уникальные фрагменты. Настройте канонические URL и редиректы."
    )
    links["duplicate_a"] = save(
        "benchmark-duplicate-a",
        "Дублированный контент: основная версия",
        p(duplicate_text),
        date="2026-02-14 12:00:00",
    )
    links["duplicate_b"] = save(
        "benchmark-duplicate-b",
        "Дублированный контент: почти копия",
        p(duplicate_text + " Это почти точная копия соседней статьи."),
        date="2026-02-15 13:00:00",
    )

    # 9. Long article for performance and suggestion quality.
    long_body = "".join(
        p(f"Раздел {i}. Внутренняя перелинковка большого архива требует плана: "
          f"опорные статьи, тематические кластеры, анкоры и регулярный аудит. "
          f"Не добавляйте ссылки только ради количества.")
        for i in range(1, 13)
    )
    links["longread"] = save(
        "benchmark-longread",
        "Большая статья: перелинковка архива из 1000 материалов",
        long_body
        + p("См. также: " + link("benchmark-target-guide", "гид по перелинковке") + " и "
            + link("seo-chto-eto", "основы SEO") + "."),
        date="2026-02-16 14:00:00",
    )

    # 10. English article for multilingual suggestions.
    links["english"] = save(
        "benchmark-english-internal-links",
        "Internal linking for a large WordPress archive",
        p("Internal linking helps readers discover related articles and helps search engines "
          "understand the site structure. Build topic clusters, choose descriptive anchors, "
          "and audit orphan pages regularly.")
        + p("A related guide: " + link("benchmark-target-guide", "internal linking guide") + "."),
        date="2026-02-17 15:00:00",
    )

    # 11. A WordPress page (not a post) to test post-type coverage.
    links["page"] = save(
        "benchmark-page",
        "Benchmark: страница с внутренними ссылками",
        p("Это обычная страница WordPress, а не запись. Она проверяет, индексирует ли "
          "инструмент страницы и предлагает ли их в редакторе записей.")
        + p("Ссылки: " + link("benchmark-target-guide", "гайд") + " и "
            + link("seo-chto-eto", "SEO") + "."),
        post_type="page",
        date="2026-02-18 16:00:00",
    )

    # 12. Thin content.
    links["thin"] = save(
        "benchmark-thin",
        "Короткая заметка про анкоры",
        p("Анкоры должны быть descriptive. " + link("benchmark-target-guide", "Подробнее") + "."),
        date="2026-02-19 17:00:00",
    )

    manifest = {
        "origin": ORIGIN,
        "target_slug": "benchmark-target-guide",
        "ids": links,
        "scenarios": {
            "orphan": ["benchmark-orphan"],
            "broken_links": {
                "source": "benchmark-broken-links",
                "broken": ["/net-takoy-stranicy/", "/staryj-adres/"],
                "draft_target": "benchmark-draft-target",
            },
            "recursive": ["benchmark-recursive-a", "benchmark-recursive-b"],
            "self_link": "benchmark-recursive-a",
            "repeated_anchor": ["benchmark-anchor-1", "benchmark-anchor-2", "benchmark-anchor-3", "benchmark-anchor-4"],
            "repeated_target": "benchmark-repeated-links",
            "link_stuffing": "benchmark-link-stuffing",
            "no_outgoing": "benchmark-no-outgoing",
            "semantic_pair": ["benchmark-similar-a", "benchmark-similar-b"],
            "duplicate_pair": ["benchmark-duplicate-a", "benchmark-duplicate-b"],
            "longread": "benchmark-longread",
            "multilingual": "benchmark-english-internal-links",
            "page": "benchmark-page",
            "thin_content": "benchmark-thin",
        },
    }
    print(json.dumps(manifest, ensure_ascii=False, indent=2))
    print("\nBenchmark fixture ready. Reindex the site before comparing tools.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
