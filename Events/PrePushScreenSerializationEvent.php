<?php
/**
 * @file
 * This file is a part of the Os2Display CoreBundle.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Os2Display\CoreBundle\Events;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class PrePushChannelsEvent
 */
class PrePushScreenSerializationEvent extends Event
{
    const NAME = 'os2display.core.pre_push_screen_serialization';

    protected $screenObject;

    /**
     * PrePushScreenSerializationEvent constructor.
     * @param \stdClass $screenObject
     */
    public function __construct(\stdClass $screenObject)
    {
        $this->screenObject = $screenObject;
    }

    public function getScreenObject()
    {
        return $this->screenObject;
    }

    public function setScreenObject(\stdClass $screenObject)
    {
        $this->screenObject = $screenObject;
    }
}
