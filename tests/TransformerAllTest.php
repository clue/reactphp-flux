<?php

namespace Clue\Tests\React\Flux;

use Clue\React\Flux\Transformer;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Stream\ThroughStream;

class TransformerAllTest extends TestCase
{
    public function testAllRejectsIfConcurrencyIsInvalid()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(true);

        Transformer::all($stream, 0, function ($arg) {
            return \React\Promise\resolve($arg);
        })->then(null, $this->expectCallableOnce());
    }

    public function testAllRejectsIfHandlerIsInvalid()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(true);

        Transformer::all($stream, 1, 'foobar')->then(null, $this->expectCallableOnce());
    }

    public function testWillResolveWithIntZeroWithoutInvokingHandlerWhenStreamIsAlreadyClosed()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(false);

        $promise = Transformer::all($stream, 1, $this->expectCallableNever());

        $promise->then($this->expectCallableOnceWith(0));
    }

    public function testWillRejectWithoutInvokingHandlerWhenStreamEmitsError()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, $this->expectCallableNever());

        $stream->emit('error', array(new \RuntimeException()));

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testWillResolveWithIntZeroWithoutInvokingHandlerWhenStreamEndsWithoutData()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, $this->expectCallableNever());

        $stream->end();

        $promise->then($this->expectCallableOnceWith(0));
    }

    public function testWillResolveWithIntZeroWithoutInvokingHandlerWhenStreamClosesWithoutData()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, $this->expectCallableNever());

        $stream->close();

        $promise->then($this->expectCallableOnceWith(0));
    }

    public function testWillPassInputDataToHandler()
    {
        $stream = new ThroughStream();

        $received = null;
        Transformer::all($stream, 1, function ($data) use (&$received) {
            $received = $data;
            return new Promise(function () { });
        });

        $stream->write('foo');

        $this->assertEquals('foo', $received);
    }

    public function testWillResolveWithIntOneIfHandlerResolves()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $stream->end('anything');

        $promise->then($this->expectCallableOnceWith(1));
    }

    public function testWillNotResolveIfInputStreamDoesNotClose()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $stream->write('anything');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testWillResolveWithIntTwoForAllValuesIfHandlerResolves()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $stream->write('hello');
        $stream->write('world');
        $stream->end();

        $promise->then($this->expectCallableOnceWith(2));
    }

    public function testWillRejectAndCloseInputStreamIfSingleHandlerRejects()
    {
        $stream = new ThroughStream();

        $promise = Transformer::all($stream, 1, function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $stream->on('close', $this->expectCallableOnce());
        $stream->write('hello');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCancelResultingPromiseWillCloseInputStream()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableOnce());

        $promise = Transformer::all($stream, 1, $this->expectCallableNever());

        $promise->cancel();
    }

    public function testCancelResultingPromiseWillCancelPendingOperation()
    {
        $stream = new ThroughStream();

        $pending = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Transformer::all($stream, 1, function () use ($pending) {
            return $pending;
        });

        $stream->write('hello');

        $promise->cancel();
    }

    public function testWillNotStartQueuedOperationIfOneOperationRejects()
    {
        $stream = new ThroughStream();

        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableNever());

        $promise = Transformer::all($stream, 1, function ($promise) {
            return $promise;
        });

        $stream->write($first->promise());
        $stream->write($second);

        $first->reject(new \RuntimeException());

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testPendingOperationWillBeCancelledIfOneOperationRejects()
    {
        $stream = new ThroughStream();

        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Transformer::all($stream, 2, function ($promise) {
            return $promise;
        });

        $stream->write($first->promise());
        $stream->write($second);

        $first->reject(new \RuntimeException());

        $promise->then(null, $this->expectCallableOnce());
    }

    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();

        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($param)
    {
        $mock = $this->createCallableMock();

        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($param);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
    }
}
