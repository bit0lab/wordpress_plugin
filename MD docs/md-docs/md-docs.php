<?php
/**
 * Plugin Name: MD Docs
 * Description: uploads/docs 配下の Markdown ドキュメントを自動検出して表示します。
 * Version: 1.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: bit0ラボ
 * License: GPL-2.0-or-later
 * Text Domain: md-docs
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MD_Docs
{
    private const VERSION = '1.3.0';
    private const CACHE_TTL = 300;
    private const QUERY_FLAG = 'md_docs_view';
    private const OPTION_ALLOWED_FILES = 'md_docs_allowed_files';
    private const SETTINGS_GROUP = 'md_docs_settings';

    public static function init(): void
    {
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_filter('query_vars', [self::class, 'add_query_vars']);
        add_action('template_redirect', [self::class, 'render_route']);
        add_shortcode('md_docs', [self::class, 'shortcode']);
        add_action('admin_menu', [self::class, 'add_settings_page']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function activate(): void
    {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function add_rewrite_rules(): void
    {
        add_rewrite_rule('^docs/?$', 'index.php?' . self::QUERY_FLAG . '=1', 'top');
        add_rewrite_rule(
            '^docs/([^/]+)(?:/(.*))?/?$',
            'index.php?' . self::QUERY_FLAG . '=1&md_docs_repo=$matches[1]&md_docs_path=$matches[2]',
            'top'
        );
    }

    public static function add_query_vars(array $vars): array
    {
        $vars[] = self::QUERY_FLAG;
        $vars[] = 'md_docs_repo';
        $vars[] = 'md_docs_path';
        return $vars;
    }

    public static function register_assets(): void
    {
        wp_register_style(
            'md-docs',
            plugins_url('assets/md-docs.css', __FILE__),
            [],
            self::VERSION
        );

        wp_enqueue_style('md-docs');
    }

    public static function shortcode(array $attributes = []): string
    {
        $attributes = shortcode_atts(['repo' => '', 'path' => ''], $attributes, 'md_docs');
        return self::render((string) $attributes['repo'], (string) $attributes['path']);
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            'MD Docs 設定',
            'MD Docs',
            'manage_options',
            'md-docs',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_ALLOWED_FILES,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_allowed_files'],
            ]
        );
    }

    public static function sanitize_allowed_files(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = [];
        foreach ($value as $repo => $files) {
            $repo = self::clean_repo((string) $repo);
            if ($repo === '' || !is_array($files)) {
                continue;
            }

            $repo_dir = self::repo_dir($repo);
            if ($repo_dir === null) {
                continue;
            }

            $available = self::discover_markdown_files($repo_dir);
            $selected = [];
            foreach ($files as $file) {
                $relative = self::clean_relative_path((string) $file);
                if ($relative !== null && in_array($relative, $available, true)) {
                    $selected[] = $relative;
                }
            }
            $allowed[$repo] = array_values(array_unique($selected));
        }
        return $allowed;
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $saved = get_option(self::OPTION_ALLOWED_FILES, null);
        $is_unconfigured = !is_array($saved);
        $repos = self::repositories();
        ?>
        <div class="wrap">
            <h1>MD Docs 設定</h1>
            <p>公開を許可する Markdown ファイルをリポジトリごとに選択してください。未選択のファイルは、一覧にも本文にも表示されません。</p>

            <form action="options.php" method="post">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <?php if ($repos === []) : ?>
                    <p>uploads/docs/ 配下にリポジトリが見つかりません。</p>
                <?php endif; ?>

                <?php foreach ($repos as $repo) : ?>
                    <?php
                    $repo_dir = self::repo_dir($repo);
                    if ($repo_dir === null) {
                        continue;
                    }
                    $files = self::discover_markdown_files($repo_dir);
                    $selected = isset($saved[$repo]) && is_array($saved[$repo]) ? $saved[$repo] : [];
                    $field_name = self::OPTION_ALLOWED_FILES . '[' . $repo . '][]';
                    ?>
                    <fieldset style="margin: 1.5em 0;">
                        <legend><strong><?php echo esc_html($repo); ?></strong></legend>
                        <input type="hidden" name="<?php echo esc_attr($field_name); ?>" value="">

                        <?php if ($files === []) : ?>
                            <p>Markdown ファイルがありません。</p>
                        <?php endif; ?>

                        <?php foreach ($files as $file) : ?>
                            <?php $is_checked = $is_unconfigured || in_array($file, $selected, true); ?>
                            <label style="display: block; margin: 0.5em 0;">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr($field_name); ?>"
                                    value="<?php echo esc_attr($file); ?>"
                                    <?php checked($is_checked); ?>
                                >
                                <code><?php echo esc_html($file); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function render_route(): void
    {
        if ((string) get_query_var(self::QUERY_FLAG) !== '1') {
            return;
        }

        status_header(200);
        nocache_headers();
        wp_enqueue_style('md-docs');
        get_header();
        ?>
        <main class="md-docs-page">
            <?php
            echo self::render((string) get_query_var('md_docs_repo'), (string) get_query_var('md_docs_path')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </main>
        <?php
        get_footer();
        exit;
    }

    private static function render(string $repo, string $path): string
    {
        wp_enqueue_style('md-docs');
        if (trim($repo) === '*') {
            return self::render_collection($path);
        }
        $repo = self::clean_repo($repo);

        if ($repo === '') {
            return self::render_repo_list();
        }

        $repo_dir = self::repo_dir($repo);
        if ($repo_dir === null) {
            return self::notice('指定されたリポジトリが見つかりません。');
        }

        $files = self::markdown_files($repo, $repo_dir);
        $relative_file = self::route_to_file($path, $repo_dir);
        if ($relative_file === null || !in_array($relative_file, $files, true)) {
            return self::notice('指定されたドキュメントが見つかりません。');
        }

        $absolute_file = self::safe_path($repo_dir, $relative_file);
        if ($absolute_file === null) {
            return self::notice('ドキュメントを読み込めません。');
        }

        $html = self::cached_markdown($repo, $relative_file, $absolute_file);
        $navigation = self::render_navigation($repo, $files, $relative_file);
        $document_name = preg_replace('/\.md$/i', '', basename($relative_file)) ?? basename($relative_file);
        if (strcasecmp($document_name, 'README') === 0) {
            $document_name = $repo;
        }
        $docs_url = esc_url(home_url('/docs/'));
        $repo_url = esc_url(self::docs_url($repo));
        $repo_name = esc_html($repo);
        $document_title = esc_html($document_name);
        $home_link = self::home_link();
        $breadcrumbs = <<<HTML
            <a href="{$docs_url}">ドキュメント</a>
            <span aria-hidden="true">/</span>
            <a href="{$repo_url}">{$repo_name}</a>
            HTML;
        if ($document_name !== $repo) {
            $breadcrumbs .= <<<HTML

                <span aria-hidden="true">/</span>
                <span aria-current="page">{$document_title}</span>
                HTML;
        }

        return <<<HTML
            <div class="md-docs-shell">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <h1>{$document_title}</h1>
                        <nav class="md-docs__breadcrumbs" aria-label="パンくず">
                            {$breadcrumbs}
                        </nav>
                    </div>
                    <div class="md-docs__hero-actions">
                        {$home_link}
                        <span class="md-docs__file-badge">Markdown</span>
                    </div>
                </header>
                <div class="md-docs">
                    <aside class="md-docs__sidebar">
                        {$navigation}
                    </aside>
                    <article class="md-docs__content">
                        {$html}
                    </article>
                </div>
            </div>
            HTML;
    }

    private static function render_collection(string $path): string
    {
        $path = self::clean_relative_path($path);
        if ($path === null || $path === '') {
            return self::notice('すべてのリポジトリを表示する場合は path を指定してください。');
        }

        $items = '';
        $count = 0;
        foreach (self::repositories() as $repo) {
            $repo_dir = self::repo_dir($repo);
            if ($repo_dir === null) {
                continue;
            }
            $files = self::markdown_files($repo, $repo_dir);
            $relative_file = self::route_to_file($path, $repo_dir);
            if ($relative_file === null || !in_array($relative_file, $files, true)) {
                continue;
            }
            $absolute_file = self::safe_path($repo_dir, $relative_file);
            if ($absolute_file === null) {
                continue;
            }

            $document_url = esc_url(self::docs_url($repo, $path));
            $repo_name = esc_html($repo);
            $document_html = self::cached_markdown($repo, $relative_file, $absolute_file);
            $items .= <<<HTML
                <section class="md-docs__collection-item">
                    <h2><a href="{$document_url}">{$repo_name}</a></h2>
                    <article class="md-docs__content">
                        {$document_html}
                    </article>
                </section>
                HTML;
            $count++;
        }

        if ($count === 0) {
            return self::notice('指定されたドキュメントは、許可済みのリポジトリに見つかりません。');
        }

        $document_name = preg_replace('/\.md$/i', '', basename($path)) ?? basename($path);
        $docs_url = esc_url(home_url('/docs/'));
        $document_title = esc_html($document_name);
        $home_link = self::home_link();

        return <<<HTML
            <div class="md-docs-shell md-docs-shell--collection">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <h1>{$document_title}</h1>
                        <nav class="md-docs__breadcrumbs" aria-label="パンくず">
                            <a href="{$docs_url}">ドキュメント</a>
                            <span aria-hidden="true">/</span>
                            <span aria-current="page">{$document_title}</span>
                        </nav>
                    </div>
                    <div class="md-docs__hero-actions">
                        {$home_link}
                        <span class="md-docs__count-badge">{$count} repositories</span>
                    </div>
                </header>
                <div class="md-docs md-docs--collection">
                    <div class="md-docs__collection">
                        {$items}
                    </div>
                </div>
            </div>
            HTML;
    }

    private static function render_repo_list(): string
    {
        $repos = self::repositories();
        if ($repos === []) {
            return self::notice('uploads/docs 配下にドキュメントがありません。');
        }

        $items = '';
        foreach ($repos as $repo) {
            $repo_url = esc_url(self::docs_url($repo));
            $repo_name = esc_html($repo);
            $items .= <<<HTML
                <li>
                    <a href="{$repo_url}">
                        <span class="md-docs__repo-icon" aria-hidden="true"></span>
                        <span>
                            <strong>{$repo_name}</strong>
                            <small>ドキュメントを見る</small>
                        </span>
                        <span class="md-docs__repo-arrow" aria-hidden="true">→</span>
                    </a>
                </li>
                HTML;
        }

        $home_link = self::home_link();
        $repo_count = count($repos);

        return <<<HTML
            <div class="md-docs-shell md-docs-shell--repos">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <p>技術情報を参照できます。</p>
                    </div>
                    <div class="md-docs__hero-actions">
                        {$home_link}
                        <span class="md-docs__count-badge">{$repo_count} repositories</span>
                    </div>
                </header>
                <div class="md-docs md-docs--repos">
                    <section class="md-docs__content">
                        <ul class="md-docs__repo-list">
                            {$items}
                        </ul>
                    </section>
                </div>
            </div>
            HTML;
    }

    private static function repositories(): array
    {
        $base = self::docs_dir();
        $cache_key = 'md_docs_repos_' . md5($base);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $repos = [];
        if (is_dir($base)) {
            foreach (new DirectoryIterator($base) as $entry) {
                if ($entry->isDir() && !$entry->isDot() && !$entry->isLink()) {
                    $name = $entry->getFilename();
                    if (self::clean_repo($name) === $name) {
                        $repos[] = $name;
                    }
                }
            }
        }
        natcasesort($repos);
        $repos = array_values($repos);
        set_transient($cache_key, $repos, self::CACHE_TTL);
        return $repos;
    }

    private static function markdown_files(string $repo, string $repo_dir): array
    {
        $files = self::discover_markdown_files($repo_dir);
        $saved = get_option(self::OPTION_ALLOWED_FILES, null);
        if (!is_array($saved)) {
            return $files;
        }

        $allowed = isset($saved[$repo]) && is_array($saved[$repo]) ? $saved[$repo] : [];
        return array_values(array_intersect($files, $allowed));
    }

    private static function discover_markdown_files(string $repo_dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repo_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink() && strtolower($file->getExtension()) === 'md') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($repo_dir) + 1));
                if (self::safe_path($repo_dir, $relative) !== null) {
                    $files[] = $relative;
                }
            }
        }
        natcasesort($files);
        return array_values($files);
    }

    private static function route_to_file(string $path, string $repo_dir): ?string
    {
        $path = self::clean_relative_path($path);
        if ($path === null) {
            return null;
        }

        $candidates = $path === ''
            ? ['README.md', 'readme.md','pr.md']
            : [$path, $path . '.md', $path . '/README.md', $path . '/readme.md'];

        foreach ($candidates as $candidate) {
            $absolute = self::safe_path($repo_dir, $candidate);
            if ($absolute !== null && is_file($absolute) && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'md') {
                return str_replace('\\', '/', substr($absolute, strlen($repo_dir) + 1));
            }
        }
        return null;
    }

    private static function cached_markdown(string $repo, string $relative_file, string $absolute_file): string
    {
        $stamp = (string) filemtime($absolute_file) . ':' . (string) filesize($absolute_file);
        $cache_key = 'md_docs_html_' . md5($absolute_file . ':' . $stamp);
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $markdown = file_get_contents($absolute_file);
        if ($markdown === false) {
            return self::notice('ドキュメントを読み込めません。');
        }

        $html = self::markdown_to_html($markdown, $repo, dirname($relative_file));
        $allowed = wp_kses_allowed_html('post');
        $allowed['pre'] = ['class' => true];
        $allowed['code'] = ['class' => true];
        $html = wp_kses($html, $allowed);
        set_transient($cache_key, $html, self::CACHE_TTL);
        return $html;
    }

    private static function markdown_to_html(string $markdown, string $repo, string $current_dir): string
    {
        $markdown = preg_replace('/\A---\R.*?\R---\R/s', '', str_replace(["\r\n", "\r"], "\n", $markdown)) ?? $markdown;
        $lines = explode("\n", $markdown);
        $html = [];
        $paragraph = [];
        $in_code = false;
        $code = [];
        $code_language = '';
        $list_type = null;

        $flush_paragraph = static function () use (&$html, &$paragraph, $repo, $current_dir): void {
            if ($paragraph !== []) {
                $text = implode(' ', array_map('trim', $paragraph));
                $html[] = '<p>' . self::inline_markdown($text, $repo, $current_dir) . '</p>';
                $paragraph = [];
            }
        };
        $close_list = static function () use (&$html, &$list_type): void {
            if ($list_type !== null) {
                $html[] = '</' . $list_type . '>';
                $list_type = null;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^```([A-Za-z0-9_-]*)\s*$/', $line, $match)) {
                if ($in_code) {
                    $class = $code_language === '' ? '' : ' class="language-' . esc_attr($code_language) . '"';
                    $html[] = '<pre><code' . $class . '>' . esc_html(implode("\n", $code)) . '</code></pre>';
                    $code = [];
                    $code_language = '';
                    $in_code = false;
                } else {
                    $flush_paragraph();
                    $close_list();
                    $in_code = true;
                    $code_language = $match[1];
                }
                continue;
            }
            if ($in_code) {
                $code[] = $line;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match)) {
                $flush_paragraph();
                $close_list();
                $level = strlen($match[1]);
                $html[] = '<h' . $level . '>' . self::inline_markdown($match[2], $repo, $current_dir) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^\s*([-*+])\s+(.+)$/', $line, $match) || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $match)) {
                $flush_paragraph();
                $type = preg_match('/^\s*\d+/', $line) ? 'ol' : 'ul';
                $content = $match[count($match) - 1];
                if ($list_type !== $type) {
                    $close_list();
                    $html[] = '<' . $type . '>';
                    $list_type = $type;
                }
                $html[] = '<li>' . self::inline_markdown($content, $repo, $current_dir) . '</li>';
                continue;
            }
            if (trim($line) === '') {
                $flush_paragraph();
                $close_list();
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $line, $match)) {
                $flush_paragraph();
                $close_list();
                $html[] = '<blockquote><p>' . self::inline_markdown($match[1], $repo, $current_dir) . '</p></blockquote>';
                continue;
            }
            if (preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/', $line)) {
                $flush_paragraph();
                $close_list();
                $html[] = '<hr>';
                continue;
            }
            $paragraph[] = $line;
        }

        if ($in_code) {
            $html[] = '<pre><code>' . esc_html(implode("\n", $code)) . '</code></pre>';
        }
        $flush_paragraph();
        $close_list();
        return implode("\n", $html);
    }

    private static function inline_markdown(string $text, string $repo, string $current_dir): string
    {
        $text = esc_html($text);
        $tokens = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $match) use (&$tokens): string {
            $key = '@@MDTOKEN' . count($tokens) . '@@';
            $tokens[$key] = '<code>' . $match[1] . '</code>';
            return $key;
        }, $text) ?? $text;
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^\s)]+)(?:\s+["\'][^"\']*["\'])?\)/', static function (array $match) use ($repo, $current_dir, &$tokens): string {
            $url = self::resolve_url(html_entity_decode($match[2]), $repo, $current_dir, true);
            $key = '@@MDTOKEN' . count($tokens) . '@@';
            $tokens[$key] = '<img src="' . esc_url($url) . '" alt="' . esc_attr(html_entity_decode($match[1])) . '" loading="lazy">';
            return $key;
        }, $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)(?:\s+["\'][^"\']*["\'])?\)/', static function (array $match) use ($repo, $current_dir, &$tokens): string {
            $url = self::resolve_url(html_entity_decode($match[2]), $repo, $current_dir, false);
            $key = '@@MDTOKEN' . count($tokens) . '@@';
            $tokens[$key] = '<a href="' . esc_url($url) . '">' . $match[1] . '</a>';
            return $key;
        }, $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        return strtr($text, $tokens);
    }

    private static function resolve_url(string $url, string $repo, string $current_dir, bool $is_image): string
    {
        if ($url === '' || str_starts_with($url, '#') || preg_match('#^(https?:|mailto:|tel:)#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return '#';
        }

        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $hash] = explode('#', $url, 2);
            $fragment = '#' . rawurlencode($hash);
        }
        $query = '';
        if (str_contains($url, '?')) {
            [$url, $raw_query] = explode('?', $url, 2);
            $query = '?' . $raw_query;
        }
        $relative = str_starts_with($url, '/') ? ltrim($url, '/') : trim($current_dir . '/' . $url, '/');
        $relative = self::normalize_relative_path($relative);
        if ($relative === null) {
            return '#';
        }

        if (!$is_image && strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'md') {
            $route = preg_replace('/\.md$/i', '', $relative) ?? $relative;
            $route = preg_replace('#(^|/)README$#i', '$1', $route) ?? $route;
            return self::docs_url($repo, trim($route, '/')) . $query . $fragment;
        }
        return self::uploads_docs_url($repo, $relative) . $query . $fragment;
    }

    private static function render_navigation(string $repo, array $files, string $active): string
    {
        $items = '';
        foreach ($files as $file) {
            $route = preg_replace('/\.md$/i', '', $file) ?? $file;
            $route = preg_replace('#(^|/)README$#i', '$1', $route) ?? $route;
            $label = preg_replace('/\.md$/i', '', basename($file)) ?? basename($file);
            $label = strcasecmp($label, 'README') === 0 ? basename(dirname($file)) : $label;
            if ($label === '.' || $label === '') {
                $label = $repo;
            }
            $active_attributes = $file === $active ? ' class="is-active" aria-current="page"' : '';
            $document_url = esc_url(self::docs_url($repo, trim($route, '/')));
            $document_label = esc_html($label);
            $items .= <<<HTML
                <li>
                    <a{$active_attributes} href="{$document_url}">{$document_label}</a>
                </li>
                HTML;
        }

        $page_count = count($files);
        $repo_url = esc_url(self::docs_url($repo));
        $repo_name = esc_html($repo);

        return <<<HTML
            <div class="md-docs__sidebar-heading">
                <span>Contents</span>
                <small>{$page_count} pages</small>
            </div>
            <h2><a href="{$repo_url}">{$repo_name}</a></h2>
            <nav aria-label="ドキュメント内">
                <ul>
                    {$items}
                </ul>
            </nav>
            HTML;
    }

    private static function docs_dir(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'docs';
    }

    private static function repo_dir(string $repo): ?string
    {
        if (!in_array($repo, self::repositories(), true)) {
            return null;
        }
        $path = self::docs_dir() . '/' . $repo;
        $real = realpath($path);
        return $real !== false && is_dir($real) ? $real : null;
    }

    private static function safe_path(string $base, string $relative): ?string
    {
        $clean = self::clean_relative_path($relative);
        if ($clean === null || $clean === '') {
            return null;
        }
        $base_real = realpath($base);
        $target_real = realpath($base . '/' . $clean);
        if ($base_real === false || $target_real === false || !str_starts_with($target_real, $base_real . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $target_real;
    }

    private static function clean_repo(string $repo): string
    {
        $repo = rawurldecode(trim($repo));
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $repo) ? $repo : '';
    }

    private static function clean_relative_path(string $path): ?string
    {
        $decoded = rawurldecode(trim($path, '/'));
        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return null;
        }
        return self::normalize_relative_path($decoded);
    }

    private static function normalize_relative_path(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private static function docs_url(string $repo, string $path = ''): string
    {
        $segments = array_filter(explode('/', trim($path, '/')), 'strlen');
        $encoded_path = implode('/', array_map('rawurlencode', $segments));
        $url = home_url('/docs/' . rawurlencode($repo) . '/');
        return $encoded_path === '' ? $url : $url . $encoded_path . '/';
    }

    private static function uploads_docs_url(string $repo, string $path): string
    {
        $uploads = wp_upload_dir();
        $segments = array_map('rawurlencode', explode('/', $repo . '/' . $path));
        return trailingslashit($uploads['baseurl']) . 'docs/' . implode('/', $segments);
    }

    private static function notice(string $message): string
    {
        $notice = esc_html($message);
        $home_link = self::home_link();

        return <<<HTML
            <div class="md-docs md-docs--notice">
                <p>{$notice}</p>
                {$home_link}
            </div>
            HTML;
    }

    private static function home_link(): string
    {
        $home_url = esc_url(home_url('/'));

        return <<<HTML
            <a class="md-docs__home-link" href="{$home_url}">
                <span aria-hidden="true">←</span>
                ホームへ戻る
            </a>
            HTML;
    }
}

register_activation_hook(__FILE__, [MD_Docs::class, 'activate']);
register_deactivation_hook(__FILE__, [MD_Docs::class, 'deactivate']);
MD_Docs::init();
