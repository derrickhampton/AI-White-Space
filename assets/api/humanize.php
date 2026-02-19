<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$k = hc_require_api_key('humanize');
hc_rate_limit('humanize|' . ($k['hash'] ?? 'nohash'), 60);

 

$data = hc_read_json_body(200_000);

$text = hc_sanitize_text($data['text'] ?? '', 20_000);
if ($text === '') hc_fail('Missing text', 400);

// Validate enums
$tone = (string)($data['tone'] ?? 'neutral');
$strength = (string)($data['strength'] ?? 'light');

$allowedTones = ['neutral','friendly','professional','confident','concise'];
$allowedStrength = ['light','medium','strong'];

if (!in_array($tone, $allowedTones, true)) $tone = 'neutral';
if (!in_array($strength, $allowedStrength, true)) $strength = 'light';

// Flags
$contractions = (bool)($data['contractions'] ?? true);
$softenRunOns = (bool)($data['softenRunOns'] ?? true);

// Run
$humanized = humanize_text($text, $tone, $strength, $contractions, $softenRunOns);

hc_send_json([
  'ok' => true,
  'humanized' => $humanized
]);
