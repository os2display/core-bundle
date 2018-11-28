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
 * Class CleanupEvent
 * @package Os2Display\CoreBundle\Events
 */
class CleanupEvent extends Event
{
    const EVENT_CLEANUP_CHANNELS = 'os2display.core.cleanup_channels';

    protected $entities;

    /**
     * Constructor
     *
     * @param array $entities
     */
    public function __construct(array $entities)
    {
        $this->entities = $entities;
    }

    public function getEntities()
    {
        return $this->entities;
    }

    public function setEntities(array $entities) {
        $this->entities = $entities;
    }
}
