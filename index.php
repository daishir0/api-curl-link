<?php
ob_start();

// 設定ファイルの読み込み
if (!file_exists(__DIR__ . '/config.php')) {
    die('Configuration file not found. Please copy config.php.example to config.php and configure it.');
}
$config = require __DIR__ . '/config.php';

// エラー設定
error_reporting(E_ALL);
ini_set('display_errors', $config['debug']['enabled'] ? 1 : 0);

// 必要なディレクトリの作成
foreach ([$config['cache']['directory'], dirname($config['debug']['log_file'])] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function writeDebugLog($message) {
    global $config;
    if (!$config['debug']['enabled']) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($config['debug']['log_file'], $logMessage, FILE_APPEND);
}

writeDebugLog("プログラム開始");

// リクエストメソッドの検証
if (!in_array($_SERVER['REQUEST_METHOD'], $config['api']['allowed_methods'])) {
    ob_clean();
    header('HTTP/1.1 405 Method Not Allowed');
    exit(json_encode(['error' => 'Method Not Allowed']));
}

// パラメータの取得
$headers = getallheaders();
writeDebugLog("受信ヘッダー: " . print_r($headers, true));

$apiKey = $_POST['API-KEY'] ?? $headers['API-KEY'] ?? '';
$url = $_POST['URL'] ?? $headers['URL'] ?? '';
$xpath = $_POST['XPATH'] ?? $headers['XPATH'] ?? '';
$force = $_POST['FORCE'] ?? $headers['FORCE'] ?? '0';

writeDebugLog("取得したパラメータ:");
writeDebugLog("API-KEY: {$apiKey}");
writeDebugLog("URL: {$url}");
writeDebugLog("XPATH: " . ($xpath ? $xpath : '指定なし'));
writeDebugLog("FORCE: {$force}");

// パラメータのバリデーション
if ($apiKey !== $config['api']['key']) {
    ob_clean();
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Invalid API Key']));
}

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    ob_clean();
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'Valid URL is required']));
}

function sanitizeFileName($str) {
    $result = preg_replace('/[^\w.-]/u', '_', $str);
    writeDebugLog("ファイル名サニタイズ: {$str} -> {$result}");
    return $result;
}

function getFormattedDateTime() {
    return date('Ymd-His');
}

function updateCacheIndex($baseFileName, $cacheFile) {
    global $config;
    $indexFile = $config['cache']['directory'] . '/index.json';
    $index = [];
    
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true) ?? [];
    }
    
    $index[$baseFileName] = [
        'file' => $cacheFile,
        'timestamp' => filemtime($cacheFile)
    ];
    
    $cleanupTime = time() - ($config['cache']['cleanup_threshold'] * 60);
    foreach ($index as $key => $info) {
        if ($info['timestamp'] < $cleanupTime) {
            unset($index[$key]);
            if (file_exists($info['file'])) {
                unlink($info['file']);
            }
        }
    }
    
    file_put_contents($indexFile, json_encode($index));
    writeDebugLog("キャッシュインデックスを更新");
}

function findRecentFile($baseFileName) {
    global $config;
    writeDebugLog("キャッシュ検索開始: {$baseFileName}");
    
    $indexFile = $config['cache']['directory'] . '/index.json';
    if (!file_exists($indexFile)) {
        writeDebugLog("インデックスファイルなし");
        return null;
    }
    
    $index = json_decode(file_get_contents($indexFile), true) ?? [];
    
    if (!isset($index[$baseFileName])) {
        writeDebugLog("キャッシュエントリなし");
        return null;
    }
    
    $cacheInfo = $index[$baseFileName];
    $expireTime = time() - ($config['cache']['expire_minutes'] * 60);
    
    if ($cacheInfo['timestamp'] >= $expireTime && file_exists($cacheInfo['file'])) {
        writeDebugLog("有効なキャッシュを発見: " . $cacheInfo['file']);
        return $cacheInfo['file'];
    }
    
    writeDebugLog("有効なキャッシュなし");
    return null;
}

function parseBaseUrl($url) {
    $parsedUrl = parse_url($url);
    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    $basePath = isset($parsedUrl['path']) ? dirname($parsedUrl['path']) : '';
    if ($basePath == '/') $basePath = '';
    return [
        'domain' => $baseUrl,
        'path' => $basePath,
        'full' => $baseUrl . $basePath
    ];
}

function makeAbsoluteUrl($url, $baseInfo) {
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    if (strpos($url, '/') === 0) {
        return $baseInfo['domain'] . $url;
    }
    return $baseInfo['full'] . '/' . $url;
}

function extractAllLinks($html, $baseInfo, $xpath = '') {
    writeDebugLog("リンク抽出開始");
    writeDebugLog("HTMLサイズ: " . strlen($html));
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    
    $encoding = mb_detect_encoding($html, 'UTF-8, ASCII, JIS, EUC-JP, SJIS');
    if ($encoding !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }
    
    if (!preg_match('/<meta[^>]+charset=/', $html)) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $html;
    }
    
    $loadResult = @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
    writeDebugLog("DOM読み込み結果: " . ($loadResult ? "成功" : "失敗"));
    
    try {
        $xpathObj = new DOMXPath($dom);
        
        if (!empty($xpath)) {
            writeDebugLog("XPath指定での抽出: " . $xpath);
            $nodes = $xpathObj->query($xpath);
            if ($nodes && $nodes->length > 0) {
                $contextNode = $nodes->item(0);
                $links = $xpathObj->query(".//a", $contextNode);
            } else {
                writeDebugLog("指定されたXPathの要素が見つかりません");
                return [];
            }
        } else {
            $links = $xpathObj->query("//a");
        }
        
        writeDebugLog("見つかったリンク数: " . ($links ? $links->length : 0));
        
        $results = [];
        $processedUrls = [];
        
        if ($links) {
            foreach ($links as $link) {
                $url = $link->getAttribute('href');
                
                if (empty($url) || $url === '#' || strpos($url, 'javascript:') === 0) {
                    continue;
                }
                
                $absoluteUrl = makeAbsoluteUrl($url, $baseInfo);
                $text = trim($link->textContent);
                
                if (empty($text)) {
                    continue;
                }
                
                $text = preg_replace('/\s+/', ' ', $text);
                
                if (isset($processedUrls[$absoluteUrl])) {
                    continue;
                }
                
                $results[] = [
                    'title' => $text,
                    'link' => $absoluteUrl
                ];
                
                $processedUrls[$absoluteUrl] = true;
            }
        }
        
        writeDebugLog("抽出完了: " . count($results) . "件のリンク");
        return $results;
        
    } catch (Exception $e) {
        writeDebugLog("エラー: " . $e->getMessage());
        return [];
    }
}

// メイン処理の実行
$sanitizedUrl = sanitizeFileName($url);
$sanitizedXpath = empty($xpath) ? '' : '_' . sanitizeFileName($xpath);
$baseFileName = "{$sanitizedUrl}{$sanitizedXpath}.txt";

if ($force !== '1') {
    $recentFile = findRecentFile($baseFileName);
    if ($recentFile !== null) {
        writeDebugLog("キャッシュファイルを使用: {$recentFile}");
        $cachedContent = file_get_contents($recentFile);
        
        header('Content-Type: application/json; charset=utf-8');
        header('X-Cache: HIT');
        
        echo $cachedContent;
        exit;
    }
}

writeDebugLog("新規取得モード");

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $config['curl']['timeout'],
    CURLOPT_SSL_VERIFYPEER => $config['curl']['verify_ssl'],
    CURLOPT_USERAGENT => $config['curl']['user_agent'],
    CURLOPT_ENCODING => 'gzip, deflate',
    CURLOPT_HTTPHEADER => $config['curl']['headers']
]);

writeDebugLog("URLへのアクセス開始: {$url}");
$html = curl_exec($ch);

if ($html === false) {
    $error = curl_error($ch);
    curl_close($ch);
    ob_clean();
    header('HTTP/1.1 500 Internal Server Error');
    exit(json_encode(['error' => "Failed to fetch URL: " . $error]));
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
writeDebugLog("HTTP Status: " . $httpCode);
writeDebugLog("取得したコンテンツ長: " . strlen($html));
curl_close($ch);

try {
    $baseInfo = parseBaseUrl($url);
    $results = extractAllLinks($html, $baseInfo, $xpath);
    
    usort($results, function($a, $b) {
        return strlen($b['title']) - strlen($a['title']);
    });
    
    $response = [
        'status' => 'success',
        'count' => count($results),
        'results' => $results
    ];
    
    $jsonOutput = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    $dateTime = getFormattedDateTime();
    $filename = $config['cache']['directory'] . '/' . $dateTime . '-' . $baseFileName;
    writeDebugLog("新規キャッシュファイル: " . $filename);
    
    if (file_put_contents($filename, $jsonOutput) === false) {
        ob_clean();
        header('HTTP/1.1 500 Internal Server Error');
        exit(json_encode(['error' => 'Failed to save cache']));
    }
    
    updateCacheIndex($baseFileName, $filename);
    
    writeDebugLog("キャッシュ保存完了");
    
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Cache: MISS');
    
    writeDebugLog("処理完了 - 出力サイズ: " . strlen($jsonOutput));
    
    echo $jsonOutput;
    
} catch (Exception $e) {
    ob_clean();
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
