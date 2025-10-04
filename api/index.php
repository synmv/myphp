<?php
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');

$id = isset($_GET['id']) ? $_GET['id'] : '';

// 频道映射表
$channelMap = [
    'btv4k' => 91417,
    'sh4k' => 96050,
    'js4k' => 95925,
    'zj4k' => 96039,
    'sd4k' => 95975,
    'hn4k' => 96038,
    'gd4k' => 93733,
    'sc4k' => 95965,
    'sz4k' => 93735,
];

// 频道名称映射
$channelNameMap = [
    'btv4k' => '北京卫视4K',
    'sh4k' => '上海卫视4K',
    'js4k' => '江苏卫视4K',
    'zj4k' => '浙江卫视4K',
    'sd4k' => '山东卫视4K',
    'hn4k' => '湖南卫视4K',
    'gd4k' => '广东卫视4K',
    'sc4k' => '四川卫视4K',
    'sz4k' => '深圳卫视4K',
];

define('CACHE_DIR', __DIR__ . '/zgstcache');
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

/**
 * 生成签名
 */
function makeSign($url, $params, $timeMillis, $key)
{
    $payload = ['url' => $url, 'params' => $params, 'time' => $timeMillis];
    $json = json_encode($payload);
    $encrypted = openssl_encrypt($json, 'AES-256-ECB', $key, OPENSSL_RAW_DATA);
    return str_replace(["\r\n", "\r", "\n"], '', base64_encode($encrypted));
}

/**
 * 获取缓存
 */
function getCache($key, $ttl)
{
    $file = CACHE_DIR . '/' . md5($key) . '.cache';
    if (!file_exists($file)) return null;
    if (filemtime($file) + $ttl < time()) {
        unlink($file);
        return null;
    }
    $cacheData = json_decode(file_get_contents($file), true);
    return $cacheData ? $cacheData['data'] : null;
}

/**
 * 设置缓存
 */
function setCache($key, $data, $ttl)
{
    $file = CACHE_DIR . '/' . md5($key) . '.cache';
    $cacheData = ['data' => $data, 'expire' => time() + $ttl / 1000];
    file_put_contents($file, json_encode($cacheData));
}

$key = '01234567890123450123456789012345';
$url1 = 'https://api.chinaaudiovisual.cn/web/user/getVisitor';
$url2 = 'https://api.chinaaudiovisual.cn/column/getColumnAllList';

// 获取token
$token = getCache('visitor_token', 86400);
if (!$token) {
    $time1 = round(microtime(true) * 1000);
    $sign1 = makeSign($url1, '', $time1, $key);
    $headers = [
        'Content-Type: application/json',
        'headers: 1.1.3',
        'sign: ' . $sign1
    ];
    $ch = curl_init($url1);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (empty($data['success']) || empty($data['data']['token'])) {
        header('HTTP/1.1 404 Not Found');
        echo '获取Token失败';
        exit;
    }
    $token = $data['data']['token'];
    setCache('visitor_token', $token, 86400000);
}

// 获取频道列表
$dataArr = getCache('column_all_list_33', 600);
if (empty($dataArr)) {
    $columnId = 350;
    $cityId = 0;
    $provinceId = 0;
    $version = "1.1.4";
    $params = "cityId={$cityId}&columnId={$columnId}&provinceId={$provinceId}&token=" . rawurlencode($token) . "&version={$version}";
    $time2 = round(microtime(true) * 1000);
    $sign2 = makeSign($url2, $params, $time2, $key);
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: okhttp/3.11.0',
        'sign: ' . $sign2
    ];
    $ch = curl_init($url2);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $dataArr = json_decode($resp, true);
    if (empty($dataArr['success'])) {
        header('HTTP/1.1 404 Not Found');
        echo '获取频道列表失败';
        exit;
    }
    setCache('column_all_list_33', $dataArr, 600000);
}


if ($id == "list") {
    $baseUrl = sprintf('%s://%s%s',
        isset($_SERVER['HTTPS']) ? 'https' : 'http',
        $_SERVER['HTTP_HOST'],
        $_SERVER['SCRIPT_NAME']
    );
    echo "4K频道列表,#genre#\n";
    foreach ($channelMap as $cid => $cidNum) {
        echo "{$channelNameMap[$cid]},{$baseUrl}?id={$cid}\n";
    }
    exit;
}

// 查找播放地址
$targetId = $channelMap[$id];
$playUrl = null;
if (!empty($dataArr['data']) && is_array($dataArr['data'])) {
    foreach ($dataArr['data'] as $item) {
        if (isset($item['mediaAsset']['id']) && $item['mediaAsset']['id'] === $targetId) {
            $playUrl = $item['mediaAsset']['url'];
            break;
        }
    }
}


$isTsRequest = isset($_GET['ts']) && $_GET['ts'] === '1';
if ($isTsRequest && $playUrl) {
    $tsPath = $_GET['path'] ?? '';
    if (empty($tsPath)) {
        header('HTTP/1.1 400 Bad Request');
        echo '缺少TS路径参数';
        exit;
    }

    // 基本信息
    $parsed = parse_url($playUrl);
    $baseUrl = "{$parsed['scheme']}://{$parsed['host']}";
    $queryParams = $_GET;
    unset($queryParams['ts'], $queryParams['path'], $queryParams['id']);
    $queryString = http_build_query($queryParams);
    $tsUrl = "{$baseUrl}/{$tsPath}" . ($queryString ? "?{$queryString}" : "");

    // 透传 Range 请求（支持拖动）
    $forwardHeaders = [
        'User-Agent: aliplayer',
        'Referer: https://api.chinaaudiovisual.cn/',
        'Accept: */*',
        'Connection: keep-alive'
    ];
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $forwardHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    // 禁用各种缓冲，开启即写即发
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);

    header('X-Accel-Buffering: no');             // 关闭 Nginx 的 FastCGI 缓冲
    // 默认类型（若上游返回了 Content-Type，会在 header 回调里覆盖）
    if (!headers_sent()) {
        header('Content-Type: video/MP2T');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
    }

    $ch = curl_init($tsUrl);
    $httpCode = 0;

    // 转发上游响应头（在 body 前触发）
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) {
        $len = strlen($headerLine);
        $headerLineLower = strtolower($headerLine);

        // 状态行
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $headerLine, $m)) {
            if (!headers_sent()) {
                http_response_code((int)$m[1]);
            }
            return $len;
        }

        // 只转发少数安全且有用的头
        if (stripos($headerLine, 'Content-Length:') === 0 ||
            stripos($headerLine, 'Content-Range:') === 0 ||
            stripos($headerLine, 'Accept-Ranges:') === 0 ||
            stripos($headerLine, 'Content-Type:') === 0) {

            if (!headers_sent()) {
                // 避免重复/非法头
                $trimmed = trim($headerLine);
                if ($trimmed !== '') header($trimmed, true);
            }
        }
        return $len;
    });

    // 逐块写出数据
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
        // 客户端断开则中止
        if (connection_aborted()) {
            return 0; // 返回 0 让 cURL 停止
        }
        echo $data;
        // 立刻把缓冲区推给客户端
        if (function_exists('fastcgi_finish_request')) {
            // 不能在这里调用 fastcgi_finish_request（那会直接结束请求）
            // 保留注释提醒，不调用
        }
        @flush();
        @ob_flush();
        return strlen($data);
    });

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,          // 我们用 WRITEFUNCTION
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $forwardHeaders,
        CURLOPT_BUFFERSIZE => 128 * 1024,     // 增大单次回调块大小
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 0,              // 持续流
        CURLOPT_NOSIGNAL => true,
    ]);

    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$ok && $httpCode === 0) {
        // cURL 层面错误（可能是客户端中断/上游断开）
        if (!headers_sent()) {
            header('HTTP/1.1 502 Bad Gateway');
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "TS获取失败: {$err}";
    }
    // 正常结束（已流式发送完毕）
    exit;
}

// 子播放单代理：?id=sc4k&sub=1&url=<绝对m3u8>  -> 重写其中TS与MAP到你的ts代理
$isSubRequest = isset($_GET['sub']) && $_GET['sub'] === '1' && !empty($_GET['url']);
if ($isSubRequest) {
    $subUrl = $_GET['url'];

    // 拉取子m3u8
    $ch = curl_init($subUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: aliplayer',
            'Referer: https://api.chinaaudiovisual.cn/',
            'Accept: */*',
            'Connection: keep-alive'
        ],
        CURLOPT_HEADER => false
    ]);
    $m3u8Content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || empty($m3u8Content)) {
        header('HTTP/1.1 502 Bad Gateway');
        echo "子M3U8获取失败: HTTP {$httpCode}";
        exit;
    }

    // 解析基础URL（用于把相对路径转绝对）
    $parsed = parse_url($subUrl);
    $baseUrlForM3u8 = "{$parsed['scheme']}://{$parsed['host']}";
    if (isset($parsed['path'])) {
        $m3u8Path = dirname($parsed['path']);
        $baseUrlForM3u8 .= rtrim($m3u8Path, '/') . '/';
    }

    $lines = explode("\n", $m3u8Content);
    $newLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || $trimmed[0] === '#') {
            // 重写 #EXT-X-MAP
            if (strpos($trimmed, '#EXT-X-MAP:URI=') === 0) {
                if (preg_match('/#EXT-X-MAP:URI="([^"]+)"/', $trimmed, $m)) {
                    $uri = $m[1];
                    // 解析出 path 与 query，保持 query 透传
                    $abs = (strpos($uri, '://') === false) ? $baseUrlForM3u8 . ltrim($uri, '/') : $uri;
                    $u = parse_url($abs);
                    $path = $u['path'] ?? '';
                    $query = isset($u['query']) ? $u['query'] : '';
                    $proxy = $_SERVER['SCRIPT_NAME'] . "?id={$id}&ts=1&path=" . urlencode(ltrim($path, '/'));
                    if ($query) $proxy .= "&{$query}";
                    $newLines[] = '#EXT-X-MAP:URI="' . $proxy . '"';
                } else {
                    $newLines[] = $trimmed;
                }
            } else {
                // 保持其它标签
                $newLines[] = $trimmed;
            }
            continue;
        }

        // 普通媒体分片行：改写到 ts 代理
        $abs = (strpos($trimmed, '://') === false) ? $baseUrlForM3u8 . ltrim($trimmed, '/') : $trimmed;
        $u = parse_url($abs);
        $path = $u['path'] ?? '';
        $query = isset($u['query']) ? $u['query'] : '';
        $proxy = $_SERVER['SCRIPT_NAME'] . "?id={$id}&ts=1&path=" . urlencode(ltrim($path, '/'));
        if ($query) $proxy .= "&{$query}";
        $newLines[] = $proxy;
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    echo implode("\n", $newLines);
    exit;
}


// 处理M3U8文件（重写路径并注入Referer）
if ($playUrl) {
    $ch = curl_init($playUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent: aliplayer',
            'Referer: https://api.chinaaudiovisual.cn/',
            'Accept: */*',
            'Connection: keep-alive'
        ),
        CURLOPT_HEADER => false
    ]);
    $m3u8Content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || empty($m3u8Content)) {
        header('HTTP/1.1 502 Bad Gateway');
        echo "M3U8获取失败: HTTP {$httpCode}";
        exit;
    }

    // 解析基础URL
    $parsed = parse_url($playUrl);
    $baseUrlForM3u8 = "{$parsed['scheme']}://{$parsed['host']}";
    if (isset($parsed['path'])) {
        $m3u8Path = dirname($parsed['path']);
        $baseUrlForM3u8 .= rtrim($m3u8Path, '/') . '/';
    }


    $lines = explode("\n", $m3u8Content);
    $newLines = [];
    foreach ($lines as $line) {

        $trimmed = trim($line);

        if (empty($trimmed) || $trimmed[0] === '#') {
            if (strpos($trimmed, '#EXTM3U') === 0) {
                $newLines[] = $trimmed;
                $newLines[] = '#EXTVLCOPT:http-referrer=https://api.chinaaudiovisual.cn/';
            } else {
                $newLines[] = $trimmed;
            }
            continue;
        } else if ($id === 'sc4k') {
            // sc4k: 保留 master，改写子播放单URI到本地 sub=1 代理
            $newLines = [];
            $expectNextUriToRewrite = false; // 标记：遇到 #EXT-X-STREAM-INF 后的下一行是视频子m3u8

            foreach ($lines as $rawLine) {
                $trimmed = trim($rawLine);

                if ($trimmed === '') {
                    $newLines[] = $trimmed;
                    continue;
                }

                if ($trimmed[0] === '#') {
                    // 改写 #EXT-X-MEDIA 里的 URI（音频子m3u8）
                    if (stripos($trimmed, '#EXT-X-MEDIA:') === 0) {
                        // 提取 URI="..."
                        if (preg_match('/URI="([^"]+)"/i', $trimmed, $m)) {
                            $uri = $m[1];
                            $abs = (strpos($uri, '://') === false) ? $baseUrlForM3u8 . ltrim($uri, '/') : $uri;
                            $proxyM3u8 = $_SERVER['SCRIPT_NAME'] . "?id={$id}&sub=1&url=" . urlencode($abs);
                            // 用代理替换 URI
                            $rewritten = preg_replace('/URI="[^"]+"/i', 'URI="' . $proxyM3u8 . '"', $trimmed, 1);
                            $newLines[] = $rewritten;
                        } else {
                            $newLines[] = $trimmed;
                        }
                    } else {
                        // STREAM-INF 出现后，下一行是这个清晰度的视频m3u8 URI，需要改写
                        if (stripos($trimmed, '#EXT-X-STREAM-INF:') === 0) {
                            $expectNextUriToRewrite = true;
                        }
                        $newLines[] = $trimmed;
                    }
                    continue;
                }

                // 非注释行：可能是 #EXT-X-STREAM-INF 后面的视频子m3u8 URI
                if ($expectNextUriToRewrite) {
                    $expectNextUriToRewrite = false;
                    $abs = (strpos($trimmed, '://') === false) ? $baseUrlForM3u8 . ltrim($trimmed, '/') : $trimmed;
                    $proxyM3u8 = $_SERVER['SCRIPT_NAME'] . "?id={$id}&sub=1&url=" . urlencode($abs);
                    $newLines[] = $proxyM3u8;
                } else {
                    // 其它非注释行（很少见于master），兜底做相对->绝对
                    $newLines[] = (strpos($trimmed, '://') === false)
                        ? $baseUrlForM3u8 . ltrim($trimmed, '/')
                        : $trimmed;
                }
            }
        } else if ($id === 'btv4k') {
            // btv4k使用TS代理路径
            $path = strpos($line, '?') ? explode('?', $line, 2)[0] : $line;
            $query = strpos($line, '?') ? explode('?', $line, 2)[1] : '';
            $proxyUrl = $_SERVER['SCRIPT_NAME'] . "?id={$id}&ts=1&path=" . urlencode($path);
            $proxyUrl .= $query ? "&{$query}" : "";
            $newLines[] = $proxyUrl;
        } else {
            // 其他频道使用原始地址
            $newLines[] = (strpos($line, '://') === false)
                ? $baseUrlForM3u8 . ltrim($line, '/')
                : $line;
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    echo implode("\n", $newLines);
} else {
    header('HTTP/1.1 404 Not Found');
    echo '未找到播放地址';
}
