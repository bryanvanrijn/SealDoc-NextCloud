<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#test', 'url' => '/config/test', 'verb' => 'GET'],
		['name' => 'seal#seal', 'url' => '/seal/{fileId}', 'verb' => 'POST'],
		['name' => 'seal#info', 'url' => '/seal/{fileId}', 'verb' => 'GET'],
	],
];
