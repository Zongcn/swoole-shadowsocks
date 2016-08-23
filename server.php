<?php
define('WWW_PATH', __DIR__);
// 请求地址类型
define('ADDRTYPE_IPV4', 1);
define('ADDRTYPE_IPV6', 4);
define('ADDRTYPE_HOST', 3);

spl_autoload_register(function ($className) {
    require WWW_PATH . '/Librarys/' . str_replace('\\', '/', $className) . '.php';
});
require_once WWW_PATH . '/Applications/Shadowsocks/Config.php';
require_once WWW_PATH . '/Applications/Shadowsocks/Function.php';
require_once WWW_PATH . '/Applications/Shadowsocks/Encryptor.php';
require_once WWW_PATH . '/Applications/Shadowsocks/Server.php';

$server= new Server;
$server->run();