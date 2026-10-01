#!/usr/bin/env python3
"""Build the file index used by the custom administration panel."""

from __future__ import annotations

import html
import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / "site"
OUTPUT = SITE / "admin" / "data" / "catalog.json"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore")


def plain(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", value))).strip()


def first(pattern: str, source: str, default: str = "") -> str:
    match = re.search(pattern, source, re.I | re.S)
    return plain(match.group(1)) if match else default


def attr(pattern: str, source: str, default: str = "") -> str:
    match = re.search(pattern, source, re.I | re.S)
    return html.unescape(match.group(1)).strip() if match else default


def image_src_by_class(source: str, class_name: str) -> str:
    match = re.search(r'<img\b[^>]*class="[^"]*' + re.escape(class_name) + r'[^"]*"[^>]*>', source, re.I | re.S)
    return attr(r'\bsrc="([^"]+)"', match.group(0)) if match else ""


def main() -> None:
    category_paths = sorted((SITE / "catalog").glob("**/index.html"))
    category_paths = [path for path in category_paths if path.parent != SITE / "catalog"]
    categories: dict[str, dict[str, str]] = {}
    memberships: dict[str, str] = {}

    catalog_index = read(SITE / "catalog" / "index.html")
    for path in category_paths:
        relative = path.parent.relative_to(SITE).as_posix()
        route = f"/{relative}/"
        source = read(path)
        name = first(r"<h1[^>]*>(.*?)</h1>", source) or first(r"<title[^>]*>(.*?)</title>", source)
        image = ""
        for link in re.finditer(r'href="' + re.escape(route) + r'"', catalog_index, re.I):
            window = catalog_index[max(0, link.start() - 300) : link.start() + 1800]
            image = image_src_by_class(window, "catalog-section-list__img-img")
            if image:
                break
        slug = relative.removeprefix("catalog/")
        categories[slug] = {
            "slug": slug,
            "name": name,
            "path": f"{relative}/index.html",
            "route": route,
            "image": image,
        }
        for product_slug in re.findall(r'href="/catalog/([^"?#]+)\.prod(?:[?#][^"]*)?"', source, re.I):
            memberships.setdefault(product_slug, slug)

    products: dict[str, dict[str, str]] = {}
    for path in sorted((SITE / "catalog").glob("*.prod")):
        source = read(path)
        slug = path.stem
        status = first(r'class="[^"]*catalog-detail__status-[^"]*"[^>]*>(.*?)</div>', source, "В наличии")
        products[slug] = {
            "slug": slug,
            "name": first(r"<h1[^>]*>(.*?)</h1>", source, slug),
            "path": f"catalog/{path.name}",
            "route": f"/catalog/{path.name}",
            "category": memberships.get(slug, ""),
            "price": first(r'class="pricespace"[^>]*>(.*?)</span>', source, "0"),
            "status": status,
            "image": image_src_by_class(source, "catalog-detail__img-img"),
        }

    for product in products.values():
        category = categories.get(product["category"])
        if category is not None and not category["image"] and product["image"]:
            category["image"] = product["image"]

    pages: dict[str, dict[str, str]] = {}
    ignored_roots = {"admin", "bitrix", "local", "upload", "images", "lib", "_external", "_mirror"}
    for path in sorted(SITE.glob("**/index.html")):
        relative = path.relative_to(SITE)
        if relative.parts[0] in ignored_roots or relative.parts[0] == "catalog":
            continue
        source = read(path)
        route = "/" if relative.as_posix() == "index.html" else "/" + relative.parent.as_posix() + "/"
        pages[relative.as_posix()] = {
            "path": relative.as_posix(),
            "route": route,
            "name": first(r"<h1[^>]*>(.*?)</h1>", source) or first(r"<title[^>]*>(.*?)</title>", source, route),
        }

    payload = {"version": 1, "products": products, "categories": categories, "pages": pages}
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"products": len(products), "categories": len(categories), "pages": len(pages)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
