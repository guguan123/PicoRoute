<?php
// config.php
return [
	'db-example' => [
		'dsn' => 'mysql:host=localhost;dbname=database_name',
		'user' => 'user_name',
		'pass' => 'Password123',
		'options' => array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION)
	],
	'routes' => [
		'/function' => function() use ($pr_dbh) {
			$res = new Response();
			$res->status = 200;
			$res->header('Content-Type: text/plain; charset=utf-8');
			$res->cache(30);
			$res->body = 'This is a function';
			return $res;
		},
		'/string' => 'This is a string',
		'/null' => null
	],
	'rewrite' => [
		'/generate_204' => '/null'
	],
	'headers' => [
		'/string' => ['aaaa: bbbb']
	]
];
