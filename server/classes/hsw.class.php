<?php
class hsw {
	private $serv = null;
	public function __construct(){
		File::init();
		$this->serv = new swoole_websocket_server("0.0.0.0",9501);
		$this->serv->set(array(
			'task_worker_num'     => 8
		));
		$this->serv->on("open",array($this,"onOpen"));
		$this->serv->on("message",array($this,"onMessage"));
		$this->serv->on("Task",array($this,"onTask"));
		$this->serv->on("Finish",array($this,"onFinish"));
		$this->serv->on("close",array($this,"onClose"));
		$this->serv->start();
	}
	
	public function onOpen( $serv , $request ){
		$data = array(
			'task' => 'open',
			'fd' => $request->fd
		);
		$this->serv->task( json_encode($data) );
	}
	
	public function onMessage( $serv , $frame ){
		$data = json_decode( $frame->data , true );   
		echo "message:\n";
		echo json_encode($data);
		echo "\n";
		switch($data['type']){
			case 1://登录:{"type":1,"name":"Mapleleaf","email":"e_dao@qq.com","roomid":"a"}
				echo "uuid:".$data['wxid']."\n";
				$wxid = $data['wxid'];
				$response = array(
					'task' => 'login',
					'params' => array(
							'token' => $data['token'],
							'wxid' => $data['wxid']
						),
					'fd' => $frame->fd,
					'roomid' =>$data['roomid']
				);
				$db = new swoole_mysql;
				$server = array(
						'host' => '127.0.0.1',
						'user' => 'root',
						'password' => 'yyj1988615',
						'database' => 'chat',
				);
				$fields = "user_id, nickname";
				$db->connect($server, function ($db, $r) use($fields, $wxid, $response) {
					print_r($response);
					echo "fields:".$fields."\n";
					echo "wxid:".$wxid."\n";
					if ($r === false) {
						var_dump($db->connect_errno, $db->connect_error);
						die;
					}
					$sql = "select {$fields} from user where uuid = '{$wxid}'";
					echo $sql."\n";
					$db->query($sql, function(swoole_mysql $db, $result) use($response) {
						echo "1\n";
						if (empty($result)){
							echo "2\n";
						}else{
							echo "3\n";
							$response['params']['name'] = $result[0]['nickname'];
							print_r($response);
							$this->serv->task( json_encode($response) );
							echo "4\n";
						}
						echo "5\n";
						print_r($result);
						echo "6\n";
						$db->close();
					});
				});
// 				if(!$data['params']['token'] || !$data['params']['token'] ){
// 					$data['task'] = "nologin";
// 					$this->serv->task( json_encode($data) );
// 					break;
// 				}
				break;
			case 2: //新消息:{"type":2,"name":"admin","avatar":"http://47.90.39.2:8081/static/images/avatar/f1/f_10.jpg","message":"\u54c8\u54c8\u54c8\u54c8\u54c8\u54c8\u54c8","c":"text","roomid":"a"}
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
				
				$db = new swoole_mysql;
				$server = array(
				    'host' => '47.90.39.2',
				    'user' => 'root',
				    'password' => 'yyj1988615',
				    'database' => 'chat',
				);
				
				$db->connect($server, function ($db, $r, $data) {
				    var_dump($data);
				    if ($r === false) {
				        var_dump($db->connect_errno, $db->connect_error);
				        die;
				    }
				    $sql = "INSERT INTO message ( room_id, user_id, message, add_time ) VALUES ( 1, 1, '{$data['message']}', 1);";
				    echo $sql."\n";
				    $db->query($sql, function(swoole_mysql $db, $r) {
				        global $s;
				        if ($r === false)
				        {
				            var_dump($db->error, $db->errno);
				        }
				        elseif ($r === true )
				        {
				            var_dump($db->affected_rows, $db->insert_id);
				        }
				        var_dump($r);
				        $db->close();
				    });
				});
				break;
			case 3: // 改变房间:{"type":3,"name":"admin","avatar":"http://47.90.39.2:8081/static/images/avatar/f1/f_2.jpg","oldroomid":"a","roomid":"b"}
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