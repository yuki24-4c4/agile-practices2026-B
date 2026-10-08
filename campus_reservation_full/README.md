# 学内施設・備品予約管理システム（授業用）

## 起動手順（Windows + Docker Desktop）
1. ZIPを展開し、`campus_reservation_full` フォルダを VS Code で開く。
2. Docker Desktop を起動する。
3. VS Code のターミナルで `docker compose up -d --build` を実行する。
4. ブラウザで **http://localhost:8080** を開く。
5. DB管理画面：**http://localhost:8081**（ユーザー: `root`、パスワード: `local_root_password`）。

## ログイン用サンプル
- 一般ユーザー：`yamada@example.com` / `DemoPass123!`
- 管理者：`admin@example.com` / `DemoPass123!`

## 機能
- 一般ユーザー：ログイン、備品一覧、予約申請、予約履歴、申請のキャンセル
- 管理者：一般ユーザー機能に加えて、予約承認／却下／貸出／返却、カテゴリー追加、備品の追加・編集・状態変更
- 予約期間が重なる申請を防止。CSRF対策、PDOプリペアドステートメント、権限検査を使用。
- 元の4テーブル `users`, `categories`, `items`, `reservations` を使用。登録ユーザーの追加画面や貸出通知等は未実装。

## 日本語が文字化けしている場合
このZIPのSQLファイルはUTF-8で収録。MySQL／PHPともにUTF-8（utf8mb4）を使用。
**旧Docker環境のデータは自動修復されません。** この新しい展開先フォルダで起動してください。
このプロジェクトをすでに起動済みでデータの文字化けが残る場合、保存データを削除してよい場合のみ、このプロジェクトのフォルダで次を実行：
```
docker compose down -v
docker compose up -d --build
```
`down -v` はこのプロジェクトのMySQLデータを削除します。重要データがある場合は絶対に実行しないこと。

## 起動エラーの確認
`docker compose ps` で状態を確認。ポート 8080/8081 が既に使われている場合、以前のDockerプロジェクトを `docker compose down` で止めるか、このフォルダの `docker-compose.yml` のポート番号を変更する。

## 注意
授業用のローカル実行が前提です。デモ用パスワードと固定DBパスワードを設定しています。インターネット上で公開しないでください。
