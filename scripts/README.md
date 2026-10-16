# Scripts

Helper scripts for the team. They are not part of the package: `.gitattributes`
keeps this folder out of the Composer archive, and nothing in `src/` or
`tests/` depends on them.

## research/

Read-only tools behind the reports in [`docs/research/`](../docs/research/).
Each one is a stopgap until the package can do the same job itself.

| Script | What it does | Run | Replaced by |
| :--- | :--- | :--- | :--- |
| [`scan-repo.sh`](research/scan-repo.sh) | Collects cache usage facts from a Laravel repository with `grep`: call styles, operations, keys, TTLs, invalidation. Basis of [cache-usage-in-the-wild.md](../docs/research/cache-usage-in-the-wild.md). | `zsh scripts/research/scan-repo.sh path/to/repo -o scan.txt` (needs zsh) | The AST scanner, `cache-contract:scan` (LRVL-18–20) |
| [`similarity-vs-public-scan.php`](research/similarity-vs-public-scan.php) | Runs the public scan's key pairs through the similarity engine and prints the results table of [similarity-vs-public-scan.md](../docs/research/similarity-vs-public-scan.md). | `docker compose exec php php scripts/research/similarity-vs-public-scan.php` | The labelled pair dataset and its test (LRVL-24, LRVL-11) |
