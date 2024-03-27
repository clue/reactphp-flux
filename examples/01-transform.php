<?php

require __DIR__ . '/../vendor/autoload.php';

$browser = new React\Http\Browser();

$concurrency = isset($argv[1]) ? $argv[1] : 3;

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here
$transformer = new Clue\React\Flux\Transformer($concurrency, function ($user) use ($browser) {
    // skip users that do not have an IP address listed
    if (!isset($user['ip'])) {
        $user['country'] = 'n/a';

        return React\Promise\resolve($user);
    }

    // look up country for this user's IP
    return $browser->get("https://ipapi.co/$user[ip]/country_name/")->then(
        function (Psr\Http\Message\ResponseInterface $response) use ($user) {
            // response successfully received
            // add country to user array and return updated user
            $user['country'] = (string)$response->getBody();

            return $user;
        }
    );
});

// load a huge number of users to process from NDJSON file
$input = new Clue\React\NDJson\Decoder(
    new React\Stream\ReadableResourceStream(
        fopen(__DIR__ . '/users.ndjson', 'r')
    ),
    true
);

// process all users by piping through transformer
$input->pipe($transformer);

// log transformed output results
$transformer->on('data', function ($user) {
    echo $user['name'] . ' is from ' . $user['country'] . PHP_EOL;
});
$transformer->on('end', function () {
    echo '[DONE]' . PHP_EOL;
});
$transformer->on('error', 'printf');
