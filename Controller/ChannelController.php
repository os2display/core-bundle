<?php

namespace Os2Display\CoreBundle\Controller;

use Os2Display\CoreBundle\Events\SharingServiceEvent;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\ChannelSlideOrder;
use Os2Display\CoreBundle\Events\SharingServiceEvents;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use JMS\Serializer\SerializationContext;

/**
 * @Route("/api/channel")
 */
class ChannelController extends Controller
{
  /**
   * Clone a channel.
   *
   * @Route("/{id}/clone")
   * @Method("post")
   *
   * @param int $id
   *   Slide id of the channel to clone.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function channelCloneAction($id)
  {
    /** @var Channel $channel */
    $channel = $this->getDoctrine()
      ->getRepository('Os2DisplayCoreBundle:Channel')
      ->findOneById($id);

    $response = new Response();

    if (!$channel) {
      $response->setStatusCode(404);
      return $response;
    }

    /** @var \Os2Display\CoreBundle\Services\EntityService $entityService */
    $entityService = $this->get('os2display.entity_service');

    $entityService->cloneChannel($channel);

    $response->setStatusCode(200);

    return $response;
  }

  /**
     * Save a (new) channel.
     *
     * @Route("")
     * @Method("POST")
     *
     * @param $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function channelSaveAction(Request $request)
    {
        // Get posted channel information from the request.
        $post = json_decode($request->getContent());

        $doctrine = $this->getDoctrine();
        $em = $doctrine->getManager();

        if ($post->id) {
            // Load current slide.
            $channel = $doctrine->getRepository('Os2DisplayCoreBundle:Channel')
                ->findOneById($post->id);

            // If channel is not found, return Not Found.
            if (!$channel) {
                $response = new Response();
                $response->setStatusCode(404);

                return $response;
            }
        } else {
            // This is a new channel.
            $channel = new Channel();
            $channel->setCreatedAt(time());

            // Set creator.
            $userEntity = $this->get('security.token_storage')->getToken()->getUser();
            $channel->setUser($userEntity->getId());
        }

        // Update fields.
        $channel->setTitle(isset($post->title) ? $post->title : null);
        $channel->setPublishFrom(isset($post->publish_from) ? $post->publish_from : null);
        $channel->setPublishTo(isset($post->publish_to) ? $post->publish_to : null);
        $channel->setScheduleRepeat(isset($post->schedule_repeat) ? $post->schedule_repeat : null);
        $channel->setScheduleRepeatDays(isset($post->schedule_repeat_days) ? $post->schedule_repeat_days : null);
        $channel->setScheduleRepeatFrom(isset($post->schedule_repeat_from) ? $post->schedule_repeat_from : null);
        $channel->setScheduleRepeatTo(isset($post->schedule_repeat_to) ? $post->schedule_repeat_to : null);
        $channel->setModifiedAt(time());

        // Get all slide ids from POST.
        $post_slide_ids = array();
        foreach ($post->slides as $slide) {
            $post_slide_ids[] = $slide->id;
        }

        // Remove slides.
        foreach ($channel->getChannelSlideOrders() as $channel_slide_order) {
            $slide = $channel_slide_order->getSlide();

            if (is_null($slide)) {
              $logger = $this->get('logger');
              $logger->warning('ChannelSlideOrder with id: ' . $channel_slide_order->getId() . ' has null slide. Removing.');
            }

            if (is_null($slide) || !in_array($slide->getId(), $post_slide_ids)) {
              $channel->removeChannelSlideOrder($channel_slide_order);
            }
        }

        // Add slides and update sort order.
        $sort_order = 0;
        $slideRepository = $doctrine->getRepository('Os2DisplayCoreBundle:Slide');
        $channelSlideOrderRepository = $doctrine->getRepository('Os2DisplayCoreBundle:ChannelSlideOrder');

        foreach ($post_slide_ids as $slide_id) {
            $slide = $slideRepository->findOneById($slide_id);

            $channel_slide_order = $channelSlideOrderRepository->findOneBy(
                array(
                    'channel' => $channel,
                    'slide' => $slide,
                )
            );
            if (!$channel_slide_order) {
                // New ChannelSLideOrder.
                $channel_slide_order = new ChannelSlideOrder();
                $channel_slide_order->setChannel($channel);
                $channel_slide_order->setSlide($slide);
                $em->persist($channel_slide_order);

                // Associate Order to Channel.
                $channel->addChannelSlideOrder($channel_slide_order);
            }

            $channel_slide_order->setSortOrder($sort_order);
            $sort_order++;
        }

        // Add channel to groups.
        $this->get('os2display.group_manager')
            ->setGroups(isset($post->groups) ? $post->groups : [], $channel);
        $em->persist($channel);

        $dispatcher = $this->get('event_dispatcher');

        // Add sharing indexes.
        foreach ($channel->getSharingIndexes() as $sharingIndex) {
            // Send event to sharingService to update channel in index.
            $event = new SharingServiceEvent($channel, $sharingIndex);
            $dispatcher->dispatch(SharingServiceEvents::UPDATE_CHANNEL, $event);
        }

        // Flush updates
        $em->flush();

        // Create response.
        $response = new Response();
        $response->setStatusCode(200);

        return $response;
    }

    /**
     * Update which indexes a channel is shared to.
     *
     * @Route("/share")
     * @Method("POST")
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   {
     *     channel {
     *       id: *
     *     },
     *     sharingIndexes: [
     *       *
     *     ]
     *   }
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function channelShareAction(Request $request)
    {
        $post = json_decode($request->getContent());

        $doctrine = $this->getDoctrine();
        $em = $doctrine->getManager();

        $channel = $doctrine->getRepository('Os2DisplayCoreBundle:Channel')
            ->findOneById($post->id);

        // Set the sharing id.
        $apikey = $this->container->getParameter('search_apikey');
        $secret = $this->container->getParameter('secret');
        $channel->setUniqueId(sha1($apikey . $secret . $channel->getId()));

        // Test for existance of sharingIndexes in post
        if (isset($post->sharing_indexes)) {
            $dispatcher = $this->get('event_dispatcher');

            // Get all sharing_indexes ids from POST.
            $post_sharing_indexes_ids = array();
            foreach ($post->sharing_indexes as $ind) {
                $post_sharing_indexes_ids[] = $ind->id;
            }

            // Remove sharing indexes.
            foreach ($channel->getSharingIndexes() as $sharingIndex) {
                if (!in_array(
                    $sharingIndex->getId(),
                    $post_sharing_indexes_ids
                )) {
                    $channel->removeSharingIndex($sharingIndex);

                    // Send event to sharingService to delete channel from index.
                    $event = new SharingServiceEvent($channel, $sharingIndex);
                    $dispatcher->dispatch(
                        SharingServiceEvents::REMOVE_CHANNEL_FROM_INDEX,
                        $event
                    );
                }
            }

            // Add sharing indexes.
            foreach ($post_sharing_indexes_ids as $sharingIndexId) {
                $sharingIndex = $doctrine->getRepository('Os2DisplayCoreBundle:SharingIndex')
                    ->findOneById($sharingIndexId);
                if ($sharingIndex) {
                    if (!$channel->getSharingIndexes()
                        ->contains($sharingIndex)) {
                        $channel->addSharingIndex($sharingIndex);

                        // Send event to sharingService to add channel to index.
                        $event = new SharingServiceEvent(
                            $channel,
                            $sharingIndex
                        );
                        $dispatcher->dispatch(
                            SharingServiceEvents::ADD_CHANNEL_TO_INDEX,
                            $event
                        );
                    } else {
                        // Send event to sharingService to add channel to index.
                        $event = new SharingServiceEvent(
                            $channel,
                            $sharingIndex
                        );
                        $dispatcher->dispatch(
                            SharingServiceEvents::UPDATE_CHANNEL,
                            $event
                        );
                    }
                }
            }
        }

        $em->flush();

        $response = new Response();
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * Get channel with $id.
     *
     * @Route("/{id}")
     * @Method("GET")
     *
     * @param int $id
     *   Channel id of the channel to delete.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function channelGetAction($id)
    {
        $channel = $this->getDoctrine()
            ->getRepository('Os2DisplayCoreBundle:Channel')
            ->findOneById($id);

        $serializer = $this->get('jms_serializer');

        $this->get('os2display.api_data')->setApiData($channel);

        // Create response.
        $response = new Response();
        if ($channel) {
            $response->headers->set('Content-Type', 'application/json');
            $json_content = $serializer->serialize(
                $channel,
                'json',
                SerializationContext::create()
                    ->setGroups(array('channel'))
                    ->enableMaxDepthChecks()
            );
            $response->setContent($json_content);
        } else {
            $response->setStatusCode(404);
        }

        return $response;
    }

    /**
     * Delete channel.
     *
     * @Route("/{id}")
     * @Method("DELETE")
     *
     * @param int $id
     *   Channel id of the channel to delete.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function channelDeleteAction($id)
    {
        $channel = $this->getDoctrine()
            ->getRepository('Os2DisplayCoreBundle:Channel')
            ->findOneById($id);

        // Create response.
        $response = new Response();

        if ($channel) {
            // Remove from sharing indexes.
            $dispatcher = $this->get('event_dispatcher');
            foreach ($channel->getSharingIndexes() as $sharingIndex) {
                // Send event to sharingService to update channel in index.
                $event = new SharingServiceEvent($channel, $sharingIndex);
                $dispatcher->dispatch(
                    SharingServiceEvents::REMOVE_CHANNEL_FROM_INDEX,
                    $event
                );
            }

            // Remove the screen from the middleware.
            $this->get('os2display.middleware.communication')
                ->removeChannel($channel);

            $em = $this->getDoctrine()->getManager();
            $em->remove($channel);
            $em->flush();

            // Element deleted.
            $response->setStatusCode(200);
        } else {
            // Not found.
            $response->setStatusCode(404);
        }

        return $response;
    }

    /**
     * Get a list of all channels.
     *
     * @Route("")
     * @Method("GET")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function channelsGetAction()
    {
        $manager = $this->get('os2display.entity_manager');
        $channelEntities = $manager->findAll(Channel::class);

        $this->get('os2display.api_data')->setApiData($channelEntities);

        // Create response.
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');

        $serializer = $this->get('jms_serializer');
        $response->setContent(
            $serializer->serialize(
                $channelEntities,
                'json',
                SerializationContext::create()
                    ->setGroups(array('api-bulk'))
                    ->enableMaxDepthChecks()
            )
        );

        return $response;
    }
}
