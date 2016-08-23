<?php
class Server
{
    protected $frontends;
    protected $backends;
    protected $encryptor;
    protected $log;
    /**
     * @var swoole_server
     */
    protected $serv;
    protected $index = 0;
    protected $mode = SWOOLE_PROCESS;

    public function run()
    {
        $serv = new swoole_server("0.0.0.0", 9501, $this->mode, SWOOLE_SOCK_TCP);
        $serv->set(array(
            'worker_num' => 2, //worker process num
            //'backlog' => 128, //listen backlog
            //'open_tcp_keepalive' => 1,
            'backlog' => 128,
            'dispatch_mode' => 2,
            'daemonize' => true,
            'log_file' => WWW_PATH . '/Log/swoole.log', //swoole error log
        ));
        $serv->on('WorkerStart', array($this, 'onStart'));
        $serv->on('Close', array($this, 'onClose'));
        $serv->on('Connect', array($this, 'onConnect'));
        $serv->on('Receive', array($this, 'onReceive'));
        $serv->on('WorkerStop', array($this, 'onShutdown'));
        $serv->start();
    }

    public function onStart($serv)
    {
        $this->serv = $serv;
        $this->log = new Logger(WWW_PATH . '/Log');
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    public function onShutdown($serv)
    {
        echo "Server: onShutdown\n";
    }

    public function onClose($serv, $fd, $from_id)
    {
        //清理掉后端连接
        if (isset($this->frontends[$fd]))
        {
            $backend_socket = $this->frontends[$fd];
            $backend_socket->closing = true;
            $backend_socket->close();
            unset($this->backends[$backend_socket->sock]);
            unset($this->frontends[$fd]);
            unset($this->encryptor[$fd]);
        }
        echo "onClose: frontend[$fd]\n";
    }

    public function onConnect($serv, $fd)
    {
        $this->encryptor[$fd] = new Encryptor('12345678', 'aes-256-cfb');
    }

    public function onReceive($serv, $fd, $from_id, $data)
    {
        $encryptor = $this->encryptor[$fd];
        // 先解密数据
        $data = $encryptor->decrypt($data);
        //尚未建立连接
        if (!isset($this->frontends[$fd]))
        {
            // 解析socket5头
            $header_data = parse_socket5_header($data);
            // 头部长度
            $header_len = $header_data[3];
            // 解析头部出错，则关闭连接
            if(!$header_data) {
                $serv->close($fd);
                return;
            }
            // 解析得到实际请求地址及端口
            $host = $header_data[1];
            $port = $header_data[2];

            //连接到后台服务器
            $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            $socket->closing = false;
            $socket->on('connect', function (swoole_client $socket) use ($data, $header_len) {
                if(strlen($data) > $header_len) {
                    $data = substr($data, $header_len);
                    $socket->send($data);
                }
            });

            $socket->on('error', function (swoole_client $socket) use ($fd) {
                echo "ERROR: connect to backend server failed\n";
                $this->serv->send($fd, "backend server not connected. please try reconnect.");
                $this->serv->close($fd);
            });

            $socket->on('close', function (swoole_client $socket) use ($fd) {
                echo "onClose: backend[{$socket->sock}]\n";
                unset($this->backends[$socket->sock]);
                unset($this->frontends[$fd]);
                if (!$socket->closing) {
                    $this->serv->close($fd);
                }
            });

            // 远程连接发来消息时，进行加密，转发给shadowsocks客户端，shadowsocks客户端会解密转发给浏览器
            $socket->on('receive', function (swoole_client $socket, $_data) use ($fd, $encryptor) {
                //PHP-5.4以下版本可能不支持此写法，匿名函数不能调用$this
                //可以修改为类静态变量
                $_data = $encryptor->encrypt($_data);
                $this->serv->send($fd, $_data);
            });

            swoole_async_dns_lookup($host, function($host, $ip) use($socket, $port, $fd) {
                if (empty($ip)) {
                    $this->serv->close($fd);
                    return;
                }
                if ($socket->connect($ip, $port)) {
                    $this->backends[$socket->sock] = $fd;
                    $this->frontends[$fd] = $socket;
                } else {
                    echo "ERROR: cannot connect to backend server.\n";
                    $this->serv->send($fd, "backend server not connected. please try reconnect.");
                    $this->serv->close($fd);
                }
            });

        }
        //已经有连接，可以直接发送数据
        else
        {
            /**
             * @var $socket swoole_client
             */
            $socket = $this->frontends[$fd];
            $socket->send($data);
        }
    }
}