<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Document;

use Exception;
use Pimcore;
use Pimcore\Document\Newsletter\AddressSourceAdapterFactoryInterface;
use Pimcore\Event\AdminEvents;
use Pimcore\Model\DataObject\ClassDefinition\Data\Email;
use Pimcore\Model\DataObject\ClassDefinition\Data\NewsletterActive;
use Pimcore\Model\DataObject\ClassDefinition\Data\NewsletterConfirmed;
use Pimcore\Model\DataObject\ClassDefinition\Listing;
use Pimcore\Model\Document;
use Pimcore\Model\Element;
use Pimcore\Model\Tool;
use Pimcore\Model\Tool\CustomReport\Config;
use Pimcore\Tool\Console;
use Pimcore\Tool\Newsletter;
use RuntimeException;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/newsletter")
 */
class NewsletterController extends DocumentControllerBase
{
    use Pimcore\Controller\Traits\ElementEditLockHelperTrait;

    /**
     * @Route("/get-data-by-id", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getDataByIdAction(Request $request): JsonResponse
    {
        // check for lock
        if (Element\Editlock::isLocked($request->get('id'), 'document')) {
            return $this->getEditLockResponse($request->get('id'), 'document');
        }
        Element\Editlock::lock($request->get('id'), 'document');

        /** @var Document\Newsletter $email */
        $email = Document\Newsletter::getById($request->get('id'));
        $email = clone $email;
        $email = $this->getLatestVersion($email);

        $versions = Element\Service::getSafeVersionInfo($email->getVersions());
        $email->setVersions(array_splice($versions, 0, 1));
        $email->idPath = Element\Service::getIdPath($email);
        $email->setUserPermissions($email->getUserPermissions());
        $email->setLocked($email->isLocked());
        $email->setParent(null);

        // unset useless data
        $email->setElements(null);
        $email->setChildren(null);

        $this->addTranslationsData($email);
        $this->minimizeProperties($email);

        // Hook for modifying return value - e.g. for changing permissions based on object data
        // data need to wrapped into a container in order to pass parameter to event listeners by reference
        // so that they can change the values
        $data = $email->getObjectVars();
        $data['versionDate'] = $email->getModificationDate();

        $data['php'] = [
            'classes' => array_merge([get_class($email)], array_values(class_parents($email))),
            'interfaces' => array_values(class_implements($email))
        ];

        $event = new GenericEvent($this, [
            'data' => $data,
            'document' => $email
        ]);
        Pimcore::getEventDispatcher()->dispatch(AdminEvents::DOCUMENT_GET_PRE_SEND_DATA, $event);
        $data = $event->getArgument('data');

        if ($email->isAllowed('view')) {
            return $this->adminJson($data);
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/save", methods={"PUT", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function saveAction(Request $request): JsonResponse
    {
        if ($request->get('id')) {
            /** @var Document\Newsletter $page */
            $page = Document\Newsletter::getById($request->get('id'));
            $page = $this->getLatestVersion($page);
            $page->setUserModification($this->getAdminUser()->getId());

            if ($request->get('task') === 'unpublish') {
                $page->setPublished(false);
            }

            if ($request->get('task') === 'publish') {
                $page->setPublished(true);
            }
            // only save when publish or unpublish
            if (($request->get('task') === 'publish' && $page->isAllowed('publish')) ||
                ($request->get('task') === 'unpublish' && $page->isAllowed('unpublish'))) {
                $this->setValuesToDocument($request, $page);

                $page->save();
                $this->saveToSession($page);

                return $this->adminJson([
                    'success' => true,
                    'data' => [
                        'versionDate' => $page->getModificationDate(),
                        'versionCount' => $page->getVersionCount()
                    ]
                ]);
            } elseif ($page->isAllowed('save')) {
                $this->setValuesToDocument($request, $page);
                $page->saveVersion();
                $this->saveToSession($page);

                return $this->adminJson(['success' => true]);
            } else {
                throw $this->createAccessDeniedHttpException();
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * @param Request $request
     * @param Document $page
     */
    protected function setValuesToDocument(Request $request, Document $page)
    {
        $this->addSettingsToDocument($request, $page);
        $this->addDataToDocument($request, $page);
        $this->addPropertiesToDocument($request, $page);

        // plaintext
        if ($request->get('plaintext')) {
            $plaintext = $this->decodeJson($request->get('plaintext'));
            $page->setValues($plaintext);
        }
    }

    /**
     * @Route("/checksql", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function checksqlAction(Request $request): JsonResponse
    {
        $count = 0;
        $success = false;

        try {
            $className = '\\Pimcore\\Model\\DataObject\\' . ucfirst($request->get('class')) . '\\Listing';
            /** @var Pimcore\Model\Listing\AbstractListing $list */
            $list = new $className();

            $conditions = ['(newsletterActive = 1 AND newsletterConfirmed = 1)'];
            if ($request->get('objectFilterSQL')) {
                $conditions[] = $request->get('objectFilterSQL');
            }
            $list->setCondition(implode(' AND ', $conditions));

            $count = $list->getTotalCount();
            $success = true;
        } catch (Exception $e) {
        }

        return $this->adminJson([
            'count' => $count,
            'success' => $success
        ]);
    }

    /**
     * @Route("/get-available-classes", methods={"GET"})
     *
     * @return JsonResponse
     */
    public function getAvailableClassesAction(): JsonResponse
    {
        $classList = new Listing();

        $availableClasses = [];
        foreach ($classList->load() as $class) {
            $fieldCount = 0;
            foreach ($class->getFieldDefinitions() as $fd) {
                if ($fd instanceof NewsletterActive ||
                    $fd instanceof NewsletterConfirmed ||
                    $fd instanceof Email) {
                    $fieldCount++;
                }
            }

            if ($fieldCount >= 3) {
                $availableClasses[] = ['name' => $class->getName()];
            }
        }

        return $this->adminJson(['data' => $availableClasses]);
    }

    /**
     * @Route("/get-available-reports", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAvailableReportsAction(Request $request): JsonResponse
    {
        $task = $request->get('task');

        if ($task === 'list') {
            $reportList = Config::getReportsList();

            $availableReports = [];
            foreach ($reportList as $report) {
                $availableReports[] = ['id' => $report['id'], 'text' => $report['text']];
            }

            return $this->adminJson(['data' => $availableReports]);
        }

        if ($task === 'fieldNames') {
            $reportId = $request->get('reportId');
            $report = Config::getByName($reportId);
            $columnConfiguration = $report !== null ? $report->getColumnConfiguration() : [];

            $availableColumns = [];
            foreach ($columnConfiguration as $column) {
                if ($column['display']) {
                    $availableColumns[] = ['name' => $column['name']];
                }
            }

            return $this->adminJson(['data' => $availableColumns]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/get-send-status", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getSendStatusAction(Request $request): JsonResponse
    {
        $document = Document\Newsletter::getById($request->get('id'));
        $data = Tool\TmpStore::get($document->getTmpStoreId());

        return $this->adminJson([
            'data' => $data ? $data->getData() : null,
            'success' => true
        ]);
    }

    /**
     * @Route("/stop-send", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function stopSendAction(Request $request): JsonResponse
    {
        $document = Document\Newsletter::getById($request->get('id'));
        Tool\TmpStore::delete($document->getTmpStoreId());

        return $this->adminJson([
            'success' => true
        ]);
    }

    /**
     * @Route("/send", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function sendAction(Request $request): JsonResponse
    {
        $document = Document\Newsletter::getById($request->get('id'));

        if (Tool\TmpStore::get($document->getTmpStoreId())) {
            throw new RuntimeException('Newsletter sending already in progress, need to finish first.');
        }

        $document = Document\Newsletter::getById($request->get('id'));

        Tool\TmpStore::add($document->getTmpStoreId(), [
            'documentId' => $document->getId(),
            'addressSourceAdapterName' => $request->get('addressAdapterName'),
            'adapterParams' => json_decode($request->get('adapterParams'), true),
            'inProgress' => false,
            'progress' => 0
        ], 'newsletter');

        Console::runPhpScriptInBackground(
            realpath(PIMCORE_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console'),
            'internal:newsletter-document-send ' . escapeshellarg($document->getTmpStoreId()) . ' ' . escapeshellarg(\Pimcore\Tool::getHostUrl()),
            PIMCORE_LOG_DIRECTORY . DIRECTORY_SEPARATOR . 'newsletter-sending-output.log'
        );

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/calculate", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function calculateAction(Request $request): JsonResponse
    {
        $addressSourceAdapterName = $request->get('addressAdapterName');
        $adapterParams = json_decode($request->get('adapterParams'), true);

        $serviceLocator = $this->get('pimcore.newsletter.address_source_adapter.factories');

        if (!$serviceLocator->has($addressSourceAdapterName)) {
            $msg = sprintf(
                'Cannot send newsletters because Address Source Adapter with identifier %s could not be found',
                $addressSourceAdapterName
            );

            return $this->adminJson(['success' => false, 'count' => '0', 'message' => $msg]);
        }

        /** @var $addressAdapterFactory AddressSourceAdapterFactoryInterface */
        $addressAdapterFactory = $serviceLocator->get($addressSourceAdapterName);
        $addressAdapter = $addressAdapterFactory->create($adapterParams);

        return $this->adminJson(['success' => true, 'count' => $addressAdapter->getTotalRecordCount()]);
    }

    /**
     * @Route("/send-test", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function sendTestAction(Request $request): JsonResponse
    {
        $document = Document\Newsletter::getById($request->get('id'));
        $addressSourceAdapterName = $request->get('addressAdapterName');
        $adapterParams = json_decode($request->get('adapterParams'), true);
        $testMailAddress = $request->get('testMailAddress');

        if (empty($testMailAddress)) {
            return $this->adminJson([
                'success' => false,
                'message' => 'Please provide a valid email address to send test newsletter'
            ]);
        }

        $serviceLocator = $this->get('pimcore.newsletter.address_source_adapter.factories');

        if (!$serviceLocator->has($addressSourceAdapterName)) {
            return $this->adminJson([
                'success' => false,
                'error' => sprintf(
                    'Cannot send newsletters because Address Source Adapter with identifier %s could not be found',
                    $addressSourceAdapterName
                )
            ]);
        }

        /** @var $addressAdapterFactory AddressSourceAdapterFactoryInterface */
        $addressAdapterFactory = $serviceLocator->get($addressSourceAdapterName);
        $addressAdapter = $addressAdapterFactory->create($adapterParams);

        $sendingContainer = $addressAdapter->getParamsForTestSending($testMailAddress);

        $mail = Newsletter::prepareMail($document);
        Newsletter::sendNewsletterDocumentBasedMail($mail, $sendingContainer);

        return $this->adminJson(['success' => true]);
    }
}
