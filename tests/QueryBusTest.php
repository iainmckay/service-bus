<?php
/**
 * This file is part of the prooph/service-bus.
 * (c) 2014-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2015-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\ServiceBus;

use Prooph\Common\Event\ActionEvent;
use Prooph\ServiceBus\Exception\MessageDispatchException;
use Prooph\ServiceBus\Exception\RuntimeException;
use Prooph\ServiceBus\MessageBus;
use Prooph\ServiceBus\Plugin\InvokeStrategy\FinderInvokeStrategy;
use Prooph\ServiceBus\QueryBus;
use ProophTest\ServiceBus\Mock\CustomMessage;
use ProophTest\ServiceBus\Mock\ErrorProducer;
use ProophTest\ServiceBus\Mock\FetchSomething;
use ProophTest\ServiceBus\Mock\Finder;
use React\Promise\Deferred;
use React\Promise\Promise;

class QueryBusTest extends TestCase
{
    /**
     * @var QueryBus
     */
    private $queryBus;

    protected function setUp()
    {
        $this->queryBus = new QueryBus();
    }

    /**
     * @test
     */
    public function it_dispatches_a_message_using_the_default_process(): void
    {
        $fetchSomething = new FetchSomething(['filter' => 'todo']);

        $receivedMessage = null;
        $dispatchEvent = null;
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$receivedMessage, &$dispatchEvent) {
                $actionEvent->setParam(
                    MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                    function (FetchSomething $fetchSomething, Deferred $deferred) use (&$receivedMessage) {
                        $deferred->resolve($fetchSomething);
                    }
                );
                $dispatchEvent = $actionEvent;
            },
            MessageBus::PRIORITY_ROUTE
        );

        $promise = $this->queryBus->dispatch($fetchSomething);

        $promise->then(function ($result) use (&$receivedMessage) {
            $receivedMessage = $result;
        });

        $this->assertSame($fetchSomething, $receivedMessage);
        $this->assertTrue($dispatchEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLED));
    }

    /**
     * @test
     */
    public function it_triggers_all_defined_action_events(): void
    {
        $initializeIsTriggered = false;
        $detectMessageNameIsTriggered = false;
        $routeIsTriggered = false;
        $locateHandlerIsTriggered = false;
        $invokeFinderIsTriggered = false;
        $finalizeIsTriggered = false;

        //Should always be triggered
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$initializeIsTriggered) {
                $initializeIsTriggered = true;
            },
            MessageBus::PRIORITY_INITIALIZE
        );

        //Should be triggered because we dispatch a message that does not
        //implement Prooph\Common\Messaging\HasMessageName
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$detectMessageNameIsTriggered) {
                $detectMessageNameIsTriggered = true;
                $actionEvent->setParam(MessageBus::EVENT_PARAM_MESSAGE_NAME, 'custom-message');
            },
            MessageBus::PRIORITY_DETECT_MESSAGE_NAME
        );

        //Should be triggered because we did not provide a message-handler yet
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$routeIsTriggered) {
                $routeIsTriggered = true;
                if ($actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME) === 'custom-message') {
                    //We provide the message handler as a string (service id) to tell the bus to trigger the locate-handler event
                    $actionEvent->setParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER, 'error-producer');
                }
            },
            MessageBus::PRIORITY_ROUTE
        );

        //Should be triggered because we provided the message-handler as string (service id)
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$locateHandlerIsTriggered) {
                $locateHandlerIsTriggered = true;
                if ($actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER) === 'error-producer') {
                    $actionEvent->setParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER, new ErrorProducer());
                }
            },
            MessageBus::PRIORITY_LOCATE_HANDLER
        );

        //Should be triggered because the message-handler is not callable
        $this->queryBus->getActionEventEmitter()->attachListener(
            QueryBus::EVENT_DISPATCH,
            function (ActionEvent $actionEvent) use (&$invokeFinderIsTriggered) {
                $invokeFinderIsTriggered = true;
                $handler = $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER);
                if ($handler instanceof ErrorProducer) {
                    $handler->throwException($actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE));
                }
            },
            QueryBus::PRIORITY_INVOKE_HANDLER
        );

        //Should always be triggered
        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_FINALIZE,
            function (ActionEvent $actionEvent) use (&$finalizeIsTriggered) {
                if ($actionEvent->getParam(MessageBus::EVENT_PARAM_EXCEPTION) instanceof \Exception) {
                    $actionEvent->setParam(MessageBus::EVENT_PARAM_EXCEPTION, null);
                }
                $finalizeIsTriggered = true;
            },
            1000 // high priority
        );

        $customMessage = new CustomMessage('I have no further meaning');

        $this->queryBus->dispatch($customMessage);

        $this->assertTrue($initializeIsTriggered);
        $this->assertTrue($detectMessageNameIsTriggered);
        $this->assertTrue($routeIsTriggered);
        $this->assertTrue($locateHandlerIsTriggered);
        $this->assertTrue($invokeFinderIsTriggered);
        $this->assertTrue($finalizeIsTriggered);
    }

    /**
     * @test
     */
    public function it_uses_the_fqcn_of_the_message_if_message_name_was_not_provided_and_message_does_not_implement_has_message_name(): void
    {
        $handler = new Finder();

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $e) use ($handler) {
                if ($e->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME) === CustomMessage::class) {
                    $e->setParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER, $handler);
                }
            },
            MessageBus::PRIORITY_ROUTE
        );

        $this->queryBus->utilize(new FinderInvokeStrategy());

        $customMessage = new CustomMessage('foo');

        $promise = $this->queryBus->dispatch($customMessage);

        $this->assertSame($customMessage, $handler->getLastMessage());
        $this->assertInstanceOf(Promise::class, $promise);
        $this->assertInstanceOf(Deferred::class, $handler->getLastDeferred());
    }

    /**
     * @test
     */
    public function it_rejects_the_deferred_with_a_service_bus_exception_if_exception_is_not_handled_by_a_plugin(): void
    {
        $exception = null;

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function () {
                throw new \Exception('ka boom');
            },
            MessageBus::PRIORITY_INITIALIZE
        );

        $promise = $this->queryBus->dispatch('throw it');

        $promise->otherwise(function ($ex) use (&$exception) {
            $exception = $ex;
        });

        $this->assertInstanceOf(MessageDispatchException::class, $exception);
    }

    /**
     * @test
     */
    public function it_throws_exception_if_event_has_no_handler_after_it_has_been_set_and_event_was_triggered(): void
    {
        $exception = null;

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $e) {
                $e->setParam(QueryBus::EVENT_PARAM_MESSAGE_HANDLER, null);
            },
            MessageBus::PRIORITY_INITIALIZE
        );

        $promise = $this->queryBus->dispatch('throw it');

        $promise->otherwise(function ($ex) use (&$exception) {
            $exception = $ex;
        });

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertEquals('Message dispatch failed. See previous exception for details.', $exception->getMessage());
    }

    /**
     * @test
     */
    public function it_throws_exception_if_event_has_stopped_propagation(): void
    {
        $exception = null;

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $e) {
                throw new \RuntimeException('throw it!');
            },
            MessageBus::PRIORITY_INITIALIZE
        );

        $promise = $this->queryBus->dispatch('throw it');

        $promise->otherwise(function ($ex) use (&$exception) {
            $exception = $ex;
        });

        $this->assertInstanceOf(MessageDispatchException::class, $exception);
        $this->assertEquals('Message dispatch failed. See previous exception for details.', $exception->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertEquals('throw it!', $exception->getPrevious()->getMessage());
    }

    /**
     * @test
     */
    public function it_can_deactive_an_action_event_listener_aggregate(): void
    {
        $handler = new Finder();

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $e) use ($handler) {
                if ($e->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME) === CustomMessage::class) {
                    $e->setParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER, $handler);
                }
            },
            MessageBus::PRIORITY_ROUTE
        );

        $plugin = new FinderInvokeStrategy();
        $this->queryBus->utilize($plugin);
        $this->queryBus->deactivate($plugin);

        $customMessage = new CustomMessage('foo');

        $promise = $this->queryBus->dispatch($customMessage);

        $this->assertNull($handler->getLastMessage());
        $this->assertInstanceOf(Promise::class, $promise);
        $this->assertNull($handler->getLastDeferred());
    }

    /**
     * @test
     */
    public function it_throws_exception_if_message_was_not_handled(): void
    {
        $this->expectException(RuntimeException::class);

        $this->queryBus->getActionEventEmitter()->attachListener(
            MessageBus::EVENT_DISPATCH,
            function (ActionEvent $e) {
                $e->setParam(MessageBus::EVENT_PARAM_MESSAGE_HANDLER, new \stdClass());
            },
            MessageBus::PRIORITY_INITIALIZE
        );

        $promise = $this->queryBus->dispatch('throw it');

        $promise->done();
    }
}
