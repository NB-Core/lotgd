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
 * Event is the base class for classes containing event data.
 *
 * This class contains no event data. It is used by events that do not pass
 * state information to an event handler when an event is raised.
 *
 * You can call the method stopPropagation() to abort the execution of
 * further listeners in your event listener.
 */
class Event
{
    /**
     * Event type
     *
     * @var string Read only property
     */
    protected $eventType;

    /**
     * @var bool Whether no further event listeners should be triggered
     */
    protected $stopped = false;

    /**
     * @param string $type
     */
    public function __construct($type)
    {
        $this->eventType = (string) $type;
    }

    /**
     * Returns whether further event listeners should be triggered.
     *
     * @see Event::stopPropagation()
     * @return bool Whether propagation was already stopped for this event.
     */
    public function isPropagationStopped()
    {
        return $this->stopped;
    }

    /**
     * Stops the propagation of the event to further event listeners.
     *
     * If multiple event listeners are connected to the same event, no
     * further event listener will be triggered once any trigger calls
     * stopPropagation().
     */
    public function stopPropagation()
    {
        $this->stopped = true;
    }

    /**
     * Get event type
     *
     * @return string
     */
    public function getEventType()
    {
        return $this->eventType;
    }
}
