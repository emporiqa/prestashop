<?php
/**
 * Checks EmporiqaSignatureHelper against the platform's scheme-2 vectors
 * (tests/fixtures/signature_vectors.json, byte-identical to the Emporiqa
 * repo's copy): for every label (sync webhooks, rule calls, rule answers)
 * every key, signature and header must match, and every negative vector
 * must be refused. A rule call signed during a secret rotation carries two
 * v1 values, and the shop must accept it holding either secret; the
 * rotation vectors pin that, the cap of three, and that one malformed v1
 * still refuses the header. Also pins the customer token shape (`aud`,
 * integer `ts`).
 *
 * Self-contained (no PHPUnit, no PrestaShop). Run: php tests/SignatureVectorsTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');

require dirname(__DIR__) . '/classes/EmporiqaSignatureHelper.php';

$failures = 0;
$checks = 0;

function check($ok, $label)
{
    global $failures, $checks;
    ++$checks;
    if (!$ok) {
        ++$failures;
        echo 'FAIL ' . $label . "\n";
    }
}

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/signature_vectors.json'), true);
check($fixture['salt'] === EmporiqaSignatureHelper::SALT, 'salt');

$labels = [
    EmporiqaSignatureHelper::LABEL_INBOUND,
    EmporiqaSignatureHelper::LABEL_OUTBOUND,
    EmporiqaSignatureHelper::LABEL_RESPONSE,
];
$seen = [];
foreach ($fixture['vectors'] as $i => $v) {
    $label = $v['label'];
    check(in_array($label, $labels, true), "vector $i has a known label");
    if (preg_match('/[^\x00-\x7f]/', $v['secret'])) {
        $seen[$label] = true;
    }
    // A response signature covers request_id . "." . body.
    $message = $label === EmporiqaSignatureHelper::LABEL_RESPONSE ? $v['request_id'] . '.' . $v['body'] : $v['body'];
    $key = EmporiqaSignatureHelper::deriveKey($v['secret'], $label, $v['store_id']);
    check(bin2hex($key) === $v['expected_key_hex'], "vector $i key");
    $header = EmporiqaSignatureHelper::buildHeader($key, $message, $v['t']);
    check($header === $v['expected_header'], "vector $i header");
    check(substr($header, strpos($header, 'v1=') + 3) === $v['expected_signature'], "vector $i signature");
    check(
        EmporiqaSignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t']) === 'ok',
        "vector $i verifies",
    );
    check(
        EmporiqaSignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t'] + 301) === 'expired',
        "vector $i expires after 5 minutes",
    );
    check(
        EmporiqaSignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t'] - 300) === 'ok',
        "vector $i accepted at -5 minutes",
    );
    foreach (array_diff($labels, [$label]) as $other) {
        check(
            EmporiqaSignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $other, $v['t']) === 'signature',
            "vector $i refused under label $other",
        );
    }
}
foreach ($labels as $label) {
    check(isset($seen[$label]), "a non-ASCII secret vector for $label");
}

foreach ($fixture['negative_vectors'] as $v) {
    // Refused by Emporiqa's single-v1 webhook verifier; ours accepts several.
    if (!empty($v['single_v1_only'])) {
        continue;
    }
    check(
        EmporiqaSignatureHelper::verifyHeader(
            $v['header'],
            $v['body'],
            $v['secret'],
            $v['store_id'],
            EmporiqaSignatureHelper::LABEL_INBOUND,
            $v['now'],
        ) !== 'ok',
        'negative: ' . $v['case'],
    );
}

check(count($fixture['rotation_vectors']) > 0, 'rotation vectors present');
foreach ($fixture['rotation_vectors'] as $v) {
    foreach ($v['verify_with'] as $secret) {
        check(
            EmporiqaSignatureHelper::verifyHeader($v['header'], $v['body'], $secret, $v['store_id'], $v['label'], $v['t']) === $v['expect'],
            'rotation: ' . $v['case'],
        );
    }
}
$rotation = $fixture['rotation_vectors'][0];
check(
    EmporiqaSignatureHelper::verifyHeader($rotation['header'], $rotation['body'], $rotation['verify_with'][0], $rotation['store_id'], $rotation['label'], $rotation['t'] + 301) === 'expired',
    'rotation: a two-v1 header still expires after 5 minutes',
);

// The response signature covers t . "." . request_id . "." . body.
$key = EmporiqaSignatureHelper::deriveKey('s', EmporiqaSignatureHelper::LABEL_RESPONSE, 'st');
check(
    EmporiqaSignatureHelper::buildHeader($key, 'rid.{"status":"found"}', 100)
        === 't=100,v1=' . hash_hmac('sha256', '100.rid.{"status":"found"}', $key),
    'response signature message',
);

$token = EmporiqaSignatureHelper::generateUserToken('77', 'secret', 'st_7Kq2mXa9');
list($encoded, $mac) = explode('.', $token);
$claims = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
check($mac === hash_hmac('sha256', $encoded, 'secret'), 'token signed with the raw secret');
check($claims['uid'] === '77' && $claims['aud'] === 'st_7Kq2mXa9', 'token uid and aud');
check(is_int($claims['ts']) && abs($claims['ts'] - time()) < 5, 'token ts is an integer now');

echo ($failures === 0 ? 'OK' : 'FAILED') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
