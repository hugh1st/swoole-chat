<?php 
$client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_SYNC); //同步阻塞
$client->connect('47.90.39.2', 9508, 0.5, 0);
$client->send("show tables");
$data = $client->recv();
