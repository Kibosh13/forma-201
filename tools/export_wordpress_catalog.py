#!/usr/bin/env python3
"""Convert the archived catalog into a WordPress/WooCommerce import bundle."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

try:
    from bs4 import BeautifulSoup
except ImportError as exc:  # pragma: no cover - deployment helper
    raise SystemExit("Install beautifulsoup4 before running this exporter") from exc


ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / "site"
SOURCE_ORIGIN = "https://kibosh13.github.io/forma-201"


def clean_text(value: str) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def local_url(value: str | None) -> str:
    if not value:
        return ""
    value = value.strip()
    if value.startswith("//"):
        value = "https:" + value
    if value.startswith("http://") or value.startswith("https://"):
        parsed = urlparse(value)
        if parsed.netloc not in {"dial-td.ru", "www.dial-td.ru"}:
            return value
        value = parsed.path
    if not value.startswith("/"):
        value = "/" + value
    return SOURCE_ORIGIN + value


def meta_content(soup: BeautifulSoup, name: str) -> str:
    node = soup.find("meta", attrs={"name": name})
    return clean_text(node.get("content", "")) if node else ""


def breadcrumb_chain(soup: BeautifulSoup) -> list[dict[str, str]]:
    chain: list[dict[str, str]] = []
    for anchor in soup.select('.breadcrumb [itemprop="item"]'):
        href = anchor.get("href", "")
        match = re.match(r"^/catalog/([^/]+)/?$", href)
        if not match:
            continue
        chain.append({"name": clean_text(anchor.get_text(" ", strip=True)), "slug": match.group(1)})
    return chain


def product_record(path: Path) -> dict:
    soup = BeautifulSoup(path.read_text(encoding="utf-8", errors="ignore"), "html.parser")
    h1 = soup.find("h1")
    name = clean_text(h1.get_text(" ", strip=True) if h1 else path.stem)
    price_node = soup.select_one(".catalog-detail__price .pricespace")
    price_match = re.search(r"\d+(?:[.,]\d+)?", clean_text(price_node.get_text(" ", strip=True)) if price_node else "")
    price = price_match.group(0).replace(",", ".") if price_match else "0"
    unit_node = soup.select_one(".catalog-detail__price-rub")
    status_node = soup.select_one('[class*="catalog-detail__status-"]')
    preview_node = soup.select_one(".catalog-detail__preview")
    description_node = soup.select_one(".catalog-detail__text")
    image_node = soup.select_one("img.catalog-detail__img-img")
    gallery: list[str] = []
    for image in soup.select(".mygallery-catalog img"):
        src = image.get("src") or image.get("data-src")
        normalized = local_url(src)
        if normalized and normalized not in gallery:
            gallery.append(normalized)

    attributes: list[dict[str, str]] = []
    for item in soup.select(".catalog-detail__parameter-item"):
        label = item.select_one(".catalog-detail__parameter-name")
        value = item.select_one(".catalog-detail__parameter-size")
        if not label or not value:
            continue
        label_text = clean_text(label.get_text(" ", strip=True))
        value_text = clean_text(value.get_text(" ", strip=True))
        if label_text and value_text:
            attributes.append({"name": label_text, "value": value_text})

    related: list[str] = []
    for anchor in soup.select(".buy-products-block a[href]"):
        href = anchor.get("href", "")
        match = re.match(r"^/catalog/([^/]+)\.prod/?$", href)
        if match and match.group(1) not in related:
            related.append(match.group(1))

    categories = breadcrumb_chain(soup)
    unit = clean_text(unit_node.get_text(" ", strip=True)) if unit_node else "р./шт."
    if unit in {"р.", "руб.", "руб"}:
        unit = "р./шт."
    return {
        "name": name,
        "slug": path.stem,
        "old_path": f"/catalog/{path.name}",
        "price": price,
        "unit": unit,
        "order_status": clean_text(status_node.get_text(" ", strip=True)) if status_node else "В наличии",
        "short_description": clean_text(preview_node.get_text(" ", strip=True)) if preview_node else "",
        "description": str(description_node) if description_node else "",
        "attributes": attributes,
        "categories": categories,
        "image": local_url(image_node.get("src") or image_node.get("data-src")) if image_node else "",
        "gallery": gallery,
        "related_slugs": related,
        "seo_title": clean_text(soup.title.get_text(" ", strip=True)) if soup.title else name,
        "seo_description": meta_content(soup, "description"),
    }


def page_record(path: Path) -> dict | None:
    soup = BeautifulSoup(path.read_text(encoding="utf-8", errors="ignore"), "html.parser")
    h1 = soup.find("h1")
    if not h1:
        return None
    content = soup.select_one(".content-box") or soup.select_one("main")
    if not content:
        return None
    for node in content.select("script, style, .breadcrumb, h1, form"):
        node.decompose()
    route = "/" + str(path.relative_to(SITE)).replace("index.html", "")
    slug = path.parent.name if path.parent != SITE else "home"
    return {
        "name": clean_text(h1.get_text(" ", strip=True)),
        "slug": slug,
        "route": route,
        "content": str(content),
        "seo_title": clean_text(soup.title.get_text(" ", strip=True)) if soup.title else "",
        "seo_description": meta_content(soup, "description"),
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=ROOT / ".wordpress-build" / "catalog.json")
    args = parser.parse_args()

    products = [product_record(path) for path in sorted((SITE / "catalog").glob("*.prod"))]
    pages: list[dict] = []
    page_paths = [SITE / "index.html", *sorted(SITE.glob("*/index.html"))]
    for path in page_paths:
        if path.parent.name == "catalog":
            continue
        record = page_record(path)
        if record:
            pages.append(record)

    categories: dict[str, dict] = {}
    for product in products:
        parent = ""
        for category in product["categories"]:
            current = categories.setdefault(category["slug"], {**category, "parent": parent})
            if not current.get("parent"):
                current["parent"] = parent
            parent = category["slug"]

    bundle = {
        "version": 1,
        "source": SOURCE_ORIGIN,
        "products": products,
        "categories": list(categories.values()),
        "pages": pages,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(bundle, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(json.dumps({"output": str(args.output), "products": len(products), "categories": len(categories), "pages": len(pages)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
