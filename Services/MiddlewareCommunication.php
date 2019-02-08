<?php
/**
 * @file
 * This file is a part of the Os2DisplayCoreBundle.
 *
 * Contains the middleware communication service.
 */

namespace Os2Display\CoreBundle\Services;

use JMS\Serializer\SerializationContext;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\SharedChannel;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Os2Display\CoreBundle\Events\PrePushChannelsEvent;
use Os2Display\CoreBundle\Events\PrePushChannelEvent;
use Os2Display\CoreBundle\Events\PostPushChannelsEvent;
use Os2Display\CoreBundle\Events\CronEvent;

/**
 * Class MiddlewareCommunication
 *
 * @package Os2Display\CoreBundle\Services
 */
class MiddlewareCommunication
{
    protected $middlewarePath;
    protected $doctrine;
    protected $serializer;
    protected $entityManager;
    protected $inMiddleware;
    protected $dispatcher;
    protected $container;

    /**
     * Constructor.
     *
     * @param Container $container
     *   The service container.
     * @param UtilityService $utilityService
     *   The utility service.
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
     *   The event dispatcher.
     * @throws \Exception
     */
    public function __construct(
        Container $container,
        UtilityService $utilityService,
        EventDispatcherInterface $dispatcher
    ) {
        $this->middlewarePath =
            $container->getParameter('middleware_host').
            $container->getParameter('middleware_path');
        $this->doctrine = $container->get('doctrine');
        $this->serializer = $container->get('jms_serializer');
        $this->entityManager = $this->doctrine->getManager();
        $this->inMiddleware = false;
        $this->dispatcher = $dispatcher;
        $this->utilityService = $utilityService;
        $this->container = $container;
    }

    /**
     * ik.onCron event listener.
     *
     * Pushes data to screens.
     *
     * @param CronEvent $event
     * @throws \Exception
     */
    public function onCron(CronEvent $event)
    {
        $this->pushToScreens();
    }

    /**
     * Get Screen Ids from json_encoded channel data.
     *
     * @param string $data The json encoded channel data.
     * @return mixed
     */
    private function getScreenIdsFromData($data)
    {
        $decoded = json_decode($data);

        return $decoded->screens;
    }

    /**
     * Push a Channel or a SharedChannel to the middleware.
     *
     * @param Channel|SharedChannel $channel
     *   The Channel or SharedChannel to push.
     * @param mixed $data
     *   The Data that should be pushed for $channel encoded as json.
     * @param string $id
     *   The id of the channel (internal id for Channel, unique_id for SharedChannel)
     * @param boolean $force
     *   Should the push be forced through?
     */
    public function pushChannel($channel, $data, $id, $force)
    {
        // Calculate hash of content, used to avoid unnecessary push.
        $sha1 = sha1($data);

        // Get screen ids.
        $screenIds = $this->getScreenIdsFromData($data);

        $lastPushScreens = [];

        // If middleware has delivered the current channels, use that as last push screens.
        // Else fallback to what is saved for the screen (backwards compatibility regarding middleware).
        if ($this->inMiddleware != false) {
            $channelsInMiddleware = $this->inMiddleware->channels;

            $neededObjectArray = array_filter(
                $channelsInMiddleware,
                function ($e) use (&$id) {
                    return $e->id == $id;
                }
            );

            // Set last pushed screens for channel.
            if (!empty($neededObjectArray)) {
                $neededObject = array_pop($neededObjectArray);
                $lastPushScreens = $neededObject->screens;
                $channel->setLastPushScreens($lastPushScreens);
            }
        } else {
            $lastPushScreens = $channel->getLastPushScreens();

            // Make sure last push screen is an array.
            if (!is_array($lastPushScreens)) {
                // Backwards compatibility conversion.
                if (is_string($lastPushScreens) &&
                    $decoded = json_decode(
                        $lastPushScreens
                    )) {
                    $lastPushScreens = $decoded;
                } else {
                    $lastPushScreens = [];
                }
            }
        }

        // Check if the channel should be pushed.
        if ($force ||
            $sha1 != $channel->getLastPushHash() ||
            $screenIds != $lastPushScreens) {
            // Only push channel if it's attached to a least one screen. If no screen
            // is attached then channel will be deleted from the middleware and
            // $lastPushTime will be reset later on in this function.
            if (count($screenIds) > 0) {
                $curlResult = $this->utilityService->curl(
                    $this->middlewarePath.'/channel/'.$id,
                    'POST',
                    $data,
                    'middleware'
                );

                // If the result was delivered, update the last hash.
                if ($curlResult['status'] == 200) {
                    // Push deletes to the middleware if a channel has been on a screen previously,
                    // but now has been removed.
                    $updatedScreensSuccess = true;

                    foreach ($lastPushScreens as $lastPushScreenId) {
                        if (!in_array($lastPushScreenId, $screenIds)) {
                            // Remove channel from screen.
                            $curlResult = $this->utilityService->curl(
                                $this->middlewarePath.'/channel/'.$id.'/screen/'.$lastPushScreenId,
                                'DELETE',
                                json_encode(array()),
                                'middleware'
                            );

                            if ($curlResult['status'] != 200) {
                                $updatedScreensSuccess = false;
                            }
                        }
                    }

                    // If the delete process was successful, update last push information.
                    // else set values to NULL to ensure new push.
                    if ($updatedScreensSuccess) {
                        $channel->setLastPushScreens($screenIds);
                        $channel->setLastPushHash($sha1);
                    } else {
                        // Removing channel from some screens have failed, hence mark the
                        // channel for re-push.
                        $channel->setLastPushHash(null);
                    }
                } else {
                    // Channel push failed for this channel mark it for re-push.
                    $channel->setLastPushHash(null);
                }
            } else {
                if (!is_null($channel->getLastPushHash()) ||
                    !empty($lastPushScreens)) {
                    // Channel don't have any screens, so delete from the middleware. This
                    // will automatically remove it from any screen connected to the
                    // middleware that displays is currently.
                    $curlResult = $this->utilityService->curl(
                        $this->middlewarePath.'/channel/'.$id,
                        'DELETE',
                        json_encode(array()),
                        'middleware'
                    );

                    if ($curlResult['status'] != 200) {
                        // Delete did not work, so mark the channel for
                        // re-push of DELETE by removing last push hash.
                        $channel->setLastPushHash(null);
                    } else {
                        // Channel delete success, so empty last pushed screens.
                        $channel->setLastPushScreens([]);
                    }
                }
            }

            // Save changes to database.
            $this->entityManager->flush();
        }
    }

    /**
     * Pushes the channels for each screen to the middleware.
     *
     * @param boolean $force
     *   Should the push to screen be forced, even though the content has previously been pushed to the middleware?
     * @throws \Exception
     */
    public function pushToScreens($force = false)
    {
        $this->inMiddleware = $this->getChannelStatusFromMiddleware();
        $idsInBackend = [];

        if ($this->inMiddleware == false) {
            $logger = $this->container->get('logger');
            $logger->info(
                'MiddlewareCommuncations (itk-campaign-bundle): Could not get channels from middleware.'
            );
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();

        // @TODO: Optimize which channels should be examined.
        // Get channels that are currently pushed to screens,
        // or should be pushed to screens.
        $activeChannels =
            $queryBuilder->select('c')
                ->from(Channel::class, 'c')
                ->getQuery()->getResult();

        // Notify that Push Channel process has started.
        $event = new PrePushChannelsEvent($activeChannels);
        $this->dispatcher->dispatch(
            PrePushChannelsEvent::EVENT_PRE_PUSH_CHANNELS,
            $event
        );

        foreach ($activeChannels as $channel) {
            $idsInBackend[] = $channel->getId();

            // @TODO: Decide if channel needs to be pushed.

            $data = $this->serializer->serialize(
                $channel,
                'json',
                SerializationContext::create()
                    ->setGroups(array('middleware'))
            );

            // Notify that Push Channel process has started.
            $event = new PrePushChannelEvent($channel, $data);
            $event = $this->dispatcher->dispatch(
                PrePushChannelEvent::EVENT_PRE_PUSH_CHANNEL,
                $event
            );

            // Set modified values.
            $data = $event->getData();
            $channel = $event->getEntity();

            $this->pushChannel($channel, $data, $channel->getId(), $force);
        }

        // Push shared channels
        $sharedChannels = $this->doctrine->getRepository(
            'Os2DisplayCoreBundle:SharedChannel'
        )->findAll();

        foreach ($sharedChannels as $sharedChannel) {
            $idsInBackend[] = $sharedChannel->getUniqueId();

            // @TODO: Decide if sharedChannel needs to be pushed.

            $data = $this->serializer->serialize(
                $sharedChannel,
                'json',
                SerializationContext::create()
                    ->setGroups(array('middleware'))
            );

            // Hack to get slides encoded correctly
            //   Issue with how the slides array is encoded in jms_serializer.
            $d = json_decode($data);
            $d->data->slides = json_decode($d->data->slides);
            $data = json_encode($d);

            if (is_null($data)) {
                continue;
            }

            // Notify that Push Channel process has started.
            $event = $this->dispatcher->dispatch(
                PrePushChannelEvent::EVENT_PRE_PUSH_CHANNEL,
                new PrePushChannelEvent($sharedChannel, $data)
            );

            // Set modified values.
            $data = $event->getData();
            $sharedChannel = $event->getEntity();

            $this->pushChannel(
                $sharedChannel,
                $data,
                $sharedChannel->getUniqueId(),
                $force
            );
        }

        // Remove all channels from middleware that is not in the backend.
        if ($this->inMiddleware) {
            foreach ($this->inMiddleware->channels as $channelInMiddleware) {
                // If not in activeChannels and sharedChannels, remove it from
                // middleware.
                $key = array_search($channelInMiddleware->id, $idsInBackend);

                if ($key === false) {
                    $curlResult = $this->utilityService->curl(
                        $this->middlewarePath.'/channel/'.$channelInMiddleware->id,
                        'DELETE',
                        json_encode(array()),
                        'middleware'
                    );

                    if ($curlResult['status'] != 200) {
                        $logger = $this->container->get('logger');
                        $logger->info(
                            'MiddlewareCommuncations (itk-campaign-bundle): Could not remove ghost channel from middleware.'
                        );
                    }
                }
            }
        }

        // Notify that Push Channel process has ended.
        $this->dispatcher->dispatch(
            PostPushChannelsEvent::EVENT_POST_PUSH_CHANNELS,
            new PostPushChannelsEvent()
        );
    }

    /**
     * Determine if the string is valid JSON.
     *
     * @param string $string
     *   String to evaluate.
     * @return bool
     *   Returns true if the string is valid JSON, else false.
     */
    private function isJSON($string)
    {
        return is_string($string) &&
        is_array(json_decode($string, true)) &&
        (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }

    /**
     * Get channel status from middleware.
     */
    public function getChannelStatusFromMiddleware()
    {
        $curlResult = $this->utilityService->curl(
            $this->middlewarePath.
            '/status/channels/'.
            $this->container->getParameter('middleware_apikey'),
            'GET',
            json_encode(array()),
            'middleware'
        );

        if ($curlResult['status'] != 200) {
            return false;
        } else {
            if ($this->isJSON($curlResult['content'])) {
                return json_decode($curlResult['content']);
            } else {
                return false;
            }
        }
    }

    /**
     * Push screen update.
     *
     * Pushes an update regarding a screen to the middleware.
     *
     * @param $screen
     *   The screen to update
     * @throws \Exception
     */
    public function pushScreenUpdate($screen)
    {
        $middlewarePath = $this->container->getParameter(
                'middleware_host'
            ).$this->container->getParameter('middleware_path');
        $serializer = $this->container->get('jms_serializer');

        $data = json_encode(
            array(
                'id' => $screen->getId(),
                'title' => $screen->getTitle(),
                'options' => $screen->getOptions(),
                'template' => json_decode(
                    $serializer->serialize(
                        $screen->getTemplate(),
                        'json',
                        SerializationContext::create()
                            ->setGroups(array('middleware'))
                    )
                ),
            )
        );

        $this->utilityService->curl(
            $middlewarePath.'/screen/'.$screen->getId(),
            'PUT',
            $data,
            'middleware'
        );

        // @TODO: Handle issue when change has not been delivered to the middleware.
    }

    /**
     * Reload screen
     *
     * @param $screen
     *   The screen to reload.
     * @return bool
     *   Did it succeed?
     */
    public function reloadScreen($screen)
    {
        $middlewarePath = $this->container->getParameter(
                'middleware_host'
            ).$this->container->getParameter('middleware_path');

        $curlResult = $this->utilityService->curl(
            $middlewarePath.'/screen/'.$screen->getId().'/reload',
            'POST',
            json_encode(array('id' => $screen->getId())),
            'middleware'
        );

        return $curlResult['status'] === 200;
    }

    /**
     * Remove channel
     *
     * @param $channel
     *   The channel to remove.
     * @return bool
     *   Did it succeed?
     * @throws \Exception
     */
    public function removeChannel($channel)
    {
        $middlewarePath = $this->container->getParameter(
                'middleware_host'
            ).$this->container->getParameter('middleware_path');

        // @TODO: Handle error.
        $curlResult = $this->utilityService->curl(
            $middlewarePath.'/channel/'.$channel->getId(),
            'DELETE',
            json_encode(array('id' => $channel->getId())),
            'middleware'
        );

        $logger = $this->container->get('logger');
        $logger->info(
            'Removing channel: '.$channel->getId(
            ).' from middleware. Result '.$curlResult['status']
        );

        return $curlResult['status'] === 200;
    }

    /**
     * Remove screen
     *
     * @param $screen
     *   The screen to remove.
     * @return bool
     *   Did it succeed?
     * @throws \Exception
     */
    public function removeScreen($screen)
    {
        $middlewarePath = $this->container->getParameter(
                'middleware_host'
            ).$this->container->getParameter('middleware_path');

        // @TODO: Handle error.
        $curlResult = $this->utilityService->curl(
            $middlewarePath.'/screen/'.$screen->getId(
            ).'/'.$screen->getActivationCode(),
            'DELETE',
            json_encode(array('id' => $screen->getId())),
            'middleware'
        );

        $logger = $this->container->get('logger');
        $logger->info(
            'Removing screen: '.$screen->getId(
            ).' from middleware. Result '.$curlResult['status']
        );

        return $curlResult['status'] === 200;
    }
}
