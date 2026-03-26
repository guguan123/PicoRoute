<?php
/**
 * 单文件路由系统
 * 所有路由（含文件）均为 fn() => ...
 */

/* Apache 伪静态：
# 绕过 index.php
RewriteRule ^index\.php$ - [L]
# 绕过请求后缀是 .php 的文件
RewriteRule \.php$ - [L]
# 其余全部扫给 index.php
RewriteRule . /index.php [L]
*/

$pr_requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pr_rootDir = __DIR__;

$pr_config_file = $pr_rootDir . '/config.php';
if (file_exists($pr_config_file)) $pr_config = require $pr_config_file;

// 如果请求路径以脚本名开头，则去除脚本名部分
if (strpos($pr_requestUri, $_SERVER['SCRIPT_NAME']) === 0) {
	if ($pr_requestUri == $_SERVER['SCRIPT_NAME']) {
		$pr_uri = '/';
	} else {
		$pr_uri = substr($pr_requestUri, strlen($_SERVER['SCRIPT_NAME']));
	}
} else {
	$pr_uri = $pr_requestUri;
}

if (empty($pr_config['db']['dsn'])) {
	$pr_dbh = null;
} else try {
	$pr_dbh = new \PDO($pr_config['db']['dsn'], $pr_config['db']['user'], $pr_config['db']['pass'], $pr_config['db']['options'] ?? array());
} catch (\PDOException $e) {
	error_log('[Database Error] ' . $e->getMessage());
	http_response_code(503);
	die("Database Error");
}

/**
 * 统一响应上下文
 */
class Response {
	public ?int $status = null;
	public array $headers = [];
	public $body;
	public ?int $cache = null;
	public ?string $etag = null;
	public bool $sent = false;

	public function header(string $h) { $this->headers[] = $h; }
	public function cache(int $seconds) { $this->cache = $seconds; }
	public function etag(string $e) { $this->etag = $e; }
	public function send() {
		if ($this->sent) return;
		if ($this->status !== null) http_response_code($this->status);
		foreach ($this->headers as $h) header($h);
		if ($this->body !== null) echo $this->body;
		$this->sent = true;
	}
}

/**
 * 路由表
 */
$routes = [
	'/' => fn() => (function() use ($pr_rootDir) {
		$res = new Response();
		$res->header('Content-Type: text/html; charset=utf-8');
		$res->cache(3600);
		$res->body = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Hello</title><link href="https://guguan.us.kg/dark.css" rel="stylesheet" media="(prefers-color-scheme: dark)"><script src="https://guguan.us.kg/tracking.js" async></script><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body><p>Hello world</p></body></html>';
		return $res;
	})(),

	'/info' => fn() => (function() {
		$res = new Response();
		$res->header('Content-Type: text/html; charset=utf-8');
		$res->cache(3600);
		$res->body = '<br/>Your server uses PHP version ' . phpversion() . PHP_EOL . '</br></br>Your website url is ' . $_SERVER['HTTP_HOST']. '<br/> <br/>Your document root is ' . $_SERVER['DOCUMENT_ROOT'] . '<br/><br/>PHP memory_limit is ' . ini_get('memory_limit') . '<br/><br/>PHP max_file_size is ' . ini_get('upload_max_filesize') . 'B<br/><br/>PHP max_execution_time is ' . ini_get('max_execution_time') . ' seconds<link href="https://guguan.us.kg/dark.css" rel="stylesheet" media="(prefers-color-scheme: dark)"><script src="https://guguan.us.kg/tracking.js" async></script>';
		return $res;
	})(),

	'/health' => fn() => (function() use ($pr_dbh) {
		if (empty($pr_dbh)) {
			$result = true;
		} else {
			try {
				$result = $pr_dbh->query("SELECT 1")->fetchColumn() == 1;
			} catch (\PDOException $e) {
				$result = false;
			}
		}
		$res = new Response();
		if (!$result) $res->status = 503;
		$res->header('Content-Type: application/json; charset=utf-8');
		$res->header('Access-Control-Allow-Origin: *');
		$res->header('Access-Control-Allow-Methods: GET');
		$res->cache(10);
		$res->body = json_encode(array('status' => $result ? 'ok' : 'error', 'ts' => (new DateTimeImmutable)->format('c')));
		return $res;
	})()
];

// 合并路由表
if (isset($pr_config['routes']) && is_array($pr_config['routes'])) $routes = array_merge($routes, $pr_config['routes']);

/**
 * 文件路由：自动注册为闭包
 */
$pr_realpath = realpath($pr_rootDir . $pr_uri);
if (empty($routes[$pr_uri]) && $pr_realpath !== false && is_file($pr_realpath)) {
	if (strtolower(pathinfo($pr_realpath, PATHINFO_EXTENSION)) == 'php') {
		$routes[$pr_uri] = fn() => (function() use ($pr_realpath) {
			ob_start();
			include $pr_realpath;
			return ob_get_clean();
		})();
	} else {
		$routes[$pr_uri] = fn() => (function() use ($pr_realpath) {
			$res = new Response();
			$size = filesize($pr_realpath);
			$mtime = filemtime($pr_realpath);
			$etag = '"' . md5($pr_realpath . $mtime) . '"';

			$res->header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($pr_realpath) ?: 'application/octet-stream'));
			$res->header('Content-Length: ' . $size);
			$res->header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
			$res->header('ETag: ' . $etag);
			$res->header('Accept-Ranges: bytes');
			$res->cache(4 * 3600);
			$res->etag($etag);

			// 304 检查
			if (
				(@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') === $mtime) ||
				(trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag)
			) {
				$res->status = 304;
				return $res;
			}

			// 断点续传
			$start = 0;
			$end = $size - 1;
			if (isset($_SERVER['HTTP_RANGE'])) {
				if (!preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
					$res->status = 416;
					return $res;
				}
				$start = (int)$m[1];
				$end = $m[2] === '' ? $end : (int)$m[2];
				if ($start > $end || $start >= $size || $end >= $size) {
					$res->status = 416;
					$res->header('Content-Range: bytes */' . $size);
					return $res;
				}
				$res->status = 206;
				$res->header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
				$res->header('Content-Length: ' . ($end - $start + 1));
			}

			// 流式输出
			$fp = fopen($pr_realpath, 'rb');
			if (!$fp) { $res->status = 500; return $res; }
			fseek($fp, $start);
			$left = $end - $start + 1;
			ob_end_clean(); // 防止缓冲
			while ($left > 0 && !feof($fp)) {
				$chunk = min(8192, $left);
				$res->body .= fread($fp, $chunk); // 累积或直接 echo
				flush();
				$left -= $chunk;
			}
			fclose($fp);
			return $res;
		})();
	}
}

// HSTS
header('Strict-Transport-Security: max-age=31536000');

// 执行路由
if (array_key_exists($pr_uri, $routes)) {
	$raw = is_callable($routes[$pr_uri]) ? $routes[$pr_uri]() : $routes[$pr_uri];
} else {
	// 没找到路由或者返回为空
	$raw = new Response();
	$raw->status = 404;
	$raw->body = 'Not Found';
}
if (is_null($raw)) {
	$res = new Response();
	$res->status = 204;
} elseif ($raw instanceof Response) {
	$res = $raw;
} elseif (is_string($raw)) {
	// 字符串自动包装
	$res = new Response();
	$res->body = $raw;
} else {
	$res = new Response();
	$res->status = 503;
	$res->body = 'Invalid Route Return';
}

// 缓存头
if ($res->cache > 0) {
	$res->header("Cache-Control: public, max-age={$res->cache}");
	$res->header("Expires: " . gmdate('D, d M Y H:i:s', time() + $res->cache) . ' GMT');
} elseif ($res->cache === 0) {
	$res->header("Cache-Control: no-cache");
	$res->header("Expires: 0");
	$res->header("Pragma: no-cache");
} elseif ($res->cache < 0) {
	$res->header("Cache-Control: no-store");
	$res->header("Pragma: no-cache");
}

// 发送
$res->send();
exit;
