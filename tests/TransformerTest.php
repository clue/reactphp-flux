<?php

namespace Clue\Tests\React\Flux;

use Clue\React\Flux\Transformer;
use PHPUnit\Framework\TestCase;
use React\Promise;
use React\Promise\Deferred;

class TransformerTest extends TestCase
{
    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsIfConcurrencyIsBelowOne()
    {
        new Transformer(0, function () { });
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsIfHandlerIsNotCallable()
    {
        new Transformer(1, 'foo');
    }

    public function testWriteEmitsDataEventIfHandlerResolves()
    {
        $through = new Transformer(1, function ($data) {
            return Promise\resolve($data);
        });

        $through->on('data', $this->expectCallableOnceWith('hello'));
        $ret = $through->write('hello');

        $this->assertTrue($ret);
    }

    public function testWriteAfterPauseEmitsDataEventIfHandlerResolves()
    {
        $through = new Transformer(1, function ($data) {
            return Promise\resolve($data);
        });

        $through->on('data', $this->expectCallableOnceWith('hello'));
        $through->pause();
        $ret = $through->write('hello');

        $this->assertFalse($ret);
    }

    public function testWriteDoesEmitDrainEventAfterHandlerResolves()
    {
        $deferred = new Deferred();
        $through = new Transformer(1, function ($data) use ($deferred) {
            return $deferred->promise();
        });

        $through->write('hello');

        $through->on('drain', $this->expectCallableOnce());
        $deferred->resolve();
    }

    public function testResumeAfterWriteAfterPauseEmitsDrainEventIfHandlerResolves()
    {
        $through = new Transformer(1, function ($data) {
            return Promise\resolve($data);
        });

        $through->pause();
        $through->write('hello');

        $through->on('drain', $this->expectCallableOnce());
        $through->resume();
    }

    public function testResumeAfterPauseDoesNotEmitDrainEventIfNothingWasWritten()
    {
        $through = new Transformer(1, $this->expectCallableNever());

        $through->on('drain', $this->expectCallableNever());
        $through->pause();
        $through->resume();
    }

    public function testWriteDoesNotEmitDataEventIfHandlerIsPending()
    {
        $through = new Transformer(1, function ($data) {
            return new Promise\Promise(function () { });
        });

        $through->on('data', $this->expectCallableNever());
        $ret = $through->write('hello');

        $this->assertFalse($ret);
    }

    public function testWriteAfterWriteDoesInvokeHandlerAgainIfHandlerResolves()
    {
        $pending = array();
        $through = new Transformer(1, function ($data) use (&$pending) {
            $pending[] = $data;
            return Promise\resolve($data);
        });

        $through->write('hello');
        $ret = $through->write('world');

        $this->assertTrue($ret);
        $this->assertEquals(array('hello', 'world'), $pending);
    }

    public function testWriteAfterWriteDoesNotInvokeHandlerAgainIfPreviousIsPending()
    {
        $pending = array();
        $through = new Transformer(1, function ($data) use (&$pending) {
            $pending[] = $data;
            return new Promise\Promise(function () { });
        });

        $through->write('hello');
        $ret = $through->write('world');

        $this->assertFalse($ret);
        $this->assertEquals(array('hello'), $pending);
    }

    public function testWriteAfterWriteDoesInvokeHandlersConcurrentlyIfPreviousIsPending()
    {
        $pending = array();
        $through = new Transformer(3, function ($data) use (&$pending) {
            $pending[] = $data;
            return new Promise\Promise(function () { });
        });

        $through->write('hello');
        $through->write('world');
        $ret = $through->write('again');

        $this->assertFalse($ret);
        $this->assertEquals(array('hello', 'world', 'again'), $pending);
    }

    public function testWriteAfterWriteDoesNotInvokeHandlersConcurrentlyIfConcurrencyIsExceeded()
    {
        $pending = array();
        $through = new Transformer(3, function ($data) use (&$pending) {
            $pending[] = $data;
            return new Promise\Promise(function () { });
        });

        $through->write('hello');
        $through->write('world');
        $through->write('again');
        $ret = $through->write('not');

        $this->assertFalse($ret);
        $this->assertEquals(array('hello', 'world', 'again'), $pending);
    }

    public function testWriteAfterWriteDoesInvokeHandlerAgainAfterPreviousHandlerResolves()
    {
        $pending = array();
        $deferred = new Deferred();
        $through = new Transformer(1, function ($data) use (&$pending, $deferred) {
            $pending[] = $data;
            return $deferred->promise();
        });

        $through->write('hello');
        $through->write('world');

        $deferred->resolve();

        $this->assertEquals(array('hello', 'world'), $pending);
    }

    public function testCloseEmitsCloseEvent()
    {
        $through = new Transformer(1, $this->expectCallableNever());

        $through->on('close', $this->expectCallableOnce());
        $through->close();

        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testCloseAfterCloseIsNoop()
    {
        $through = new Transformer(1, $this->expectCallableNever());
        $through->close();

        $through->on('close', $this->expectCallableNever());
        $through->close();
    }

    public function testCloseTriesToCancelPendingWriteHandler()
    {
        $once = $this->expectCallableOnce();
        $through = new Transformer(1, function () use ($once) {
            return new Promise\Promise(function () { }, $once);
        });

        $through->write('hello');
        $through->close();
    }

    public function testCloseTriesToCancelPendingEndHandler()
    {
        $once = $this->expectCallableOnce();
        $through = new Transformer(1, function () use ($once) {
            return new Promise\Promise(function () { }, $once);
        });

        $through->end('hello');
        $through->close();

        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testCloseDoesNotEmitErrorIfCancellingPendingWriteHandlerRejects()
    {
        $through = new Transformer(1, function () {
            return new Promise\Promise(function () { }, function () {
                throw new \RuntimeException();
            });
        });

        $through->on('data', $this->expectCallableNever());
        $through->on('error', $this->expectCallableNever());
        $through->on('close', $this->expectCallableOnce());

        $through->write('hello');
        $through->close();
    }

    public function testCloseDoesNotEmitDataIfCancellingPendingWriteHandlerResolves()
    {
        $through = new Transformer(1, function () {
            return new Promise\Promise(function () { }, function ($resolve) {
                $resolve('hello');
            });
        });

        $through->on('data', $this->expectCallableNever());
        $through->on('close', $this->expectCallableOnce());

        $through->write('hello');
        $through->close();
    }

    public function testWriteEmitsErrorAndCloseEventsIfHandlerRejects()
    {
        $through = new Transformer(1, function () {
            return Promise\reject(new \RuntimeException());
        });

        $through->on('data', $this->expectCallableNever());
        $through->on('error', $this->expectCallableOnce());
        $through->on('close', $this->expectCallableOnce());
        $ret = $through->write('hello');

        $this->assertFalse($ret);
        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testWriteAfterCloseDoesNotInvokeHandler()
    {
        $through = new Transformer(1, $this->expectCallableNever());
        $through->close();

        $ret = $through->write('hello');

        $this->assertFalse($ret);
        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testEndWithoutDataDoesEmitEndAndCloseEventsWithoutInvokingHandler()
    {
        $through = new Transformer(1, $this->expectCallableNever());

        $through->on('end', $this->expectCallableOnce());
        $through->on('close', $this->expectCallableOnce());
        $through->end();

        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testEndWithDataEmitsDataAndEndAndCloseEventsIfHandlerResolves()
    {
        $through = new Transformer(1, function ($data) {
            return Promise\resolve($data);
        });

        $through->on('data', $this->expectCallableOnceWith('hello'));
        $through->on('end', $this->expectCallableOnce());
        $through->on('close', $this->expectCallableOnce());
        $through->end('hello');

        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testEndWithDataEmitsErrorAndCloseEventsIfHandlerRejects()
    {
        $through = new Transformer(1, function () {
            return Promise\reject(new \RuntimeException());
        });

        $through->on('data', $this->expectCallableNever());
        $through->on('end', $this->expectCallableNever());
        $through->on('error', $this->expectCallableOnce());
        $through->on('close', $this->expectCallableOnce());
        $through->end('hello');

        $this->assertFalse($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testEndWithDataDoesNotEmitEndAndCloseEventsIfHandlerIsPending()
    {
        $through = new Transformer(1, function () {
            return new Promise\Promise(function () { });
        });

        $through->on('data', $this->expectCallableNever());
        $through->on('end', $this->expectCallableNever());
        $through->on('error', $this->expectCallableNever());
        $through->on('close', $this->expectCallableNever());
        $through->end('hello');

        $this->assertTrue($through->isReadable());
        $this->assertFalse($through->isWritable());
    }

    public function testEndDoesEmitDataAndEndAndCloseEventsAfterHandlerResolves()
    {
        $deferred = new Deferred();
        $through = new Transformer(1, function ($data) use ($deferred) {
            return $deferred->promise();
        });

        $through->end('hello');

        $through->on('data', $this->expectCallableOnceWith('hello'));
        $through->on('end', $this->expectCallableOnce());
        $through->on('close', $this->expectCallableOnce());
        $deferred->resolve('hello');
    }

    public function testEndWithDataAfterWriteAfterWriteDoesInvokeHandlerAgainInCorrectOrderAfterHandlerResolves()
    {
        $pending = array();
        $deferred = new Deferred();
        $through = new Transformer(1, function ($data) use (&$pending, $deferred) {
            $pending[] = $data;
            return $deferred->promise();
        });

        $through->write('hello');
        $through->write('world');
        $through->end('again');
        $deferred->resolve();

        $this->assertEquals(array('hello', 'world', 'again'), $pending);
    }

    public function testEndDoesNotEmitDrainEventAfterHandlerResolves()
    {
        $deferred = new Deferred();
        $through = new Transformer(1, function ($data) use ($deferred) {
            return $deferred->promise();
        });

        $through->end('hello');

        $through->on('drain', $this->expectCallableNever());
        $deferred->resolve();
    }

    public function testEndAfterCloseIsNoop()
    {
        $through = new Transformer(1, $this->expectCallableNever());
        $through->close();

        $through->on('end', $this->expectCallableNever());
        $through->on('close', $this->expectCallableNever());
        $through->end();
    }

    public function testPipeReturnsDestinationStream()
    {
        $through = new Transformer(1, $this->expectCallableNever());

        $dest = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $ret = $through->pipe($dest);

        $this->assertSame($ret, $dest);
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
