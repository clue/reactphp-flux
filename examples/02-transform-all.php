<?php

use Clue\React\Flux\Transformer;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$browser = new React\Http\Browser($loop);

$concurrency = isset($argv[1]) ? $argv[1] : 3;
$url = isset($argv[2]) ? $argv[2] : 'http://httpbin.org/post';

// load a huge number of users to process from NDJSON file
$input = new Clue\React\NDJson\Decoder(
    new React\Stream\ReadableResourceStream(
        fopen(__DIR__ . '/users.ndjson', 'r'),
        $loop
    ),
    true
);

// each job should use the browser to POST each user object to a certain URL
// process all users by processing all users through transformer
// limit number of concurrent jobs here
$promise = Transformer::all($input, $concurrency, function ($user) use ($browser, $url) {
    return $browser->post(
        $url,
        array('Content-Type' => 'application/json'),
        json_encode($user)
    )->then(function (ResponseInterface $response) {
        // demo HTTP response validation
        $body = json_decode($response->getBody());
        if (!isset($body->json)) {
            throw new RuntimeException('Unexpected response');
        }
    });
});

$promise->then(
    function ($count) {
        echo 'Successfully processed all ' . $count . ' user records' . PHP_EOL;
    },
    function (Exception $e) {
        echo 'An error occured: ' . $e->getMessage() . PHP_EOL;
        if ($e->getPrevious()) {
            echo 'Previous: ' . $e->getPrevious()->getMessage() . PHP_EOL;
        }
    }
);

$loop->run();
