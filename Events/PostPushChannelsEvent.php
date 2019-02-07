<?php
/**
 * @file
 * This file is a part of the Os2Display CoreBundle.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Os2Display\CoreBundle\Events;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PostPushChannelsEvent
 */
class PostPushChannelsEvent extends Event
{
    const EVENT_POST_PUSH_CHANNELS = 'os2display.core.post_push_channels';
}
