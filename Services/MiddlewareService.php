<?php

namespace Os2Display\CoreBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\Screen;
use JMS\Serializer\SerializationContext;
use Doctrine\Common\Cache\CacheProvider;

class MiddlewareService
{
    protected $serializer;
    protected $entityManager;
    protected $channelRepository;
    protected $screenRepository;
    protected $cache;
    protected $cacheTTL;

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
        $cacheTTL
    ) {
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->channelRepository = $entityManager->getRepository(
            Channel::class
        );
        $this->screenRepository = $entityManager->getRepository(Screen::class);
        $this->cache = $cache;
        $this->cacheTTL = $cacheTTL;
    }

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

    public function getCurrentScreenArray($screenId)
    {
        $cachedResult = $this->cache->fetch('os2display.core.screen.' . $screenId);

        if ($cachedResult != false) {
            return $cachedResult;
        }

        $screenRepository = $this->entityManager->getRepository(
            'Os2DisplayCoreBundle:Screen'
        );

        $screen = $screenRepository->findOneById($screenId);
        $screenArray = $this->getScreenArray($screen);

        $result = (object)[
            'channels' => [],
            'screen' => $screenArray,
        ];

        foreach ($screen->getChannelScreenRegions() as $channelScreenRegion) {
            $channel = $channelScreenRegion->getChannel();
            $region = $channelScreenRegion->getRegion();
            $channelId = null;
            $data = null;

            if (!is_null($channel)) {
                $channelId = $channel->getId();
                $data = $this->getChannelArray($channel);
            } else {
                // Handle shared channels.
                $channel = $channelScreenRegion->getSharedChannel();
                $channelId = $channel->getUniqueId();
                $data = $this->getSharedChannelArray($channel);
                $data->data->slides = json_decode($data->data->slides);
            }

            if (!isset($result->channels[$channelId])) {
                $result->channels[$channelId] = (object)[
                    'data' => $data->data,
                    'regions' => [$region],
                ];
            } else {
                $result->channels[$channelId]->regions[] = $region;
            }

            // Hash the the channel object to avoid unnecessary updates in the frontend.
            $result->channels[$channelId]->hash = sha1(
                json_encode($result->channels[$channelId])
            );
        }

        $this->cache->save('os2display.core.screen.' . $screenId, $result, $this->cacheTTL);

        return $result;
    }
}
