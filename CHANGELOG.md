# Changelog

## 1.4.0 (2023-04-21)

*   Feature: Forward compatibility with upcoming Promise v3.
    (#26 by @clue)

*   Feature: Full support for PHP 8.2 and update test environment.
    (#25 by @clue)

*   Update documentation and simplify examples by updating to new default loop.
    (#23 and #24 by @PaulRotmann)

*   Improve test suite, ensure 100% code coverage and use GitHub actions for continuous integration (CI).
    (#21 and #27 by @clue)

## 1.3.0 (2020-10-16)

*   Enhanced documentation for ReactPHP's new HTTP client.
    (#20 by @SimonFrings)

*   Improve test suite and add `.gitattributes` to exclude dev files from exports.
    Prepare PHP 8 support, update to PHPUnit 9 and simplify test matrix.
    (#16, #18 and #19 by @SimonFrings)

## 1.2.0 (2020-04-17)

*   Feature: Add `any()` helper to await first successful fulfillment of operations.
    (#15 by @clue)

    ```php
    // new: limit concurrency while awaiting first operation to complete successfully
    $promise = Transformer::any($input, 3, function ($data) use ($browser, $url) {
        return $browser->post($url, [], json_encode($data));
    });

    $promise->then(function (ResponseInterface $response) {
        echo 'First successful response: ' . $response->getBody() . PHP_EOL;
    });
    ```

*   Improve test suite to run tests on PHP 7.4 and simplify test matrix
    and add support / sponsorship info.
    (#13 and #14 by @clue)

## 1.1.0 (2018-08-13)

*   Feature: Add `all()` helper to await successful fulfillment of all operations.
    (#11 by @clue)

    ```php
    // new: limit concurrency while awaiting all operations to complete
    $promise = Transformer::all($input, 3, function ($data) use ($browser, $url) {
        return $browser->post($url, [], json_encode($data));
    });

    $promise->then(function ($count) {
        echo 'All ' . $count . ' jobs successful!' . PHP_EOL;
    });
    ```

*   Feature: Forward compatibility with stable Stream v1.0 LTS.
    (#10 by @clue)

## 1.0.0 (2018-05-25)

*   First stable release, following SemVer

    I'd like to thank [@geertvanbommel](https://github.com/geertvanbommel),
    a fellow software architect specializing in database batch processing and
    API development, for sponsoring the first release! 🎉
    Thanks to sponsors like this, who understand the importance of open source
    development, I can justify spending time and focus on open source development
    instead of traditional paid work.

    > Did you know that I offer custom development services and issuing invoices for
      sponsorships of releases and for contributions? Contact me (@clue) for details.
