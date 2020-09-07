<?php

namespace Os2Display\CoreBundle\Controller;

use Os2Display\CoreBundle\Entity\MediaOrder;
use Os2Display\CoreBundle\Entity\ChannelSlideOrder;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializationContext;
use Os2Display\CoreBundle\Entity\Slide;
use Os2Display\CoreBundle\Events\SharingServiceEvent;
use Os2Display\CoreBundle\Events\SharingServiceEvents;

/**
 * @Route("/api/slide")
 */
class SlideController extends Controller
{
  /**
   * Clone a slide.
   *
   * @Route("/{id}/clone")
   * @Method("post")
   *
   * @param int $id
   *   Slide id of the slide to clone.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function slideCloneAction($id)
  {

    $response = new Response();
    $response->setStatusCode(200);

    $slide = $this->getDoctrine()
      ->getRepository('Os2DisplayCoreBundle:Slide')
      ->findOneById($id);

    // Create response.
    $response = new Response();

    if (!$slide) {
        // Not found.
        $response->setStatusCode(404);
    }

    /** @var \Os2Display\CoreBundle\Services\EntityService $entityService */
    $entityService = $this->get('os2display.entity_service');
    $entityService->cloneSlide($slide);

    $response->setStatusCode(200);

    return $response;
  }

    /**
     * Save a (new) slide.
     *
     * @Route("")
     * @Method("POST")
     *
     * @param $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function slideSaveAction(Request $request)
    {
        // Get posted slide information from the request.
        $post = json_decode($request->getContent());

        // Get hooks into doctrine.
        $doctrine = $this->getDoctrine();
        $em = $doctrine->getManager();

        // Check if slide exists, to update, else create new slide.
        if (isset($post->id)) {
            // Load current slide.
            $slide = $doctrine->getRepository('Os2DisplayCoreBundle:Slide')
                ->findOneById($post->id);

            if (!$slide) {
                $response = new Response();
                $response->setStatusCode(404);

                return $response;
            }
        } else {
            // This is a new slide.
            $slide = new Slide();
            $slide->setCreatedAt(time());

            // Set creator.
            $userEntity = $this->get('security.token_storage')->getToken()->getUser();
            $slide->setUser($userEntity->getId());
        }

        // Update fields from post.
        $slide->setSlideType(isset($post->slide_type) ? $post->slide_type : null);
        $slide->setTitle(isset($post->title) ? $post->title : null);
        $slide->setOrientation(isset($post->orientation) ? $post->orientation : null);
        $slide->setTemplate(isset($post->template) ? $post->template : null);
        $slide->setOptions(isset($post->options) ? $post->options : null);
        $slide->setDuration(isset($post->duration) ? $post->duration : null);
        $slide->setPublished(isset($post->published) ? $post->published : null);
        $slide->setScheduleFrom(isset($post->schedule_from) ? $post->schedule_from : null);
        $slide->setScheduleTo(isset($post->schedule_to) ? $post->schedule_to : null);
        $slide->setMediaType(isset($post->media_type) ? $post->media_type : null);
        $slide->setModifiedAt(time());

        // Get channel ids.
        $postChannelIds = array();
        foreach ($post->channels as $channel) {
            $postChannelIds[] = $channel->id;
        }

        // Update channel orders.
        foreach ($slide->getChannelSlideOrders() as $channelSlideOrder) {
            $channel = $channelSlideOrder->getChannel();

            if (!in_array($channel->getId(), $postChannelIds)) {
                $em->remove($channelSlideOrder);
            }
        }

        // Add to channels.
        $channelRepository = $doctrine->getRepository('Os2DisplayCoreBundle:Channel');
        $channelSlideOrderRepository = $doctrine->getRepository('Os2DisplayCoreBundle:ChannelSlideOrder');

        foreach ($post->channels as $channel) {
            $channel = $channelRepository->findOneById($channel->id);

            // Check if ChannelSlideOrder already exists, if not create it.
            $channelSlideOrder = $channelSlideOrderRepository->findOneBy(
                array(
                    'channel' => $channel,
                    'slide' => $slide,
                )
            );
            if (!$channelSlideOrder) {
                // Find the next sort order index for the given channel.
                $index = 0;
                $channelLargestSortOrder = $channelSlideOrderRepository->findOneBy(
                    array('channel' => $channel),
                    array('sortOrder' => 'DESC')
                );
                if ($channelLargestSortOrder) {
                    $index = $channelLargestSortOrder->getSortOrder();
                }

                // Create new ChannelSlideOrder.
                $channelSlideOrder = new ChannelSlideOrder();
                $channelSlideOrder->setChannel($channel);
                $channelSlideOrder->setSlide($slide);
                $channelSlideOrder->setSortOrder($index + 1);

                // Save the ChannelSlideOrder.
                $em->persist($channelSlideOrder);

                $slide->addChannelSlideOrder($channelSlideOrder);
            }
        }

        // Get channel ids.
        $postMediaIds = array();
        foreach ($post->media as $media) {
            $postMediaIds[] = $media->id;
        }

        // Update media orders.
        foreach ($slide->getMediaOrders() as $mediaOrder) {
            $media = $mediaOrder->getMedia();

            if (!in_array($media->getId(), $postMediaIds)) {
                $em->remove($mediaOrder);
            }
        }

        // Add to media.
        $mediaRepository = $doctrine->getRepository('Os2DisplayMediaBundle:Media');
        $mediaOrderRepository = $doctrine->getRepository('Os2DisplayCoreBundle:MediaOrder');

        $mediaIndex = 0;

        foreach ($post->media as $media) {
            $media = $mediaRepository->findOneById($media->id);

            // Check if ChannelSlideOrder already exists, if not create it.
            $mediaOrder = $mediaOrderRepository->findOneBy(
                array(
                    'media' => $media,
                    'slide' => $slide,
                )
            );
            if (!$mediaOrder) {
                // Create new ChannelSlideOrder.
                $mediaOrder = new MediaOrder();
                $mediaOrder->setMedia($media);
                $mediaOrder->setSlide($slide);
                $mediaOrder->setSortOrder($mediaIndex);

                // Save the ChannelSlideOrder.
                $em->persist($mediaOrder);

                $slide->addMediaOrder($mediaOrder);
            } else {
                $mediaOrder->setSortOrder($mediaIndex);
            }

            $mediaIndex++;
        }

        // Set logo
        if (isset($post->logo)) {
            $logo = $mediaRepository->findOneById($post->logo->id);

            $slide->setLogo($logo);
        } else {
            $slide->setLogo(null);
        }

        // Update shared channels
        $dispatcher = $this->get('event_dispatcher');
        foreach ($slide->getChannels() as $channel) {
            foreach ($channel->getSharingIndexes() as $sharingIndex) {
                // Send event to sharingService to add channel to index.
                $event = new SharingServiceEvent($channel, $sharingIndex);
                $dispatcher->dispatch(SharingServiceEvents::UPDATE_CHANNEL, $event);
            }
        }

        // Add slide to groups.
        $this->get('os2display.group_manager')->setGroups(isset($post->groups) ? $post->groups : [], $slide);

        // Save the slide.
        $em->persist($slide);

        // Persist to database.
        $em->flush();

        // Create response.
        $response = new Response();
        $response->setStatusCode(200);

        return $response;
    }

    /**
     * Get slide with ID.
     *
     * @Route("/{id}")
     * @Method("GET")
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function slideGetAction($id)
    {
        $slide = $this->getDoctrine()
            ->getRepository('Os2DisplayCoreBundle:Slide')
            ->findOneById($id);

        // Create response.
        $response = new Response();
        if ($slide) {
            // Get the serializer
            $serializer = $this->get('jms_serializer');

            $this->get('os2display.api_data')->setApiData($slide);

            $response->headers->set('Content-Type', 'application/json');
            $jsonContent = $serializer->serialize($slide, 'json', SerializationContext::create()
                ->setGroups(array('slide'))
                ->enableMaxDepthChecks());
            $response->setContent($jsonContent);
        } else {
            // Not found.
            $response->setStatusCode(404);
        }

        return $response;
    }

    /**
     * Delete slide.
     *
     * @Route("/{id}")
     * @Method("DELETE")
     *
     * @param int $id
     *   Slide id of the slide to delete.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function slideDeleteAction($id)
    {
        $slide = $this->getDoctrine()
            ->getRepository('Os2DisplayCoreBundle:Slide')
            ->findOneById($id);

        // Create response.
        $response = new Response();

        if ($slide) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($slide);
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
     * Get a list of all slides.
     *
     * @Route("")
     * @Method("GET")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function slidesGetAction()
    {
        $manager = $this->get('os2display.entity_manager');
        $slideEntities = $manager->findAll(Slide::class);

        $this->get('os2display.api_data')->setApiData($slideEntities);

        // Create response.
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');

        $serializer = $this->get('jms_serializer');
        $jsonContent = $serializer->serialize($slideEntities, 'json', SerializationContext::create()
            ->setGroups(array('api-bulk'))
            ->enableMaxDepthChecks());
        $response->setContent($jsonContent);

        return $response;
    }
}
