<?php

namespace Clue\React\Flux;

use Evenement\EventEmitter;
use InvalidArgumentException;
use React\Promise\CancellablePromiseInterface;
use React\Stream\DuplexStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/**
 * The `Transformer` passes all input data through its transformation handler
 * and forwards the resulting output data.
 *
 * It uses ReactPHP's standard [streaming interfaces](#streaming) which allow
 * to process huge inputs without having to store everything in memory at once
 * and instead allows you to efficiently process its input in small chunks.
 * Any data you write to this stream will be passed through its transformation
 * handler which is responsible for processing and transforming this data and
 * also takes care of mangaging streaming throughput and back-pressure.
 *
 * The transformation handler can be any non-blocking (async) callable that uses
 * [promises](#promises) to signal its eventual results. This callable receives
 * a single data argument as passed to the writable side and must return a
 * promise. A succesful fulfillment value will be forwarded to the readable end
 * of the stream, while an unsuccessful rejection value will emit an `error`
 * event and then `close()` the stream.
 *
 * *Continue with reading more about [promises](#promises).*
 *
 * #### Promises
 *
 * This library works under the assumption that you want to concurrently handle
 * async operations that use a [Promise](https://github.com/reactphp/promise)-based API.
 *
 * The demonstration purposes, the examples in this documentation use the async
 * HTTP client [clue/reactphp-buzz](https://github.com/clue/reactphp-buzz), but you
 * may use any Promise-based API with this project. Its API can be used like this:
 *
 * ```php
 * $loop = React\EventLoop\Factory::create();
 * $browser = new Clue\React\Buzz\Browser($loop);
 *
 * $promise = $browser->get($url);
 * ```
 *
 * If you wrap this in a `Transformer` instance as given above, this code will look
 * like this:
 *
 * ```php
 * $loop = React\EventLoop\Factory::create();
 * $browser = new Clue\React\Buzz\Browser($loop);
 *
 * $transformer = new Transformer(10, function ($url) use ($browser) {
 *     return $browser->get($url);
 * });
 *
 * $transformer->write($url);
 * ```
 *
 * The `$transformer` instance is a `WritableStreaminterface`, so that writing to it
 * with `write($data)` will actually be forwarded as `$browser->get($data)` as
 * given in the `$handler` argument (more about this in the following section about
 * [streaming](#streaming)).
 *
 * Each operation is expected to be async (non-blocking), so you may actually
 * invoke multiple handlers concurrently (send multiple requests in parallel).
 * The `$handler` is responsible for responding to each request with a resolution
 * value, the order is not guaranteed.
 * These operations use a [Promise](https://github.com/reactphp/promise)-based
 * interface that makes it easy to react to when an operation is completed (i.e.
 * either successfully fulfilled or rejected with an error):
 *
 * ```php
 * $transformer = new Transformer(10, function ($url) use ($browser) {
 *     $promise = $browser->get($url);
 *
 *     return $promise->then(
 *         function ($response) {
 *             var_dump('Result received', $result);
 *
 *             return json_decode($response->getBody());
 *         },
 *         function (Exception $error) {
 *             var_dump('There was an error', $error->getMessage());
 *
 *             throw $error;
 *         }
 *     );
 * );
 * ```
 *
 * Each operation may take some time to complete, but due to its async nature you
 * can actually start any number of (queued) operations. Once the concurrency limit
 * is reached, this invocation will simply be queued and this stream will signal
 * to the writing side that it should pause writing, thus effectively throttling
 * the writable side (back-pressure). It will automatically start the next
 * operation once another operation is completed and signal to the writable side
 * that is may resume writing. This means that this is handled entirely
 * transparently and you do not need to worry about this concurrency limit
 * yourself.
 *
 * This example expects URI strings as input, sends a simple HTTP GET request
 * and returns the JSON-decoded HTTP response body. You can transform your
 * fulfillment value to anything that should be made available on the readable
 * end of your stream. Similar logic may be used to filter your input stream,
 * such as skipping certain input values or rejecting it by returning a rejected
 * promise. Accordingly, returning a rejected promise (the equivalent of
 * throwing an `Exception`) will result in an `error` event that tries to
 * `cancel()` all pending operations and then `close()` the stream.
 *
 * #### Timeout
 *
 * By default, this library does not limit how long a single operation can take,
 * so that the transformation handler may stay pending for a long time.
 * Many use cases involve some kind of "timeout" logic so that an operation is
 * cancelled after a certain threshold is reached.
 *
 * You can simply use [react/promise-timer](https://github.com/reactphp/promise-timer)
 * which helps taking care of this through a simple API.
 *
 * The resulting code with timeouts applied look something like this:
 *
 * ```php
 * use React\Promise\Timer;
 *
 * $transformer = new Transformer(10, function ($uri) use ($browser, $loop) {
 *     return Timer\timeout($browser->get($uri), 2.0, $loop);
 * });
 *
 * $transformer->write($uri);
 * ```
 *
 * The resulting stream can be consumed as usual and the above code will ensure
 * that execution of this operation can not take longer than the given timeout
 * (i.e. after it is actually started).
 *
 * Please refer to [react/promise-timer](https://github.com/reactphp/promise-timer)
 * for more details.
 *
 * #### Streaming
 *
 * The `Transformer` implements the [`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
 * and as such allows you to write to its writable input side and to consume
 * from its readable output side. Any data you write to this stream will be
 * passed through its transformation handler which is responsible for processing
 * and transforming this data (see above for more details).
 *
 * The `Transformer` takes care of passing data you pass on its writable side to
 * the transformation handler argument and forwarding resuling data to it
 * readable end.
 * Each operation may take some time to complete, but due to its async nature you
 * can actually start any number of (queued) operations. Once the concurrency limit
 * is reached, this invocation will simply be queued and this stream will signal
 * to the writing side that it should pause writing, thus effectively throttling
 * the writable side (back-pressure). It will automatically start the next
 * operation once another operation is completed and signal to the writable side
 * that is may resume writing. This means that this is handled entirely
 * transparently and you do not need to worry about this concurrency limit
 * yourself.
 *
 * The following examples use an async (non-blocking) transformation handler as
 * given above:
 *
 * ```php
 * $loop = React\EventLoop\Factory::create();
 * $browser = new Clue\React\Buzz\Browser($loop);
 *
 * $transformer = new Transformer(10, function ($url) use ($browser) {
 *     return $browser->get($url);
 * });
 * ```
 *
 * The `write(mixed $data): bool` method can be used to
 * transform data through the transformation handler like this:
 *
 * ```php
 * $transformer->on('data', function (ResponseInterface $response) {
 *     var_dump($response);
 * });
 *
 * $transformer->write('http://example.com/');
 * ```
 *
 * This callable receives a single data argument as passed to the writable side
 * and must return a promise. A succesful fulfillment value will be forwarded to
 * the readable end of the stream, while an unsuccessful rejection value will
 * emit an `error` event, try to `cancel()` all pending operations and and
 * `close()` the stream.
 *
 * Note that this class makes no assumptions about any data types. Whatever is
 * written to it, will be processed by the transformation handler. Whatever the
 * transformation handler yields will be forwarded to its readable end.
 *
 * The `end(mixed $data = null): bool` method can be used to
 * soft-close the stream once all transformation handlers are completed.
 * It will close the writable side, wait for all outstanding transformation
 * handlers to complete and then emit an `end` event and then `close()` the stream.
 * You may optionally pass a (non-null) `$data` argument which will be processed
 * just like a `write($data)` call immediately followed by an `end()` call.
 *
 * ```php
 * $transformer->on('data', function (ResponseInterface $response) {
 *     var_dump($response);
 * });
 * $transformer->on('end', function () {
 *     echo '[DONE]' . PHP_EOL;
 * });
 *
 * $transformer->end('http://example.com/');
 * ```
 *
 * The `close(): void` method can be used to
 * forcefully close the stream. It will try to `cancel()` all pending transformation
 * handlers and then immediately close the stream and emit a `close` event.
 *
 * ```php
 * $transformer->on('data', $this->expectCallableNever());
 * $transformer->on('close', function () {
 *     echo '[CLOSED]' . PHP_EOL;
 * });
 *
 * $transformer->write('http://example.com/');
 * $transformer->close();
 * ```
 *
 * The `pipe(WritableStreamInterface $dest): WritableStreamInterface` method can be used to
 * forward an input stream into the transformer and/or to forward the resulting
 * output stream to another stream.
 *
 * ```php
 * $source->pipe($transformer)->pipe($dest);
 * ```
 *
 * This piping context is particularly powerful because it will automatically
 * throttle the incoming source stream and wait for the transformation handler
 * to complete before resuming work (back-pressure). Any additional data events
 * will be queued in-memory and resumed as appropriate. As such, it allows you
 * to limit how many operations are processed at once.
 *
 * Because streams are one of the core abstractions of ReactPHP, a large number
 * of stream implementations are available for many different use cases. For
 * example, this allows you to use the following pseudo code to send an HTTP
 * request for each JSON object in a compressed NDJSON file:
 *
 * ```php
 * $transformer = new Transformer(10, function ($data) use ($http) {
 *     return $http->post('https://example.com/?id=' . $data['id'])->then(
 *         function ($response) use ($data) {
 *             return array('done' => $data['id']);
 *         }
 *     );
 * });
 *
 * $source->pipe($gunzip)->pipe($ndjson)->pipe($transformer)->pipe($dest);
 * ```
 *
 * Keep in mind that the transformation handler may return a rejected promise.
 * In this case, the stream will emit an `error` event and then `close()` the
 * stream. If you do not want the stream to end in this case, you explicitly
 * have to handle any rejected promises and return some placeholder value
 * instead, for example like this:
 *
 * ```php
 * $uploader = new Transformer(10, function ($data) use ($http) {
 *     return $http->post('https://example.com/?id=' . $data['id'])->then(
 *         function ($response) use ($data) {
 *             return array('done' => $data['id']);
 *         },
 *         function ($error) use ($data) {
 *             // HTTP request failed => return dummy indicator
 *             return array(
 *                 'failed' => $data['id'],
 *                 'reason' => $error->getMessage()
 *             );
 *         }
 *     );
 * });
 * ```
 *
 * @see DuplexStreamInterface
 */
final class Transformer extends EventEmitter implements DuplexStreamInterface
{
    private $readable = true;
    private $writable = true;
    private $closed = false;
    private $paused = false;
    private $drain = false;
    private $concurrency;
    private $callback;

    private $promises = array();
    private $queued = array();

    /**
     * Instantiates a new Transformer instance.
     *
     * You can create any number of transformation streams, for example when you
     * want to apply different transformations to different kinds of streams.
     *
     * The `$concurrency` parameter sets a new soft limit for the maximum number
     * of jobs to handle concurrently. Finding a good concurrency limit depends
     * on your particular use case. It's common to limit concurrency to a rather
     * small value, as doing more than a dozen of things at once may easily
     * overwhelm the receiving side. Using a `1` value will ensure that all jobs
     * are processed one after another, effectively creating a "waterfall" of
     * jobs. Using a value less than 1 will throw an `InvalidArgumentException`.
     *
     * ```php
     * // handle up to 10 jobs concurrently
     * $transformer = new Transformer(10, $handler);
     * ```
     *
     * ```php
     * // handle each job after another without concurrency (waterfall)
     * $transformer = new Transformer(1, $handler);
     * ```
     *
     * The `$handler` parameter must be a valid callable that accepts your job
     * parameter (the data from its writable side), invokes the appropriate
     * operation and returns a Promise as a placeholder for its future result
     * (which will be made available on its readable side).
     *
     * ```php
     * // using a Closure as handler is usually recommended
     * $transformer = new Transformer(10, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     * ```
     *
     * ```php
     * // accepts any callable, so PHP's array notation is also supported
     * $transformer = new Transformer(10, array($browser, 'get'));
     * ```
     *
     * @param int $concurrency
     * @param callable $handler
     * @throws InvalidArgumentException
     */
    public function __construct($concurrency, $handler)
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('Invalid concurrency limit given');
        }
        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Invalid transformation handler given');
        }

        $this->concurrency = $concurrency;
        $this->callback = $handler;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        if ($this->drain) {
            $this->drain = false;
            $this->emit('drain');
        }
        $this->paused = false;
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return Util::pipe($this, $dest, $options);
    }

    public function isReadable()
    {
        return $this->readable;
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function write($data)
    {
        if (!$this->writable) {
            return false;
        }

        if (count($this->promises) >= $this->concurrency) {
            $this->queued[] = $data;
            return false;
        }

        $this->processData($data);

        if (!$this->writable) {
            return false;
        }

        // stream explicitly in paused state or pending promises still above limit
        if ($this->paused || count($this->promises) >= $this->concurrency) {
            $this->drain = true;
            return false;
        }

        return true;
    }

    public function end($data = null)
    {
        if (!$this->writable) {
            return;
        }

        $this->writable = false;
        $this->drain = false;

        if (null !== $data) {
            if (count($this->promises) >= $this->concurrency) {
                $this->queued[] = $data;
            } else {
                $this->processData($data);
            }
        }

        // either already closed or awaiting any pending promises
        if ($this->closed || $this->promises) {
            return;
        }

        $this->readable = false;
        $this->emit('end');
        $this->close();
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->readable = false;
        $this->writable = false;
        $this->closed = true;
        $this->drain = false;
        $this->callback = null;
        $this->queued = array();

        foreach ($this->promises as $promise) {
            if ($promise instanceof CancellablePromiseInterface) {
                $promise->cancel();
            }
        }
        $this->promises = array();

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function processData($data)
    {
        $handler = $this->callback;
        $this->promises[] = $promise = $handler($data);
        end($this->promises);
        $id = key($this->promises);

        $that = $this;
        $promise->then(
            function ($result) use ($that, $id) {
                $that->handleResult($result, $id);
            },
            function ($error) use ($that, $id) {
                $that->handleError(
                    new \RuntimeException('Handler rejected', 0, $error),
                    $id
                );
            }
        );
    }

    /** @internal */
    public function handleResult($result, $id)
    {
        if ($this->closed) {
            return;
        }

        unset($this->promises[$id]);
        $this->emit('data', array($result));

        // process next queued item if still below concurrency limit
        if (count($this->promises) < $this->concurrency && $this->queued) {
            $data = array_shift($this->queued);
            $this->processData($data);
            return;
        }

        // end and close stream if this is the final end write
        if (!$this->writable && !$this->promises) {
            $this->readable = false;
            $this->emit('end');
            $this->close();
            return;
        }

        // nothing left to do? signal source stream to continue writing to this stream
        if ($this->writable && $this->drain) {
            $this->drain = false;
            $this->emit('drain');
        }
    }

    /** @internal */
    public function handleError(\Exception $e, $id)
    {
        if ($this->closed) {
            return;
        }

        unset($this->promises[$id]);
        $this->emit('error', array($e));
        $this->close();
    }
}
