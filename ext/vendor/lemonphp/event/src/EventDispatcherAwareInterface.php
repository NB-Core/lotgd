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
 * The EventDispatcherAware interface
 */
interface EventDispatcherAwareInterface
{
    /**
     * Set the EventDispatcher.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @return $this
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher = null);

    /**
     * Get the EventDispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher();
}
