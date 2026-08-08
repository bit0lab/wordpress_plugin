<?php

declare(strict_types=1);

$GLOBALS['md_docs_test_actions'] = [];
$GLOBALS['md_docs_test_reloaded'] = false;

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['md_docs_test_actions'][$hook] = $callback;
}
function current_user_can(string $capability): bool { return $capability === 'manage_options'; }
function check_admin_referer(string $action): void
{
    if ($action !== 'md_docs_reload_files') {
        throw new RuntimeException('nonce のアクションが不正です。');
    }
}
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect(string $url): never
{
    if ($url !== 'https://example.test/wp-admin/options-general.php?page=md-docs') {
        throw new RuntimeException('リダイレクト先が不正です。');
    }
    throw new LogicException('redirected');
}
function wp_die(string $message): never { throw new RuntimeException($message); }

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/md-docs/class-md-docs-settings.php';

MD_Docs_Settings::init(
    static fn(): array => [],
    static function (): bool {
        $GLOBALS['md_docs_test_reloaded'] = true;
        return true;
    }
);

$hook = $GLOBALS['md_docs_test_actions']['admin_post_md_docs_reload_files'] ?? null;
if (!is_callable($hook)) {
    throw new RuntimeException('再読み込みアクションが登録されていません。');
}

try {
    $hook();
    throw new RuntimeException('再読み込み後にリダイレクトされませんでした。');
} catch (LogicException $exception) {
    if ($exception->getMessage() !== 'redirected') {
        throw $exception;
    }
}

if (!$GLOBALS['md_docs_test_reloaded']) {
    throw new RuntimeException('再読み込みコールバックが実行されていません。');
}

echo "OK\n";
