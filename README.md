# api-curl-link
## Overview
A PHP-based API service that fetches web pages and extracts links with advanced features including:
- XPath-based link extraction
- Efficient caching system with automatic cleanup
- Flexible API key authentication
- Configurable debug logging
- URL normalization and sanitization

## Installation
1. Clone the repository
```bash
git clone https://github.com/daishir0/api-curl-link
```

2. Navigate to the project directory
```bash
cd api-curl-link
```

3. Create configuration file
```bash
cp config.php.example config.php
```

4. Edit config.php and set your configurations
- Set a secure API key
- Configure cache settings
- Adjust debug options as needed

5. Set up directory permissions
```bash
chmod 755 cache logs
chmod 644 config.php
```

## Usage
Send a POST request with the following parameters:

Required parameters:
- `API-KEY`: Your authentication key (set in config.php)
- `URL`: Target webpage URL to analyze

Optional parameters:
- `XPATH`: XPath query to limit link extraction scope
- `FORCE`: Set to "1" to bypass cache

Example using curl:
```bash
curl -X POST \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "API-KEY=your-api-key&URL=https://example.com&XPATH=//div[@class='content']" \
  https://your-server/api-curl-link/
```

Response format:
```json
{
  "status": "success",
  "count": 42,
  "results": [
    {
      "title": "Link Title",
      "link": "https://example.com/page"
    },
    ...
  ]
}
```

## Notes
- The cache system automatically maintains files for 5 minutes by default
- Debug logs are disabled by default but can be enabled in config.php
- SSL certificate verification is configurable for development environments
- All extracted URLs are converted to absolute URLs
- Duplicate links are automatically filtered
- Links are sorted by title length in descending order

## License
This project is licensed under the MIT License - see the LICENSE file for details.

---

# api-curl-link
## 概要
Webページからリンクを抽出するPHP製APIサービスで、以下の機能を提供します：
- XPathを使用した柔軟なリンク抽出
- 効率的なキャッシュシステムと自動クリーンアップ
- APIキーによる認証
- 設定可能なデバッグログ
- URL正規化とサニタイズ処理

## インストール方法
1. レポジトリをクローン
```bash
git clone https://github.com/daishir0/api-curl-link
```

2. プロジェクトディレクトリへ移動
```bash
cd api-curl-link
```

3. 設定ファイルの作成
```bash
cp config.php.example config.php
```

4. config.phpを編集して設定を行う
- 安全なAPIキーを設定
- キャッシュの設定を調整
- 必要に応じてデバッグオプションを設定

5. ディレクトリのパーミッション設定
```bash
chmod 755 cache logs
chmod 644 config.php
```

## 使い方
以下のパラメータでPOSTリクエストを送信します：

必須パラメータ：
- `API-KEY`: 認証用APIキー（config.phpで設定）
- `URL`: 分析対象のWebページURL

オプションパラメータ：
- `XPATH`: リンク抽出範囲を制限するXPathクエリ
- `FORCE`: "1"を設定するとキャッシュをバイパス

curlを使用した例：
```bash
curl -X POST \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "API-KEY=your-api-key&URL=https://example.com&XPATH=//div[@class='content']" \
  https://your-server/api-curl-link/
```

レスポンス形式：
```json
{
  "status": "success",
  "count": 42,
  "results": [
    {
      "title": "リンクタイトル",
      "link": "https://example.com/page"
    },
    ...
  ]
}
```

## 注意点
- キャッシュは初期設定で5分間保持されます
- デバッグログは初期設定では無効です（config.phpで有効化可能）
- 開発環境用にSSL証明書の検証は設定可能です
- 抽出されたURLは全て絶対URLに変換されます
- 重複するリンクは自動的に除外されます
- リンクはタイトルの長さで降順ソートされます

## ライセンス
このプロジェクトはMITライセンスの下でライセンスされています。詳細はLICENSEファイルを参照してください。
