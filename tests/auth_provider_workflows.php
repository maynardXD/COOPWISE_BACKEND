<?php

declare(strict_types=1);

define('PREVENT_DIRECT_ACCESS', true);

class Controller {}

require_once dirname(__DIR__) . '/app/controllers/ApiAuth.php';

function assertAuthValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$auth = new ReflectionClass(ApiAuth::class);
$claimsValidator = $auth->getMethod('google_claims_are_valid');
$claimsValidator->setAccessible(true);
$turnstileValidator = $auth->getMethod('turnstile_response_is_valid');
$turnstileValidator->setAccessible(true);
$publicKeyBuilder = $auth->getMethod('google_rsa_public_key');
$publicKeyBuilder->setAccessible(true);

$now = time();
$claims = [
    'iss' => 'https://accounts.google.com',
    'aud' => 'coopwise-web-client',
    'azp' => 'coopwise-web-client',
    'exp' => $now + 300,
    'iat' => $now - 10,
    'sub' => 'google-user-123',
    'email' => 'member@example.com',
    'email_verified' => true
];
assertAuthValue(
    true,
    $claimsValidator->invoke(null, $claims, 'coopwise-web-client'),
    'A verified, current Google member identity with the configured audience should pass'
);

$invalid_claims = $claims;
$invalid_claims['email_verified'] = false;
assertAuthValue(false, $claimsValidator->invoke(null, $invalid_claims, 'coopwise-web-client'), 'Unverified Google email must be rejected');
$invalid_claims = $claims;
$invalid_claims['aud'] = 'another-client';
assertAuthValue(false, $claimsValidator->invoke(null, $invalid_claims, 'coopwise-web-client'), 'A token for another OAuth client must be rejected');
$invalid_claims = $claims;
$invalid_claims['exp'] = $now - 1;
assertAuthValue(false, $claimsValidator->invoke(null, $invalid_claims, 'coopwise-web-client'), 'Expired Google ID tokens must be rejected');
$invalid_claims = $claims;
$invalid_claims['aud'] = ['coopwise-web-client', 'another-client'];
$invalid_claims['azp'] = 'another-client';
assertAuthValue(false, $claimsValidator->invoke(null, $invalid_claims, 'coopwise-web-client'), 'A mismatched authorized party must be rejected');

$valid_challenge = [
    'success' => true,
    'action' => 'register',
    'hostname' => 'coopwise-frontend.vercel.app'
];
assertAuthValue(
    true,
    $turnstileValidator->invoke(null, $valid_challenge, 'register', ['coopwise-frontend.vercel.app']),
    'A successful Turnstile response with the expected action and hostname should pass'
);
assertAuthValue(false, $turnstileValidator->invoke(null, $valid_challenge, 'login', ['coopwise-frontend.vercel.app']), 'A Turnstile action mismatch must be rejected');
assertAuthValue(false, $turnstileValidator->invoke(null, $valid_challenge, 'register', ['other.example.com']), 'A Turnstile hostname mismatch must be rejected');
$valid_challenge['success'] = false;
assertAuthValue(false, $turnstileValidator->invoke(null, $valid_challenge, 'register', ['coopwise-frontend.vercel.app']), 'An unsuccessful Turnstile response must be rejected');

$private_key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048
]);
if ($private_key === false) {
    throw new RuntimeException('Could not generate an RSA key for the Google signing-key test.');
}
$details = openssl_pkey_get_details($private_key);
$to_base64url = static function (string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
$test_jwk = [
    'kty' => 'RSA',
    'alg' => 'RS256',
    'use' => 'sig',
    'kid' => 'test-key',
    'n' => $to_base64url($details['rsa']['n']),
    'e' => $to_base64url($details['rsa']['e'])
];
$public_key = $publicKeyBuilder->invoke(null, $test_jwk);
if ($public_key === false) {
    throw new RuntimeException('The Google JWK could not be converted into a public key.');
}
$message = 'google-id-token-test';
if (!openssl_sign($message, $signature, $private_key, OPENSSL_ALGO_SHA256)
    || openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256) !== 1) {
    throw new RuntimeException('A signature made by the test Google key did not verify.');
}
assertAuthValue(
    1,
    openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256),
    'The generated Google RSA public key should verify a valid signature'
);

echo "Auth provider workflow tests passed.\n";
