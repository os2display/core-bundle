<?php

namespace Os2Display\CoreBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\Screen;
use JMS\Serializer\SerializationContext;
use Doctrine\Common\Cache\CacheProvider;
use Os2Display\CoreBundle\Entity\ScreenTemplate;
use Os2Display\CoreBundle\Entity\SharedChannel;
use Os2Display\CoreBundle\Events\PrePushScreenSerializationEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MiddlewareService
{
    protected $serializer;
    protected $entityManager;
    protected $channelRepository;
    protected $sharedChannelRepository;
    protected $screenRepository;
    protected $cache;
    protected $cacheTTL;
    protected $dispatcher;

    /**
     * MiddlewareService constructor.
     * @param \Doctrine\ORM\EntityManagerInterface $entityManager
     * @param \JMS\Serializer\SerializerInterface $serializer
     * @param \Doctrine\Common\Cache\CacheProvider $cache
     * @param $cacheTTL
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        CacheProvider $cache,
        $cacheTTL,
        EventDispatcherInterface $dispatcher
    ) {
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->channelRepository = $entityManager->getRepository(
            Channel::class
        );
        $this->sharedChannelRepository = $entityManager->getRepository(SharedChannel::class);
        $this->screenRepository = $entityManager->getRepository(Screen::class);
        $this->cache = $cache;
        $this->cacheTTL = $cacheTTL;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Get screen as an array.
     *
     * @param $screen
     * @return mixed|string
     */
    public function getScreenArray($screen)
    {
        $data = $this->serializer->serialize(
            $screen,
            'json',
            SerializationContext::create()
                ->setGroups(array('middleware'))
        );

        $data = json_decode($data);

        return $data;
    }

    /**
     * Get channel as an array.
     *
     * @param $channel
     * @return mixed|string
     */
    public function getChannelArray($channel)
    {
        $data = $this->serializer->serialize(
            $channel,
            'json',
            SerializationContext::create()
                ->setGroups(array('middleware'))
        );

        $data = json_decode($data);

        return $data;
    }

    /**
     * Get shared channel as an array.
     *
     * @param $channel
     * @return mixed|string
     */
    public function getSharedChannelArray($channel)
    {
        $data = $this->serializer->serialize(
            $channel,
            'json',
            SerializationContext::create()
                ->setGroups(array('middleware'))
        );

        $data = json_decode($data);

        return $data;
    }

    /**
     * Get a fake screen array of 'full-screen' screen template, where
     * channel is inserted in region 1.
     *
     * @param $channelId
     * @return false|mixed|object
     */
    public function getCurrentChannelArray($channelId) {
        $cachedResult = $this->cache->fetch('os2display.core.channel.' . $channelId);

        if ($cachedResult != false) {
            return $cachedResult;
        }

        $channelRepository = $this->entityManager->getRepository(Channel::class);

        $channel = $channelRepository->findOneById($channelId);
        $channelArray = $this->getChannelArray($channel);

        $channelArray->regions = [1];

        // Use full-screen screen template.
        $templateRepository = $this->entityManager->getRepository(ScreenTemplate::class);
        $templateArray = json_decode($this->serializer->serialize(
            $templateRepository->findOneById('full-screen'),
            'json',
            SerializationContext::create()
                ->setGroups(array('middleware'))
        ));

        $result = (object)[
            'channels' => [$channelArray],
            'screen' => [
                'id' => 1,
                'title' => 'channel-public',
                'options' => [],
                'template' => $templateArray,
            ],
        ];

        return $result;
    }

    /**
     * @param $screenId
     * @return false|mixed|object
     */
    public function getCurrentScreenArray($screenId)
    {
        $cachedResult = $this->cache->fetch('os2display.core.screen.' . $screenId);

        if ($cachedResult != false) {
            return $cachedResult;
        }

        $screenRepository = $this->entityManager->getRepository(Screen::class);

        $screen = $screenRepository->findOneById($screenId);
        $screenArray = $this->getScreenArray($screen);

        $result = (object)[
            'channels' => [],
            'screen' => $screenArray,
        ];

        // Build result object.
        foreach ($screen->getChannelScreenRegions() as $channelScreenRegion) {
            $channel = $channelScreenRegion->getChannel();
            $region = $channelScreenRegion->getRegion();
            $channelId = null;
            $data = null;
            $isSharedChannel = false;

            if (!is_null($channel)) {
                $channelId = $channel->getId();
            } else {
                // Handle shared channels.
                $channel = $channelScreenRegion->getSharedChannel();
                $channelId = $channel->getUniqueId();
                $isSharedChannel = true;
            }

            if (!isset($result->channels[$channelId])) {
                $result->channels[$channelId] = (object)[
                    'regions' => [$region],
                ];
            } else {
                $result->channels[$channelId]->regions[] = $region;
            }

            $result->channels[$channelId]->isSharedChannel = $isSharedChannel;
        }

        // Send event to let other processes affect which channels are shown in
        // the different regions of the screen. This is to allow e.g. campaigns
        // to affect which channels are shown.
        $event = new PrePushScreenSerializationEvent($result);
        $this->dispatcher->dispatch(PrePushScreenSerializationEvent::NAME, $event);
        $result = $event->getScreenObject();

        // Serialize the channels in the region array.
        foreach ($result->channels as $channelId => $channelObject) {
            if (isset($channelObject->isSharedChannel) && $channelObject->isSharedChannel) {
                $channel = $this->sharedChannelRepository->findOneByUniqueId($channelId);
                $serializedChannel = $this->getSharedChannelArray($channel);
                $serializedChannel->data->slides = json_decode($serializedChannel->data->slides);
            }
            else {
                $channel = $this->channelRepository->findOneById($channelId);
                $serializedChannel = $this->getChannelArray($channel);
            }

            $channelObject->data = $serializedChannel->data;

            $channelObject->hash = sha1(
                json_encode($result->channels[$channelId])
            );
        }

        // Cache and return results.
        $this->cache->save('os2display.core.screen.' . $screenId, $result, $this->cacheTTL);

        return $result;
    }
}
