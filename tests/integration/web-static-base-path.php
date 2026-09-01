<?php
declare(strict_types=1);

function check(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}

$root=dirname(__DIR__,2).'/apps/web/public';
$index=file_get_contents($root.'/index.html');
$app=file_get_contents($root.'/app.js');
$admin=file_get_contents($root.'/admin.html');

check(is_string($index),'index.html readable');
check(str_contains($index,'href="app.css"'),'index stylesheet is base-path relative');
check(str_contains($index,'href="admin.html"'),'admin link is base-path relative');
check(str_contains($index,'href="login"'),'login link is base-path relative');
check(str_contains($index,'src="app.js"'),'app script is base-path relative');

check(is_string($app),'app.js readable');
check(str_contains($app,"fetch('logout'"),'logout endpoint is base-path relative');
check(str_contains($app,"location.href='./'"),'logout return is base-path relative');

check(is_string($admin),'admin.html readable');
check(str_contains($admin,'href="app.css"'),'admin stylesheet is base-path relative');
check(str_contains($admin,'href="./"'),'admin back link is base-path relative');
check(str_contains($admin,'src="admin.js"'),'admin script is base-path relative');

echo "web static base-path integration ok\n";
