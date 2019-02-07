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
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\SharedChannel;

/**
 * Class PrePushChannelEvent
 */
class PrePushChannelEvent extends Event
{
    const EVENT_PRE_PUSH_CHANNEL = 'os2display.core.pre_push_channel';

    protected $channel;
    protected $data;

    /**
     * Constructor
     *
     * @param Channel|SharedChannel $channel
     *   Channel or SharedChannel that is about to be pushed.
     * @param string $data
     *   JSON encoded data for channel.
     */
    public function __construct($channel, $data)
    {
        $this->channel = $channel;
        $this->data = $data;
    }

    public function getEntity() {
        return $this->channel;
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data) {
        $this->data = $data;
    }
}
