<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function errorJson(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function fetchPage(string $url): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                . 'Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);

    if ($body === false || $err) {
        errorJson('Failed to fetch Pinterest page: ' . $err, 502);
    }
    return $body;
}

function metaContent(string $html, string $property): string
{
    if (preg_match('/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]*>/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    return '';
}

function extractVideo(string $html, string $pageUrl): ?array
{
    if (preg_match('/__PWS_INITIAL_STORE__\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
        $json = json_decode($m[1], true);
        if ($json) {
            $hit = searchForVideoUrls($json);
            if ($hit) return $hit;
        }
    }

    if (preg_match('/__PWS_DATA__\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
        $json = json_decode($m[1], true);
        if ($json) {
            $hit = searchForVideoUrls($json);
            if ($hit) return $hit;
        }
    }

    preg_match_all('/<script[^>]*type=["\']application\/json["\'][^>]*>(.+?)<\/script>/si', $html, $scripts);
    foreach ($scripts[1] as $blob) {
        $json = json_decode($blob, true);
        if ($json) {
            $hit = searchForVideoUrls($json);
            if ($hit) return $hit;
        }
    }

    if (preg_match_all('/"(https:\/\/[^"]+\.mp4[^"]*)"/', $html, $mp4matches)) {
        $urls = array_unique($mp4matches[1]);
        usort($urls, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($urls as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return ['url' => $url, 'type' => 'mp4'];
            }
        }
    }

    if (preg_match('/"(https:\/\/[^"]+\.m3u8[^"]*)"/', $html, $m3u8match)) {
        $url = html_entity_decode($m3u8match[1], ENT_QUOTES | ENT_HTML5);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return ['url' => $url, 'type' => 'm3u8'];
        }
    }

    $ogVideo = metaContent($html, 'og:video');
    if (!$ogVideo) $ogVideo = metaContent($html, 'og:video:url');
    if ($ogVideo && filter_var($ogVideo, FILTER_VALIDATE_URL)) {
        $type = str_contains($ogVideo, '.m3u8') ? 'm3u8' : 'mp4';
        return ['url' => $ogVideo, 'type' => $type];
    }

    return null;
}

function searchForVideoUrls(mixed $data, int $depth = 0): ?array
{
    if ($depth > 30 || !is_array($data)) return null;

    if (isset($data['videos']['video_list']) && is_array($data['videos']['video_list'])) {
        $best = null;
        $bestH = 0;
        foreach ($data['videos']['video_list'] as $key => $entry) {
            if (!isset($entry['url'])) continue;
            $h = (int)($entry['height'] ?? 0);
            if ($h > $bestH) {
                $bestH = $h;
                $best  = $entry;
            }
        }
        if ($best) {
            $url  = $best['url'];
            $type = str_contains($url, '.m3u8') ? 'm3u8' : 'mp4';
            return ['url' => $url, 'type' => $type];
        }
    }

    if (isset($data['url']) && is_string($data['url'])) {
        $url = $data['url'];
        if (str_contains($url, '.mp4') || str_contains($url, '.m3u8')) {
            $type = str_contains($url, '.m3u8') ? 'm3u8' : 'mp4';
            return ['url' => $url, 'type' => $type];
        }
    }

    foreach ($data as $val) {
        if (is_array($val)) {
            $hit = searchForVideoUrls($val, $depth + 1);
            if ($hit) return $hit;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$rawUrl = trim($input['url'] ?? '');

if (empty($rawUrl)) {
    errorJson('No URL provided.');
}

if (!filter_var($rawUrl, FILTER_VALIDATE_URL)) {
    errorJson('Invalid URL format.');
}

$host = parse_url($rawUrl, PHP_URL_HOST);
if (!$host || !preg_match('/pinterest\.|pin\.it/i', $host)) {
    errorJson('Only Pinterest URLs are supported.');
}

$html = fetchPage($rawUrl);

$title     = metaContent($html, 'og:title') ?: metaContent($html, 'twitter:title') ?: 'Pinterest Video';
$thumbnail = metaContent($html, 'og:image') ?: metaContent($html, 'twitter:image');
$desc      = metaContent($html, 'og:description') ?: '';

$title = preg_replace('/\s*\|\s*Pinterest\s*$/i', '', $title);

$video = extractVideo($html, $rawUrl);

if (!$video) {
    errorJson('Could not find a video on this Pinterest page. Make sure the Pin contains a video (not just an image).');
}

echo json_encode([
    'success'   => true,
    'title'     => $title,
    'desc'      => $desc,
    'thumbnail' => $thumbnail,
    'video_url' => $video['url'],
    'video_type'=> $video['type'],
]);
