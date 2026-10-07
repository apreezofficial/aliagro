<?php

require __DIR__ . '/../bootstrap/app.php';

App\Core\Kernel::handleRequest(App\Core\Request::capture())->send();
