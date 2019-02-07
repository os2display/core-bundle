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
class PrePushChannelsEvent extends Event
{
    const EVENT_PRE_PUSH_CHANNELS = 'os2display.core.pre_push_channels';

    protected $channels;

    /**
     * Constructor
     *
     * @param array $channels
     *   Array of channels that will be processed.
     */
    public function __construct(array $channels)
    {
        $this->channels = $channels;
    }
}
