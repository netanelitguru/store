#!/usr/bin/env php
<?php
/**
 * MetaPkg - simple meta package manager in a single PHP file
 *
 * Ecosystems:
 *   - pypi      : Python / PyPI (https://pypi.org)
 *   - composer  : Packagist / Composer (https://repo.packagist.org)
 *   - oss       : Generic OSS via GitHub repositories (owner/repo)
 *
 * Commands:
 *   php meta-pkg.php help
 *
 *   php meta-pkg.php info <ecosystem> <name>
 *   php meta-pkg.php deps <ecosystem> <name> [version]
 *   php meta-pkg.php install <ecosystem> <name> [version]
 *
 *   php meta-pkg.php search oss "<query>"
 *   php meta-pkg.php issue  oss <owner/repo> "<title>" "<body>"
 *
 * To create GitHub issues or avoid rate limits:
 *   export GITHUB_TOKEN="ghp_xxxxx"
 */

///////////////////////////////////////
// Basic CLI argument handling
///////////////////////////////////////

array_shift($argv); // drop script name
$command   = $argv[0] ?? null;
$ecosystem = $argv[1] ?? null;
$name      = $argv[2] ?? null;
$version   = $argv[3] ?? null;
$arg1      = $argv[4] ?? null; // extra, e.g. search query / issue title
$arg2      = $argv[5] ?? null; // extra, e.g. issue body

///////////////////////////////////////
// Configuration
///////////////////////////////////////

function metaPkgHomeDir(): string {
    // Try HOME (Linux/macOS) then USERPROFILE (Windows)
    $home = getenv('HOME');
    if (!$home) {
        $home = getenv('USERPROFILE') ?: '.';
    }
    return rtrim($home, DIRECTORY_SEPARATOR);
}

$META_ROOT = metaPkgHomeDir() . DIRECTORY_SEPARATOR . '.metapkg';

if (!is_dir($META_ROOT)) {
    mkdir($META_ROOT, 0777, true);
}

$GITHUB_TOKEN = getenv('GITHUB_TOKEN') ?: null;
$GITHUB_HEADERS = [
    'Accept: application/vnd.github+json',
    'User-Agent: MetaPkg-PHP',
];
if ($GITHUB_TOKEN) {
    $GITHUB_HEADERS[] = 'Authorization: Bearer ' . $GITHUB_TOKEN;
}

///////////////////////////////////////
// Utility functions
///////////////////////////////////////

function show_help(): void {
    echo <<<TXT
MetaPkg (PHP) - simple meta package manager (PyPI + Composer + OSS via GitHub)

USAGE:
  php meta-pkg.php help

  php meta-pkg.php info <ecosystem> <name>
  php meta-pkg.php deps <ecosystem> <name> [version]
  php meta-pkg.php install <ecosystem> <name> [version]

  php meta-pkg.php search oss "<query>"
  php meta-pkg.php issue  oss <owner/repo> "<title>" "<body>"

ECOSYSTEMS:
  pypi       Python packages from https://pypi.org
  composer   PHP packages from https://repo.packagist.org
  oss        Generic open-source via GitHub repositories (owner/repo)

EXAMPLES:
  php meta-pkg.php info pypi requests
  php meta-pkg.php deps pypi requests 2.32.3
  php meta-pkg.php install pypi requests

  php meta-pkg.php info composer monolog/monolog
  php meta-pkg.php install composer monolog/monolog 3.7.0

  php meta-pkg.php search oss "logging library"
  php meta-pkg.php info oss symfony/symfony
  php meta-pkg.php install oss symfony/symfony v7.1.0

  php meta-pkg.php issue oss owner/repo "Bug title" "Bug description"

TXT;
}

/**
 * Basic HTTP GET returning decoded JSON (array or object).
 */
function http_get_json(string $url, array $headers = []): mixed {
    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => 20
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
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
 * Basic HTTP POST JSON -> JSON.
 */
function http_post_json(string $url, array $headers, array $payload): mixed {
    $headers[] = "Content-Type: application/json";

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'timeout' => 20
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
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
        mkdir($path, 0777, true);
    }
    return $path;
}

///////////////////////////////////////
// PyPI logic
///////////////////////////////////////

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

///////////////////////////////////////
// Composer / Packagist logic
///////////////////////////////////////

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

///////////////////////////////////////
// OSS / GitHub logic
///////////////////////////////////////

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

///////////////////////////////////////
// High-level dispatch helpers
///////////////////////////////////////

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
/**
 * Crawl GitHub repositories by topic/tag and store JSON.
 *
 * @param string      $topic      GitHub topic/tag, e.g. "backup", "monitoring"
 * @param int         $maxResults Max results to fetch (1–100)
 * @param string      $outFile    Path to JSON file to write
 * @param array       $ghHeaders  GitHub headers (with or without token)
 */
function crawl_github_by_topic(string $topic, int $maxResults, string $outFile, array $ghHeaders): void {
    // clamp
    $maxResults = max(1, min($maxResults, 100));

    $all = [];
    $page = 1;

    while (count($all) < $maxResults) {
        $remaining = $maxResults - count($all);
        $perPage = min(100, $remaining);

        // Search repos by topic
        // Docs: https://docs.github.com/en/rest/search/search?apiVersion=2022-11-28#search-repositories
        $q = urlencode('topic:' . $topic . ' is:public');
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
                'topics'      => $item['topics'] ?? [],  // requires proper Accept header, but safe
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

function crawl_github_trending(string $outFile): void {
    $url = "https://github.com/trending";

    $html = @file_get_contents($url);
    if (!$html) {
        fwrite(STDERR, "Failed to fetch GitHub Trending\n");
        return;
    }

    $results = [];

    // Basic extraction (no regex nightmares)
    // Each project block begins with "Box-row"
    foreach (explode('Box-row', $html) as $block) {
        if (!str_contains($block, 'href="/')) continue;

        // Extract repo full name
        if (preg_match('#href="/([^"]+)"#', $block, $m)) {
            $repo = trim($m[1]);
        } else {
            continue;
        }

        // Extract description
        $desc = "";
        if (preg_match('#<p.*?>(.*?)</p>#s', $block, $m)) {
            $desc = trim(strip_tags($m[1]));
        }

        // Extract number of stars (rough)
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
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if (!file_put_contents($outFile, $json)) {
        fwrite(STDERR, "Failed to write JSON to $outFile\n");
        return;
    }

    echo "Stored " . count($results) . " trending repos to $outFile\n";
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

    $targetDir = ensure_pkg_dir($ecosystem, $name, $version, $root);
    $fileName = basename(parse_url($url, PHP_URL_PATH) ?? '');
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

        $ch = curl_init($url);
        $fh = fopen($destPath, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => (strtolower($ecosystem) === 'oss' ? $GLOBALS['GITHUB_HEADERS'] : []),
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) {
            fwrite(STDERR, "Download failed: " . curl_error($ch) . PHP_EOL);
            curl_close($ch);
            fclose($fh);
            @unlink($destPath);
            return;
        }
        curl_close($ch);
        fclose($fh);
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

///////////////////////////////////////
// CLI dispatch
///////////////////////////////////////

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
        if (strtolower($ecosystem) !== 'oss' || !$arg1) {
            fwrite(STDERR, "Usage: php meta-pkg.php search oss \"<query>\"" . PHP_EOL);
            show_help();
            exit(1);
        }
        oss_search_repos($arg1, $GITHUB_HEADERS);
        break;
    case 'crawl':
        // Mode 1: php meta-pkg.php crawl trending <output.json>
        if (strtolower($ecosystem) === 'trending' && $name) {
            crawl_github_trending($name); // your previous function
            break;
        }

        // Mode 2: php meta-pkg.php crawl tag <topic> <output.json> [maxResults]
        if (strtolower($ecosystem) === 'tag' && $name && $version) {
            $topic    = $name;     // 3rd arg
            $outFile  = $version;  // 4th arg
            $max      = $arg1 ? (int)$arg1 : 50; // optional 5th arg, default 50

            crawl_github_by_topic($topic, $max, $outFile, $GITHUB_HEADERS);
            break;
        }

        fwrite(STDERR, "Usage:\n");
        fwrite(STDERR, "  php meta-pkg.php crawl trending <output.json>\n");
        fwrite(STDERR, "  php meta-pkg.php crawl tag <topic> <output.json> [maxResults]\n");
        break;

    case 'issue':
        if (strtolower($ecosystem) !== 'oss' || !$name || !$arg1 || !$arg2) {
            fwrite(STDERR, "Usage: php meta-pkg.php issue oss <owner/repo> \"<title>\" \"<body>\"" . PHP_EOL);
            show_help();
            exit(1);
        }
        oss_new_issue($name, $arg1, $arg2, $GITHUB_HEADERS, $GITHUB_TOKEN);
        break;

    default:
        fwrite(STDERR, "Unknown command '{$command}'" . PHP_EOL);
        show_help();
        exit(1);
}
