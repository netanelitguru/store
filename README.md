# MetaPkg

Single-file **meta package manager** and **GitHub client** written in PHP.

- Works as a **CLI tool**
- Also exposes a **minimal HTML GUI**
- No database, no framework, **no cURL** – just `file_get_contents` and public APIs

Supported ecosystems:

- **PyPI** (`pypi`) – Python packages
- **Composer / Packagist** (`composer`) – PHP packages
- **OSS / GitHub** (`oss`) – generic open-source repos

Extra GitHub capabilities:

- Search repositories
- Crawl trending repos / repos by topic
- Read & write **issues**
- Read & write **discussions**

---

## Requirements

- PHP **7.4+**
- `allow_url_fopen = On`
- OpenSSL enabled (for HTTPS)
- Internet access

Optional but recommended:

- `GITHUB_TOKEN` environment variable  
  - Needed for **creating/commenting** on issues and discussions  
  - Also increases GitHub API rate limits

---

## Installation

1. Copy `meta-pkg.php` into your project or somewhere in `$PATH`.
2. (Optional) Make it executable:

   ```bash
   chmod +x meta-pkg.php
