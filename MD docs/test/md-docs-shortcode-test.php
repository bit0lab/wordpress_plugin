<?php

declare(strict_types=1);

$test_root = sys_get_temp_dir() . '/md-docs-test-' . bin2hex(random_bytes(4));
$GLOBALS['md_docs_test_root'] = $test_root;
$GLOBALS['md_docs_test_allowed'] = [];
$GLOBALS['md_docs_test_repository_names'] = [];

function add_action(...$args): void {}
function add_filter(...$args): void {}
function add_shortcode(...$args): void {}
function register_activation_hook(...$args): void {}
function register_deactivation_hook(...$args): void {}
function wp_enqueue_style(...$args): void {}
function shortcode_atts(array $defaults, array $attributes): array { return array_merge($defaults, $attributes); }
function wp_upload_dir(): array { return ['basedir' => $GLOBALS['md_docs_test_root'], 'baseurl' => 'https://example.test/uploads']; }
function trailingslashit(string $path): string { return rtrim($path, '/') . '/'; }
function get_transient(string $key): mixed { return false; }
function set_transient(...$args): bool { return true; }
function get_option(string $key, mixed $default = false): mixed
{
    return match ($key) {
        MD_Docs_Settings::OPTION_ALLOWED_FILES => $GLOBALS['md_docs_test_allowed'] ?? $default,
        MD_Docs_Settings::OPTION_REPOSITORY_NAMES => $GLOBALS['md_docs_test_repository_names'] ?? $default,
        default => $default,
    };
}
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr(string $value): string { return esc_html($value); }
function esc_url(string $value): string { return $value; }
function wp_kses_allowed_html(string $context): array { return []; }
function wp_kses(string $html, array $allowed): string { return $html; }

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/md-docs/md-docs.php';

function assert_contains(string $needle, string $actual, string $message): void
{
    if (!str_contains($actual, $needle)) {
        throw new RuntimeException($message);
    }
}

function remove_test_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir()) {
            remove_test_tree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    mkdir($test_root . '/docs/repo-A', 0777, true);
    mkdir($test_root . '/docs/repo-B', 0777, true);
    file_put_contents($test_root . '/docs/repo-A/README.md', '# README');
    file_put_contents(
        $test_root . '/docs/repo-A/操作手順.md',
        "\xEF\xBB\xBF# 操作手順\n\n| 機能 | 内容 |\n| --- | --- |\n| 連携 | **自動**で実行 |"
    );
    file_put_contents($test_root . '/docs/repo-B/README.md', '# README');

    $GLOBALS['md_docs_test_allowed'] = ['repo-A' => ['操作手順.md'], 'repo-B' => []];
    $html = MD_Docs::shortcode();
    assert_contains('/docs/repo-A/%E6%93%8D%E4%BD%9C%E6%89%8B%E9%A0%86/', $html, '許可済みファイルへのリンクがありません。');
    if (str_contains($html, '/docs/repo-B/')) {
        throw new RuntimeException('許可済みファイルがないリポジトリが表示されています。');
    }

    $html = MD_Docs::shortcode(['repo' => 'repo-A']);
    assert_contains('<h1>操作手順</h1>', $html, 'README.md なしで既定ドキュメントを表示できません。');
    if (substr_count($html, '<h1>操作手順</h1>') !== 2) {
        throw new RuntimeException('BOM付きMarkdownの先頭見出しを変換できません。');
    }
    assert_contains('<table><thead><tr>', $html, 'Markdownテーブルを変換できません。');
    assert_contains('<th>機能</th>', $html, 'Markdownテーブルの見出しを変換できません。');
    assert_contains('<td><strong>自動</strong>で実行</td>', $html, 'Markdownテーブル内のインライン記法を変換できません。');
    assert_contains('<h2><a href="https://example.test/docs/repo-A/">repo-A</a></h2>', $html, '表示名が未設定のときにフォルダー名が表示されません。');

    $GLOBALS['md_docs_test_allowed'] = ['repo-A' => ['README.md'], 'repo-B' => []];
    $GLOBALS['md_docs_test_repository_names'] = ['repo-A' => '製品A ドキュメント'];
    $html = MD_Docs::shortcode();
    assert_contains('<strong>製品A ドキュメント</strong>', $html, 'リポジトリ一覧に表示名が反映されていません。');
    assert_contains('/docs/repo-A/', $html, '表示名の設定によってURLが変更されています。');

    $html = MD_Docs::shortcode(['repo' => 'repo-A']);
    assert_contains('<h1>製品A ドキュメント</h1>', $html, 'READMEのタイトルに表示名が反映されていません。');
    assert_contains('<h2><a href="https://example.test/docs/repo-A/">製品A ドキュメント</a></h2>', $html, 'サイドバーに表示名が反映されていません。');

    $html = MD_Docs::shortcode(['repo' => '*', 'path' => 'README']);
    assert_contains('<h2><a href="https://example.test/docs/repo-A/README/">製品A ドキュメント</a></h2>', $html, '横断表示に表示名が反映されていません。');

    echo "OK\n";
} finally {
    remove_test_tree($test_root);
}
