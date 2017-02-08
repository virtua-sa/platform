<?php

namespace Oro\Bundle\FormBundle\Model;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\UIBundle\Route\Router;
use Oro\Bundle\FormBundle\Event\FormHandler\AfterFormProcessEvent;
use Oro\Bundle\FormBundle\Event\FormHandler\Events;
use Oro\Bundle\FormBundle\Event\FormHandler\FormProcessEvent;

class UpdateHandler
{
    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var FormTemplateDataProviderRegistry
     */
    protected $dataProviderRegistry;

    /**
     * @param RequestStack $requestStack
     * @param Session $session
     * @param Router $router
     * @param DoctrineHelper $doctrineHelper
     * @param EventDispatcherInterface $eventDispatcher
     * @param FormTemplateDataProviderRegistry $dataProviderRegistry
     */
    public function __construct(
        RequestStack $requestStack,
        Session $session,
        Router $router,
        DoctrineHelper $doctrineHelper,
        EventDispatcherInterface $eventDispatcher,
        FormTemplateDataProviderRegistry $dataProviderRegistry
    ) {
        $this->requestStack = $requestStack;
        $this->session = $session;
        $this->router = $router;
        $this->doctrineHelper = $doctrineHelper;
        $this->eventDispatcher = $eventDispatcher;
        $this->dataProviderRegistry = $dataProviderRegistry;
    }

    /**
     * @deprecated since 1.10 and will be removed after 1.12. Use update method instead
     *
     * @param object $entity
     * @param FormInterface $form
     * @param array|callable $saveAndStayRoute
     * @param array|callable $saveAndCloseRoute
     * @param string $saveMessage
     * @param null|object $formHandler
     * @param callable|null $resultCallback
     * @return array|RedirectResponse
     */
    public function handleUpdate(
        $entity,
        FormInterface $form,
        $saveAndStayRoute,
        $saveAndCloseRoute,
        $saveMessage,
        $formHandler = null,
        $resultCallback = null
    ) {
        if ($formHandler) {
            if (method_exists($formHandler, 'process') && $formHandler->process($entity)) {
                return $this->processSave(
                    $form,
                    $entity,
                    $saveAndStayRoute,
                    $saveAndCloseRoute,
                    $saveMessage,
                    $resultCallback
                );
            }
        } elseif ($this->saveForm($form, $entity)) {
            return $this->processSave(
                $form,
                $entity,
                $saveAndStayRoute,
                $saveAndCloseRoute,
                $saveMessage,
                $resultCallback
            );
        }

        return $this->getResult($entity, $form, $resultCallback);
    }

    /**
     * Handles update action of controller used to create or update entity on separate page or widget dialog.
     *
     * @param object $data Data of form
     * @param FormInterface $form Form instance
     * @param string $saveMessage Message added to session flash bag in case if form will be saved successfully
     *               and if form is not submitted from widget.
     * @param null|callable $formHandler Callback to handle form, by default method saveForm used.
     * @param callable|string|null $resultCallback optional callable or alias of FormTemplateDataProvider service
     * @return array|RedirectResponse Returns an array
     *                                  if form wasn't successfully submitted
     *                                  or when request method is not PUT and POST,
     *                                  or if form was submitted from widget dialog,
     *                                returns RedirectResponse
     *                                  if form was successfully submitted from create/update page
     * @throws \InvalidArgumentException
     */
    public function update(
        $data,
        FormInterface $form,
        $saveMessage,
        $formHandler = null,
        $resultCallback = null
    ) {
        if ($formHandler) {
            if (is_object($formHandler) && method_exists($formHandler, 'process')) {
                if ($formHandler->process($data)) {
                    return $this->constructResponse($form, $data, $saveMessage, $resultCallback);
                }
            } else {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Argument $formHandler should be an object with method "process", %s given.',
                        is_object($formHandler) ? get_class($formHandler) : gettype($formHandler)
                    )
                );
            }
        } elseif ($this->saveForm($form, $data)) {
            return $this->constructResponse($form, $data, $saveMessage, $resultCallback);
        }

        return $this->getResult($data, $form, $resultCallback);
    }

    /**
     * @param FormInterface $form
     * @param object $data
     * @return bool
     * @throws \Exception
     */
    protected function saveForm(FormInterface $form, $data)
    {
        $event = new FormProcessEvent($form, $data);
        $this->eventDispatcher->dispatch(Events::BEFORE_FORM_DATA_SET, $event);

        if ($event->isFormProcessInterrupted()) {
            return false;
        }

        $form->setData($data);

        $request = $this->getCurrentRequest();

        if (in_array($request->getMethod(), ['POST', 'PUT'], true)) {
            $event = new FormProcessEvent($form, $data);
            $this->eventDispatcher->dispatch(Events::BEFORE_FORM_SUBMIT, $event);

            if ($event->isFormProcessInterrupted()) {
                return false;
            }

            $form->submit($request);

            if ($form->isValid()) {
                $manager = $this->doctrineHelper->getEntityManager($data);

                $manager->beginTransaction();
                try {
                    $manager->persist($data);
                    $this->eventDispatcher->dispatch(Events::BEFORE_FLUSH, new AfterFormProcessEvent($form, $data));
                    $manager->flush();
                    $this->eventDispatcher->dispatch(Events::AFTER_FLUSH, new AfterFormProcessEvent($form, $data));
                    $manager->commit();
                } catch (\Exception $exception) {
                    $manager->rollback();
                    throw $exception;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated since 1.10 and will be removed after 1.12
     *
     * @param FormInterface $form
     * @param object $entity
     * @param array|callable $saveAndStayRoute
     * @param array|callable $saveAndCloseRoute
     * @param string $saveMessage
     * @param callback|null $resultCallback
     * @return array|RedirectResponse
     */
    protected function processSave(
        FormInterface $form,
        $entity,
        $saveAndStayRoute,
        $saveAndCloseRoute,
        $saveMessage,
        $resultCallback = null
    ) {
        $request = $this->getCurrentRequest();
        if ($request->get('_wid')) {
            $result = $this->getResult($entity, $form, $resultCallback);
            $result['savedId'] = $this->doctrineHelper->getSingleEntityIdentifier($entity);

            return $result;
        } else {
            $this->session->getFlashBag()->add('success', $saveMessage);

            if (is_callable($saveAndStayRoute)) {
                $saveAndStayRoute = call_user_func($saveAndStayRoute, $entity);
            }
            $saveAndStayRoute = $this->addQueryParameters($saveAndStayRoute);

            if (is_callable($saveAndCloseRoute)) {
                $saveAndCloseRoute = call_user_func($saveAndCloseRoute, $entity);
            }
            $saveAndCloseRoute = $this->addQueryParameters($saveAndCloseRoute);

            return $this->router->redirectAfterSave($saveAndStayRoute, $saveAndCloseRoute, $entity);
        }
    }

    /**
     * @param FormInterface $form
     * @param object $entity
     * @param string $saveMessage
     * @param null $resultCallback
     *
     * @return array|RedirectResponse
     */
    protected function constructResponse(FormInterface $form, $entity, $saveMessage, $resultCallback = null)
    {
        $request = $this->getCurrentRequest();
        if ($request->get('_wid')) {
            $result = $this->getResult($entity, $form, $resultCallback);
            $result['savedId'] = $this->doctrineHelper->getSingleEntityIdentifier($entity);

            return $result;
        } else {
            $this->session->getFlashBag()->add('success', $saveMessage);

            return $this->router->redirect($entity);
        }
    }

    /**
     * @param object $entity
     * @param FormInterface $form
     * @param callable|string|null $resultCallback
     * @return array
     */
    protected function getResult($entity, FormInterface $form, $resultCallback = null)
    {
        $request = $this->getCurrentRequest();
        if ($resultCallback) {
            $result = $this->getResultData($resultCallback, $entity, $form);
        } else {
            $result = [
                'form' => $form->createView()
            ];
        }
        if (!array_key_exists('entity', $result)) {
            $result['entity'] = $entity;
        }
        $result['isWidgetContext'] = (bool)$request->get('_wid', false);

        return $result;
    }

    /**
     * @param string|callable $resultCallback
     * @param object $entity
     * @param FormInterface $form
     *
     * @return array
     */
    private function getResultData($resultCallback, $entity, FormInterface $form)
    {
        $request = $this->getCurrentRequest();
        if (is_callable($resultCallback)) {
            return $resultCallback($entity, $form, $request);
        } else {
            return $this->dataProviderRegistry->get($resultCallback)->getData($entity, $form, $request);
        }
    }

    /**
     * @param array $routeData
     * @return array
     */
    protected function addQueryParameters(array $routeData)
    {
        $request = $this->getCurrentRequest();
        $queryParts = $request->query->all();
        if ($queryParts) {
            if (!isset($routeData['parameters'])) {
                $routeData['parameters'] = [];
            }
            $routeData['parameters'] = array_merge($queryParts, $routeData['parameters']);
        }

        return $routeData;
    }

    /**
     * @return Request
     */
    protected function getCurrentRequest()
    {
        return $this->requestStack->getCurrentRequest();
    }
}
