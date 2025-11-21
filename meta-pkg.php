#!/usr/bin/env php
<?php
/**
 * MetaPkg - single-file PHP meta package manager + simple HTML GUI
 *
 * CLI usage examples:
 *
 *   php meta-pkg.php help
 *
 *   php meta-pkg.php info pypi requests
 *   php meta-pkg.php deps pypi requests 2.32.3
 *   php meta-pkg.php install pypi requests
 *
 *   php meta-pkg.php info composer monolog/monolog
 *   php meta-pkg.php install composer monolog/monolog 3.7.0
 *
 *   php meta-pkg.php search oss "json library"
 *   php meta-pkg.php info oss symfony/symfony
 *   php meta-pkg.php install oss symfony/symfony v7.1.0
 *
 *   # Create GitHub issue (requires GITHUB_TOKEN)
 *   GITHUB_TOKEN=ghp_xxx php meta-pkg.php issue oss owner/repo "Bug title" "Body..."
 *
 *   # Crawl GitHub trending into JSON
 *   php meta-pkg.php crawl trending trending.json
 *
 *   # Crawl GitHub repos by topic/tag into JSON
 *   php meta-pkg.php crawl tag backup backup-tools.json 50
 *
 * Web GUI:
 *   php -S 127.0.0.1:8000 meta-pkg.php
 *   Open http://127.0.0.1:8000 in browser
 */

//////////////////////
// Global config
//////////////////////

function metaPkgHomeDir(): string {
    $home = getenv('HOME');
    if (!$home) {
        $home = getenv('USERPROFILE') ?: '.';
    }
    return rtrim($home, DIRECTORY_SEPARATOR);
}

$META_ROOT = metaPkgHomeDir() . DIRECTORY_SEPARATOR . '.metapkg';
if (!is_dir($META_ROOT)) {
    @mkdir($META_ROOT, 0777, true);
}

// GitHub headers
$GITHUB_TOKEN = getenv('GITHUB_TOKEN') ?: null;
$GITHUB_HEADERS = [
    'Accept: application/vnd.github+json',
    'User-Agent: MetaPkg-PHP',
];
if ($GITHUB_TOKEN) {
    $GITHUB_HEADERS[] = 'Authorization: Bearer ' . $GITHUB_TOKEN;
}

//////////////////////
// Utility functions
//////////////////////

function show_help(): void {
    echo <<<TXT
MetaPkg - single-file meta package manager (PyPI + Composer + OSS via GitHub)

CLI USAGE:
  php meta-pkg.php help

  php meta-pkg.php info <ecosystem> <name>
  php meta-pkg.php deps <ecosystem> <name> [version]
  php meta-pkg.php install <ecosystem> <name> [version]

  php meta-pkg.php search oss "<query>"
  php meta-pkg.php issue  oss <owner/repo> "<title>" "<body>"

  php meta-pkg.php crawl trending <output.json>
  php meta-pkg.php crawl tag <topic> <output.json> [maxResults]

ECOSYSTEMS:
  pypi       Python packages from https://pypi.org
  composer   PHP packages from https://repo.packagist.org
  oss        Generic OSS via GitHub repositories (owner/repo)

TXT;
}

/**
 * HTTP GET returning decoded JSON (no cURL).
 */
function http_get_json(string $url, array $headers = []): mixed {
    $headerStr = '';
    if (!empty($headers)) {
        $headerStr = implode("\r\n", $headers);
    }

    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => $headerStr,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];

    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        fwrite(STDERR, "HTTP GET failed for $url\n");
        return null;
    }

    $json = json_decode($body, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "JSON decode error: " . json_last_error_msg() . "\n");
        return null;
    }

    return $json;
}

/**
 * HTTP POST JSON -> JSON (no cURL).
 */
function http_post_json(string $url, array $headers, array $payload): mixed {
    $headers[] = 'Content-Type: application/json';

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];

    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        fwrite(STDERR, "HTTP POST failed for $url\n");
        return null;
    }

    $json = json_decode($body, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "JSON decode error: " . json_last_error_msg() . "\n");
        return null;
    }

    return $json;
}

/**
 * Ensure target directory exists for a given ecosystem/name/version.
 */
function ensure_pkg_dir(string $ecosystem, string $name, string $version, string $root): string {
    $safeName = preg_replace('#[\\\/:"*?<>|]#', '_', $name);
    $safeVer  = preg_replace('#[\\\/:"*?<>|]#', '_', $version);
    $path = $root . DIRECTORY_SEPARATOR . $ecosystem . DIRECTORY_SEPARATOR . $safeName . DIRECTORY_SEPARATOR . $safeVer;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

//////////////////////
// PyPI logic
//////////////////////

function get_pypi_package(string $name): ?array {
    $url = "https://pypi.org/pypi/" . rawurlencode($name) . "/json";
    $data = http_get_json($url);
    if ($data === null) {
        return null;
    }

    $info     = $data['info'] ?? [];
    $releases = $data['releases'] ?? [];

    $versions = [];
    foreach ($releases as $ver => $files) {
        if (!$files || !is_array($files)) {
            continue;
        }
        $file0 = $files[0];
        $versions[] = [
            'version'      => $ver,
            'released_at'  => $file0['upload_time_iso_8601'] ?? null,
            'download_url' => $file0['url'] ?? null,
        ];
    }

    usort($versions, fn($a, $b) => version_compare($a['version'], $b['version']));

    $deps = [];
    if (!empty($info['requires_dist']) && is_array($info['requires_dist'])) {
        foreach ($info['requires_dist'] as $entry) {
            $parts = explode(';', $entry, 2);
            $first = trim($parts[0]);
            if (preg_match('/^([^\(\s]+)\s*(\((.*)\))?$/', $first, $m)) {
                $depName = $m[1];
                $depSpec = isset($m[3]) ? trim($m[3]) : '';
                $deps[] = [
                    'ecosystem'    => 'pypi',
                    'name'         => $depName,
                    'version_spec' => $depSpec,
                ];
            }
        }
    }

    return [
        'ecosystem'       => 'pypi',
        'name'            => $info['name'] ?? $name,
        'normalized_name' => strtolower($info['name'] ?? $name),
        'latest_version'  => $info['version'] ?? null,
        'summary'         => $info['summary'] ?? '',
        'homepage'        => $info['home_page'] ?? '',
        'source_url'      => 'https://pypi.org/project/' . ($info['name'] ?? $name) . '/',
        'versions'        => $versions,
        'dependencies'    => $deps,
    ];
}

function get_pypi_version_info(array $pkg, string $version): ?array {
    foreach ($pkg['versions'] as $v) {
        if ($v['version'] === $version) {
            return [
                'ecosystem'    => 'pypi',
                'name'         => $pkg['name'],
                'version'      => $version,
                'download_url' => $v['download_url'],
                'released_at'  => $v['released_at'],
                'dependencies' => $pkg['dependencies'],
            ];
        }
    }
    fwrite(STDERR, "Version '$version' not found for PyPI package '{$pkg['name']}'" . PHP_EOL);
    return null;
}

//////////////////////
// Composer logic
//////////////////////

function get_composer_package(string $fullName): ?array {
    $url = "https://repo.packagist.org/p2/" . $fullName . ".json";
    $data = http_get_json($url);
    if ($data === null) {
        return null;
    }
    $packages = $data['packages'][$fullName] ?? null;
    if (!$packages || !is_array($packages)) {
        fwrite(STDERR, "No versions returned for composer package '$fullName'" . PHP_EOL);
        return null;
    }

    $versions = [];
    $latest = null;

    foreach ($packages as $ver) {
        $verName = $ver['version'] ?? null;
        if (!$verName) {
            continue;
        }
        if ($latest === null) {
            $latest = $verName;
        }

        $deps = [];
        if (!empty($ver['require']) && is_array($ver['require'])) {
            foreach ($ver['require'] as $depName => $depSpec) {
                if ($depName === 'php' || str_starts_with($depName, 'ext-')) {
                    continue;
                }
                $deps[] = [
                    'ecosystem'    => 'composer',
                    'name'         => $depName,
                    'version_spec' => $depSpec,
                ];
            }
        }

        $versions[] = [
            'version'      => $verName,
            'released_at'  => $ver['time'] ?? null,
            'download_url' => $ver['dist']['url'] ?? null,
            'dependencies' => $deps,
        ];
    }

    $first = $packages[0];

    return [
        'ecosystem'       => 'composer',
        'name'            => $fullName,
        'normalized_name' => strtolower($fullName),
        'latest_version'  => $latest,
        'summary'         => $first['description'] ?? '',
        'homepage'        => $first['homepage'] ?? '',
        'source_url'      => $first['source']['url'] ?? '',
        'versions'        => $versions,
    ];
}

function get_composer_version_info(array $pkg, string $version): ?array {
    foreach ($pkg['versions'] as $v) {
        if ($v['version'] === $version) {
            return [
                'ecosystem'    => 'composer',
                'name'         => $pkg['name'],
                'version'      => $version,
                'download_url' => $v['download_url'],
                'released_at'  => $v['released_at'],
                'dependencies' => $v['dependencies'],
            ];
        }
    }
    fwrite(STDERR, "Version '$version' not found for composer package '{$pkg['name']}'" . PHP_EOL);
    return null;
}

//////////////////////
// OSS / GitHub logic
//////////////////////

function get_oss_repo(string $fullName, array $ghHeaders): ?array {
    $repoUrl = "https://api.github.com/repos/" . $fullName;
    $repo = http_get_json($repoUrl, $ghHeaders);
    if ($repo === null) {
        fwrite(STDERR, "GitHub repo '$fullName' not found or API error." . PHP_EOL);
        return null;
    }

    $relUrl = "https://api.github.com/repos/" . $fullName . "/releases";
    $releases = http_get_json($relUrl, $ghHeaders) ?? [];

    $versions = [];
    foreach ($releases as $r) {
        $tag = $r['tag_name'] ?? null;
        if (!$tag) {
            continue;
        }
        $download = null;
        if (!empty($r['assets']) && is_array($r['assets'])) {
            $asset = $r['assets'][0];
            $download = $asset['browser_download_url'] ?? null;
        }
        if (!$download) {
            $download = $r['tarball_url'] ?? null;
        }
        $versions[] = [
            'version'      => $tag,
            'released_at'  => $r['published_at'] ?? null,
            'download_url' => $download,
            'dependencies' => [],
        ];
    }

    $latest = null;
    if (!empty($versions)) {
        $latest = $versions[0]['version'];
    } else {
        $latest = $repo['default_branch'] ?? 'main';
    }

    return [
        'ecosystem'       => 'oss',
        'name'            => $fullName,
        'normalized_name' => strtolower($fullName),
        'latest_version'  => $latest,
        'summary'         => $repo['description'] ?? '',
        'homepage'        => $repo['homepage'] ?? '',
        'source_url'      => $repo['html_url'] ?? '',
        'versions'        => $versions,
        'dependencies'    => [],
    ];
}

function get_oss_version_info(array $pkg, string $version): ?array {
    foreach ($pkg['versions'] as $v) {
        if ($v['version'] === $version) {
            return [
                'ecosystem'    => 'oss',
                'name'         => $pkg['name'],
                'version'      => $v['version'],
                'download_url' => $v['download_url'],
                'released_at'  => $v['released_at'],
                'dependencies' => $v['dependencies'],
            ];
        }
    }
    // fallback: tarball of branch/tag
    fwrite(STDOUT, "No release tag '$version' found; using tarball for ref '$version'." . PHP_EOL);
    $download = "https://api.github.com/repos/" . $pkg['name'] . "/tarball/" . rawurlencode($version);
    return [
        'ecosystem'    => 'oss',
        'name'         => $pkg['name'],
        'version'      => $version,
        'download_url' => $download,
        'released_at'  => null,
        'dependencies' => [],
    ];
}

function oss_search_repos(string $query, array $ghHeaders): void {
    $q = urlencode($query);
    $url = "https://api.github.com/search/repositories?q={$q}&sort=stars&order=desc&per_page=10";
    $data = http_get_json($url, $ghHeaders);
    if ($data === null) {
        return;
    }
    $items = $data['items'] ?? [];
    if (empty($items)) {
        echo "No repositories found for query: {$query}" . PHP_EOL;
        return;
    }

    echo "Top repositories for '{$query}':" . PHP_EOL;
    foreach ($items as $item) {
        $full = $item['full_name'] ?? '';
        $stars = $item['stargazers_count'] ?? 0;
        $desc = $item['description'] ?? '';
        echo sprintf("  %-35s ⭐ %6d  %s\n", $full, $stars, $desc);
    }
}

function oss_new_issue(string $fullName, string $title, string $body, array $ghHeaders, ?string $token): void {
    if (!$token) {
        fwrite(STDERR, "GITHUB_TOKEN env var is required to create issues." . PHP_EOL);
        return;
    }
    $url = "https://api.github.com/repos/" . $fullName . "/issues";
    $payload = ['title' => $title, 'body' => $body];
    $issue = http_post_json($url, $ghHeaders, $payload);
    if ($issue === null) {
        return;
    }
    $num  = $issue['number'] ?? '?';
    $html = $issue['html_url'] ?? '';
    echo "Created issue #{$num} at {$html}" . PHP_EOL;
}

//////////////////////
// Crawlers
//////////////////////

// GitHub trending HTML (no API)
function crawl_github_trending(string $outFile): void {
    $url = "https://github.com/trending";
    $html = @file_get_contents($url);
    if (!$html) {
        fwrite(STDERR, "Failed to fetch GitHub Trending\n");
        return;
    }

    $results = [];
    $parts = explode('Box-row', $html);

    foreach ($parts as $block) {
        if (!str_contains($block, 'href="/')) continue;

        if (preg_match('#href="/([^"/]+/[^"]+)"#', $block, $m)) {
            $repo = trim($m[1]);
        } else {
            continue;
        }

        $desc = "";
        if (preg_match('#<p[^>]*>(.*?)</p>#s', $block, $m)) {
            $desc = trim(strip_tags($m[1]));
        }

        $stars = "";
        if (preg_match('#([\d,]+)\s+stars?#i', $block, $m)) {
            $stars = $m[1];
        }

        $results[] = [
            'repo'        => $repo,
            'link'        => "https://github.com/" . $repo,
            'description' => $desc,
            'stars'       => $stars,
        ];
    }

    $json = json_encode(
        ['source' => 'github_trending', 'count' => count($results), 'results' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (!file_put_contents($outFile, $json)) {
        fwrite(STDERR, "Failed to write JSON to $outFile\n");
        return;
    }

    echo "Stored " . count($results) . " trending repos to $outFile\n";
}

/**
 * Crawl GitHub repositories by topic/tag and store JSON.
 */
function crawl_github_by_topic(string $topic, int $maxResults, string $outFile, array $ghHeaders): void {
    $maxResults = max(1, min($maxResults, 100));

    $all = [];
    $page = 1;

    while (count($all) < $maxResults) {
        $remaining = $maxResults - count($all);
        $perPage   = min(100, $remaining);

        $q   = urlencode('topic:' . $topic . ' is:public');
        $url = "https://api.github.com/search/repositories?q={$q}&sort=stars&order=desc&per_page={$perPage}&page={$page}";

        $data = http_get_json($url, $ghHeaders);
        if ($data === null) {
            break;
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            break;
        }

        foreach ($items as $item) {
            $all[] = [
                'full_name'   => $item['full_name'] ?? '',
                'html_url'    => $item['html_url'] ?? '',
                'description' => $item['description'] ?? '',
                'language'    => $item['language'] ?? '',
                'stars'       => $item['stargazers_count'] ?? 0,
                'topics'      => $item['topics'] ?? [],
            ];
            if (count($all) >= $maxResults) {
                break;
            }
        }

        $page++;
    }

    if (empty($all)) {
        echo "No repositories found for topic '{$topic}'\n";
        return;
    }

    $payload = [
        'source'  => 'github_topic',
        'topic'   => $topic,
        'count'   => count($all),
        'results' => $all,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode JSON: " . json_last_error_msg() . "\n");
        return;
    }

    if (file_put_contents($outFile, $json) === false) {
        fwrite(STDERR, "Failed to write JSON to {$outFile}\n");
        return;
    }

    echo "Stored " . count($all) . " repos for topic '{$topic}' to {$outFile}\n";
}

//////////////////////
// High-level wrappers
//////////////////////

function get_package(string $ecosystem, string $name, array $ghHeaders): ?array {
    return match (strtolower($ecosystem)) {
        'pypi'     => get_pypi_package($name),
        'composer' => get_composer_package($name),
        'oss'      => get_oss_repo($name, $ghHeaders),
        default    => null,
    };
}

function get_version_info(string $ecosystem, array $pkg, string $version): ?array {
    return match (strtolower($ecosystem)) {
        'pypi'     => get_pypi_version_info($pkg, $version),
        'composer' => get_composer_version_info($pkg, $version),
        'oss'      => get_oss_version_info($pkg, $version),
        default    => null,
    };
}

function show_pkg_info(string $ecosystem, string $name, array $ghHeaders): void {
    $pkg = get_package($ecosystem, $name, $ghHeaders);
    if ($pkg === null) {
        fwrite(STDERR, "Failed to fetch package info." . PHP_EOL);
        return;
    }

    echo "Ecosystem : {$pkg['ecosystem']}" . PHP_EOL;
    echo "Name      : {$pkg['name']}" . PHP_EOL;
    echo "Latest    : {$pkg['latest_version']}" . PHP_EOL;
    echo "Summary   : {$pkg['summary']}" . PHP_EOL;
    echo "Homepage  : {$pkg['homepage']}" . PHP_EOL;
    echo "Source    : {$pkg['source_url']}" . PHP_EOL;
    echo PHP_EOL;

    $versions = $pkg['versions'] ?? [];
    if (empty($versions)) {
        echo "No explicit versions/releases found." . PHP_EOL;
        return;
    }

    echo "Versions / Releases:" . PHP_EOL;
    foreach ($versions as $v) {
        $ver = $v['version'] ?? '';
        $rel = $v['released_at'] ?? '';
        echo "  - {$ver}";
        if ($rel) {
            echo "  ({$rel})";
        }
        echo PHP_EOL;
    }
}

function show_pkg_deps(string $ecosystem, string $name, ?string $version, array $ghHeaders): void {
    $pkg = get_package($ecosystem, $name, $ghHeaders);
    if ($pkg === null) {
        fwrite(STDERR, "Failed to fetch package info." . PHP_EOL);
        return;
    }

    if (!$version) {
        $version = $pkg['latest_version'] ?? null;
        echo "No version specified; using latest: {$version}" . PHP_EOL;
    }
    if (!$version) {
        fwrite(STDERR, "No version available." . PHP_EOL);
        return;
    }

    $vi = get_version_info($ecosystem, $pkg, $version);
    if ($vi === null) {
        return;
    }

    $deps = $vi['dependencies'] ?? [];
    echo "Dependencies for {$pkg['name']} {$version} ({$ecosystem}):" . PHP_EOL;
    if (empty($deps)) {
        echo "  (none declared / not available)" . PHP_EOL;
        return;
    }

    foreach ($deps as $d) {
        $eco = $d['ecosystem'] ?? '';
        $dn  = $d['name'] ?? '';
        $vs  = $d['version_spec'] ?? '';
        echo "  - [{$eco}] {$dn} {$vs}" . PHP_EOL;
    }
}

function install_pkg(string $ecosystem, string $name, ?string $version, array $ghHeaders, string $root): void {
    $pkg = get_package($ecosystem, $name, $ghHeaders);
    if ($pkg === null) {
        fwrite(STDERR, "Failed to fetch package info." . PHP_EOL);
        return;
    }

    if (!$version) {
        $version = $pkg['latest_version'] ?? null;
        echo "No version specified; using latest: {$version}" . PHP_EOL;
    }
    if (!$version) {
        fwrite(STDERR, "No version available to install." . PHP_EOL);
        return;
    }

    $vi = get_version_info($ecosystem, $pkg, $version);
    if ($vi === null) {
        return;
    }

    $url = $vi['download_url'] ?? null;
    if (!$url) {
        fwrite(STDERR, "No download_url for {$ecosystem} package '{$name}' version '{$version}'" . PHP_EOL);
        return;
    }

    global $GITHUB_HEADERS;
    $targetDir = ensure_pkg_dir($ecosystem, $name, $version, $root);
    $fileName  = basename(parse_url($url, PHP_URL_PATH) ?? '');
    if ($fileName === '' || $fileName === '/') {
        $fileName = $pkg['name'] . '-' . $version . '.tgz';
    }
    $destPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (file_exists($destPath)) {
        echo "Already downloaded: {$destPath}" . PHP_EOL;
    } else {
        echo "Downloading {$ecosystem}:{$name}:{$version}" . PHP_EOL;
        echo "  URL  : {$url}" . PHP_EOL;
        echo "  Dest : {$destPath}" . PHP_EOL;

        $headers = [];
        if (strtolower($ecosystem) === 'oss') {
            $headers = $GITHUB_HEADERS;
        }

        $headerStr = '';
        if (!empty($headers)) {
            $headerStr = implode("\r\n", $headers);
        }

        $opts = [
            'http' => [
                'method'  => 'GET',
                'header'  => $headerStr,
                'timeout' => 60,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];
        $context = stream_context_create($opts);
        $file = @file_get_contents($url, false, $context);

        if ($file === false) {
            fwrite(STDERR, "Download failed for $url\n");
            return;
        }

        if (file_put_contents($destPath, $file) === false) {
            fwrite(STDERR, "Failed to save file to $destPath\n");
            return;
        }
    }

    echo PHP_EOL;
    echo "Downloaded artifact:" . PHP_EOL;
    echo "  {$destPath}" . PHP_EOL;
    echo PHP_EOL;
    echo "NOTE:" . PHP_EOL;
    echo "  Artifacts are only downloaded/cached under:" . PHP_EOL;
    echo "    {$targetDir}" . PHP_EOL;

    switch (strtolower($ecosystem)) {
        case 'pypi':
            echo "  You can now install with e.g.:  pip install \"{$destPath}\"" . PHP_EOL;
            break;
        case 'composer':
            echo "  You can reference this dist in composer.json or unpack as needed." . PHP_EOL;
            break;
        case 'oss':
            echo "  This is a GitHub release asset/tarball; unpack or use as appropriate." . PHP_EOL;
            break;
    }
}

//////////////////////
// CLI vs Web dispatch
//////////////////////

if (php_sapi_name() === 'cli') {
    // ---------- CLI MODE ----------
    global $GITHUB_HEADERS, $GITHUB_TOKEN, $META_ROOT;

    $argv0 = $argv[0] ?? null;
    array_shift($argv); // drop script name

    $command   = $argv[0] ?? null;
    $ecosystem = $argv[1] ?? null;
    $name      = $argv[2] ?? null;
    $version   = $argv[3] ?? null;
    $arg1      = $argv[4] ?? null;
    $arg2      = $argv[5] ?? null;

    if (
        !$command ||
        in_array($command, ['help', '-h', '--help', '/?'], true)
    ) {
        show_help();
        exit(0);
    }

    switch (strtolower($command)) {
        case 'info':
            if (!$ecosystem || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php info <ecosystem> <name>" . PHP_EOL);
                show_help();
                exit(1);
            }
            show_pkg_info($ecosystem, $name, $GITHUB_HEADERS);
            break;

        case 'deps':
            if (!$ecosystem || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php deps <ecosystem> <name> [version]" . PHP_EOL);
                show_help();
                exit(1);
            }
            show_pkg_deps($ecosystem, $name, $version, $GITHUB_HEADERS);
            break;

        case 'install':
            if (!$ecosystem || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php install <ecosystem> <name> [version]" . PHP_EOL);
                show_help();
                exit(1);
            }
            install_pkg($ecosystem, $name, $version, $GITHUB_HEADERS, $META_ROOT);
            break;

        case 'search':
            if (strtolower($ecosystem) !== 'oss' || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php search oss \"<query>\"" . PHP_EOL);
                show_help();
                exit(1);
            }
            oss_search_repos($name, $GITHUB_HEADERS);
            break;

        case 'issue':
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version || !$arg1) {
                fwrite(STDERR, "Usage: php meta-pkg.php issue oss <owner/repo> \"<title>\" \"<body>\"" . PHP_EOL);
                show_help();
                exit(1);
            }
            // here: $name = owner/repo, $version = title, $arg1 = body
            oss_new_issue($name, $version, $arg1, $GITHUB_HEADERS, $GITHUB_TOKEN);
            break;

        case 'crawl':
            // php meta-pkg.php crawl trending <output.json>
            if (strtolower($ecosystem) === 'trending' && $name) {
                crawl_github_trending($name);
                break;
            }

            // php meta-pkg.php crawl tag <topic> <output.json> [maxResults]
            if (strtolower($ecosystem) === 'tag' && $name && $version) {
                $topic   = $name;
                $outFile = $version;
                $max     = $arg1 ? (int)$arg1 : 50;
                crawl_github_by_topic($topic, $max, $outFile, $GITHUB_HEADERS);
                break;
            }

            fwrite(STDERR, "Usage:\n");
            fwrite(STDERR, "  php meta-pkg.php crawl trending <output.json>\n");
            fwrite(STDERR, "  php meta-pkg.php crawl tag <topic> <output.json> [maxResults]\n");
            break;

        default:
            fwrite(STDERR, "Unknown command '{$command}'" . PHP_EOL);
            show_help();
            exit(1);
    }

    exit(0);
}

// ---------- WEB GUI MODE ----------

global $GITHUB_HEADERS, $META_ROOT;

$action    = $_GET['action']    ?? '';
$eco       = $_GET['ecosystem'] ?? '';
$name      = $_GET['name']      ?? '';
$version   = $_GET['version']   ?? '';
$topic     = $_GET['topic']     ?? '';
$query     = $_GET['query']     ?? '';
$output    = null;
$message   = '';

if ($action === 'info' && $eco && $name) {
    $output = get_package($eco, $name, $GITHUB_HEADERS);
} elseif ($action === 'deps' && $eco && $name) {
    $pkg = get_package($eco, $name, $GITHUB_HEADERS);
    if ($pkg) {
        if (!$version) {
            $version = $pkg['latest_version'] ?? null;
        }
        if ($version) {
            $output = get_version_info($eco, $pkg, $version);
        }
    }
} elseif ($action === 'search_oss' && $query) {
    // capture search results into buffer and show as text
    ob_start();
    oss_search_repos($query, $GITHUB_HEADERS);
    $message = ob_get_clean();
} elseif ($action === 'crawl_topic' && $topic) {
    $tmp = sys_get_temp_dir() . '/crawl-' . preg_replace('/\W+/', '_', $topic) . '.json';
    crawl_github_by_topic($topic, 20, $tmp, $GITHUB_HEADERS);
    $json = @file_get_contents($tmp);
    if ($json) {
        $output = json_decode($json, true);
    }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>MetaPkg GUI</title>
  <style>
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 20px; }
    h1 { margin-top: 0; }
    .section { border: 1px solid #ccc; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
    label { display: block; margin-top: 6px; }
    input[type=text] { width: 320px; padding: 4px; }
    select { padding: 4px; }
    button { margin-top: 8px; padding: 6px 10px; cursor: pointer; }
    textarea { width: 100%; height: 260px; font-family: monospace; font-size: 12px; }
    pre { background: #f7f7f7; padding: 10px; border-radius: 6px; overflow-x: auto; }
  </style>
</head>
<body>
  <h1>MetaPkg – Web UI</h1>
  <p><strong>Hint:</strong> From CLI use: <code>php meta-pkg.php help</code></p>

  <div class="section">
    <h2>Package Info / Deps</h2>
    <form method="get">
      <label>
        Action:
        <select name="action">
          <option value="info">Info</option>
          <option value="deps">Dependencies</option>
        </select>
      </label>

      <label>
        Ecosystem:
        <select name="ecosystem">
          <option value="pypi" <?php if ($eco==='pypi') echo 'selected'; ?>>pypi</option>
          <option value="composer" <?php if ($eco==='composer') echo 'selected'; ?>>composer</option>
          <option value="oss" <?php if ($eco==='oss') echo 'selected'; ?>>oss (GitHub)</option>
        </select>
      </label>

      <label>
        Name (e.g. <code>requests</code>, <code>monolog/monolog</code>, <code>owner/repo</code>):
        <input type="text" name="name" value="<?php echo htmlspecialchars($name); ?>" required>
      </label>

      <label>
        Version (optional; leave empty for latest):
        <input type="text" name="version" value="<?php echo htmlspecialchars($version); ?>">
      </label>

      <button type="submit">Run</button>
    </form>
  </div>

  <div class="section">
    <h2>Search OSS (GitHub)</h2>
    <form method="get">
      <input type="hidden" name="action" value="search_oss">
      <label>
        Query:
        <input type="text" name="query" value="<?php echo htmlspecialchars($query); ?>" placeholder="json library, backup tool, etc.">
      </label>
      <button type="submit">Search</button>
    </form>
  </div>

  <div class="section">
    <h2>Crawl GitHub by Topic (Tag)</h2>
    <form method="get">
      <input type="hidden" name="action" value="crawl_topic">
      <label>
        Topic (e.g. <code>backup</code>, <code>monitoring</code>, <code>cli</code>):
        <input type="text" name="topic" value="<?php echo htmlspecialchars($topic); ?>" required>
      </label>
      <button type="submit">Crawl</button>
    </form>
  </div>

  <?php if ($message): ?>
    <div class="section">
      <h2>Text Output</h2>
      <pre><?php echo htmlspecialchars($message); ?></pre>
    </div>
  <?php endif; ?>

  <?php if ($output !== null): ?>
    <div class="section">
      <h2>Result JSON</h2>
      <textarea readonly><?php echo htmlspecialchars(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
    </div>
  <?php endif; ?>

</body>
</html>
