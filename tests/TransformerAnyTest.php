<?php

namespace Clue\Tests\React\Mq;

use Clue\React\Flux\Transformer;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Stream\ThroughStream;

class TransformerAnyTest extends TestCase
{
    public function testWillRejectWithInvalidArgumentExceptionWhenConcurrencyIsInvalid()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(true);

        $promise = Transformer::any($stream, 0, function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $promise->then(null, $this->expectCallableOnce($this->isInstanceOf('InvalidArgumentException')));
    }

    public function testWillRejectWithInvalidArgumentExceptionWhenHandlerIsInvalid()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(true);

        $promise = Transformer::any($stream, 1, 'foobar');

        $promise->then(null, $this->expectCallableOnce($this->isInstanceOf('InvalidArgumentException')));
    }

    public function testWillRejectWithUnderflowExceptionWithoutInvokingHandlerWhenStreamIsAlreadyClosed()
    {
        $stream = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stream->expects($this->once())->method('isReadable')->willReturn(false);

        $promise = Transformer::any($stream, 1, $this->expectCallableNever());

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('UnderflowException')));
    }

    public function testWillRejectWithoutInvokingHandlerWhenStreamEmitsError()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, $this->expectCallableNever());

        $stream->emit('error', array(new \RuntimeException()));

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testWillRejectWithUnderflowExceptionWithoutInvokingHandlerWhenStreamEndsWithoutData()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, $this->expectCallableNever());

        $stream->end();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('UnderflowException')));
    }

    public function testWillRejectWithUnderflowExceptionWithoutInvokingHandlerWhenStreamClosesWithoutData()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, $this->expectCallableNever());

        $stream->close();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('UnderflowException')));
    }

    public function testWillPassInputDataToHandler()
    {
        $stream = new ThroughStream();

        $received = null;
        Transformer::any($stream, 1, function ($data) use (&$received) {
            $received = $data;
            return new Promise(function () { });
        });

        $stream->write('foo');

        $this->assertEquals('foo', $received);
    }

    public function testWillResolveWithHandlerResultIfHandlerResolves()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, function ($arg) {
            return \React\Promise\resolve(strtoupper($arg));
        });

        $stream->end('hello');

        $promise->then($this->expectCallableOnceWith('HELLO'));
    }

    public function testWillResolveWithHandlerResultIfHandlerResolvesAndWillCloseInputStream()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, function ($arg) {
            return \React\Promise\resolve(strtoupper($arg));
        });

        $stream->on('close', $this->expectCallableOnce());
        $stream->write('hello');

        $promise->then($this->expectCallableOnceWith('HELLO'));
    }

    public function testWillResolveWithFirstHandlerResultIfInputStreamKeepsEmittingData()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $stream->write('hello');
        $stream->write('world');

        $promise->then($this->expectCallableOnceWith('hello'));
    }

    public function testWillStayPendingIfSingleHandlerRejects()
    {
        $stream = new ThroughStream();

        $promise = Transformer::any($stream, 1, function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $stream->on('close', $this->expectCallableNever());
        $stream->write('hello');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testCancelResultingPromiseWillCloseInputStream()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableOnce());

        $promise = Transformer::any($stream, 1, $this->expectCallableNever());

        $promise->cancel();
    }

    public function testCancelResultingPromiseWillCancelPendingOperation()
    {
        $stream = new ThroughStream();

        $pending = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Transformer::any($stream, 1, function () use ($pending) {
            return $pending;
        });

        $stream->write('hello');

        $promise->cancel();
    }

    public function testWillStartQueuedOperationIfPendingOperationRejects()
    {
        $stream = new ThroughStream();

        $first = new Deferred();
        $second = new Promise(function () { });

        $started = 0;
        $promise = Transformer::any($stream, 1, function ($promise) use (&$started) {
            ++$started;
            return $promise;
        });

        $stream->write($first->promise());
        $stream->write($second);
        $this->assertEquals(1, $started);

        $first->reject(new \RuntimeException());
        $this->assertEquals(2, $started);

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPendingOperationWillBeCancelledIfOneOperationResolves()
    {
        $stream = new ThroughStream();

        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Transformer::any($stream, 2, function ($promise) {
            return $promise;
        });

        $stream->write($first->promise());
        $stream->write($second);

        $first->resolve('hello');

        $promise->then($this->expectCallableOnceWith('hello'));
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
