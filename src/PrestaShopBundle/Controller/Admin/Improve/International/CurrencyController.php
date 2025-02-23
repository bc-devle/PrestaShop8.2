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

namespace PrestaShopBundle\Controller\Admin\Improve\International;

use Exception;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\BulkDeleteCurrenciesCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\BulkToggleCurrenciesStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\DeleteCurrencyCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\RefreshExchangeRatesCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\Command\ToggleCurrencyStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\BulkDeleteCurrenciesException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\BulkToggleCurrenciesException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CannotDeleteDefaultCurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CannotDisableDefaultCurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CannotRefreshExchangeRatesException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CannotToggleCurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\CurrencyNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\DefaultCurrencyInMultiShopException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\ExchangeRateNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Exception\InvalidUnofficialCurrencyException;
use PrestaShop\PrestaShop\Core\Domain\Currency\Query\GetCurrencyExchangeRate;
use PrestaShop\PrestaShop\Core\Domain\Currency\Query\GetReferenceCurrency;
use PrestaShop\PrestaShop\Core\Domain\Currency\QueryResult\ExchangeRate as ExchangeRateResult;
use PrestaShop\PrestaShop\Core\Domain\Currency\QueryResult\ReferenceCurrency;
use PrestaShop\PrestaShop\Core\Domain\Currency\ValueObject\ExchangeRate;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface as ConfigurationFormHandlerInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Language\LanguageInterface;
use PrestaShop\PrestaShop\Core\Language\LanguageRepositoryInterface;
use PrestaShop\PrestaShop\Core\Localization\CLDR\ComputingPrecision;
use PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository as CldrLocaleRepository;
use PrestaShop\PrestaShop\Core\Localization\Currency\PatternTransformer;
use PrestaShop\PrestaShop\Core\Localization\Locale\RepositoryInterface as LocaleRepositoryInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\CurrencyFilters;
use PrestaShop\PrestaShop\Core\Security\Permission;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class CurrencyController is responsible for handling "Improve -> International -> Localization -> Currencies" page.
 */
class CurrencyController extends PrestaShopAdminController
{
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            LanguageRepositoryInterface::class => LanguageRepositoryInterface::class,
            LocaleRepositoryInterface::class => LocaleRepositoryInterface::class,
            CldrLocaleRepository::class => CldrLocaleRepository::class,
            PatternTransformer::class => PatternTransformer::class,
        ];
    }

    /**
     * Show currency page.
     *
     * @param CurrencyFilters $filters
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        CurrencyFilters $filters,
        Request $request,
        #[Autowire(service: 'prestashop.core.grid.factory.currency')]
        GridFactoryInterface $currencyGridFactory,
        #[Autowire(service: 'prestashop.admin.currency_settings.form_handler')]
        ConfigurationFormHandlerInterface $settingsFormHandler
    ): Response {
        $currencyGrid = $currencyGridFactory->getGrid($filters);

        $settingsForm = $settingsFormHandler->getForm();

        return $this->render('@PrestaShop/Admin/Improve/International/Currency/index.html.twig', [
            'currencyGrid' => $this->presentGrid($currencyGrid),
            'currencySettingsForm' => $settingsForm->createView(),
            'enableSidebar' => true,
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
        ]);
    }

    /**
     * Displays and handles currency form.
     *
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index', message: 'You need permission to create this.')]
    public function createAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.builder.currency_form_builder')]
        FormBuilderInterface $currencyFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.currency_form_handler')]
        FormHandlerInterface $currencyFormHandler,
    ): Response {
        $currencyForm = $currencyFormBuilder->getForm();
        $currencyForm->handleRequest($request);

        try {
            $result = $currencyFormHandler->handle($currencyForm);
            if (null !== $result->getIdentifiableObjectId()) {
                $this->addFlash('success', $this->trans('Successful creation', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_currencies_index');
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->render('@PrestaShop/Admin/Improve/International/Currency/create.html.twig', [
            'isShopFeatureEnabled' => $this->getShopContext()->isMultiShopUsed(),
            'currencyForm' => $currencyForm->createView(),
            'layoutTitle' => $this->trans('New currency', [], 'Admin.Navigation.Menu'),
        ]);
    }

    /**
     * Displays currency form.
     *
     * @param int $currencyId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index', message: 'You need permission to edit this.')]
    public function editAction(
        int $currencyId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.builder.currency_form_builder')]
        FormBuilderInterface $currencyFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.currency_form_handler')]
        FormHandlerInterface $currencyFormHandler,
    ): Response {
        $currencyForm = $currencyFormBuilder->getFormFor($currencyId);

        try {
            $currencyForm->handleRequest($request);

            $result = $currencyFormHandler->handleFor($currencyId, $currencyForm);

            if ($result->isSubmitted() && $result->isValid()) {
                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_currencies_index');
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        $templateVars = [
            'isShopFeatureEnabled' => $this->getShopContext()->isMultiShopUsed(),
            'currencyForm' => $currencyForm->createView(),
            'layoutTitle' => $this->trans(
                'Editing currency %name%',
                [
                    '%name%' => $currencyForm->getData()['names'][$this->getLanguageContext()->getId()],
                ],
                'Admin.Navigation.Menu'
            ),
        ];
        try {
            $languageData = $this->getLanguagesData($currencyForm->getData()['iso_code']);
            $templateVars['languages'] = $languageData;
        } catch (Exception $e) {
            $templateVars['languageDataError'] = $e->getMessage();
            $templateVars['languages'] = [];
        }

        return $this->render('@PrestaShop/Admin/Improve/International/Currency/edit.html.twig', $templateVars);
    }

    /**
     * @param string $currencyIsoCode
     *
     * @return array
     */
    private function getLanguagesData(string $currencyIsoCode): array
    {
        /** @var LanguageRepositoryInterface $langRepository */
        $langRepository = $this->container->get(LanguageRepositoryInterface::class);
        $languages = $langRepository->findAll();
        /** @var LocaleRepositoryInterface $localeRepository */
        $localeRepository = $this->container->get(LocaleRepositoryInterface::class);
        /** @var CldrLocaleRepository $cldrLocaleRepository */
        $cldrLocaleRepository = $this->container->get(CldrLocaleRepository::class);
        /** @var PatternTransformer $transformer */
        $transformer = $this->container->get(PatternTransformer::class);

        $languagesData = [];
        /** @var LanguageInterface $language */
        foreach ($languages as $language) {
            $locale = $localeRepository->getLocale($language->getLocale());
            $cldrLocale = $cldrLocaleRepository->getLocale($language->getLocale());
            $cldrCurrency = $cldrLocale->getCurrency($currencyIsoCode);
            $priceSpecification = $locale->getPriceSpecification($currencyIsoCode);

            $transformations = [];
            foreach (PatternTransformer::ALLOWED_TRANSFORMATIONS as $transformationType) {
                $transformations[$transformationType] = $transformer->transform(
                    $cldrLocale->getCurrencyPattern(),
                    $transformationType
                );
            }

            $languagesData[] = [
                'id' => $language->getId(),
                'name' => $language->getName(),
                'currencyPattern' => $cldrLocale->getCurrencyPattern(),
                'currencySymbol' => null !== $cldrCurrency ? $cldrCurrency->getSymbol() : $currencyIsoCode,
                'priceSpecification' => $priceSpecification->toArray(),
                'transformations' => $transformations,
            ];
        }

        return $languagesData;
    }

    /**
     * Deletes currency.
     *
     * @param int $currencyId
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index', message: 'You need permission to delete this.')]
    public function deleteAction(int $currencyId): RedirectResponse
    {
        try {
            $this->dispatchCommand(new DeleteCurrencyCommand((int) $currencyId));
        } catch (CurrencyException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));

            return $this->redirectToRoute('admin_currencies_index');
        }

        $this->addFlash('success', $this->trans('Successful deletion', [], 'Admin.Notifications.Success'));

        return $this->redirectToRoute('admin_currencies_index');
    }

    /**
     * Get the data for a currency (from CLDR)
     *
     * @param string $currencyIsoCode
     *
     * @return JsonResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function getReferenceDataAction(string $currencyIsoCode): JsonResponse
    {
        try {
            /** @var ReferenceCurrency $referenceCurrency */
            $referenceCurrency = $this->dispatchQuery(new GetReferenceCurrency($currencyIsoCode));
        } catch (CurrencyException) {
            return new JsonResponse([
                'error' => $this->trans(
                    'Cannot find reference data for currency %isoCode%',
                    [
                        '%isoCode%' => $currencyIsoCode,
                    ],
                    'Admin.International.Feature'
                ),
            ], 404);
        }

        try {
            /** @var ExchangeRateResult $exchangeRate */
            $exchangeRate = $this->dispatchQuery(new GetCurrencyExchangeRate($currencyIsoCode));
            $computingPrecision = new ComputingPrecision();
            $exchangeRateValue = $exchangeRate->getValue()->round($computingPrecision->getPrecision(2));
        } catch (ExchangeRateNotFoundException) {
            $exchangeRateValue = ExchangeRate::DEFAULT_RATE;
        }

        return new JsonResponse([
            'isoCode' => $referenceCurrency->getIsoCode(),
            'numericIsoCode' => $referenceCurrency->getNumericIsoCode(),
            'precision' => $referenceCurrency->getPrecision(),
            'names' => $referenceCurrency->getNames(),
            'symbols' => $referenceCurrency->getSymbols(),
            'patterns' => $referenceCurrency->getPatterns(),
            'exchangeRate' => $exchangeRateValue,
        ]);
    }

    /**
     * Toggles status.
     *
     * @param int $currencyId
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index', message: 'You need permission to edit this.')]
    public function toggleStatusAction(int $currencyId): RedirectResponse
    {
        try {
            $this->dispatchCommand(new ToggleCurrencyStatusCommand((int) $currencyId));
        } catch (CurrencyException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));

            return $this->redirectToRoute('admin_currencies_index');
        }

        $this->addFlash(
            'success',
            $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success')
        );

        return $this->redirectToRoute('admin_currencies_index');
    }

    /**
     * Refresh exchange rates.
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index', message: 'You need permission to edit this.')]
    public function refreshExchangeRatesAction(): RedirectResponse
    {
        try {
            $this->dispatchCommand(new RefreshExchangeRatesCommand());

            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (CannotRefreshExchangeRatesException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_currencies_index');
    }

    /**
     * Handles ajax request which updates live exchange rates.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateLiveExchangeRatesAction(
        Request $request,
        #[Autowire(service: 'prestashop.admin.currency_settings.form_handler')]
        ConfigurationFormHandlerInterface $settingsFormHandler
    ): JsonResponse {
        if ($this->isDemoModeEnabled()) {
            return $this->json([
                'status' => false,
                'message' => $this->getDemoModeErrorMessage(),
            ],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $authLevel = $this->getAuthorizationLevel($request->attributes->get('_legacy_controller'));

        if (!in_array($authLevel, [Permission::LEVEL_UPDATE, Permission::LEVEL_DELETE])) {
            return $this->json([
                'status' => false,
                'message' => $this->trans(
                    'You need permission to edit this.',
                    [],
                    'Admin.Notifications.Error'
                ),
            ],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $settingsForm = $settingsFormHandler->getForm();

        $settingsForm->handleRequest($request);

        $response = [
            'status' => false,
            'message' => $this->trans('An unexpected error occurred.', [], 'Admin.Notifications.Error'),
        ];
        $statusCode = Response::HTTP_BAD_REQUEST;

        if ($settingsForm->isSubmitted()) {
            try {
                $settingsFormHandler->save($settingsForm->getData());
                $response = [
                    'status' => true,
                    'message' => $this->trans(
                        'The status has been successfully updated.',
                        [],
                        'Admin.Notifications.Success'
                    ),
                ];
                $statusCode = Response::HTTP_OK;
            } catch (CurrencyException $e) {
                $response['message'] = $this->getErrorMessageForException($e, $this->getErrorMessages($e));
            }
        }

        return $this->json($response, $statusCode);
    }

    /**
     * Toggles currencies status in bulk action
     *
     * @param Request $request
     * @param string $status
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index')]
    public function bulkToggleStatusAction(Request $request, string $status): RedirectResponse
    {
        $currenciesIds = $this->getBulkCurrenciesFromRequest($request);
        $expectedStatus = 'enable' === $status;

        try {
            $this->dispatchCommand(new BulkToggleCurrenciesStatusCommand(
                $currenciesIds,
                $expectedStatus
            ));

            $this->addFlash(
                'success',
                $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success')
            );
        } catch (CurrencyException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_currencies_index');
    }

    /**
     * Deletes currencies in bulk action
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_currencies_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_currencies_index')]
    public function bulkDeleteAction(Request $request): RedirectResponse
    {
        $currenciesIds = $this->getBulkCurrenciesFromRequest($request);

        try {
            $this->dispatchCommand(new BulkDeleteCurrenciesCommand($currenciesIds));

            $this->addFlash(
                'success',
                $this->trans('The selection has been successfully deleted.', [], 'Admin.Notifications.Success')
            );
        } catch (CurrencyException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_currencies_index');
    }

    /**
     * Gets an error by exception class and its code.
     *
     * @param Exception $e
     *
     * @return array
     */
    private function getErrorMessages(Exception $e): array
    {
        $isoCode = $e instanceof InvalidUnofficialCurrencyException ? $e->getIsoCode() : '';

        return [
            CurrencyConstraintException::class => [
                CurrencyConstraintException::INVALID_ISO_CODE => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('ISO code', [], 'Admin.International.Feature')),
                    ],
                    'Admin.Notifications.Error'
                ),
                CurrencyConstraintException::INVALID_NUMERIC_ISO_CODE => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('Numeric ISO code', [], 'Admin.International.Feature')),
                    ],
                    'Admin.Notifications.Error'
                ),
                CurrencyConstraintException::INVALID_EXCHANGE_RATE => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('Exchange rate', [], 'Admin.International.Feature')),
                    ],
                    'Admin.Notifications.Error'
                ),
                CurrencyConstraintException::INVALID_NAME => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('Currency name', [], 'Admin.International.Feature')),
                    ],
                    'Admin.Notifications.Error'
                ),
                CurrencyConstraintException::CURRENCY_ALREADY_EXISTS => $this->trans(
                    'This currency already exists.',
                    [],
                    'Admin.International.Notification'
                ),
                CurrencyConstraintException::EMPTY_BULK_TOGGLE => $this->trans(
                    'You must select at least one item to perform a bulk action.',
                    [],
                    'Admin.Notifications.Error'
                ),
                CurrencyConstraintException::EMPTY_BULK_DELETE => $this->trans(
                    'You must select at least one item to perform a bulk action.',
                    [],
                    'Admin.Notifications.Error'
                ),
            ],
            DefaultCurrencyInMultiShopException::class => [
                DefaultCurrencyInMultiShopException::CANNOT_REMOVE_CURRENCY => $this->trans(
                    '%currency% is the default currency for shop %shop_name%, and therefore cannot be removed from shop association',
                    [
                        '%currency%' => $e instanceof DefaultCurrencyInMultiShopException ? $e->getCurrencyName() : '',
                        '%shop_name%' => $e instanceof DefaultCurrencyInMultiShopException ? $e->getShopName() : '',
                    ],
                    'Admin.International.Notification'
                ),
                DefaultCurrencyInMultiShopException::CANNOT_DISABLE_CURRENCY => $this->trans(
                    '%currency% is the default currency for shop %shop_name%, and therefore cannot be disabled',
                    [
                        '%currency%' => $e instanceof DefaultCurrencyInMultiShopException ? $e->getCurrencyName() : '',
                        '%shop_name%' => $e instanceof DefaultCurrencyInMultiShopException ? $e->getShopName() : '',
                    ],
                    'Admin.International.Notification'
                ),
            ],
            CurrencyNotFoundException::class => $this->trans(
                'The object cannot be loaded (or found).',
                [],
                'Admin.Notifications.Error'
            ),
            CannotToggleCurrencyException::class => $this->trans(
                'An error occurred while updating the status.',
                [],
                'Admin.Notifications.Error'
            ),
            CannotDeleteDefaultCurrencyException::class => $this->trans(
                'You cannot delete the default currency',
                [],
                'Admin.International.Notification'
            ),
            CannotDisableDefaultCurrencyException::class => $this->trans(
                'You cannot disable the default currency',
                [],
                'Admin.International.Notification'
            ),
            InvalidUnofficialCurrencyException::class => $this->trans(
                'Oops... it looks like this ISO code already exists. If you are: [1][2]trying to create an alternative currency, you must type a different ISO code[/2][2]trying to modify the currency with ISO code %isoCode%, make sure you did not check the creation box[/2][/1]',
                [
                    '%isoCode%' => $isoCode,
                    '[1]' => '<ul>',
                    '[/1]' => '</ul>',
                    '[2]' => '<li>',
                    '[/2]' => '</li>',
                ],
                'Admin.International.Notification'
            ),
            BulkDeleteCurrenciesException::class => sprintf(
                '%s: %s',
                $this->trans(
                    'An error occurred while deleting this selection.',
                    [],
                    'Admin.Notifications.Error'
                ),
                $e instanceof BulkDeleteCurrenciesException ? implode(', ', $e->getCurrenciesNames()) : ''
            ),
            BulkToggleCurrenciesException::class => sprintf(
                '%s: %s',
                $this->trans(
                    'An error occurred while updating the status.',
                    [],
                    'Admin.Notifications.Error'
                ),
                $e instanceof BulkToggleCurrenciesException ? implode(', ', $e->getCurrenciesNames()) : ''
            ),
        ];
    }

    /**
     * Get currencies ids from request for bulk action
     *
     * @param Request $request
     *
     * @return int[]
     */
    private function getBulkCurrenciesFromRequest(Request $request): array
    {
        $currenciesIds = $request->request->all('currency_currency_bulk');

        foreach ($currenciesIds as $i => $currencyId) {
            $currenciesIds[$i] = (int) $currencyId;
        }

        return $currenciesIds;
    }
}
