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
 * The EventDispatcher is the central point of Symfony's event listener system.
 *
 * Listeners are registered on the manager and events are dispatched through the
 * manager.
 */
class EventDispatcher implements EventDispatcherInterface
{
    use EventDispatcherTrait;
}
