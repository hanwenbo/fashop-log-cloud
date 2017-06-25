<?php
/**
 * hanwenbo/fashop-log-cloud
 * Collect the logs to improve development efficiency.
 *
 *
 *
 * @copyright  Copyright (c) 2016-2017 MoJiKeJi Inc. (http://www.fashop.cn)
 * @license    http://www.fashop.cn
 * @link       http://www.fashop.cn
 * @since      File available since Release v1.1
 * @author     hanwenbo <9476400@qq.com>
 */
namespace fashop\log\cloud;

use think\App;

class LogCloud {
	protected $config = [
		'time_format' => ' c ',
		'file_size'   => 2097152,
		'path'        => LOG_PATH,
		'apart_level' => [],
	];

	protected $writed = [];

	// 实例化并传入参数
	public function __construct($config = []) {
		if (is_array($config)) {
			$this->config = array_merge($this->config, $config);
		}
	}

	/**
	 * 日志写入接口
	 * @access public
	 * @param array $log 日志信息
	 * @return bool
	 */
	public function save(array $log = []) {
		$request = request();
		$header  = $request->header();
		$get     = $request->get();
		$post    = $request->post();
		if (strlen(json_encode($post)) > 20000) {
			$post["notice"] = "长度大于20000太长，有可能是图片或附件或长文本，不记录";
		}
		$ip = $_SERVER['REMOTE_ADDR'];

		$cli         = IS_CLI ? '_cli' : '';
		$destination = $this->config['path'] . date('Ym') . DS . date('d') . $cli . '.log';

		$path = dirname($destination);
		!is_dir($path) && mkdir($path, 0755, true);

		$info           = [];
		$info['header'] = $header;
		$info['get']    = $get;
		$info['post']   = $post;
		foreach ($log as $type => $val) {
			if (in_array($type, $this->config['level'])) {
				$info[$type] = $val;
			}
		}

		if ($info) {
			return $this->write($info, $destination);
		}

		return true;
	}

	protected function write($message, $destination, $apart = false) {

		$json_data = [];

		//检测日志文件大小，超过配置大小则备份日志文件重新生成
		if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
			rename($destination, dirname($destination) . DS . time() . '-' . basename($destination));
			$this->writed[$destination] = false;
		}

		if (empty($this->writed[$destination]) && !IS_CLI) {
			if (App::$debug && !$apart) {
				// 获取基本信息
				$http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';
				if (isset($_SERVER['HTTP_HOST'])) {
					$current_uri = $http_type . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				} else {
					$current_uri = "cmd:" . implode(' ', $_SERVER['argv']);
				}

				$runtime                  = round(microtime(true) - THINK_START_TIME, 10);
				$reqs                     = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
				$time_str                 = ' [运行时间：' . number_format($runtime, 6) . 's][吞吐率：' . $reqs . 'req/s]';
				$memory_use               = number_format((memory_get_usage() - THINK_START_MEM) / 1024, 2);
				$memory_str               = ' [内存消耗：' . $memory_use . 'kb]';
				$file_load                = ' [文件加载：' . count(get_included_files()) . ']';
				$json_data['current_uri'] = $current_uri;
				$json_data['time_str']    = $time_str;
				$json_data['memory_str']  = $memory_str;
				$json_data['file_load']   = $file_load;
			}
			$now                     = date($this->config['time_format']);
			$server                  = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '0.0.0.0';
			$remote                  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
			$method                  = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI';
			$uri                     = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
			$json_data['load_files'] = get_included_files();
			$json_data['now']        = $now;
			$json_data['server']     = $server;
			$json_data['remote']     = $remote;
			$json_data['method']     = $method;
			$json_data['uri']        = $uri;

			$json_data = array_merge($json_data, $message);
			if (!empty($json_data['api_return'])) {
				$json_data['api_return'] = $json_data['api_return'][0];
			}
			$this->writed[$destination] = true;
		}
		// 断开客户端请求，提高访问速度
		fastcgi_finish_request();
		// 是否提交到云端
		$config = config('fashop_log_cloud');
		if ($config['open'] === true) {
			$client  = new \GuzzleHttp\Client();
			$url     = "{$config['host']}{$config['index']}/{$config['type']}/" . time();
			$header  = ['Authorization' => "Basic " . base64_encode("{$config['username']}:{$config['password']}"), 'Content-Type' => "application/json"];
			$request = new \GuzzleHttp\Psr7\Request('PUT', $url, $header, json_encode([
				'current_uri' => $json_data['current_uri'],
				'time_str'    => $json_data['time_str'],
				'get'         => $json_data['get'],
				'post'        => $json_data['post'],
				'now'         => $json_data['now'],
				'server'      => $json_data['server'],
				'remote'      => $json_data['remote'],
				'method'      => $json_data['method'],
				'uri'         => $json_data['uri'],
				'header'      => $json_data['header'],
			]));
			$promise = $client->sendAsync($request)->then(function ($response) {
				// $response->getBody();
			});
			$promise->wait(false);
		}

		$message = json_encode($json_data) . "\r\n";
		return error_log($message, 3, $destination);
	}
}
