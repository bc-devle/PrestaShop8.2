<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShopBundle\Controller\Admin\Configure\AdvancedParameters;

use PrestaShop\PrestaShop\Adapter\Backup\Backup;
use PrestaShop\PrestaShop\Core\Backup\Exception\BackupException;
use PrestaShop\PrestaShop\Core\Backup\Exception\DirectoryIsNotWritableException;
use PrestaShop\PrestaShop\Core\Backup\Manager\BackupCreatorInterface;
use PrestaShop\PrestaShop\Core\Backup\Manager\BackupRemoverInterface;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\BackupFilters;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class BackupController is responsible for "Configure > Advanced Parameters > Database > Backup" page.
 */
class BackupController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to update this.', redirectRoute: 'admin_products_index')]
    public function indexAction(
        Request $request,
        BackupFilters $filters,
        #[Autowire(service: 'prestashop.core.grid.factory.backup')]
        GridFactoryInterface $backupsGridFactory,
        #[Autowire(service: 'prestashop.admin.backup.form_handler')]
        FormHandlerInterface $backupFormHandler,
    ): Response {
        $backupForm = $backupFormHandler->getForm();
        $configuration = $this->getConfiguration();

        $hasDownloadFile = false;
        $downloadFile = null;

        if ($request->query->has('download_filename')) {
            $hasDownloadFile = true;
            $backup = new Backup($request->query->get('download_filename'));
            $downloadFile = [
                'url' => $backup->getUrl(),
                'size' => number_format($backup->getSize() * 0.000001, 2, '.', ''),
            ];
        }

        $backupGrid = $backupsGridFactory->getGrid($filters);

        return $this->render('@PrestaShop/Admin/Configure/AdvancedParameters/Backup/index.html.twig', [
            'backupGrid' => $this->presentGrid($backupGrid),
            'backupForm' => $backupForm->createView(),
            'dbPrefix' => $configuration->get('_DB_PREFIX_'),
            'hasDownloadFile' => $hasDownloadFile,
            'downloadFile' => $downloadFile,
            'enableSidebar' => true,
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
            'multistoreInfoTip' => $this->trans(
                'Note that this feature is only available in the "all stores" context. It will be added to all your stores.',
                [],
                'Admin.Notifications.Info'
            ),
            'multistoreIsUsed' => $this->getShopContext()->isMultiShopUsed(),
        ]);
    }

    #[DemoRestricted(redirectRoute: 'admin_backups_index')]
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function downloadViewAction(Request $request, string $downloadFileName): Response
    {
        $backup = new Backup($downloadFileName);

        return $this->render('@PrestaShop/Admin/Configure/AdvancedParameters/Backup/download_view.html.twig', [
            'downloadFile' => [
                'url' => $backup->getUrl(),
                'size' => $backup->getSize(),
            ],
            'layoutTitle' => $this->trans('Downloading backup %s', [$downloadFileName], 'Admin.Navigation.Menu'),
            'enableSidebar' => true,
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
        ]);
    }

    #[DemoRestricted(redirectRoute: 'admin_backup')]
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function downloadContentAction(string $downloadFileName): BinaryFileResponse
    {
        $backup = new Backup($downloadFileName);

        return new BinaryFileResponse($backup->getFilePath());
    }

    #[DemoRestricted(redirectRoute: 'admin_backups_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('create', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to update this.', redirectRoute: 'admin_backups_index')]
    public function saveOptionsAction(
        Request $request,
        #[Autowire(service: 'prestashop.admin.backup.form_handler')]
        FormHandlerInterface $backupFormHandler,
    ): RedirectResponse {
        $backupForm = $backupFormHandler->getForm();
        $backupForm->handleRequest($request);

        if ($backupForm->isSubmitted()) {
            $errors = $backupFormHandler->save($backupForm->getData());

            if (!empty($errors)) {
                $this->addFlashErrors($errors);
            } else {
                $this->addFlash('success', $this->trans('Update successful', [], 'Admin.Notifications.Success'));
            }
        }

        return $this->redirectToRoute('admin_backups_index');
    }

    #[DemoRestricted(redirectRoute: 'admin_backups_index')]
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.', redirectRoute: 'admin_backups_index')]
    public function createAction(
        BackupCreatorInterface $backupCreator,
    ): RedirectResponse {
        try {
            $backup = $backupCreator->createBackup();

            $this->addFlash(
                'success',
                $this->trans(
                    'It appears the backup was successful, however you must download and carefully verify the backup file before proceeding.',
                    [],
                    'Admin.Advparameters.Notification'
                )
            );

            return $this->redirectToRoute('admin_backups_index', ['download_filename' => $backup->getFileName()]);
        } catch (DirectoryIsNotWritableException) {
            $this->addFlash(
                'error',
                $this->trans(
                    'The "Backups" directory located in the admin directory must be writable (CHMOD 755 / 777).',
                    [],
                    'Admin.Advparameters.Notification'
                )
            );
        } catch (BackupException) {
            $this->addFlash('error', $this->trans('The backup file does not exist', [], 'Admin.Advparameters.Notification'));
        }

        return $this->redirectToRoute('admin_backups_index');
    }

    #[DemoRestricted(redirectRoute: 'admin_backups_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.', redirectRoute: 'admin_backups_index')]
    public function deleteAction(
        string $deleteFileName,
        BackupRemoverInterface $backupRemover,
    ): RedirectResponse {
        $backup = new Backup($deleteFileName);

        if (!$backupRemover->remove($backup)) {
            $this->addFlash(
                'error',
                sprintf(
                    '%s "%s"',
                    $this->trans('Error deleting', [], 'Admin.Advparameters.Notification'),
                    $backup->getFileName()
                )
            );

            return $this->redirectToRoute('admin_backups_index');
        }

        $this->addFlash('success', $this->trans('Successful deletion', [], 'Admin.Notifications.Success'));

        return $this->redirectToRoute('admin_backups_index');
    }

    #[DemoRestricted(redirectRoute: 'admin_backups_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.', redirectRoute: 'admin_backups_index')]
    public function bulkDeleteAction(
        Request $request,
        BackupRemoverInterface $backupRemover,
    ): RedirectResponse {
        $backupsToDelete = $request->request->all('backup_backup_bulk_file_names');

        if (empty($backupsToDelete)) {
            $this->addFlash(
                'error',
                $this->trans('You must select at least one element to delete.', [], 'Admin.Notifications.Error')
            );

            return $this->redirectToRoute('admin_backups_index');
        }

        $failedBackups = [];
        foreach ($backupsToDelete as $backupFileName) {
            $backup = new Backup($backupFileName);

            if (!$backupRemover->remove($backup)) {
                $failedBackups[] = $backup->getFileName();
            }
        }

        if (!empty($failedBackups)) {
            $this->addFlash(
                'error',
                $this->trans('An error occurred while deleting this selection.', [], 'Admin.Notifications.Error')
            );

            foreach ($failedBackups as $backupFileName) {
                $this->addFlash(
                    'error',
                    $this->trans('Can\'t delete #%id%', ['%id%' => $backupFileName], 'Admin.Notifications.Error')
                );
            }

            return $this->redirectToRoute('admin_backups_index');
        }

        $this->addFlash(
            'success',
            $this->trans('The selection has been successfully deleted.', [], 'Admin.Notifications.Success')
        );

        return $this->redirectToRoute('admin_backups_index');
    }
}
