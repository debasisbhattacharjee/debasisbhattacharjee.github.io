<?php
/**
 * Fetches a single URL and reduces it to plain text for training.
 * Deliberately simple: one page per fetch (no recursive crawling) so a
 * customer can't accidentally hammer someone else's site or run up
 * against host execution-time limits on shared hosting.
 */

const CRAWL_MAX_BYTES = 500000;
const CRAWL_TIMEOUT = 15;

/** @return array{ok:bool, text:?string, title:?string, error:?string} */
function crawl_url(string $url): array
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true) || empty($parts['host'])) {
        return ['ok' => false, 'text' => null, 'title' => null, 'error' => 'Please enter a valid http(s) URL.'];
    }

    // Basic SSRF guard: block obviously-internal hosts. Not exhaustive, but
    // stops the common accidental cases (localhost, link-local, RFC1918).
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || preg_match('/\.(local|internal)$/', $host)) {
        return ['ok' => false, 'text' => null, 'title' => null, 'error' => 'That host is not allowed.'];
    }
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['ok' => false, 'text' => null, 'title' => null, 'error' => 'That host resolves to a private/internal address and is not allowed.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => CRAWL_TIMEOUT,
        CURLOPT_USERAGENT => APP_NAME . ' TrainingBot/1.0',
        CURLOPT_RANGE => '0-' . CRAWL_MAX_BYTES,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false) {
        return ['ok' => false, 'text' => null, 'title' => null, 'error' => 'Could not fetch that URL: ' . $curlError];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'text' => null, 'title' => null, 'error' => 'The page returned HTTP ' . $httpCode];
    }

    $title = null;
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }

    $text = html_to_text($html);
    if (strlen(trim($text)) < 40) {
        return ['ok' => false, 'text' => null, 'title' => $title, 'error' => 'Could not find readable text on that page (it may require JavaScript).'];
    }

    return ['ok' => true, 'text' => $text, 'title' => $title, 'error' => null];
}

function html_to_text(string $html): string
{
    // Drop non-content elements entirely, including their inner text.
    $html = preg_replace('/<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?<\/\1>/is', ' ', $html);
    // Turn common block-level boundaries into line breaks before stripping tags.
    $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\b[^>]*>/i', "\n", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n\s*\n\s*/", "\n\n", $text);
    return trim($text);
}
