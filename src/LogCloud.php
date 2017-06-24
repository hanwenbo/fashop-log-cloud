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
			$post = "长度大于20000太长，有可能是图片或附件或长文本，不记录";
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
			$json_data               = array_merge($json_data, $message);
			if (!empty($json_data['api_return'])) {
				$json_data['api_return'] = $json_data['api_return'][0];
			}
			$this->writed[$destination] = true;
		}

		// 是否提交到云端
		$config = config('wenshuai_log_cloud');
		if ($config['open'] === true) {
			$id = time();
			foreach ($log_cloud_cofing['hosts'] as $host) {
				$this->ajax($host, "/{$config['index']}/{$config['type']}/{$id}", $json_data, 'PUT');
			}
		}

		$message = json_encode($json_data) . "\r\n";
		return error_log($message, 3, $destination);
	}
	/**
	 * 异步请求
	 * @param string $domain 域名
	 * @param string $path 深层地址
	 * 比如：www.fashop.cn/hanwenbo/module/controller/action，hanwenbo/controller/action这部分就是path要带入的内容，默认为/
	 * 由于文件夹名字是会变的，写法参考：__ROOT__+你的path，（__ROOT__ = /hanwenbo）
	 * @param array $query_param 请求地址的附加参数，如：array('page'=>1,'rows'=>5)
	 * @param string $method 请求方式，GET | POST
	 * @param string $port 端口号，默认80
	 * @param string $header_param 头部参数，比如异步请求自己的服务，可加一些验证参数去限制被请求的来源 如：array('access_token'=>'hanwenbo')
	 * @example 示例：ajax( $_SERVER['SERVER_NAME'] ,__ROOT__.'/Home/index/test',array('param'=>1),'GET',80,array('access_token'=>'hanwenbo'));
	 * @author 韩文博
	 */
	private function ajax($host = 'www.fashop.cn', $path = '/', $query_param = array(), $method = 'GET', $port = 80, $header_param = array()) {
		// 请求的头部
		$header_string = '';
		if (!empty($header_param)) {
			foreach ($header_param as $key => $value) {
				$header_string .= $key . ": " . $value . "\r\n";
			}
		}
		// 尝试连接
		$fp = fsockopen($host, $port, $errno, $errstr, 30);
		if (!$fp) {
			trace($errstr, 'fashop\log\cloud->ajax()失败');
		} else {
			switch (strtolower($method)) {
			case 'post':
				$post = http_build_query($query_param);
				$out  = "POST " . $path . " HTTP/1.1\r\n";
				$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$out .= "Host: $host\r\n";
				$out .= $header_string;
				$out .= 'Content-Length: ' . strlen($post) . "\r\n";
				$out .= "Connection: Close\r\n\r\n";
				$out .= $post;
				break;
			case 'put':
				$post = http_build_query($query_param);
				$out  = "PUT " . $path . " HTTP/1.1\r\n";
				$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$out .= "Host: $host\r\n";
				$out .= $header_string;
				$out .= 'Content-Length: ' . strlen($post) . "\r\n";
				$out .= "Connection: Close\r\n\r\n";
				$out .= $post;
				break;
			default:
				$query_data   = http_build_query($query_param);
				$query_data   = $query_data ? '?' . $query_data : '';
				$query_string = (strpos($path, '/') == 0 ? $path : '/' . $path) . $query_data;

				$out = "GET " . $query_string . " HTTP/1.1\r\n";
				$out .= "Host: " . $host . "\r\n";
				$out .= $header_string;
				$out .= "Connection: Close\r\n\r\n";
				break;
			}
			fwrite($fp, $out);
			fclose($fp);
		}
	}
}
