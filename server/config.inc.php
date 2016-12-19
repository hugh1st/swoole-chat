<?php

error_reporting(E_ALL ^ E_NOTICE);
define("STORAGE","file"); //file 文件存储，mysql 数据库存储，redis 缓存存储
define("DOMAIN","http://47.90.39.2:8081");
define('ONLINE_DIR','/usr/share/nginx/swoole-chat/rooms/');

/*房间配置*/
$rooms = array(
	'a' => '齐',
	'b' => '楚',
	'c' => '秦',
	'd' => '燕',
	'e' => '赵',
	'f' => '魏',
    'g' => '韩'
);

?>