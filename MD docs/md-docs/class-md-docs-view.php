<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MD_Docs_View
{
    public static function document(array $data): string
    {
        $title = esc_html($data['title']);
        $breadcrumbs = self::breadcrumbs($data['breadcrumbs']);
        $home_link = self::home_link($data['home_url']);
        $navigation = self::navigation($data['navigation']);
        $html = $data['html'];

        return <<<HTML
            <div class="md-docs-shell">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <h1>{$title}</h1>
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

    public static function collection(array $data): string
    {
        $title = esc_html($data['title']);
        $breadcrumbs = self::breadcrumbs($data['breadcrumbs']);
        $home_link = self::home_link($data['home_url']);
        $count = count($data['items']);
        $items = '';
        foreach ($data['items'] as $item) {
            $repo = esc_html($item['repo']);
            $url = esc_url($item['url']);
            $html = $item['html'];
            $items .= <<<HTML
                <section class="md-docs__collection-item">
                    <h2><a href="{$url}">{$repo}</a></h2>
                    <article class="md-docs__content">
                        {$html}
                    </article>
                </section>
                HTML;
        }

        return <<<HTML
            <div class="md-docs-shell md-docs-shell--collection">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <h1>{$title}</h1>
                        <nav class="md-docs__breadcrumbs" aria-label="パンくず">
                            {$breadcrumbs}
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

    public static function repositories(array $repos, string $home_url): string
    {
        $home_link = self::home_link($home_url);
        $count = count($repos);
        $items = '';
        foreach ($repos as $repo) {
            $name = esc_html($repo['name']);
            $url = esc_url($repo['url']);
            $items .= <<<HTML
                <li>
                    <a href="{$url}">
                        <span class="md-docs__repo-icon" aria-hidden="true"></span>
                        <span>
                            <strong>{$name}</strong>
                            <small>ドキュメントを見る</small>
                        </span>
                        <span class="md-docs__repo-arrow" aria-hidden="true">→</span>
                    </a>
                </li>
                HTML;
        }

        return <<<HTML
            <div class="md-docs-shell md-docs-shell--repos">
                <header class="md-docs__hero">
                    <div>
                        <span class="md-docs__eyebrow">Knowledge base</span>
                        <p>技術情報を参照できます。</p>
                    </div>
                    <div class="md-docs__hero-actions">
                        {$home_link}
                        <span class="md-docs__count-badge">{$count} repositories</span>
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

    public static function notice(string $message, string $home_url): string
    {
        $notice = esc_html($message);
        $home_link = self::home_link($home_url);

        return <<<HTML
            <div class="md-docs md-docs--notice">
                <p>{$notice}</p>
                {$home_link}
            </div>
            HTML;
    }

    private static function breadcrumbs(array $items): string
    {
        $html = '';
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $html .= '<span aria-hidden="true">/</span>';
            }
            $label = esc_html($item['label']);
            if (isset($item['url'])) {
                $url = esc_url($item['url']);
                $html .= "<a href=\"{$url}\">{$label}</a>";
            } else {
                $html .= "<span aria-current=\"page\">{$label}</span>";
            }
        }
        return $html;
    }

    private static function navigation(array $data): string
    {
        $repo = esc_html($data['repo']);
        $repo_url = esc_url($data['repo_url']);
        $count = count($data['items']);
        $items = '';
        foreach ($data['items'] as $item) {
            $attributes = $item['active'] ? ' class="is-active" aria-current="page"' : '';
            $url = esc_url($item['url']);
            $label = esc_html($item['label']);
            $items .= "<li><a{$attributes} href=\"{$url}\">{$label}</a></li>";
        }

        return <<<HTML
            <div class="md-docs__sidebar-heading">
                <span>Contents</span>
                <small>{$count} pages</small>
            </div>
            <h2><a href="{$repo_url}">{$repo}</a></h2>
            <nav aria-label="ドキュメント内">
                <ul>{$items}</ul>
            </nav>
            HTML;
    }

    private static function home_link(string $home_url): string
    {
        $url = esc_url($home_url);
        return <<<HTML
            <a class="md-docs__home-link" href="{$url}">
                <span aria-hidden="true">←</span>
                ホームへ戻る
            </a>
            HTML;
    }
}
