<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$ADMIN_PASS = getenv('HC_ADMIN_PASS') ?: '';
if ($ADMIN_PASS === '') hc_fail('Admin not configured', 500);

// Basic protection: require a header
$pass = trim(hc_get_header('X-Admin-Pass'));
if (!hash_equals($ADMIN_PASS, $pass)) hc_fail('Unauthorized', 401);

$data = hc_load_keys();
$action = $_GET['action'] ?? '';

if ($action === 'create') {
  $label = substr((string)($_GET['label'] ?? 'web'), 0, 50);
  $key = hc_generate_api_key();
  $hash = hc_hash_key($key);

  $data['keys'][$hash] = [
    'label' => $label,
    'created_at' => time(),
    'revoked' => false,
    'scopes' => ['ai_likelihood','humanize'],
    'expires_at' => 0
  ];

  hc_save_keys($data);

  // return plaintext key ONCE
  hc_send_json([
    'ok' => true,
    'api_key' => $key,
    'label' => $label
  ]);
}

if ($action === 'revoke') {
  $hash = (string)($_GET['hash'] ?? '');
  if ($hash === '' || !isset($data['keys'][$hash])) hc_fail('Unknown key hash', 404);

  $data['keys'][$hash]['revoked'] = true;
  $data['keys'][$hash]['revoked_at'] = time();
  hc_save_keys($data);

  hc_send_json(['ok' => true, 'revoked' => $hash]);
}

if ($action === 'list') {
  // Return only safe info (no secrets)
  $safe = [];
  foreach ($data['keys'] as $h => $k) {
    $safe[] = [
      'hash' => $h,
      'label' => $k['label'] ?? '',
      'created_at' => $k['created_at'] ?? 0,
      'revoked' => $k['revoked'] ?? false,
      'expires_at' => $k['expires_at'] ?? 0,
      'scopes' => $k['scopes'] ?? []
    ];
  }
  hc_send_json(['ok' => true, 'keys' => $safe]);
}

hc_send_json([
  'ok' => true,
  'usage' => [
    'create' => '/api/admin_keys.php?action=create&label=web',
    'list' => '/api/admin_keys.php?action=list',
    'revoke' => '/api/admin_keys.php?action=revoke&hash=<sha256hash>'
  ]
]);
