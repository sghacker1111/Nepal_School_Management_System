<?php
/** Public build assets only. Never reads or changes the school database. */
function schoolAsset(string $name): string {
    static $manifest = null;
    if ($manifest === null) {
        $path = __DIR__.'/../assets/dist/manifest.json';
        $manifest = is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
    }
    if (!preg_match('/^[a-z][a-z0-9.-]*$/D', $name)) throw new InvalidArgumentException('Invalid public asset name.');
    return APP_URL.'/assets/dist/'.$name.'?v='.rawurlencode((string)($manifest[$name] ?? '1'));
}

function schoolProtectForms(string $content, string $token): string {
    $field='<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.htmlspecialchars($token, ENT_QUOTES, 'UTF-8').'">';
    return preg_replace_callback('/<form\\b[^>]*\\bmethod\\s*=\\s*["\\\']?post["\\\']?[^>]*>/i', static fn($match) => $match[0].$field, $content);
}
