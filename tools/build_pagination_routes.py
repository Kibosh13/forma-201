#!/usr/bin/env python3
"""Build the PHP allowlist used by the production pagination dispatcher."""

from __future__ import annotations

import html
import json
import re
from pathlib import Path
from urllib.parse import parse_qsl, unquote, urlencode, urlsplit


ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "metadata" / "manifest.json"
OUTPUT = ROOT / "site" / "pagination-routes.php"


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def route_key(url: str) -> str | None:
    parsed = urlsplit(html.unescape(url))
    pagination = sorted(
        (name, value)
        for name, value in parse_qsl(parsed.query, keep_blank_values=False)
        if name.startswith("PAGEN_") or name == "page"
    )
    if not pagination:
        return None
    return unquote(parsed.path) + "?" + urlencode(pagination)


def main() -> None:
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))["files"]
    captured_routes: dict[str, str] = {}

    for url, record in manifest.items():
        route = route_key(url)
        if route is None:
            continue

        source = Path(record["file"])
        try:
            relative = source.relative_to("site").as_posix()
        except ValueError as error:
            raise SystemExit(f"Pagination file is outside site/: {source}") from error

        target = ROOT / "site" / relative
        if not target.is_file():
            raise SystemExit(f"Pagination file is missing: {target}")

        captured_routes[route] = relative

    referenced_routes: set[str] = set()
    for path in (ROOT / "site").rglob("*"):
        if not path.is_file() or path.suffix.lower() not in {".html", ".prod", ".tag"}:
            continue
        source = path.read_text(encoding="utf-8", errors="ignore")
        for match in re.finditer(r'href=["\']([^"\']+)["\']', source, re.I):
            route = route_key(match.group(1))
            if route is not None:
                referenced_routes.add(route)

    missing = sorted(referenced_routes - captured_routes.keys())
    if missing:
        raise SystemExit("Missing captured pagination routes:\n" + "\n".join(missing))

    routes = {
        route: captured_routes[route]
        for route in sorted(referenced_routes)
    }

    lines = ["<?php", "declare(strict_types=1);", "", "return ["]
    lines.extend(
        f"    {php_string(route)} => {php_string(routes[route])},"
        for route in sorted(routes)
    )
    lines.extend(["];", ""])
    OUTPUT.write_text("\n".join(lines), encoding="utf-8")
    print(json.dumps({"pagination_routes": len(routes)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
