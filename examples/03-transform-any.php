<?php

use Clue\React\Flux\Transformer;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$browser = new React\Http\Browser();

$concurrency = isset($argv[1]) ? $argv[1] : 3;
$url = isset($argv[2]) ? $argv[2] : 'http://httpbin.org/post';

// load a huge number of users to process from NDJSON file
$input = new Clue\React\NDJson\Decoder(
    new React\Stream\ReadableResourceStream(
        fopen(__DIR__ . '/users.ndjson', 'r')
    ),
    true
);

// each job should use the browser to POST each user object to a certain URL
// process all users by processing all users through transformer
// limit number of concurrent jobs here
$promise = Transformer::any($input, $concurrency, function ($user) use ($browser, $url) {
    return $browser->post(
        $url,
        array('Content-Type' => 'application/json'),
        json_encode($user)
    )->then(function (ResponseInterface $response) use ($user) {
        // demo HTTP response validation
        $body = json_decode($response->getBody());
        if (!isset($body->json)) {
            throw new RuntimeException('Unexpected response');
        }

        // demo result includes full user from NDJSON with additional properties
        $user['result'] = $body;
        return $user;
    });
});

$promise->then(
    function ($user) {
        echo 'Successfully processed user record:' . print_r($user, true) . PHP_EOL;
    },
    function (Exception $e) {
        echo 'An error occurred: ' . $e->getMessage() . PHP_EOL;
        if ($e->getPrevious()) {
            echo 'Previous: ' . $e->getPrevious()->getMessage() . PHP_EOL;
        }
    }
);

