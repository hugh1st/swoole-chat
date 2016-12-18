<?php
class hsw {
	private $serv = null;
	public function __construct(){
		File::init();
		/*
		 $serv = new swoole_server(string $host, int $port, int $mode = SWOOLE_PROCESS, int $sock_type = SWOOLE_SOCK_TCP);
		 	$host参数用来指定监听的ip地址，如127.0.0.1，或者外网地址，或者0.0.0.0监听全部地址
				IPv4使用 127.0.0.1表示监听本机，0.0.0.0表示监听所有地址
				IPv6使用::1表示监听本机，:: (0:0:0:0:0:0:0:0) 表示监听所有地址
			$port监听的端口，如9501，监听小于1024端口需要root权限，如果此端口被占用server->start时会失败
			$mode运行的模式，swoole提供了3种运行模式，默认为多进程模式
				Base模式
				线程模式
				进程模式
			$sock_type指定socket的类型，支持TCP/UDP、TCP6/UDP6、UnixSock Stream/Dgram 6种
		 */
		$this->serv = new swoole_websocket_server("0.0.0.0",9501);
		/*
		 用于设置swoole_server运行时的各项参数
		 	最大连接：max_conn => 10000
		 		此参数用来设置Server最大允许维持多少个tcp连接。超过此数量后，新进入的连接将被拒绝。
				此参数不要调整的过大，根据机器内存的实际情况来设置。Swoole会根据此数值一次性分配一块大内存来保存Connection信息
			守护进程化：daemonize => 1
				加入此参数后，执行php server.php将转入后台作为守护进程运行
			reactor线程数：reactor_num => 2
				通过此参数来调节poll线程的数量，以充分利用多核
				reactor_num和writer_num默认设置为CPU核数
			worker进程数：worker_num => 4
				设置启动的worker进程数量。swoole采用固定worker进程的模式。
				PHP代码中是全异步非阻塞，worker_num配置为CPU核数的1-4倍即可。如果是同步阻塞，worker_num配置为100或者更高，具体要看每次请求处理的耗时和操作系统负载状况。
				当设定的worker进程数小于reactor线程数时，会自动调低reactor线程的数量
			max_request：max_request => 2000
				此参数表示worker进程在处理完n次请求后结束运行。manager会重新创建一个worker进程。此选项用来防止worker进程内存溢出。
				PHP代码也可以使用memory_get_usage来检测进程的内存占用情况，发现接近memory_limit时，调用exit()退出进程。manager进程会回收此进程，然后重新启动一个新的Worker进程。
				onConnect/onClose不增加计数
				设置为0表示不自动重启。在Worker进程中需要保存连接信息的服务，需要设置为0.
		 */
		$this->serv->set(array(
			'task_worker_num'     => 8
		));
		//注册Server的事件回调函数。
		$this->serv->on("open",array($this,"onOpen"));	
		$this->serv->on("message",array($this,"onMessage"));
		$this->serv->on("Task",array($this,"onTask"));
		$this->serv->on("Finish",array($this,"onFinish"));
		$this->serv->on("close",array($this,"onClose"));
		/*
		 	启动server，监听所有TCP/UDP端口，函数原型：
			启动成功后会创建worker_num+2个进程。主进程+Manager进程+worker_num个Worker进程。
		 */
		$this->serv->start();
	}
	
	public function onWorkerStart($serv, $worker_id)
	{
		if (!$serv->taskworker) {
			$serv->tick(1000, function ($id) {
				var_dump($id);
			});
		}
		else
		{
			$serv->addtimer(1000);
		}
	}
	
	public function onReceive($server, $fd, $from_id, $data) {
		$server->tick(1000, function() use ($server, $fd) {
			$server->send($fd, "hello world");
		});
	}
	
	public function onOpen( $serv , $request ){
		$data = array(
			'task' => 'open',
			'fd' => $request->fd
		);
		$this->serv->task( json_encode($data) );
		echo "open\n";
	}
	
	public function onMessage( $serv , $frame ){
		$data = json_decode( $frame->data , true );
		switch($data['type']){
			case 1://登录
				$data = array(
					'task' => 'login',
					'params' => array(
							'name' => $data['name'],
							'email' => $data['email']
						),
					'fd' => $frame->fd,
					'roomid' =>$data['roomid']
				);
				if(!$data['params']['name'] || !$data['params']['email'] ){
					$data['task'] = "nologin";
					$this->serv->task( json_encode($data) );
					break;
				}
				$this->serv->task( json_encode($data) );
				break;
			case 2: //新消息
				$data = array(
					'task' => 'new',
					'params' => array(
							'name' => $data['name'],
							'avatar' => $data['avatar']
						),
					'c' => $data['c'],
					'message' => $data['message'],
					'fd' => $frame->fd,
					'roomid' => $data['roomid']
				);
				$this->serv->task( json_encode($data) );
				break;
			case 3: // 改变房间
				$data = array(
					'task' => 'change',
					'params' => array(
						'name'   => $data['name'],
						'avatar' => $data['avatar'],
					),
					'fd' => $frame->fd,
					'oldroomid' => $data['oldroomid'],
					'roomid' => $data['roomid']
				);
				
				$this->serv->task( json_encode($data) );
				
				break;
			default :
				$this->serv->push($frame->fd, json_encode(array('code'=>0,'msg'=>'type error')));
		}
	}
	public function onTask( $serv , $task_id , $from_id , $data ){
		$pushMsg = array('code'=>0,'msg'=>'','data'=>array());
		$data = json_decode($data,true);
		switch( $data['task'] ){
			case 'open':
				$pushMsg = Chat::open( $data );
				$this->serv->push( $data['fd'] , json_encode($pushMsg) );
				return 'Finished';
			case 'login':
				$pushMsg = Chat::doLogin( $data );
				break;
			case 'new':
				$pushMsg = Chat::sendNewMsg( $data );
				break;
			case 'logout':
				$pushMsg = Chat::doLogout( $data );
				break;
			case 'nologin':
				$pushMsg = Chat::noLogin( $data );
				$this->serv->push( $data['fd'] ,json_encode($pushMsg));
				return "Finished";
			case 'change':
				$pushMsg = Chat::change( $data );
				break;
		}
		$this->sendMsg($pushMsg,$data['fd']);
		return "Finished";
	}
	
	public function onClose( $serv , $fd ){
		
		$pushMsg = array('code'=>0,'msg'=>'','data'=>array());
		//获取用户信息
		$user = Chat::logout("",$fd);
		if($user){
			$data = array(
				'task' => 'logout',
				'params' => array(
						'name' => $user['name']
					),
				'fd' => $fd
			);
			$this->serv->task( json_encode($data) );
		}
		
		echo "client {$fd} closed\n";
	}
	
	public function sendMsg($pushMsg,$myfd){
		foreach($this->serv->connections as $fd) {
			if($fd === $myfd){
				$pushMsg['data']['mine'] = 1;
			} else {
				$pushMsg['data']['mine'] = 0;
			}
			$this->serv->push($fd, json_encode($pushMsg));
		}
	}
	
	
	public function onFinish( $serv , $task_id , $data ){
		echo "Task {$task_id} finish\n";
        echo "Result: {$data}\n";
	}
}