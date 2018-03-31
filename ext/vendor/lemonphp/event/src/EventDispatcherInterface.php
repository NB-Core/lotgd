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
 * The EventDispatcherInterface is the central point of Symfony's event listener system.
 * Listeners are registered on the manager and events are dispatched through the
 * manager.
 */
interface EventDispatcherInterface
{
    /**
     * Dispatches an event to all registered listeners.
     *
     * @param Event|string  $event The event or event type to pass to the event handlers/listeners.
     * @return Event
     */
    public function dispatch($event);

    /**
     * Adds an event listener that listens on the specified events.
     *
     * @param string   $eventType The event to listen on
     * @param callable $listener  The listener. It passed Event object is first argument
     * @param int      $priority  The higher this value, the earlier an event
     *                            listener will be triggered in the chain (defaults to 0)
     */
    public function addListener($eventType, $listener, $priority = 0);

    /**
     * Removes an event listener from the specified events.
     *
     * @param string   $eventType The event to remove a listener from
     * @param callable $listener  The listener to remove
     */
    public function removeListener($eventType, $listener);

    /**
     * Removes all event listeners from the specified events.
     *
     * @param string $eventType
     */
    public function removeAllListeners($eventType = null);

    /**
     * Gets the listeners of a specific event or all listeners sorted by descending priority.
     *
     * @param string $eventType The name of the event
     *
     * @return array The event listeners for the specified event, or all event listeners by event name
     */
    public function getListeners($eventType = null);

    /**
     * Checks whether an event has any registered listeners.
     *
     * @param string $eventType The name of the event
     *
     * @return bool true if the specified event has any listeners, false otherwise
     */
    public function hasListeners($eventType = null);

    /**
     * Adds an event subscriber.
     *
     * The subscriber is asked for all the events he is
     * interested in and added as a listener for these events.
     *
     * @param EventSubscriberInterface $subscriber The subscriber
     */
    public function addSubscriber(EventSubscriberInterface $subscriber);

    /**
     * Removes an event subscriber.
     *
     * @param EventSubscriberInterface $subscriber The subscriber
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber);
}
