#!/usr/bin/env php
<?php
/**
 * MetaPkg - single-file PHP meta package manager + HTML GUI
 *
 * CLI examples:
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
 *   # Issues (read/write)
 *   php meta-pkg.php issues-list    oss owner/repo [state] [limit]
 *   php meta-pkg.php issues-show    oss owner/repo <number>
 *   php meta-pkg.php issues-comment oss owner/repo <number> "<body>"
 *   php meta-pkg.php issue          oss owner/repo "<title>" "<body>"
 *
 *   # Discussions (read/write)
 *   php meta-pkg.php discuss-list    oss owner/repo [limit]
 *   php meta-pkg.php discuss-show    oss owner/repo <number>
 *   php meta-pkg.php discuss-comment oss owner/repo <number> "<body>"
 *   php meta-pkg.php discuss-new     oss owner/repo <categoryName> "<title>" "<body>"
 *
 *   # Crawlers
 *   php meta-pkg.php crawl trending trending.json
 *   php meta-pkg.php crawl tag backup backup-tools.json 50
 *
 * Web GUI:
 *   php -S 127.0.0.1:8000 meta-pkg.php
 *   Then open http://127.0.0.1:8000 in your browser
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

// Polyfills for PHP < 8 helpers (basic)
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
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

  # Issues (read/write)
  php meta-pkg.php issues-list    oss <owner/repo> [state] [limit]
  php meta-pkg.php issues-show    oss <owner/repo> <number>
  php meta-pkg.php issues-comment oss <owner/repo> <number> "<body>"
  php meta-pkg.php issue          oss <owner/repo> "<title>" "<body>"

  # Discussions (read/write)
  php meta-pkg.php discuss-list    oss <owner/repo> [limit]
  php meta-pkg.php discuss-show    oss <owner/repo> <number>
  php meta-pkg.php discuss-comment oss <owner/repo> <number> "<body>"
  php meta-pkg.php discuss-new     oss <owner/repo> <categoryName> "<title>" "<body>"

  # Crawlers
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
        fwrite(STDERR, "GITHUB_TOKEN env var is required to create issues.\n");
        return;
    }
    $url     = "https://api.github.com/repos/{$fullName}/issues";
    $payload = ['title' => $title, 'body' => $body];
    $issue   = http_post_json($url, $ghHeaders, $payload);
    if ($issue === null) {
        fwrite(STDERR, "Failed to create issue in {$fullName}\n");
        return;
    }
    $num  = $issue['number'] ?? '?';
    $html = $issue['html_url'] ?? '';
    echo "Created issue #{$num} at {$html}" . PHP_EOL;
}

//////////////////////
// Issues helpers
//////////////////////

function oss_list_issues(string $fullName, string $state, int $limit, array $ghHeaders): void {
    $limit = max(1, min($limit, 100));
    $page  = 1;
    $collected = 0;

    echo "Issues for {$fullName} (state={$state}):" . PHP_EOL;

    while ($collected < $limit) {
        $remaining = $limit - $collected;
        $perPage   = min(100, $remaining);
        $url = "https://api.github.com/repos/{$fullName}/issues?state={$state}&per_page={$perPage}&page={$page}";
        $items = http_get_json($url, $ghHeaders);
        if ($items === null || empty($items)) {
            break;
        }

        foreach ($items as $issue) {
            if (isset($issue['pull_request'])) {
                continue;
            }
            $number = $issue['number'] ?? 0;
            $title  = $issue['title'] ?? '';
            $st     = $issue['state'] ?? '';
            $user   = $issue['user']['login'] ?? '';
            echo sprintf("#%d [%s] %s (by %s)\n", $number, $st, $title, $user);
            $collected++;
            if ($collected >= $limit) {
                break 2;
            }
        }

        $page++;
    }

    if ($collected === 0) {
        echo "  (no issues found)\n";
    }
}

function oss_show_issue(string $fullName, int $number, array $ghHeaders): void {
    $url = "https://api.github.com/repos/{$fullName}/issues/{$number}";
    $issue = http_get_json($url, $ghHeaders);
    if ($issue === null) {
        fwrite(STDERR, "Failed to fetch issue #{$number}\n");
        return;
    }

    $title = $issue['title'] ?? '';
    $state = $issue['state'] ?? '';
    $user  = $issue['user']['login'] ?? '';
    $body  = $issue['body'] ?? '';
    $html  = $issue['html_url'] ?? '';

    echo "Issue #{$number}: {$title}" . PHP_EOL;
    echo "State : {$state}" . PHP_EOL;
    echo "Author: {$user}" . PHP_EOL;
    echo "URL   : {$html}" . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;
    echo ($body ?: "(no body)") . PHP_EOL;

    $comments = $issue['comments'] ?? 0;
    if ($comments > 0) {
        echo PHP_EOL . "Comments:" . PHP_EOL;
        $cUrl  = "https://api.github.com/repos/{$fullName}/issues/{$number}/comments?per_page=50";
        $cList = http_get_json($cUrl, $ghHeaders) ?? [];
        foreach ($cList as $c) {
            $cUser = $c['user']['login'] ?? '';
            $cBody = $c['body'] ?? '';
            echo "---- {$cUser} ----" . PHP_EOL;
            echo $cBody . PHP_EOL . PHP_EOL;
        }
    }
}

function oss_comment_issue(string $fullName, int $number, string $body, array $ghHeaders, ?string $token): void {
    if (!$token) {
        fwrite(STDERR, "GITHUB_TOKEN env var is required to comment on issues.\n");
        return;
    }
    $url     = "https://api.github.com/repos/{$fullName}/issues/{$number}/comments";
    $payload = ['body' => $body];
    $res     = http_post_json($url, $ghHeaders, $payload);
    if ($res === null) {
        fwrite(STDERR, "Failed to post comment on issue #{$number}\n");
        return;
    }
    $html = $res['html_url'] ?? '';
    echo "Comment posted on issue #{$number}: {$html}" . PHP_EOL;
}

//////////////////////
// Discussions helpers
//////////////////////

function oss_list_discussions(string $fullName, int $limit, array $ghHeaders): void {
    $limit = max(1, min($limit, 100));
    $page  = 1;
    $collected = 0;

    echo "Discussions for {$fullName}:" . PHP_EOL;

    while ($collected < $limit) {
        $remaining = $limit - $collected;
        $perPage   = min(100, $remaining);
        $url       = "https://api.github.com/repos/{$fullName}/discussions?per_page={$perPage}&page={$page}";
        $data      = http_get_json($url, $ghHeaders);
        if ($data === null || empty($data)) {
            break;
        }

        foreach ($data as $disc) {
            $number = $disc['number'] ?? 0;
            $title  = $disc['title'] ?? '';
            $state  = $disc['state'] ?? '';
            $author = $disc['user']['login'] ?? ($disc['author']['login'] ?? '');
            echo sprintf("#%d [%s] %s (by %s)\n", $number, $state, $title, $author);
            $collected++;
            if ($collected >= $limit) {
                break 2;
            }
        }

        $page++;
    }

    if ($collected === 0) {
        echo "  (no discussions found or discussions disabled)\n";
    }
}

function oss_show_discussion(string $fullName, int $number, array $ghHeaders): void {
    $url  = "https://api.github.com/repos/{$fullName}/discussions/{$number}";
    $disc = http_get_json($url, $ghHeaders);
    if ($disc === null) {
        fwrite(STDERR, "Failed to fetch discussion #{$number}\n");
        return;
    }

    $title = $disc['title'] ?? '';
    $state = $disc['state'] ?? '';
    $user  = $disc['user']['login'] ?? ($disc['author']['login'] ?? '');
    $body  = $disc['body'] ?? '';
    $html  = $disc['html_url'] ?? '';

    echo "Discussion #{$number}: {$title}" . PHP_EOL;
    echo "State : {$state}" . PHP_EOL;
    echo "Author: {$user}" . PHP_EOL;
    echo "URL   : {$html}" . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;
    echo ($body ?: "(no body)") . PHP_EOL;

    $cUrl  = "https://api.github.com/repos/{$fullName}/discussions/{$number}/comments?per_page=50";
    $cList = http_get_json($cUrl, $ghHeaders) ?? [];
    if (!empty($cList)) {
        echo PHP_EOL . "Comments:" . PHP_EOL;
        foreach ($cList as $c) {
            $cUser = $c['user']['login'] ?? '';
            $cBody = $c['body'] ?? '';
            echo "---- {$cUser} ----" . PHP_EOL;
            echo $cBody . PHP_EOL . PHP_EOL;
        }
    }
}

function oss_comment_discussion(string $fullName, int $number, string $body, array $ghHeaders, ?string $token): void {
    if (!$token) {
        fwrite(STDERR, "GITHUB_TOKEN env var is required to comment on discussions.\n");
        return;
    }
    $url     = "https://api.github.com/repos/{$fullName}/discussions/{$number}/comments";
    $payload = ['body' => $body];
    $res     = http_post_json($url, $ghHeaders, $payload);
    if ($res === null) {
        fwrite(STDERR, "Failed to post comment on discussion #{$number}\n");
        return;
    }
    $html = $res['html_url'] ?? '';
    echo "Comment posted on discussion #{$number}: {$html}" . PHP_EOL;
}

function oss_get_discussion_category_id(string $fullName, string $categoryName, array $ghHeaders): ?int {
    $url  = "https://api.github.com/repos/{$fullName}/discussions/categories";
    $cats = http_get_json($url, $ghHeaders);
    if ($cats === null || empty($cats)) {
        fwrite(STDERR, "Could not fetch discussion categories for {$fullName}\n");
        return null;
    }

    foreach ($cats as $cat) {
        $name = $cat['name'] ?? '';
        if (strcasecmp($name, $categoryName) === 0) {
            return (int)($cat['id'] ?? 0);
        }
    }

    fwrite(STDERR, "Category '{$categoryName}' not found for {$fullName}\n");
    return null;
}

function oss_create_discussion(string $fullName, string $categoryName, string $title, string $body, array $ghHeaders, ?string $token): void {
    if (!$token) {
        fwrite(STDERR, "GITHUB_TOKEN env var is required to create discussions.\n");
        return;
    }

    $catId = oss_get_discussion_category_id($fullName, $categoryName, $ghHeaders);
    if (!$catId) {
        return;
    }

    $url     = "https://api.github.com/repos/{$fullName}/discussions";
    $payload = [
        'title'       => $title,
        'body'        => $body,
        'category_id' => $catId,
    ];

    $res = http_post_json($url, $ghHeaders, $payload);
    if ($res === null) {
        fwrite(STDERR, "Failed to create discussion in {$fullName}\n");
        return;
    }

    $num  = $res['number'] ?? '?';
    $html = $res['html_url'] ?? '';
    echo "Created discussion #{$num} at {$html}" . PHP_EOL;
}

//////////////////////
// Crawlers
//////////////////////

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
function crawl_github_by_topic(string $topic, int $maxResults,  array $ghHeaders): void {
    $maxResults = max(1, min($maxResults, 100));
$outFile=$topic . '.json';

    $all = [];
    $page = 1;

    while (count($all) < $maxResults) {
        $remaining = $maxResults - count($all);
        $perPage   = min(1000, $remaining);

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
                break 2;
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
    array_shift($argv);

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
            // php meta-pkg.php issue oss owner/repo "<title>" "<body>"
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version || !$arg1) {
                fwrite(STDERR, "Usage: php meta-pkg.php issue oss <owner/repo> \"<title>\" \"<body>\"" . PHP_EOL);
                show_help();
                exit(1);
            }
            oss_new_issue($name, $version, $arg1, $GITHUB_HEADERS, $GITHUB_TOKEN);
            break;

        case 'issues-list':
            // php meta-pkg.php issues-list oss owner/repo [state] [limit]
            if (strtolower($ecosystem) !== 'oss' || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php issues-list oss <owner/repo> [state] [limit]\n");
                show_help();
                exit(1);
            }
            $state = $version ?: 'open';
            $limit = $arg1 ? (int)$arg1 : 20;
            oss_list_issues($name, $state, $limit, $GITHUB_HEADERS);
            break;

        case 'issues-show':
            // php meta-pkg.php issues-show oss owner/repo <number>
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version) {
                fwrite(STDERR, "Usage: php meta-pkg.php issues-show oss <owner/repo> <number>\n");
                show_help();
                exit(1);
            }
            oss_show_issue($name, (int)$version, $GITHUB_HEADERS);
            break;

        case 'issues-comment':
            // php meta-pkg.php issues-comment oss owner/repo <number> "<body>"
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version || !$arg1) {
                fwrite(STDERR, "Usage: php meta-pkg.php issues-comment oss <owner/repo> <number> \"<body>\"\n");
                show_help();
                exit(1);
            }
            oss_comment_issue($name, (int)$version, $arg1, $GITHUB_HEADERS, $GITHUB_TOKEN);
            break;

        case 'discuss-list':
            // php meta-pkg.php discuss-list oss owner/repo [limit]
            if (strtolower($ecosystem) !== 'oss' || !$name) {
                fwrite(STDERR, "Usage: php meta-pkg.php discuss-list oss <owner/repo> [limit]\n");
                show_help();
                exit(1);
            }
            $limit = $version ? (int)$version : 20;
            oss_list_discussions($name, $limit, $GITHUB_HEADERS);
            break;

        case 'discuss-show':
            // php meta-pkg.php discuss-show oss owner/repo <number>
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version) {
                fwrite(STDERR, "Usage: php meta-pkg.php discuss-show oss <owner/repo> <number>\n");
                show_help();
                exit(1);
            }
            oss_show_discussion($name, (int)$version, $GITHUB_HEADERS);
            break;

        case 'discuss-comment':
            // php meta-pkg.php discuss-comment oss owner/repo <number> "<body>"
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version || !$arg1) {
                fwrite(STDERR, "Usage: php meta-pkg.php discuss-comment oss <owner/repo> <number> \"<body>\"\n");
                show_help();
                exit(1);
            }
            oss_comment_discussion($name, (int)$version, $arg1, $GITHUB_HEADERS, $GITHUB_TOKEN);
            break;

        case 'discuss-new':
            // php meta-pkg.php discuss-new oss owner/repo <categoryName> "<title>" "<body>"
            if (strtolower($ecosystem) !== 'oss' || !$name || !$version || !$arg1 || !$arg2) {
                fwrite(STDERR, "Usage: php meta-pkg.php discuss-new oss <owner/repo> <categoryName> \"<title>\" \"<body>\"\n");
                show_help();
                exit(1);
            }
            $categoryName = $version;
            $title        = $arg1;
            $body         = $arg2;
            oss_create_discussion($name, $categoryName, $title, $body, $GITHUB_HEADERS, $GITHUB_TOKEN);
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
                crawl_github_by_topic($topic, $max, $GITHUB_HEADERS);
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

global $GITHUB_HEADERS, $GITHUB_TOKEN, $META_ROOT;

$action    = $_GET['action']    ?? '';
$eco       = $_GET['ecosystem'] ?? '';
$name      = $_GET['name']      ?? '';
$version   = $_GET['version']   ?? '';
$topic     = $_GET['topic']     ?? '';
$query     = $_GET['query']     ?? '';

$issueRepo   = $_GET['issue_repo']   ?? '';
$issueState  = $_GET['issue_state']  ?? 'open';
$issueLimit  = (int)($_GET['issue_limit'] ?? 20);
$issueNumber = (int)($_GET['issue_number'] ?? 0);
$issueTitle  = $_GET['issue_title']  ?? '';
$issueBody   = $_GET['issue_body']   ?? '';

$discRepo     = $_GET['disc_repo']     ?? '';
$discLimit    = (int)($_GET['disc_limit'] ?? 20);
$discNumber   = (int)($_GET['disc_number'] ?? 0);
$discCategory = $_GET['disc_category'] ?? '';
$discTitle    = $_GET['disc_title']    ?? '';
$discBody     = $_GET['disc_body']     ?? '';

$output  = null;
$message = '';

function save_web_log(string $prefix, string $suffix, string $content): void {
    // sanitize "owner/repo" → "owner_repo"
    $safePrefix = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $prefix);
    $safeSuffix = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $suffix);

    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = $dir . '/' . $safePrefix;
    if ($safeSuffix !== '') {
        $filename .= '_' . $safeSuffix;
    }
    $filename .= '.txt';

    file_put_contents($filename, $content);
}
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
    ob_start();
    oss_search_repos($query, $GITHUB_HEADERS);
    $message = ob_get_clean();

} elseif ($action === 'crawl_topic' && $topic) {
    // use a temp file + correct function signature
    $tmp = sys_get_temp_dir() . '/crawl-' . preg_replace('/\W+/', '_', $topic) . '.json';
    crawl_github_by_topic($topic, 2000,  $GITHUB_HEADERS); // max clamped inside function

    $json = @file_get_contents($tmp);
    if ($json) {
        $output = json_decode($json, true);
    }

} elseif ($action === 'issues_list' && $issueRepo) {
    ob_start();
    oss_list_issues($issueRepo, $issueState, $issueLimit, $GITHUB_HEADERS);
    $message = ob_get_clean();

    // log: repo + "issues"
    save_web_log($issueRepo, 'issues', $message);

} elseif ($action === 'issues_show' && $issueRepo && $issueNumber) {
    ob_start();
    oss_show_issue($issueRepo, $issueNumber, $GITHUB_HEADERS);
    $message = ob_get_clean();

    // log: repo + issue number
    save_web_log($issueRepo, 'issue_' . $issueNumber, $message);

} elseif ($action === 'issues_comment' && $issueRepo && $issueNumber && $issueBody) {
    ob_start();
    oss_comment_issue($issueRepo, $issueNumber, $issueBody, $GITHUB_HEADERS, $GITHUB_TOKEN);
    $message = ob_get_clean();

    // log response to same file
    save_web_log($issueRepo, 'issue_' . $issueNumber, $message);

} elseif ($action === 'issue_new' && $issueRepo && $issueTitle && $issueBody) {
    ob_start();
    oss_new_issue($issueRepo, $issueTitle, $issueBody, $GITHUB_HEADERS, $GITHUB_TOKEN);
    $message = ob_get_clean();

    // log creation result
    save_web_log($issueRepo, 'issue_new', $message);

} elseif ($action === 'disc_list' && $discRepo) {
    ob_start();
    oss_list_discussions($discRepo, $discLimit, $GITHUB_HEADERS);
    $message = ob_get_clean();

    // log: repo + discussions
    save_web_log($discRepo, 'discussions', $message);

} elseif ($action === 'disc_show' && $discRepo && $discNumber) {
    ob_start();
    oss_show_discussion($discRepo, $discNumber, $GITHUB_HEADERS);
    $message = ob_get_clean();

    // log: repo + discussion number
    save_web_log($discRepo, 'disc_' . $discNumber, $message);

} elseif ($action === 'disc_comment' && $discRepo && $discNumber && $discBody) {
    ob_start();
    oss_comment_discussion($discRepo, $discNumber, $discBody, $GITHUB_HEADERS, $GITHUB_TOKEN);
    $message = ob_get_clean();

    // log: same discussion file
    save_web_log($discRepo, 'disc_' . $discNumber, $message);

} elseif ($action === 'disc_new' && $discRepo && $discCategory && $discTitle && $discBody) {
    ob_start();
    oss_create_discussion($discRepo, $discCategory, $discTitle, $discBody, $GITHUB_HEADERS, $GITHUB_TOKEN);
    $message = ob_get_clean();

    // log: new discussion result
    save_web_log($discRepo, 'disc_new', $message);
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
    input[type=text], textarea { width: 100%; max-width: 600px; padding: 4px; }
    select { padding: 4px; }
    button { margin-top: 8px; padding: 6px 10px; cursor: pointer; }
    textarea { height: 260px; font-family: monospace; font-size: 12px; }
    pre { background: #f7f7f7; padding: 10px; border-radius: 6px; overflow-x: auto; }
    small { color: #555; }
  </style>
</head>
<body>
  <h1>MetaPkg – Web UI</h1>
  <p><strong>CLI hint:</strong> <code>php meta-pkg.php help</code></p>
  <p><strong>Note:</strong> Write operations on GitHub (issues/discussions) require <code>GITHUB_TOKEN</code> environment variable.</p>

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

  <div class="section">
    <h2>GitHub Issues</h2>
    <h3>List Issues</h3>
    <form method="get">
      <input type="hidden" name="action" value="issues_list">
      <label>
        Repo (owner/repo):
        <input type="text" name="issue_repo" value="<?php echo htmlspecialchars($issueRepo); ?>">
      </label>
      <label>
        State:
        <select name="issue_state">
          <option value="open"   <?php if ($issueState==='open') echo 'selected'; ?>>open</option>
          <option value="closed" <?php if ($issueState==='closed') echo 'selected'; ?>>closed</option>
          <option value="all"    <?php if ($issueState==='all') echo 'selected'; ?>>all</option>
        </select>
      </label>
      <label>
        Limit:
        <input type="text" name="issue_limit" value="<?php echo htmlspecialchars((string)$issueLimit); ?>">
      </label>
      <button type="submit">List</button>
    </form>

    <h3>Show Issue</h3>
    <form method="get">
      <input type="hidden" name="action" value="issues_show">
      <label>
        Repo (owner/repo):
        <input type="text" name="issue_repo" value="<?php echo htmlspecialchars($issueRepo); ?>">
      </label>
      <label>
        Issue #:
        <input type="text" name="issue_number" value="<?php echo htmlspecialchars((string)$issueNumber); ?>">
      </label>
      <button type="submit">Show</button>
    </form>

    <h3>New Issue</h3>
    <form method="get">
      <input type="hidden" name="action" value="issue_new">
      <label>
        Repo (owner/repo):
        <input type="text" name="issue_repo" value="<?php echo htmlspecialchars($issueRepo); ?>">
      </label>
      <label>
        Title:
        <input type="text" name="issue_title" value="<?php echo htmlspecialchars($issueTitle); ?>">
      </label>
      <label>
        Body:
        <textarea name="issue_body"><?php echo htmlspecialchars($issueBody); ?></textarea>
      </label>
      <small>Requires <code>GITHUB_TOKEN</code> with <code>repo</code> scope.</small><br>
      <button type="submit">Create Issue</button>
    </form>

    <h3>Comment on Issue</h3>
    <form method="get">
      <input type="hidden" name="action" value="issues_comment">
      <label>
        Repo (owner/repo):
        <input type="text" name="issue_repo" value="<?php echo htmlspecialchars($issueRepo); ?>">
      </label>
      <label>
        Issue #:
        <input type="text" name="issue_number" value="<?php echo htmlspecialchars((string)$issueNumber); ?>">
      </label>
      <label>
        Comment:
        <textarea name="issue_body"><?php echo htmlspecialchars($issueBody); ?></textarea>
      </label>
      <small>Requires <code>GITHUB_TOKEN</code>.</small><br>
      <button type="submit">Post Comment</button>
    </form>
  </div>

  <div class="section">
    <h2>GitHub Discussions</h2>
    <h3>List Discussions</h3>
    <form method="get">
      <input type="hidden" name="action" value="disc_list">
      <label>
        Repo (owner/repo):
        <input type="text" name="disc_repo" value="<?php echo htmlspecialchars($discRepo); ?>">
      </label>
      <label>
        Limit:
        <input type="text" name="disc_limit" value="<?php echo htmlspecialchars((string)$discLimit); ?>">
      </label>
      <button type="submit">List</button>
    </form>

    <h3>Show Discussion</h3>
    <form method="get">
      <input type="hidden" name="action" value="disc_show">
      <label>
        Repo (owner/repo):
        <input type="text" name="disc_repo" value="<?php echo htmlspecialchars($discRepo); ?>">
      </label>
      <label>
        Discussion #:
        <input type="text" name="disc_number" value="<?php echo htmlspecialchars((string)$discNumber); ?>">
      </label>
      <button type="submit">Show</button>
    </form>

    <h3>New Discussion</h3>
    <form method="get">
      <input type="hidden" name="action" value="disc_new">
      <label>
        Repo (owner/repo):
        <input type="text" name="disc_repo" value="<?php echo htmlspecialchars($discRepo); ?>">
      </label>
      <label>
        Category name:
        <input type="text" name="disc_category" value="<?php echo htmlspecialchars($discCategory); ?>" placeholder="e.g. General, Ideas">
      </label>
      <label>
        Title:
        <input type="text" name="disc_title" value="<?php echo htmlspecialchars($discTitle); ?>">
      </label>
      <label>
        Body:
        <textarea name="disc_body"><?php echo htmlspecialchars($discBody); ?></textarea>
      </label>
      <small>Requires <code>GITHUB_TOKEN</code> and discussions enabled in the repo.</small><br>
      <button type="submit">Create Discussion</button>
    </form>

    <h3>Comment on Discussion</h3>
    <form method="get">
      <input type="hidden" name="action" value="disc_comment">
      <label>
        Repo (owner/repo):
        <input type="text" name="disc_repo" value="<?php echo htmlspecialchars($discRepo); ?>">
      </label>
      <label>
        Discussion #:
        <input type="text" name="disc_number" value="<?php echo htmlspecialchars((string)$discNumber); ?>">
      </label>
      <label>
        Comment:
        <textarea name="disc_body"><?php echo htmlspecialchars($discBody); ?></textarea>
      </label>
      <small>Requires <code>GITHUB_TOKEN</code>.</small><br>
      <button type="submit">Post Comment</button>
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
