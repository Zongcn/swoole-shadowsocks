<?php
class Client
{
    public $swoole_client;

    private function onConnect(swoole_client $client)
    {

    }

    private function onReceive(swoole_client $client, $data)
    {

    }

    public function run($ip, $port)
    {
        $swoole_client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $this->swoole_client = $swoole_client;
        $this->swoole_client->on('receive', array($this, 'onReceive'));
        $this->swoole_client->on('connect', array($this, 'onConnect'));
        $this->swoole_client->connect($ip, $port);
    }
}