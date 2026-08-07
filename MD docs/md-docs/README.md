# MD Docs

WordPress の `uploads/docs/{repo}/` 配下を自動検出し、複数リポジトリの Markdown をドキュメントとして表示するプラグインです。

## 動作要件

- WordPress 6.4 以上
- PHP 8.0 以上
- 「設定 → パーマリンク設定」で「基本」以外のパーマリンク構造を使用

外部ライブラリは使用しません。

## インストール

1. `md-docs.zip` を WordPress 管理画面の「プラグイン → 新規プラグインを追加 → プラグインのアップロード」からアップロードします。
2. 「MD Docs」を有効化します。
3. `wp-content/uploads/docs/` を作成し、Markdown と画像を配置します。
4. 既存サイトで URL が 404 になる場合は、「設定 → パーマリンク設定」を開いて、そのまま「変更を保存」を押します。

## フォルダ構成

```text
wp-content/uploads/docs/
├── repo-a/
│   ├── README.md
│   ├── guide/
│   │   └── install.md
│   └── images/
│       └── screen.png
└── repo-b/
    ├── README.md
    └── usage.md
```

リポジトリ名には半角英数字、ピリオド、ハイフン、アンダースコアを使用できます。シンボリックリンクは探索対象外です。

## URL

| URL                           | 内容                      |
| ----------------------------- | ------------------------- |
| `/docs/`                      | リポジトリ一覧            |
| `/docs/repo-a/`               | `repo-a/README.md`        |
| `/docs/repo-a/guide/install/` | `repo-a/guide/install.md` |

各フォルダの `README.md` も、そのフォルダのトップとして表示できます。

## ショートコード

固定ページなどには次のショートコードを配置できます。

```text
[md_docs]
[md_docs repo="repo-a"]
[md_docs repo="repo-a" path="guide/install"]
```

## Markdown 内の相対パス

Markdown ファイルからの相対リンクと相対画像を、同じリポジトリ内で解決します。

```markdown
[インストール](guide/install.md)
[上の階層](../README.md)
![画面](../images/screen.png)
```

`.md` へのリンクは `/docs/{repo}/{path}/` に変換されます。画像やその他のファイルは `uploads/docs/{repo}/` の公開 URL に変換されます。`http`、`https`、`mailto`、`tel`、ページ内アンカーはそのまま使用できます。

## GitHub Actions で同期する場合

同期先をリポジトリごとに分けてください。

```text
wp-content/uploads/docs/repo-a/
wp-content/uploads/docs/repo-b/
```

デプロイ方法はサーバーに合わせて rsync、SFTP、SSH などを利用します。認証情報は GitHub Actions の Secrets に保存し、リポジトリや Workflow に直接書かないでください。

ファイル一覧は5分間キャッシュされます。同期直後に追加ファイルが表示されない場合は最大5分待つか、WordPress の Transient キャッシュを削除してください。Markdown 本文は更新日時とファイルサイズを含むキーでキャッシュされるため、内容更新時は新しい表示へ切り替わります。

## セキュリティ上の仕様

- `realpath` とベースディレクトリ検証によるディレクトリトラバーサル対策
- リポジトリ名の許可リスト検証
- WordPress の `wp_kses` による生成 HTML のサニタイズ
- 未知の URL スキームを無効化
- シンボリックリンクを探索対象から除外

Markdown に記述した生の HTML は表示されません。このプラグインの Markdown 対応は、見出し、段落、強調、リンク、画像、リスト、引用、水平線、インラインコード、コードブロックを対象とした軽量実装です。

## アンインストール

プラグインを停止して削除してください。`uploads/docs/` 内のファイルは削除されません。必要であれば別途バックアップ後に削除してください。
