<?php

namespace Neos\RedirectHandler\Ui\Controller;

/*
 * This file is part of the Neos.RedirectHandler.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use DateTime;
use Exception;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Service as LocalizationService;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Utility\Environment;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Error\Messages as Error;

use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Service\RedirectExportService;
use Neos\RedirectHandler\Service\RedirectImportService;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;

/**
 * @Flow\Scope("singleton")
 */
class ModuleController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var RedirectRepository
     */
    protected $redirectRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * @Flow\Inject
     * @var LocalizationService
     */
    protected $localizationService;

    /**
     * @Flow\Inject
     * @var RedirectExportService
     */
    protected $redirectExportService;

    /**
     * @Flow\Inject
     * @var RedirectImportService
     */
    protected $redirectImportService;

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * Renders the list of all redirects and allows modifying them.
     */
    public function indexAction()
    {
        $redirects = $this->redirectRepository->search();
        $csrfToken = $this->securityContext->getCsrfProtectionToken();
        $flashMessages = $this->flashMessageContainer->getMessagesAndFlush();
        $currentLocale = $this->localizationService->getConfiguration()->getCurrentLocale();

        $this->view->assignMultiple([
            'redirects' => $redirects,
            'flashMessages' => $flashMessages,
            'csrfToken' => $csrfToken,
            'locale' => $currentLocale,
        ]);
    }

    /**
     * Creates a single redirect and goes back to the list
     *
     * @throws StopActionException
     */
    public function createAction(): void
    {
        $status = true;

        [
            'host' => $host,
            'sourceUriPath' => $sourceUriPath,
            'targetUriPath' => $targetUriPath,
            'statusCode' => $statusCode,
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'comment' => $comment,
        ] = $this->request->getArguments();

        // TODO: Catch redirects without sourceUri or when source and target are the same

        if (empty($startDateTime)) {
            $startDateTime = null;
        } else {
            try {
                $startDateTime = new DateTime($startDateTime);
            } catch (Exception $e) {
                $status = false;
                $this->addFlashMessage('', $this->translateById('message.startDateTimeError'),
                    Error\Message::SEVERITY_ERROR);
            }
        }
        if (empty($endDateTime)) {
            $endDateTime = null;
        } else {
            try {
                $endDateTime = new DateTime($endDateTime);
            } catch (Exception $e) {
                $status = false;
                $this->addFlashMessage('', $this->translateById('message.endDateTimeError'),
                    Error\Message::SEVERITY_ERROR);
            }
        }

        if ($status) {
            $status = $this->addRedirect(
                $sourceUriPath, $targetUriPath, $statusCode, $host, $comment, $startDateTime, $endDateTime
            );
        }

        if (count($status) < 1) {
            $this->addFlashMessage('', $this->translateById('message.redirectNotCreated'),
                Error\Message::SEVERITY_ERROR);
        } else {
            // TODO: Render list of changed redirects
            $message = array_reduce($status, function ($carry, RedirectInterface $redirect) {
                return $carry . '<li>' . $redirect->getHost() . $redirect->getSourceUriPath() . '</li>';
            }, '');
            $this->addFlashMessage($message ? '<ul>' . $message . '</ul>' : '', $this->translateById('message.redirectCreated'),
                Error\Message::SEVERITY_OK);
        }

        $this->redirect('index');
    }

    /**
     * Updates a single redirect and goes back to the list
     *
     * @throws StopActionException
     */
    public function updateAction()
    {
        $status = true;

        [
            'host' => $host,
            'originalHost' => $originalHost,
            'sourceUriPath' => $sourceUriPath,
            'originalSourceUriPath' => $originalSourceUriPath,
            'targetUriPath' => $targetUriPath,
            'statusCode' => $statusCode,
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'comment' => $comment,
        ] = $this->request->getArguments();

        // TODO: Catch redirects without sourceUri or when source and target are the same

        if (empty($startDateTime)) {
            $startDateTime = null;
        } else {
            try {
                $startDateTime = new DateTime($startDateTime);
            } catch (Exception $e) {
                $status = false;
                $this->addFlashMessage('', $this->translateById('message.startDateTimeError'),
                    Error\Message::SEVERITY_ERROR);
            }
        }
        if (empty($endDateTime)) {
            $endDateTime = null;
        } else {
            try {
                $endDateTime = new DateTime($endDateTime);
            } catch (Exception $e) {
                $status = false;
                $this->addFlashMessage('', $this->translateById('message.endDateTimeError'),
                    Error\Message::SEVERITY_ERROR);
            }
        }

        if ($status) {
            $status = $this->updateRedirect(
                $originalSourceUriPath, $originalHost, $sourceUriPath, $targetUriPath, $statusCode, $host, $comment,
                $startDateTime, $endDateTime
            );
        }

        if (!$status) {
            $this->addFlashMessage('', $this->translateById('message.redirectNotUpdated'),
                Error\Message::SEVERITY_ERROR);
        } else {
            // TODO: Render list of changed redirects
            $this->addFlashMessage('', $this->translateById('message.redirectUpdated'),
                Error\Message::SEVERITY_OK);
        }

        $this->redirect('index');
    }

    /**
     * Deletes a single redirect and goes back to the list
     *
     * @throws StopActionException
     */
    public function deleteAction(): void
    {
        [
            'host' => $host,
            'sourceUriPath' => $sourceUriPath,
        ] = $this->request->getArguments();

        $status = $this->deleteRedirect($sourceUriPath, $host ?? null);

        if ($status === false) {
            $this->addFlashMessage('', $this->translateById('message.redirectNotDeleted'),
                Error\Message::SEVERITY_ERROR);
        } else {
            $this->addFlashMessage('', $this->translateById('message.redirectDeleted'), Error\Message::SEVERITY_OK);
        }

        $this->redirect('index');
    }

    /**
     * Shows the import interface with its options, actions and a protocol after an action
     */
    public function importAction(): void
    {
        $csrfToken = $this->securityContext->getCsrfProtectionToken();
        $flashMessages = $this->flashMessageContainer->getMessagesAndFlush();
        $this->view->assignMultiple([
            'csrfToken' => $csrfToken,
            'flashMessages' => $flashMessages,
        ]);
    }

    /**
     * Shows the export interface with its options and actions
     */
    public function exportAction(): void
    {
    }

    /**
     * Exports all redirects into a CSV file and starts its download
     */
    public function exportCsvAction(): void
    {
        $includeInactiveRedirects = $this->request->hasArgument('includeInactiveRedirects');
        $includeGeneratedRedirects = $this->request->hasArgument('includeGeneratedRedirects');

        // TODO: Make host selectable from distinct list of existing hosts
        $host = null;

        $csvWriter = $this->redirectExportService->exportCsv(
            $host,
            !$includeInactiveRedirects,
            $includeGeneratedRedirects ? null : RedirectInterface::REDIRECT_TYPE_MANUAL,
            true
        );
        $filename = 'neos-redirects-' . (new DateTime())->format('Y-m-d-H-i-s') . '.csv';

        $filePath = $this->environment->getPathToTemporaryDirectory() . $filename;

        file_put_contents($filePath, $csvWriter->getContent());

        header("Pragma: no-cache");
        header("Content-type: application/text");
        header("Content-Length: " . filesize($filePath));
        header("Content-Disposition: attachment; filename=" . $filename);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        readfile($filePath);

        // Remove file again
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        exit;
    }

    /**
     * Tries to import redirects from the given CSV file and then shows a protocol
     *
     * @param PersistentResource $csvFile
     * @param string $delimiter
     * @throws StopActionException
     */
    public function importCsvAction(PersistentResource $csvFile = null, $delimiter = ','): void
    {
        $protocol = [];

        if (!$csvFile) {
            $this->addFlashMessage('', $this->translateById('error.csvFileNotSet'),
                Error\Message::SEVERITY_ERROR);
            $this->redirect('import');
        }

        try {
            $reader = Reader::createFromStream($csvFile->getStream());
            $reader->setDelimiter($delimiter);
            $protocol = $this->redirectImportService->import($reader->getIterator());
            $this->addFlashMessage('', $this->translateById('message.importCsvSuccess'), Error\Message::SEVERITY_OK);
        } catch (CsvException $e) {
            $this->addFlashMessage('', $this->translateById('error.importCsvFailed'),
                Error\Message::SEVERITY_ERROR);
            $this->redirect('import');
        }

        $flashMessages = $this->flashMessageContainer->getMessagesAndFlush();
        $this->view->assignMultiple([
            'protocol' => $protocol,
            'flashMessages' => $flashMessages,
        ]);
    }

    /**
     * @param string $sourceUriPath
     * @param string $targetUriPath
     * @param integer $statusCode
     * @param string|null $host
     * @param string|null $comment
     * @param DateTime|null $startDateTime
     * @param DateTime|null $endDateTime
     * @param bool $force
     * @return array
     */
    protected function addRedirect(
        $sourceUriPath,
        $targetUriPath,
        $statusCode,
        $host = null,
        $comment = null,
        DateTime $startDateTime = null,
        DateTime $endDateTime = null,
        $force = false
    ): array {
        $redirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourceUriPath, $host ? $host : null, false);
        $isSame = $this->isSame($sourceUriPath, $targetUriPath, $host, $statusCode, $redirect);
        $go = true;

        if ($redirect !== null && $isSame === false && $force === false) {
            $go = false; // Ignore.. A redirect with the same source URI exist.
        } elseif ($redirect !== null && $isSame === false && $force === true) {
            $this->redirectStorage->removeOneBySourceUriPathAndHost($sourceUriPath, $host);
            $this->persistenceManager->persistAll();
        } elseif ($redirect !== null && $isSame === true) {
            $go = false; // Ignore.. Not valid.
        }

        if ($go) {
            $creator = $this->securityContext->getAccount()->getAccountIdentifier();

            $redirects = $this->redirectStorage->addRedirect($sourceUriPath, $targetUriPath, $statusCode, [$host],
                $creator,
                $comment, RedirectInterface::REDIRECT_TYPE_MANUAL, $startDateTime, $endDateTime);
            $this->persistenceManager->persistAll();
            return $redirects;
        }

        return [];
    }

    /**
     * @param string $originalSourceUriPath
     * @param string|null $originalHost
     * @param string $sourceUriPath
     * @param string|null $targetUriPath
     * @param integer $statusCode
     * @param string|null $host
     * @param string|null $comment
     * @param DateTime|null $startDateTime
     * @param DateTime|null $endDateTime
     * @param bool $force
     * @return array
     */
    protected function updateRedirect(
        $originalSourceUriPath,
        $originalHost,
        $sourceUriPath,
        $targetUriPath,
        $statusCode,
        $host = null,
        $comment = null,
        DateTime $startDateTime = null,
        DateTime $endDateTime = null,
        $force = false
    ): array {
        // TODO: Actually update redirect instead of deleting and creating it?
        $go = false;
        $redirect = $this->redirectStorage->getOneBySourceUriPathAndHost($originalSourceUriPath,
            $originalHost ? $originalHost : null, false);
        if ($redirect !== null && $force === false) {
            $this->deleteRedirect($originalSourceUriPath, $originalHost);
            $go = true;
        } elseif ($force === true) {
            $go = true;
        }

        if ($go) {
            return $this->addRedirect($sourceUriPath, $targetUriPath, $statusCode, $host, $comment, $startDateTime,
                $endDateTime, $force);
        }

        return [];
    }

    /**
     * @param string $sourceUriPath
     * @param string|null $host
     * @return bool
     */
    protected function deleteRedirect($sourceUriPath, $host = null)
    {
        $redirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourceUriPath, $host ? $host : null);
        if ($redirect === null) {
            return false;
        }
        $this->redirectStorage->removeOneBySourceUriPathAndHost($sourceUriPath, $host);
        $this->persistenceManager->persistAll();

        return true;
    }

    /**
     * @param string $sourceUriPath
     * @param string $targetUriPath
     * @param string $host
     * @param string $statusCode
     * @param RedirectInterface|null $redirect
     * @return bool
     */
    protected function isSame($sourceUriPath, $targetUriPath, $host, $statusCode, RedirectInterface $redirect = null)
    {
        if ($redirect === null) {
            return false;
        }

        return $redirect->getSourceUriPath() === $sourceUriPath
            && $redirect->getTargetUriPath() === $targetUriPath
            && $redirect->getHost() === $host
            && $redirect->getStatusCode() === (integer)$statusCode;
    }

    /**
     * Shorthand to translate labels for this package
     *
     * @param $id
     * @return string
     */
    protected function translateById($id): string
    {
        return $this->translator->translateById($id, [], null, null, 'Modules',
            'Neos.RedirectHandler.Ui');
    }
}
