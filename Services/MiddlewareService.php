<?php

namespace Os2Display\CoreBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\Screen;
use JMS\Serializer\SerializationContext;

class MiddlewareService
{
    protected $serializer;
    protected $entityManager;
    protected $channelRepository;
    protected $screenRepository;

    /**
     * ScreenService constructor.
     */
    public function __construct(EntityManagerInterface $entityManager, SerializerInterface $serializer)
    {
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->channelRepository = $entityManager->getRepository(Channel::class);
        $this->screenRepository = $entityManager->getRepository(Screen::class);
    }

    public function getScreenArray($screen) {
        $data = $this->serializer->serialize($screen, 'json', SerializationContext::create()
            ->setGroups(array('middleware')));

        $data = json_decode($data);

        return $data;
    }

    public function getChannelArray($channel) {
        $data = $this->serializer->serialize($channel, 'json', SerializationContext::create()
            ->setGroups(array('middleware')));

        $data = json_decode($data);

        return $data;
    }

    public function getCurrentScreenArray($screenId) {
        $screenRepository = $this->entityManager->getRepository('Os2DisplayCoreBundle:Screen');

        $screen = $screenRepository->findOneById($screenId);
        $screenArray = $this->getScreenArray($screen);

        $result = (object) [
            'channels' => [],
            'screen' => $screenArray,
        ];

        // @TODO: Handles shared channels.
        foreach ($screen->getChannelScreenRegions() as $channelScreenRegion) {
            $channel = $channelScreenRegion->getChannel();
            $region = $channelScreenRegion->getRegion();
            $channelId = $channel->getId();

            if (!isset($result->channels[$channelId])) {
                $data = $this->getChannelArray($channel);

                $result->channels[$channelId] = (object) [
                    'data' => $data->data,
                    'regions' => [$region],
                ];
            }
            else {
                $result->channels[$channelId]->regions[] = $region;
            }

            // Hash the the channel object to avoid unnecessary updates in the frontend.
            $result->channels[$channelId]->hash = sha1(json_encode($result->channels[$channelId]));
        }

        return $result;
    }
}
