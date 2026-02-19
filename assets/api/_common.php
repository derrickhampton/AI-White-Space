<?php
declare(strict_types=1);

/**
 * Shared hardened helpers + core logic for HiddenChars Humanize APIs.
 * Place in: /api/_common.php
 */

function hc_send_json(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Cache-Control: no-store, max-age=0');
  // If you serve via HTTPS, consider also:
  // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function hc_fail(string $msg, int $status = 400): void {
  hc_send_json(['ok' => false, 'error' => $msg], $status);
}

function hc_read_json_body(int $maxBytes = 200_000): array {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hc_fail('Method not allowed', 405);
  }

  $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($len <= 0 || $len > $maxBytes) {
    hc_fail('Invalid or too large request body', 413);
  }

  $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
  if ($raw === false || $raw === '') {
    hc_fail('Empty body', 400);
  }
  if (strlen($raw) > $maxBytes) {
    hc_fail('Body too large', 413);
  }

  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  if ($ct !== '' && strpos($ct, 'application/json') === false) {
    hc_fail('Content-Type must be application/json', 415);
  }

  try {
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  } catch (\Throwable $e) {
    hc_fail('Malformed JSON', 400);
  }

  return is_array($data) ? $data : [];
}

function hc_sanitize_text($text, int $maxChars = 20_000): string {
  if (!is_string($text)) return '';
  // strip null bytes
  $text = str_replace("\0", '', $text);

  // hard cap characters
  if (function_exists('mb_substr')) {
    if (mb_strlen($text, 'UTF-8') > $maxChars) {
      $text = mb_substr($text, 0, $maxChars, 'UTF-8');
    }
  } else {
    if (strlen($text) > $maxChars) $text = substr($text, 0, $maxChars);
  }

  // normalize newlines
  $text = str_replace(["\r\n", "\r"], "\n", $text);

  return trim($text);
}

function hc_rate_limit(string $key = 'default', int $maxPerMin = 60): void {
  // Lightweight file-based limiter (works on shared hosting).
  // For higher traffic, swap to Redis/APCu.
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $bucket = sys_get_temp_dir() . '/hc_rl_' . sha1($key . '|' . $ip) . '.json';

  $now = time();
  $window = 60;

  $state = ['t' => $now, 'c' => 0];
  if (is_file($bucket)) {
    $raw = @file_get_contents($bucket);
    if ($raw) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded) && isset($decoded['t'], $decoded['c'])) {
        $state = $decoded;
      }
    }
  }

  // reset each window
  if (($now - (int)$state['t']) >= $window) {
    $state = ['t' => $now, 'c' => 0];
  }

  $state['c'] = (int)$state['c'] + 1;

  // persist
  @file_put_contents($bucket, json_encode($state), LOCK_EX);

  if ($state['c'] > $maxPerMin) {
    hc_fail('Too many requests', 429);
  }
}

function hc_clamp(float $n, float $min, float $max): float {
  return max($min, min($max, $n));
}

function hc_mb_lower(string $s): string {
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function hc_mb_upper(string $s): string {
  return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

/* ============================================================
 * AI likelihood (ported from JS)
 * ============================================================ */
function analyze_ai_likelihood(string $text): array {
  $t = trim($text);
  if ($t === '') return ['score' => 0, 'band' => '—', 'reasons' => []];

  $lower = hc_mb_lower($t);

  $strongPhrases = [
    "as an ai language model", "as an ai", "i don't have access to real-time data",
    "it is important to note that", "it is worth noting that", "key takeaways",
    "in conclusion", "to summarize", "in summary"
  ];

  $weakPhrases = [
    "furthermore", "moreover", "additionally", "therefore", "thus",
    "overall", "robust", "comprehensive", "delve into", "dive into"
  ];

  $strongHits = 0; $weakHits = 0;
  foreach ($strongPhrases as $p) if (strpos($lower, $p) !== false) $strongHits++;
  foreach ($weakPhrases as $p) if (strpos($lower, $p) !== false) $weakHits++;

  preg_match_all("/[A-Za-z0-9']+/u", $t, $mWords);
  $words = $mWords[0] ?? [];
  $wordCount = count($words);

  $sentences = preg_split('/(?<=[.!?])(?:\s+|\n+)/u', str_replace("\r\n", "\n", $t));
  $sentences = array_values(array_filter(array_map('trim', $sentences ?? []), fn($s) => $s !== ''));
  $sentenceCount = count($sentences);

  // uniformity
  $sentLens = [];
  foreach ($sentences as $s) {
    preg_match_all("/[A-Za-z0-9']+/u", $s, $mm);
    $n = count($mm[0] ?? []);
    if ($n > 0) $sentLens[] = $n;
  }
  $avgLen = 0.0;
  if (count($sentLens) > 0) $avgLen = array_sum($sentLens) / count($sentLens);

  $variance = 0.0;
  if (count($sentLens) > 0) {
    foreach ($sentLens as $n) $variance += pow($n - $avgLen, 2);
    $variance /= count($sentLens);
  }
  $stdDev = sqrt($variance);
  $uniformity = $avgLen > 0 ? (1 - hc_clamp($stdDev / $avgLen, 0, 1)) : 0; // 0..1

  // transitions
  preg_match_all('/\b(furthermore|moreover|additionally|therefore|however|thus|consequently)\b/ui', $lower, $mmT);
  $transitions = count($mmT[0] ?? []);
  $transitionRate = $wordCount > 0 ? ($transitions / $wordCount) : 0;

  // bullets
  preg_match_all('/^\s*([-*•]|\d+\.)\s+/m', $t, $mmB);
  $bulletLines = count($mmB[0] ?? []);

  // repeated trigrams
  $tokens = array_map(fn($w) => hc_mb_lower($w), $words);
  $tri = [];
  for ($i = 0; $i < count($tokens) - 2; $i++) {
    $g = $tokens[$i] . ' ' . $tokens[$i+1] . ' ' . $tokens[$i+2];
    $tri[$g] = ($tri[$g] ?? 0) + 1;
  }
  $repeatedTrigrams = 0;
  foreach ($tri as $v) if ($v >= 3) $repeatedTrigrams++;

  $score = 0.0;
  $reasons = [];

  $strongScore = hc_clamp($strongHits * 18, 0, 45);
  $score += $strongScore;
  if ($strongHits >= 2) $reasons[] = "multiple AI-style boilerplate phrases";
  else if ($strongHits === 1) $reasons[] = "AI-style boilerplate phrase";

  $weakScore = hc_clamp($weakHits * 3, 0, 12);
  $score += $weakScore;
  if ($weakHits >= 4) $reasons[] = "heavy formal transition phrasing";

  if ($sentenceCount >= 6) {
    $score += hc_clamp($uniformity * 12, 0, 12);
    if ($uniformity > 0.78) $reasons[] = "very uniform sentence lengths";
  }

  $score += hc_clamp(($transitionRate / 0.02) * 16, 0, 16);
  if ($transitionRate > 0.018 && $wordCount >= 120) $reasons[] = "very frequent transition words";

  if ($bulletLines >= 5) { $score += 8; $reasons[] = "list-heavy structure"; }
  else if ($bulletLines >= 3) { $score += 4; }

  $score += hc_clamp($repeatedTrigrams * 2.5, 0, 10);
  if ($repeatedTrigrams >= 3) $reasons[] = "repetitive phrasing";

  // damping
  if ($wordCount < 200) $score *= 0.85;
  if ($wordCount < 120) $score *= 0.70;
  if ($wordCount < 60)  $score *= 0.50;
  if ($wordCount < 30)  $score *= 0.35;

  $score = (int)round(hc_clamp($score, 0, 100));

  $band = "Low";
  if ($score >= 80) $band = "High";
  else if ($score >= 50) $band = "Medium";

  if ($band === "High") {
    $hasStrong = $strongHits >= 2;
    $longEnough = $wordCount >= 180;
    $multiSignal = ($weakHits >= 4) || ($transitionRate > 0.018) || ($repeatedTrigrams >= 3);
    if (!$hasStrong && !($longEnough && $multiSignal)) $band = "Medium";
  }

  // de-dupe reasons
  $reasons = array_values(array_unique($reasons));

  return ['score' => $score, 'band' => $band, 'reasons' => $reasons];
}

/* ============================================================
 * Humanize (ported from JS — relevant logic only)
 * ============================================================ */
function normalize_dashes(string $text): string {
  // EM DASH — (U+2014), EN DASH – (U+2013), MINUS SIGN − (U+2212)
  return preg_replace('/[\x{2014}\x{2013}\x{2212}]/u', '-', $text) ?? $text;
}

function apply_case_like(string $src, string $replacement): string {
  if ($src === '') return $replacement;

  // ALL CAPS
  if ($src === hc_mb_upper($src)) return hc_mb_upper($replacement);

  // Title Case
  $first = function_exists('mb_substr') ? mb_substr($src, 0, 1, 'UTF-8') : substr($src, 0, 1);
  $rest  = function_exists('mb_substr') ? mb_substr($src, 1, null, 'UTF-8') : substr($src, 1);

  $isTitle = ($first === hc_mb_upper($first)) && ($rest === hc_mb_lower($rest));
  if ($isTitle) {
    if ($replacement === '') return $replacement;
    $rFirst = function_exists('mb_substr') ? mb_substr($replacement, 0, 1, 'UTF-8') : substr($replacement, 0, 1);
    $rRest  = function_exists('mb_substr') ? mb_substr($replacement, 1, null, 'UTF-8') : substr($replacement, 1);
    return hc_mb_upper($rFirst) . $rRest;
  }

  // all lower
  if ($src === hc_mb_lower($src)) return hc_mb_lower($replacement);

  // mixed
  return $replacement;
}

function replace_preserve_case(string $text, string $pattern, $out): string {
  return preg_replace_callback($pattern, function($m) use ($out) {
    $match = $m[0] ?? '';
    $raw = '';
    if (is_callable($out)) {
      $raw = (string)call_user_func_array($out, $m);
    } else {
      $raw = (string)$out;
    }
    return apply_case_like((string)$match, (string)$raw);
  }, $text) ?? $text;
}

function apply_swaps(string $text): string {
  $swaps = [
    // AI disclaimers
    ['re' => '/\bAs an AI language model\b[:, ]*/iu', 'out' => ""],
    ['re' => '/\bAs an AI\b[:, ]*/iu', 'out' => ""],

    // wrappers
    ['re' => '/\bIn conclusion\b[:, ]*/iu', 'out' => ""],
    ['re' => '/\bIn summary\b[:, ]*/iu', 'out' => ""],
    ['re' => '/\bTo summarize\b[:, ]*/iu', 'out' => ""],
    ['re' => '/\bIt is important to note that\b\s+/iu', 'out' => ""],
    ['re' => '/\bIt is worth noting that\b\s+/iu', 'out' => "Notably, "],
    ['re' => '/\bIn order to\b/iu', 'out' => "to"],
    ['re' => '/\bDue to the fact that\b/iu', 'out' => "because"],
    ['re' => '/\bIn the event that\b/iu', 'out' => "if"],

    // transitions
    ['re' => '/\bFurthermore\b/iu', 'out' => "also"],
    ['re' => '/\bMoreover\b/iu', 'out' => "plus"],
    ['re' => '/\bAdditionally\b/iu', 'out' => "also"],
    ['re' => '/\bHowever\b/iu', 'out' => "but"],
    ['re' => '/\bNevertheless\b/iu', 'out' => "still"],
    ['re' => '/\bOn the other hand\b/iu', 'out' => "that said"],

    // corporate
    ['re' => '/\bUtilize\b/iu', 'out' => "use"],
    ['re' => '/\bLeverage\b/iu', 'out' => "use"],
    ['re' => '/\bFacilitate\b/iu', 'out' => "help"],
    ['re' => '/\bCommence\b/iu', 'out' => "start"],

    // capture group example
    [
      're' => '/\bwith regard to\s+([^,.!?;:]+)/iu',
      'out' => function($full, $g1){ return "regarding " . $g1; }
    ],

    // clean multi-spaces
    ['re' => '/\s{2,}/u', 'out' => " "],
  ];

  $t = $text;
  foreach ($swaps as $s) {
    $t = replace_preserve_case($t, $s['re'], $s['out']);
  }
  return $t;
}

function split_sentences(string $text): array {
  $normalized = trim(
    preg_replace("/\n{3,}/u", "\n\n",
      preg_replace("/\s+\n/u", "\n",
        str_replace(["\r\n","\r"], "\n", $text)
      )
    ) ?? $text
  );

  $parts = preg_split('/(?<=[.!?])(?:\s+|\n+)/u', $normalized);
  $parts = array_values(array_filter(array_map('trim', $parts ?? []), fn($s) => $s !== ''));
  return $parts;
}

function join_sentences(array $sentences): string {
  $joined = trim(preg_replace('/\s+/u', ' ', implode(' ', $sentences)) ?? implode(' ', $sentences));
  return $joined;
}

function apply_contractions(string $text): string {
  $contractions = [
    ['re' => '/\bdo not\b/iu', 'out' => "don't"],
    ['re' => '/\bdoes not\b/iu', 'out' => "doesn't"],
    ['re' => '/\bdid not\b/iu', 'out' => "didn't"],
    ['re' => '/\bcannot\b/iu', 'out' => "can't"],
    ['re' => '/\bwill not\b/iu', 'out' => "won't"],
    ['re' => '/\bis not\b/iu', 'out' => "isn't"],
    ['re' => '/\bare not\b/iu', 'out' => "aren't"],
    ['re' => '/\bshould not\b/iu', 'out' => "shouldn't"],
    ['re' => '/\bwould not\b/iu', 'out' => "wouldn't"],
    ['re' => '/\bcould not\b/iu', 'out' => "couldn't"],
    ['re' => '/\bI am\b/iu', 'out' => "I'm"],
    ['re' => '/\bI have\b/iu', 'out' => "I've"],
    ['re' => '/\bwe are\b/iu', 'out' => "we're"],
    ['re' => '/\byou are\b/iu', 'out' => "you're"],
    ['re' => '/\bthey are\b/iu', 'out' => "they're"],
  ];

  $t = $text;
  foreach ($contractions as $c) $t = replace_preserve_case($t, $c['re'], $c['out']);
  return $t;
}

function ensure_sentence_capitalization(string $s): string {
  return preg_replace_callback('/^\s*([a-z])/u', fn($m) => hc_mb_upper($m[1]), $s) ?? $s;
}

function de_duplicate_transitions(array $sentences): array {
  $seenAdd = 0; $seenBut = 0;
  $out = [];

  foreach ($sentences as $s) {
    $x = $s;

    if (preg_match('/^(Moreover|Furthermore|Additionally|In addition|Also)\b/iu', $x)) {
      $seenAdd++;
      if ($seenAdd > 1) {
        $x = preg_replace('/^(Moreover|Furthermore|Additionally|In addition|Also)\b[:,]?\s+/iu', '', $x) ?? $x;
      }
    }

    if (preg_match('/^(However|That said|On the other hand)\b/iu', $x)) {
      $seenBut++;
      if ($seenBut > 1) {
        $x = preg_replace('/^(However|That said|On the other hand)\b[:,]?\s+/iu', 'Still, ', $x) ?? $x;
      }
    }

    $x = preg_replace('/^(However)\b[:,]?\s+/iu', 'But ', $x) ?? $x;
    $x = preg_replace('/^(Furthermore|Additionally|In addition)\b[:,]?\s+/iu', 'Also, ', $x) ?? $x;
    $x = preg_replace('/^(Moreover)\b[:,]?\s+/iu', 'Plus, ', $x) ?? $x;

    $x = ensure_sentence_capitalization($x);
    $out[] = trim($x);
  }

  return $out;
}

function soften_run_ons(array $sentences, string $strength): array {
  $maxLen = ($strength === 'strong') ? 170 : (($strength === 'medium') ? 200 : 240);
  $splitRe = '/\s+(and|but|so|because|which|while)\s+/iu';

  $out = [];
  foreach ($sentences as $s) {
    if (mb_strlen($s, 'UTF-8') <= $maxLen) { $out[] = $s; continue; }

    $parts = preg_split($splitRe, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (is_array($parts) && count($parts) >= 3) {
      $first = trim(($parts[0] ?? '') . ' ' . ($parts[1] ?? '') . ' ' . ($parts[2] ?? ''));
      $rest = trim(implode(' ', array_slice($parts, 3)));

      if (!preg_match('/[.!?]$/u', $first)) $first .= '.';
      $out[] = $first;

      if ($rest !== '') {
        $out[] = ensure_sentence_capitalization($rest);
      }
    } else {
      $out[] = $s;
    }
  }
  return $out;
}

function remove_wrapper_phrases(string $text): string {
  $t = trim($text);
  $t = preg_replace('/^\s*(in conclusion|to summarize|in summary)\b[:,]?\s*/iu', '', $t) ?? $t;
  $t = preg_replace('/\s*(in conclusion|to summarize|in summary)\b[:,]?\s*$/iu', '', $t) ?? $t;
  return trim($t);
}

function context_aware_rewrite(string $text, array $opts): string {
  $t = remove_wrapper_phrases($text);

  $sentences = split_sentences($t);
  $sentences = de_duplicate_transitions($sentences);

  if (!empty($opts['contractions'])) {
    $sentences = array_map('apply_contractions', $sentences);
  }
  if (!empty($opts['softenRunOns'])) {
    $strength = (string)($opts['strength'] ?? 'light');
    $sentences = soften_run_ons($sentences, $strength);
  }

  return join_sentences($sentences);
}

function humanize_text(string $input, string $tone, string $strength, bool $contractions, bool $softenRunOns): string {
  $text = trim($input);
  $text = normalize_dashes($text);

  $text = apply_swaps($text);

  $opts = [
    'strength' => $strength,
    'contractions' => $contractions,
    'softenRunOns' => $softenRunOns
  ];
  $text = context_aware_rewrite($text, $opts);

  // tone hints (kept minimal, like your JS)
  if ($tone === 'professional') {
    $text = preg_replace('/🙂|😄|😁|😊/u', '', $text) ?? $text;
  } elseif ($tone === 'confident') {
    $text = preg_replace_callback('/\b(might|may|could)\b/iu', fn($m) => apply_case_like($m[0], 'can'), $text) ?? $text;
    $text = preg_replace_callback('/\bperhaps\b/iu', fn($m) => apply_case_like($m[0], ''), $text) ?? $text;
    $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
  } elseif ($tone === 'concise') {
    $text = preg_replace('/\b(very|really|actually|basically)\b/iu', '', $text) ?? $text;
    $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
  }

  // strength whitespace normalize
  if ($strength === 'strong') {
    $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
  } elseif ($strength === 'medium') {
    $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
    $text = preg_replace("/\n{4,}/u", "\n\n\n", $text) ?? $text;
  }

  return $text;
}

// === API KEY STORAGE ===

// Change this to a non-web-accessible location:
function hc_key_store_path(): string {
  $preferred = dirname(__DIR__, 1) . '/../private/hc_keys.json'; // adjust if needed
  if (is_dir(dirname($preferred))) return $preferred;

  // fallback (less ideal)
  return sys_get_temp_dir() . '/hc_keys.json';
}

function hc_load_keys(): array {
  $path = hc_key_store_path();
  if (!is_file($path)) return ['keys' => []];

  $raw = @file_get_contents($path);
  if (!$raw) return ['keys' => []];

  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
    return ['keys' => []];
  }
  return $data;
}

function hc_save_keys(array $data): void {
  $path = hc_key_store_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0700, true);

  // permissions: owner-only read/write
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) hc_fail("Failed to save key store", 500);

  $tmp = $path . '.tmp';
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) hc_fail("Failed to write key store", 500);
  @chmod($tmp, 0600);
  @rename($tmp, $path);
  @chmod($path, 0600);
}

function hc_generate_api_key(): string {
  // 32 bytes => 64 hex chars
  return bin2hex(random_bytes(32));
}

function hc_hash_key(string $key): string {
  // store only hashes (never plaintext keys)
  return hash('sha256', $key);
}

function hc_get_header(string $name): string {
  $nameLower = strtolower($name);

  // Most hosts expose HTTP_* for headers
  foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
      $headerName = strtolower(str_replace('_', '-', substr($k, 5)));
      if ($headerName === $nameLower) return (string)$v;
    }
  }

  // Fallback direct
  $direct = $_SERVER[$name] ?? '';
  return is_string($direct) ? $direct : '';
}

function hc_require_api_key(string $scope = 'default'): array {
  // Prefer header, but allow JSON body param as fallback for older clients
  $key = trim(hc_get_header('X-API-Key'));

  // Optional: allow query only for debugging (disable in prod)
  // if (!$key) $key = trim((string)($_GET['api_key'] ?? ''));

  if ($key === '') hc_fail('Missing API key', 401);
  if (strlen($key) < 40 || strlen($key) > 200) hc_fail('Invalid API key format', 401);

  $data = hc_load_keys();
  $hash = hc_hash_key($key);

  $entry = $data['keys'][$hash] ?? null;
  if (!$entry || !is_array($entry)) hc_fail('Invalid API key', 401);

  if (!empty($entry['revoked'])) hc_fail('API key revoked', 403);

  // optional scopes
  $scopes = $entry['scopes'] ?? [];
  if (!is_array($scopes)) $scopes = [];

  if ($scope !== 'default' && !in_array($scope, $scopes, true)) {
    hc_fail('API key missing required scope', 403);
  }

  // optional expiration
  if (!empty($entry['expires_at']) && time() > (int)$entry['expires_at']) {
    hc_fail('API key expired', 403);
  }

  return $entry + ['hash' => $hash];
}
