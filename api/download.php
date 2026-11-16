<?php
$rawUrl = trim($_GET['url'] ?? '');
if (empty($rawUrl)) {
    http_response_code(400);
    exit('Missing url parameter.');
}
$url = filter_var($rawUrl, FILTER_VALIDATE_URL);
if (!$url) {
    http_response_code(400);
    exit('Invalid URL.');
}
$host = strtolower(parse_url($url, PHP_URL_HOST));
$allowed = [
    'v.pinimg.com',
    'i.pinimg.com',
    'video.pinimg.com',
    'pinimg.com',
];
$isAllowed = false;
foreach ($allowed as $domain) {
    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        $isAllowed = true;
        break;
    }
}
if (!$isAllowed) {
    http_response_code(403);
    exit('Domain not allowed.');
}
$path     = parse_url($url, PHP_URL_PATH);
$basename = basename($path) ?: 'pindrop_video';
$basename = preg_replace('/\?.*$/', '', $basename);
if (str_contains($url, '.m3u8')) {
    $mime     = 'application/vnd.apple.mpegurl';
    $ext      = '.m3u8';
} else {
    $mime     = 'video/mp4';
    $ext      = '.mp4';
}
if (!preg_match('/\.(mp4|m3u8|mov|webm)$/i', $basename)) {
    $basename .= $ext;
}
$filename = 'PinDrop_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $basename);
$rangeHeader = '';
if (!empty($_SERVER['HTTP_RANGE'])) {
    $rangeHeader = 'Range: ' . $_SERVER['HTTP_RANGE'];
}
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_NOBODY         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
curl_exec($ch);
$contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$remoteCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');
if ($contentLength > 0) {
    header('Content-Length: ' . (int)$contentLength);
}
$headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
if ($rangeHeader) {
    $headers[] = $rangeHeader;
}
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_BUFFERSIZE     => 128 * 1024,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        flush();
        if (connection_aborted()) return -1;
        return strlen($data);
    },
]);
curl_exec($ch);
curl_close($ch);
