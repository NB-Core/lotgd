<?php
/*
 * This file is part of `lemonphp/event` project.
 *
 * (c) 2015-2016 LemonPHP Team
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Lemon\Event;

/**
 * Trait EventDispatcherTrait
 */
trait EventDispatcherTrait
{
    /**
     * @var array
     */
    protected $listeners = [];

    /**
     * @var array
     */
    protected $sorted = [];

    /**
     * {@inheritdoc}
     */
    public function dispatch($event)
    {
        if (!($event instanceof Event)) {
            $event = new Event($event);
        }

        if ($listeners = $this->getListeners($event->getEventType())) {
            $this->doDispatch($listeners, $event);
        }

        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function addListener($eventType, $listener, $priority = 0)
    {
        $this->listeners[$eventType][$priority][] = $listener;
        unset($this->sorted[$eventType]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeListener($eventType, $listener)
    {
        if (!isset($this->listeners[$eventType])) {
            return;
        }

        foreach ($this->listeners[$eventType] as $priority => $listeners) {
            if (false !== ($key = array_search($listener, $listeners, true))) {
                unset($this->listeners[$eventType][$priority][$key], $this->sorted[$eventType]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllListeners($eventType = null)
    {
        if (null !== $eventType) {
            unset($this->listeners[$eventType], $this->sorted[$eventType]);
        } else {
            $this->listeners = [];
            $this->sorted = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getListeners($eventType = null)
    {
        if (null !== $eventType) {
            if (!isset($this->listeners[$eventType])) {
                return [];
            }

            if (!isset($this->sorted[$eventType])) {
                $this->sortListeners($eventType);
            }

            return $this->sorted[$eventType];
        }

        foreach ($this->listeners as $eventType => $eventListeners) {
            if (!isset($this->sorted[$eventType])) {
                $this->sortListeners($eventType);
            }
        }

        return array_filter($this->sorted);
    }

    /**
     * {@inheritdoc}
     */
    public function hasListeners($eventType = null)
    {
        return (bool) count($this->getListeners($eventType));
    }

    /**
     * Gets the listener priority for a specific event.
     *
     * Returns null if the event or the listener does not exist.
     *
     * @param string   $eventType The name of the event
     * @param callable $listener  The listener
     *
     * @return int|null The event listener priority
     */
    public function getListenerPriority($eventType, $listener)
    {
        if (isset($this->listeners[$eventType])) {
            foreach ($this->listeners[$eventType] as $priority => $listeners) {
                if (false !== ($key = array_search($listener, $listeners, true))) {
                    return $priority;
                }
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventType => $params) {
            if (is_string($params)) {
                $this->addListener($eventType, [$subscriber, $params]);
            } elseif (is_string($params[0])) {
                $this->addListener($eventType, [$subscriber, $params[0]], isset($params[1]) ? intval($params[1]) : 0);
            } else {
                foreach ($params as $listener) {
                    $this->addListener(
                        $eventType,
                        [$subscriber, $listener[0]],
                        isset($listener[1]) ? intval($listener[1]) : 0
                    );
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventType => $params) {
            if (is_array($params) && is_array($params[0])) {
                foreach ($params as $listener) {
                    $this->removeListener($eventType, [$subscriber, $listener[0]]);
                }
            } else {
                $this->removeListener($eventType, [$subscriber, is_string($params) ? $params : $params[0]]);
            }
        }
    }

    /**
     * Triggers the listeners of an event.
     *
     * This method can be overridden to add functionality that is executed
     * for each listener.
     *
     * @param callable[] $listeners The event listeners.
     * @param Event      $event     The event object to pass to the event handlers/listeners.
     */
    protected function doDispatch($listeners, Event $event)
    {
        foreach ($listeners as $listener) {
            call_user_func($listener, $event);
            if ($event->isPropagationStopped()) {
                break;
            }
        }
    }

    /**
     * Sorts the internal list of listeners for the given event by priority.
     *
     * @param string $eventType The name of the event.
     */
    protected function sortListeners($eventType)
    {
        krsort($this->listeners[$eventType]);
        $this->sorted[$eventType] = call_user_func_array('array_merge', $this->listeners[$eventType]);
    }
}
