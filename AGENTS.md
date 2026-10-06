# Production content

This repository is the original custom AlyumProfi site, not WordPress.
The customer edits live content through `/admin/`. Never deploy the entire
`site/` directory or overwrite existing `.prod`, `.tag`, `.html`, pagination
archives, `admin/data`, `admin/storage`, `admin/config.local.php`, or
`upload/admin` with repository copies.

Use `tools/deploy_beget_code.py` with an explicit list of changed code files.
For requested content fixes read the current live data, make a backup and
patch only the necessary fields. Complete product data and version history
live in the private `admin/storage/product-content` and `product-history`
directories. Public PHP rendering overlays that data on the original design.

Run `tools/test_admin_editor.py` for backend changes. For template handling
also run `tools/audit_admin_templates.php`. Production HTTP uses PHP 8.2;
use `/usr/local/bin/php8.2` for matching CLI checks, not the default `php`
command. Do not test CRUD by
changing real customer products; use a private copy.
