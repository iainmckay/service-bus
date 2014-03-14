<?php
/*
 * This file is part of the codeliner/php-service-bus.
 * (c) Alexander Miertsch <kontakt@codeliner.ws>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * Date: 11.03.14 - 22:59
 */

namespace Codeliner\ServiceBus\Event;

use Codeliner\ServiceBus\Message\MessageDispatcherInterface;
use Codeliner\ServiceBus\Message\MessageFactory;
use Codeliner\ServiceBus\Message\MessageFactoryInterface;
use Codeliner\ServiceBus\Message\QueueInterface;
use Codeliner\ServiceBus\Service\Definition;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;

/**
 * Class EventBus
 *
 * @package Codeliner\ServiceBus\Event
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
class EventBus implements EventBusInterface
{
    /**
     * @var MessageDispatcherInterface
     */
    protected $messageDispatcher;

    /**
     * @var QueueInterface[]
     */
    protected $queueCollection;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var MessageFactoryInterface
     */
    protected $messageFactory;

    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @param string                     $aName
     * @param MessageDispatcherInterface $aMessageDispatcher
     * @param QueueInterface[]           $aQueueCollection
     */
    public function __construct($aName, MessageDispatcherInterface $aMessageDispatcher, array $aQueueCollection)
    {
        \Assert\that($aName)->notEmpty('EventBus.name must not be empty')->string('EventBus.name must be a string');
        \Assert\that($aQueueCollection)->all()->isInstanceOf('Codeliner\ServiceBus\Message\QueueInterface');

        $this->name              = $aName;
        $this->messageDispatcher = $aMessageDispatcher;
        $this->queueCollection   = $aQueueCollection;
    }

    /**
     * @param EventInterface $anEvent
     *
     * @return void
     */
    public function publish(EventInterface $anEvent)
    {
        $results = $this->events()->trigger(__FUNCTION__ . '.pre', $this, array('event' => $anEvent));

        if ($results->stopped()) {
            return;
        }

        $message = $this->getMessageFactory()->fromEvent($anEvent, $this->name);

        foreach ($this->queueCollection as $queue) {

            $params = compact('message', 'queue');

            $results = $this->events()->trigger('publish_on_queue.pre', $this, $params);

            if ($results->stopped()) {
                continue;
            }

            $this->messageDispatcher->dispatch($queue, $message);

            $this->events()->trigger('publish_on_queue.post', $this, $params);
        }

        $this->events()->trigger(__FUNCTION__ . '.post', $this, array('event' => $anEvent, 'message' => $message));
    }

    /**
     * @param MessageFactoryInterface $aMessageFactory
     */
    public function setMessageFactory(MessageFactoryInterface $aMessageFactory)
    {
        $this->messageFactory = $aMessageFactory;
    }

    /**
     * @return MessageFactoryInterface
     */
    public function getMessageFactory()
    {
        if (is_null($this->messageFactory)) {
            $this->messageFactory = new MessageFactory();
        }

        return $this->messageFactory;
    }

    /**
     * @return EventManagerInterface
     */
    public function events()
    {
        if (is_null($this->events)) {
            $this->events = new EventManager(array(
                Definition::SERVICE_BUS_COMPONENT,
                'event_bus',
                __CLASS__
            ));
        }

        return $this->events;
    }
}
