<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MD_Docs_Settings
{
    public const OPTION_ALLOWED_FILES = 'md_docs_allowed_files';

    private const SETTINGS_GROUP = 'md_docs_settings';
    private const RELOAD_ACTION = 'md_docs_reload_files';

    private static ?Closure $files_provider = null;
    private static ?Closure $reload_callback = null;

    public static function init(callable $files_provider, callable $reload_callback): void
    {
        self::$files_provider = Closure::fromCallable($files_provider);
        self::$reload_callback = Closure::fromCallable($reload_callback);
        add_action('admin_menu', [self::class, 'add_settings_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_post_' . self::RELOAD_ACTION, [self::class, 'reload_files']);
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

        $available_by_repo = self::available_files();
        $allowed = [];
        foreach ($available_by_repo as $repo => $available) {
            $submitted = isset($value[$repo]) && is_array($value[$repo]) ? $value[$repo] : [];
            $selected = array_filter(
                array_map('strval', $submitted),
                static fn(string $file): bool => in_array($file, $available, true)
            );
            $allowed[$repo] = array_values(array_unique($selected));
        }
        return $allowed;
    }

    public static function reload_files(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('この操作を実行する権限がありません。');
        }

        check_admin_referer(self::RELOAD_ACTION);
        if (self::$reload_callback !== null) {
            (self::$reload_callback)();
        }

        wp_safe_redirect(admin_url('options-general.php?page=md-docs'));
        exit;
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $saved = get_option(self::OPTION_ALLOWED_FILES, null);
        $is_unconfigured = !is_array($saved);
        $available_by_repo = self::available_files();
        ?>
        <div class="wrap">
            <h1>MD Docs 設定</h1>
            <p>公開を許可する Markdown ファイルをリポジトリごとに選択してください。未選択のファイルは、一覧にも本文にも表示されません。</p>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::RELOAD_ACTION); ?>">
                <?php wp_nonce_field(self::RELOAD_ACTION); ?>
                <?php submit_button('再読み込み', 'secondary', 'submit', false); ?>
            </form>

            <form action="options.php" method="post">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <?php if ($available_by_repo === []) : ?>
                    <p>uploads/docs/ 配下にリポジトリが見つかりません。</p>
                <?php endif; ?>

                <?php foreach ($available_by_repo as $repo => $files) : ?>
                    <?php
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

    private static function available_files(): array
    {
        return self::$files_provider === null ? [] : (self::$files_provider)();
    }
}
