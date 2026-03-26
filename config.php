<?php
// config.php
return [
	'db' => [
		'dsn' => 'mysql:host=localhost;dbname=database_name',
		'user' => 'user_name',
		'pass' => 'Password123',
		'options' => array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION)
	],
	'routes' => [
		'/function' => function() use ($dbh) {
			$res = new Response();
			$res->header('Content-Type: text/plain; charset=utf-8');
			$res->cache(30);
			$res->body = 'This is a function'
		},
		'/string' => 'This is a string',
		'/null' => null
	]
];
