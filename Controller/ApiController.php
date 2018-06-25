<?php

namespace Os2Display\CoreBundle\Controller;

use FOS\RestBundle\Controller\FOSRestController;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController extends FOSRestController {

  protected function findAll($class) {
    return $this->findBy($class, []);
  }

  protected function findBy($class, array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL) {
    $manager = $this->get('os2display.entity_manager');
    return $manager->findBy($class, $criteria, $orderBy, $limit, $offset);
  }

  /**
   * Deserialize JSON content from request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return array
   */
  protected function getData(Request $request, $key = NULL) {
    return $key ? $request->request->get($key) : $request->request->all();
  }

  /**
   * @param $data
   * @param array $headers
   * @param array $serializationGroups
   * @return \Symfony\Component\HttpFoundation\Response
   */
  protected function createCreatedResponse($data, array $headers = [], array $serializationGroups = ['api']) {
    $view = $this->view($data, Response::HTTP_CREATED);
    $context = $view->getContext();
    $context->setGroups($serializationGroups);

    return $this->handleView($view);
  }

  /**
   * Apply values to an object.
   *
   * @param $entity
   * @param array $data
   */
  protected function setValues($entity, array $data, array $properties = NULL) {
    $entityService = $this->get('os2display.entity_service');
    $entityService->setValues($entity, $data, $properties);
  }

  protected function validateEntity($entity) {
    $entityService = $this->get('os2display.entity_service');

    return $entityService->validateEntity($entity);
  }

  /**
   * Convenience method.
   *
   * @param $entity
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  protected function setValuesFromRequest($entity, Request $request, array $properties = NULL) {
    $data = $this->getData($request);
    $this->setValues($entity, $data, $properties);
  }

  /**
   * Set API data on an object or a list of objects.
   *
   * @param $object
   * @return mixed
   */
  protected function setApiData($object) {
    return $this->get('os2display.api_data')->setApiData($object);
  }
}
