<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../packages/core/bootstrap.php';

use MCMA\Core\Web\HttpRequest;
use MCMA\Core\Web\WebFactory;

WebFactory::fromEnvironment()
    ->handle(HttpRequest::fromGlobals())
    ->send();
