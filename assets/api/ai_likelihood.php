<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$k = hc_require_api_key('ai_likelihood');
hc_rate_limit('ai_likelihood|' . ($k['hash'] ?? 'nohash'), 120);


 

$data = hc_read_json_body(80_000);

$text = hc_sanitize_text($data['text'] ?? '', 20_000);
if ($text === '') hc_fail('Missing text', 400);

$result = analyze_ai_likelihood($text);

hc_send_json([
  'ok' => true,
  'score' => $result['score'],
  'band' => $result['band'],
  'reasons' => $result['reasons'],
]);
