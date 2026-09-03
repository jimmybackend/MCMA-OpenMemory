<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$conf=file_get_contents($root.'/config/nginx/mcma-mailit-v1.conf');
$deploy=file_get_contents($root.'/scripts/deploy-mailit-nginx.sh');

function nginx_mailit_ok(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}

nginx_mailit_ok(is_string($conf)&&$conf!=='','mailit.click MCMA fragment missing');
nginx_mailit_ok(is_string($deploy)&&$deploy!=='','mailit.click deploy script missing');

foreach([
    'location = /mcma',
    'location = /mcma/',
    'location = /mcma/app.js',
    'location = /mcma/app.css',
    'location = /mcma/admin.html',
    'location = /mcma/admin.js',
    'location = /mcma/login',
    'location = /mcma/callback',
    'location = /mcma/logout',
    'location ^~ /mcma/v1/',
    'location ^~ /mcma/',
] as $route){
    nginx_mailit_ok(str_contains($conf,$route.' {'),'Missing managed route: '.$route);
}

nginx_mailit_ok(substr_count($conf,'fastcgi_read_timeout 180s;')===4,'Expected four MCMA FastCGI read timeouts');
nginx_mailit_ok(substr_count($conf,'fastcgi_send_timeout 180s;')===4,'Expected four MCMA FastCGI send timeouts');
nginx_mailit_ok(substr_count($conf,'fastcgi_pass unix:/run/php-fpm-mcma/mcma.sock;')===4,'Expected four dedicated MCMA PHP-FPM routes');
nginx_mailit_ok(str_contains($conf,'/var/www/memory/apps/web/public/index.php'),'Production checkout path missing');
nginx_mailit_ok(!str_contains($conf,'MCMA_MASTER_KEY_B64'),'Nginx fragment must not contain key material');
nginx_mailit_ok(!str_contains($conf,'AWS_'),'Nginx fragment must not contain AWS credentials');
nginx_mailit_ok(!preg_match('/\bserver\s*\{/',$conf),'Managed file must remain a server-block fragment');
nginx_mailit_ok(!str_contains($conf,'location ^~ /mcma/v2/'),'Managed V1 fragment must not capture historical V2 routes');

nginx_mailit_ok(str_contains($deploy,'PARENT_CONF="/etc/nginx/mcma-mailit.conf"'),'Deployer parent path mismatch');
nginx_mailit_ok(str_contains($deploy,'TARGET_FRAGMENT="/etc/nginx/mcma-mailit-v1-managed.conf"'),'Deployer target path mismatch');
nginx_mailit_ok(str_contains($deploy,'nginx -t'),'Deployer must validate Nginx');
nginx_mailit_ok(str_contains($deploy,'systemctl reload nginx'),'Deployer must reload Nginx only after validation');
nginx_mailit_ok(str_contains($deploy,'rollback'),'Deployer rollback missing');
nginx_mailit_ok(str_contains($deploy,'location ^~ /mcma/v1/'),'Deployer adoption rules missing V1 API');
nginx_mailit_ok(!str_contains($deploy,'location ^~ /mcma/v2/'),'Deployer must not remove historical V2 routes');

echo "MCMA mailit.click Nginx repository config integration passed.\n";
