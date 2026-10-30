<?php

declare(strict_types=1);

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShopBundle\Service\Routing\Router;
use SimPay\SDK\SimPay as SimPaySDK;
use SimPaypl\PrestaShop\Form\SimpayDataConfiguration;
use SimPaypl\PrestaShop\Helper\SimPayChannelCache;
use SimPaypl\PrestaShop\Helper\SimPayLogger;
use SimPaypl\PrestaShop\PaymentClientFactory;
use SimPaypl\PrestaShop\Service\SimPayBlikAliasService;
use SimPaypl\PrestaShop\Service\SimPayPaymentAttemptService;
use SimPaypl\PrestaShop\Service\SimPayPaymentRequestBuilder;
use SimPaypl\PrestaShop\Service\SimPayRefundService;
use SimPaypl\PrestaShop\Service\SimPayRetryPaymentService;
use SimPaypl\PrestaShop\Update\UpdateChecker;

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

final class Simpay extends PaymentModule
{
    public const CONFIG_OS_AWAITING = 'PS_OS_SIMPAY_AWAITING';
    public const CONFIG_OS_EXPIRED = 'PS_OS_SIMPAY_EXPIRED';

    private const HOOKS = [
        'paymentOptions',
        'displayBackOfficeHeader',
        'displayHeader',
        'displayOrderDetail',
        'actionGetExtraMailTemplateVars',
        'displayAdminOrderMain',
    ];

    /** @var array<string, object> */
    private array $services = [];

    /**
     * Module-level service locator.
     * Works in ALL contexts: hooks, front controllers, admin controllers, CLI.
     * Independent of Symfony DI container and its cache.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function getService(string $className): object
    {
        if (isset($this->services[$className])) {
            return $this->services[$className];
        }

        $this->services[$className] = match ($className) {
            SimPaySDK::class => (new PaymentClientFactory(
                new \PrestaShop\PrestaShop\Adapter\Configuration()
            ))(),
            SimPayPaymentAttemptService::class => new SimPayPaymentAttemptService(),
            SimPayRefundService::class => new SimPayRefundService($this->getService(SimPaySDK::class)),
            SimPayBlikAliasService::class => new SimPayBlikAliasService(),
            SimPayChannelCache::class => new SimPayChannelCache($this->getService(SimPaySDK::class)),
            SimPayPaymentRequestBuilder::class => new SimPayPaymentRequestBuilder(
                new \PrestaShop\PrestaShop\Adapter\LegacyContext()
            ),
            SimPayRetryPaymentService::class => new SimPayRetryPaymentService($this),
            UpdateChecker::class => new UpdateChecker($this),
            SimPayLogger::class => new SimPayLogger(),
            default => throw new \InvalidArgumentException(
                sprintf('Unknown service "%s" requested from SimPay module.', $className)
            ),
        };

        return $this->services[$className];
    }

    public function __construct()
    {
        $this->name = 'simpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.2.8';
        $this->author = 'Payments Solution Sp. z o.o.';
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->controllers = ['failed', 'notify', 'validate', 'retry', 'blik'];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('SimPay Payments', [], 'Modules.Simpay.Admin');
        $this->description = $this->trans('Accept fast and secure online payments with SimPay – BLIK, online transfers and instant payments. Easy integration and smooth checkout for your customers.', [], 'Modules.Simpay.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall? You will lose all your settings!', [], 'Modules.Simpay.Admin');
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->registerHook(self::HOOKS)) {
            return false;
        }

        Configuration::updateValue(SimpayDataConfiguration::REPAYMENT_ENABLED, true);

        if (!$this->ensureOrderState(self::CONFIG_OS_AWAITING, [
            'en' => 'Awaiting SimPay payment',
            'pl' => 'Oczekuje na płatność SimPay',
        ], '#03d14e', false)) {
            return false;
        }

        if (!$this->ensureOrderState(
            self::CONFIG_OS_EXPIRED,
            [
                'en' => 'SimPay payment expired',
                'pl' => 'Płatność SimPay wygasła',
            ],
            '#03d14e',
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            'simpay_retry_payment'
        )) {
            return false;
        }

        if (!$this->ensureMailTemplate('simpay_retry_payment')) {
            return false;
        }

        if (!$this->installPaymentAttemptTable()) {
            return false;
        }

        if (!$this->installRefundsTable()) {
            return false;
        }

        if (!$this->installPaymentLogTable()) {
            return false;
        }

        if (!$this->installBlikAliasTable()) {
            return false;
        }

        $this->clearSymfonyCache();

        return true;
    }

    public function enable($force_all = false): bool
    {
        $result = parent::enable($force_all)
            && $this->ensureMailTemplate('simpay_retry_payment');

        if ($result) {
            $this->clearSymfonyCache();
        }

        return $result;
    }

    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        $this->clearSymfonyCache();

        // leave order states and tables intact to preserve IDs/data
        return true;
    }

    public function ensureOrderState(
        string $configurationKey,
        array $nameByLangIsoCode,
        string $color,
        bool $isLogable = false,
        bool $isPaid = false,
        bool $isInvoice = false,
        bool $isShipped = false,
        bool $isDelivery = false,
        bool $isPdfDelivery = false,
        bool $isPdfInvoice = false,
        bool $isSendEmail = false,
        string $template = '',
        bool $isHidden = false,
        bool $isUnremovable = true,
        bool $isDeleted = false,
    ): bool
    {
        $existingId = (int) Configuration::get($configurationKey);
        if ($existingId > 0) {
            $existing = new OrderState($existingId);
            if (Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        return $this->createOrderState(
            $configurationKey,
            $nameByLangIsoCode,
            $color,
            $isLogable,
            $isPaid,
            $isInvoice,
            $isShipped,
            $isDelivery,
            $isPdfDelivery,
            $isPdfInvoice,
            $isSendEmail,
            $template,
            $isHidden,
            $isUnremovable,
            $isDeleted
        );
    }

    /**
     * Indicates that this module uses the new PrestaShop translation system.
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Module header register scripts and styles.
     *
     * @return void
     */
    public function hookDisplayHeader(): void
    {
        if (!isset($this->context->controller)) {
            return;
        }

        $c = $this->context->controller;

        $phpSelf = $c->php_self ?? null;
        if (is_string($phpSelf) && in_array($phpSelf, ['order', 'order-opc', 'order-detail', 'guest-tracking'], true)) {
            $c->addCSS($this->_path . 'views/css/front/simpay.css');
            $c->addJS($this->_path . 'views/js/front/front.js');
            $c->addJS($this->_path . 'views/js/front/blikWidget.js');

            // Commission notice on order-detail page
            if ($phpSelf === 'order-detail') {
                $this->addOrderCommissionNotice();
            }

            return;
        }

        $fc = Tools::getValue('fc');
        $module = Tools::getValue('module');
        $controller = Tools::getValue('controller');

        if ($fc === 'module' && $module === $this->name && is_string($controller)) {
            $c->addCSS($this->_path . 'views/css/front/simpay.css');
            $c->addJS($this->_path . 'views/js/front/front.js');
            $c->addJS($this->_path . 'views/js/front/blikWidget.js');
            return;
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        $controller = Tools::getValue('controller');

        if ($controller === 'SimpayConfigurationAdminController') {
            Media::addJsDef([
                'simpayChannelsUrl' => $this->get('router')->generate('simpay_admin_channels'),
            ]);

            $this->context->controller->addJS($this->_path . 'views/js/admin/payment-methods.js');
            $this->context->controller->addCSS($this->_path . 'views/css/admin/admin.css');
        }

        if ($controller === 'AdminOrders') {
            $this->context->controller->addJS($this->_path . 'views/js/admin/order-main.js');
            $this->context->controller->addCSS($this->_path . 'views/css/admin/admin.css');
        }

        /** @var UpdateChecker $updateChecker */
        $updateChecker = $this->getService(UpdateChecker::class);
        $update = $updateChecker->getUpdateIfAvailable();

        if ($update && isset($this->context->controller) && in_array($controller, ['AdminModules', 'AdminModulesManage', 'AdminModulesNotifications'], true)) {
            $msg = $this->trans(
                'A new SimPay module version (:ver) is available.',
                [':ver' => $update['latest_version']],
                'Modules.Simpay.Admin'
            );
            $link = $this->trans('Download ZIP', [], 'Modules.Simpay.Admin');

            $this->context->controller->warnings[] = $msg . ' <a href="' . htmlspecialchars($update['zip_url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $link . '</a>';
        }
    }

    /**
     * @param array{cart: Cart} $params
     * @return array<PaymentOption>
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->isConfigurationComplete()) {
            return [];
        }

        $cart = $params['cart'];

        if (false === Validate::isLoadedObject($cart)) {
            return [];
        }

        if (false === $this->checkCurrency($cart)) {
            return [];
        }

        if ($cart->isVirtualCart()) {
            return [];
        }

        if (!isset($this->context->link)) {
            return [];
        }

        $methods = [];

        $showPaymentMethods = (bool)Configuration::get(SimpayDataConfiguration::SHOW_PAYMENT_METHODS_IN_MAIN);
        $showSeparateMethods = (bool) Configuration::get(SimpayDataConfiguration::SHOW_SEPARATE_PAYMENT_METHODS);
        $showBlikInWidget = (bool) Configuration::get(SimpayDataConfiguration::SHOW_BLIK_IN_WIDGET);
        $commissionMode = (string) (Configuration::get(SimpayDataConfiguration::COMMISSION_MODE) ?: 'merchant');
        $isPayerCommission = ($commissionMode === 'payer' || $commissionMode === 'split');
        $commissionSplit = ($commissionMode === 'split') ? (int) (Configuration::get(SimpayDataConfiguration::COMMISSION_SPLIT) ?: 50) : 100;
        $token = Tools::getToken('simpay');
        $cartTotalWithShipping = (float) $cart->getOrderTotal(true, Cart::BOTH);

        if ($showSeparateMethods) {
            foreach ($this->getCheckoutSeparateMethodsToDisplay($cartTotalWithShipping) as $method) {
                $methodId = (string) ($method['id'] ?? '');
                if ($methodId === '') {
                    continue;
                }

                $methodName = (string) ($method['name'] ?? $methodId);
                $callToAction = $this->trans('Pay with %method%', ['%method%' => $methodName], 'Modules.Simpay.Shop');
                $additionalInformation = '';
                $logo = isset($method['img']) ? (string) $method['img'] : null;

                if ($methodId === 'blik' && $showBlikInWidget) {
                    $this->context->smarty->assign([
                        'blik_type' => 'widget',
                        'simpay_blik_widget_action' => $this->context->link->getModuleLink((string) $this->name, 'blik', [], true),
                        'simpay_blik_widget_cart_id' => (int) $cart->id,
                        'simpay_blik_widget_token' => $token,
                        'simpay_blik_widget_assets' => $this->_path,
                        'simpay_blik_oneclick_enabled' => (bool) Configuration::get(SimpayDataConfiguration::BLIK_ONECLICK_ENABLED),
                    ]);
                    $additionalInformation = $this->fetch('module:' . $this->name . '/views/templates/hook/blik_widget.tpl');
                }

                $paymentOption = (new PaymentOption())
                    ->setModuleName($this->name)
                    ->setCallToActionText($callToAction)
                    ->setAction($this->context->link->getModuleLink((string) $this->name, 'validate', ['method' => $methodId], true))
                    ->setInputs([
                        'token' => [
                            'name' => 'token',
                            'type' => 'hidden',
                            'value' => $token,
                        ],
                    ]);

                if ($additionalInformation !== '') {
                    $paymentOption->setAdditionalInformation($additionalInformation);
                }
                if ($logo !== null && $logo !== '') {
                    $paymentOption->setLogo($logo);
                }

                $methods[] = $paymentOption;
            }
        }

        $this->context->smarty->assign([
            'simpay_methods' => $this->getCheckoutMethodsGridToDisplay($cartTotalWithShipping),
            'simpay_module_name' => $this->name,
            'simpay_action' => $this->context->link->getModuleLink($this->name, 'validate', [], true),
            'simpay_token' => $token,
            'simpay_show_methods' => $showPaymentMethods,
            'simpay_payer_commission' => $isPayerCommission,
            'simpay_cart_total' => $cartTotalWithShipping,
        ]);

        $gridHtml = $this->fetch('module:' . $this->name . '/views/templates/hook/payment_grid.tpl');

        // Build commission map for all channels (used by commission_notice.tpl + JS)
        $commissionNoticeHtml = '';
        if ($isPayerCommission) {
            /** @var SimPayChannelCache $channelCache */
            $channelCache = $this->getService(SimPayChannelCache::class);
            $allChannels = $channelCache->get();
            $commissionMap = [];
            foreach ($allChannels as $ch) {
                $chId = (string) ($ch['id'] ?? '');
                $commissions = $ch['commissions'] ?? [];
                $percentage = isset($commissions['percentage']) ? (float) $commissions['percentage'] : 0.0;
                if ($chId !== '' && $percentage > 0) {
                    $commissionMap[$chId] = [
                        'percentage' => $percentage,
                        'fixed' => isset($commissions['fixed']) ? (float) $commissions['fixed'] : 0.0,
                        'minimal' => isset($commissions['minimal']) ? (float) $commissions['minimal'] : null,
                    ];
                }
            }

            $this->context->smarty->assign([
                'simpay_commission_map_json' => json_encode($commissionMap),
                'simpay_cart_total' => $cartTotalWithShipping,
                'simpay_commission_split' => $commissionSplit,
                'simpay_commission_msg_generic' => $this->trans(
                    'A transaction fee will be added to the payment amount. The exact amount will be shown on the payment gateway.',
                    [],
                    'Modules.Simpay.Shop'
                ),
                'simpay_commission_msg_fee' => $this->trans('Transaction fee', [], 'Modules.Simpay.Shop'),
            ]);
            $commissionNoticeHtml = $this->fetch('module:' . $this->name . '/views/templates/hook/commission_notice.tpl');
        }

        $methods[] = (new PaymentOption())
            ->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay by transfer with SimPay', [], 'Modules.Simpay.Shop'))
            ->setAction($this->context->link->getModuleLink((string)$this->name, 'validate', [], true))
            ->setForm($gridHtml . $commissionNoticeHtml)
            ->setLogo($this->_path . 'views/img/option/simpay.svg');

        return $methods;
    }

    private function isConfigurationComplete(): bool
    {
        return
            (bool) Configuration::get(SimpayDataConfiguration::API_PASSWORD)
            && (bool) Configuration::get(SimpayDataConfiguration::SERVICE_ID)
            && (bool) Configuration::get(SimpayDataConfiguration::SERVICE_IPN_SIGNATURE_KEY);
    }

    /**
     * Build a safe list of methods to display in checkout
     * @return array<int, array{id:string,name:string,type:string,img:?string}>
     */
    private function getCheckoutMethodsGridToDisplay(float $cartTotalWithShipping): array
    {
        // Selected in admin
        $raw = (string) Configuration::get(SimpayDataConfiguration::PAYMENT_METHODS_LIST_IN_MAIN);
        $selected = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedIds = array_keys($selected);

        // Available from cache
        /** @var \SimPaypl\PrestaShop\Helper\SimPayChannelCache $cache */
        $cache = $this->getService(SimPayChannelCache::class);
        $available = $cache->get();

        if (empty($available)) {
            return [];
        }

        $availableById = [];
        foreach ($available as $ch) {
            if (!isset($ch['id'])) {
                continue;
            }
            if (!$this->isMethodInAmountRange($ch, $cartTotalWithShipping)) {
                continue;
            }
            $availableById[(string) $ch['id']] = $ch;
        }

        // keep admin order
        $methods = [];
        foreach ($selectedIds as $id) {
            if (isset($availableById[$id])) {
                $methods[] = $availableById[$id];
            }
        }

        // if admin selected nothing or all missing, show some defaults
        if (empty($methods)) {
            $methods = array_slice($available, 0, 12);
        }

        return $methods;
    }

    /**
     * @return array<int, array{id:string,name:string,type:string,img:?string}>
     */
    private function getCheckoutSeparateMethodsToDisplay(float $cartTotalWithShipping): array
    {
        $raw = (string) Configuration::get(SimpayDataConfiguration::SEPARATE_PAYMENT_METHODS_LIST);
        $selected = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($selected) || empty($selected)) {
            return [];
        }

        /** @var \SimPaypl\PrestaShop\Helper\SimPayChannelCache $cache */
        $cache = $this->getService(SimPayChannelCache::class);
        $available = $cache->get();

        if (empty($available)) {
            return [];
        }

        $availableById = [];
        foreach ($available as $channel) {
            if (!isset($channel['id'])) {
                continue;
            }

            // transfer should not be displayed as a standalone option
            if (($channel['id'] ?? '') === 'transfer' || ($channel['type'] ?? '') === 'transfer') {
                continue;
            }
            if (!$this->isMethodInAmountRange($channel, $cartTotalWithShipping)) {
                continue;
            }

            $availableById[(string) $channel['id']] = $channel;
        }

        $methods = [];
        foreach (array_keys($selected) as $id) {
            $id = (string) $id;
            if (isset($availableById[$id])) {
                $methods[] = $availableById[$id];
            }
        }

        return $methods;
    }

    /**
     * @param array{id?:mixed,amounts?:mixed} $method
     */
    private function isMethodInAmountRange(array $method, float $cartTotalWithShipping): bool
    {
        $amounts = isset($method['amounts']) && is_array($method['amounts']) ? $method['amounts'] : [];

        $min = $this->parseMethodAmountLimit($amounts, 'min');
        $max = $this->parseMethodAmountLimit($amounts, 'max');

        if ($min !== null && $cartTotalWithShipping < $min) {
            return false;
        }

        if ($max !== null && $cartTotalWithShipping > $max) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $amounts
     */
    private function parseMethodAmountLimit(array $amounts, string $key): ?float
    {
        if (!isset($amounts[$key])) {
            return null;
        }

        $value = $amounts[$key];
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public function getContent(): void
    {
        /** @var UpdateChecker $updateChecker */
        $updateChecker = $this->getService(UpdateChecker::class);
        $updateChecker->getUpdateIfAvailable();

        /** @var Router $router */
        $route = $this->get('router')->generate('simpay_configuration');
        ToolsCore::redirectAdmin($route);
    }

    /** @param array<string, string> $nameByLangIsoCode */
    private function createOrderState(
        string $configurationKey,
        array  $nameByLangIsoCode,
        string $color,
        bool   $isLogable = false,
        bool   $isPaid = false,
        bool   $isInvoice = false,
        bool   $isShipped = false,
        bool   $isDelivery = false,
        bool   $isPdfDelivery = false,
        bool   $isPdfInvoice = false,
        bool   $isSendEmail = false,
        string $template = '',
        bool   $isHidden = false,
        bool   $isUnremovable = true,
        bool   $isDeleted = false,
    ): bool
    {
        $tabNameByLangId = [];

        foreach ($nameByLangIsoCode as $langIsoCode => $name) {
            /** @var array<string, array<string>> $languages */
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                if (Tools::strtolower($language['iso_code']) === $langIsoCode) {
                    $tabNameByLangId[(int)$language['id_lang']] = $name;
                } elseif (isset($nameByLangIsoCode['en'])) {
                    $tabNameByLangId[(int)$language['id_lang']] = $nameByLangIsoCode['en'];
                }
            }
        }

        $orderState = new OrderState();
        $orderState->module_name = $this->name;
        $orderState->name = $tabNameByLangId;
        $orderState->color = $color;
        $orderState->logable = $isLogable;
        $orderState->paid = $isPaid;
        $orderState->invoice = $isInvoice;
        $orderState->shipped = $isShipped;
        $orderState->delivery = $isDelivery;
        $orderState->pdf_delivery = $isPdfDelivery;
        $orderState->pdf_invoice = $isPdfInvoice;
        $orderState->send_email = $isSendEmail;
        $orderState->hidden = $isHidden;
        $orderState->unremovable = $isUnremovable;
        $orderState->template = $template;
        $orderState->deleted = $isDeleted;

        if (false === $orderState->add()) {
            $this->_errors[] = sprintf(
                'Failed to create OrderState %s',
                $configurationKey
            );

            return false;
        }

        if (false === Configuration::updateGlobalValue($configurationKey, (int)$orderState->id)) {
            $this->_errors[] = sprintf(
                'Failed to save OrderState %s to Configuration',
                $configurationKey
            );

            return false;
        }

        $orderStateImgPath = $this->getLocalPath() . 'views/img/orderstate/' . $configurationKey . '.png';
        if (false === Tools::file_exists_cache($orderStateImgPath)) {
            $this->_errors[] = sprintf(
                'Failed to find icon file of OrderState %s',
                $configurationKey
            );

            return false;
        }

        if (false === Tools::copy($orderStateImgPath, _PS_ORDER_STATE_IMG_DIR_ . $orderState->id . '.gif')) {
            $this->_errors[] = sprintf(
                'Failed to copy icon of OrderState %s',
                $configurationKey
            );

            return false;
        }

        return true;
    }

    private function deleteOrderState(): bool
    {
        $result = true;

        $orderStateCollection = new PrestaShopCollection('OrderState');
        $orderStateCollection->where('module_name', '=', $this->name);
        /** @var OrderState[] $orderStates */
        $orderStates = $orderStateCollection->getAll();

        foreach ($orderStates as $orderState) {
            $orderState->deleted = true;
            if (!$orderState->save()) {
                $result = false;
            }
        }

        return $result;
    }

    public function ensureMailTemplate(string $template): bool
    {
        static $ensured = [];

        if (!isset($ensured[$template])) {
            $ensured[$template] = $this->installMailTemplates($template);
        }

        return $ensured[$template];
    }

    private function installMailTemplates(string $template): bool
    {
        $languages = Language::getLanguages(false);
        $moduleMailDir = _PS_MODULE_DIR_ . $this->name . '/mails/';

        foreach ($languages as $language) {
            $iso = Tools::strtolower((string) $language['iso_code']);
            $destDir = _PS_MAIL_DIR_ . $iso . '/';
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                $this->_errors[] = sprintf('Failed to create mail dir for %s', $iso);
                return false;
            }

            foreach (['html', 'txt'] as $ext) {
                $src = $this->resolveMailTemplatePath($moduleMailDir, $iso, $template, $ext);
                if ($src === '') {
                    $this->_errors[] = sprintf('Missing mail template %s.%s for %s', $template, $ext, $iso);
                    return false;
                }

                $dest = $destDir . $template . '.' . $ext;
                if (!Tools::copy($src, $dest)) {
                    $this->_errors[] = sprintf('Failed to copy mail template to %s', $dest);
                    return false;
                }
            }
        }

        return true;
    }

    private function resolveMailTemplatePath(string $baseDir, string $iso, string $template, string $ext): string
    {
        foreach ([$iso, 'en'] as $candidate) {
            $path = $baseDir . $candidate . '/' . $template . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    public function hookDisplayOrderDetail(array $params): string
    {
        $output = '';

        // Retry payment
        /** @var SimPayRetryPaymentService $retryService */
        $retryService = $this->getService(SimPayRetryPaymentService::class);
        $output .= $retryService->hookDisplayOrderDetail($params, __FILE__);

        return $output;
    }

    private function addOrderCommissionNotice(): void
    {
        $orderId = (int) Tools::getValue('id_order');
        if ($orderId <= 0) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }

        /** @var SimPayPaymentAttemptService $attemptService */
        $attemptService = $this->getService(SimPayPaymentAttemptService::class);
        $attempts = $attemptService->findByOrderId($orderId);

        $commissionMode = null;
        foreach ($attempts as $attempt) {
            $mode = (string) ($attempt['commission_mode'] ?? '');
            if ($mode !== '' && $mode !== 'merchant') {
                $commissionMode = $mode;
                break;
            }
        }

        if (!$commissionMode) {
            return;
        }

        if ($commissionMode === 'payer' || $commissionMode === 'split') {
            $message = $this->trans('A transaction fee was charged during payment. This fee was visible at checkout and on the payment gateway.', [], 'Modules.Simpay.Shop');
        } else {
            return;
        }

        $this->context->controller->info[] = $message;
    }

    public function hookActionGetExtraMailTemplateVars(array &$params): void
    {
        /** @var SimPayRetryPaymentService $retryService */
        $retryService = $this->getService(SimPayRetryPaymentService::class);
        $retryService->hookActionGetExtraMailTemplateVars($params);
    }

    public function hookDisplayAdminOrderMain(array $params): string
    {
        if (!$this->shouldDisplayAdminTabs($params)) {
            return '';
        }

        /** @var SimPayPaymentAttemptService $attemptService */
        $attemptService = $this->getService(SimPayPaymentAttemptService::class);
        /** @var SimPayRefundService $refundService */
        $refundService = $this->getService(SimPayRefundService::class);
        /** @var SimPayLogger $simpayLogger */
        $simpayLogger = $this->getService(SimPayLogger::class);

        $order = new Order((int) $params['id_order']);
        $currency = new Currency((int) $order->id_currency);

        $attempts = $attemptService->findByOrderId((int) $order->id) ?? [];
        $logs = $simpayLogger->getPaymentLogsForOrder((int) $order->id) ?? [];
        $refunds = $refundService->findByOrderId((int) $order->id);
        $isRefundPossible = $refundService->isRefundPossible($order);

        $refundStatus = null;
        $refundNoticeType = null;
        $refundNoticeMessage = null;

        if ((int) Tools::getValue('simpay_refund_done') === 1) {
            if ((int) Tools::getValue('simpay_refund_ok') === 1) {
                $refundNoticeType = 'success';
                $refundNoticeMessage = $this->trans('Refund request has been accepted for processing.', [], 'Modules.Simpay.Admin');
                $refundStatus = $this->displayConfirmation($refundNoticeMessage);
            } else {
                $refundNoticeType = 'error';
                $code = (string) (Tools::getValue('simpay_result_code') ?? '');
                $msg  = (string) (Tools::getValue('simpay_result_message') ?? '');
                $refundNoticeMessage = match ($code) {
                    'invalid_type' => $this->trans('Invalid refund type.', [], 'Modules.Simpay.Admin'),
                    'invalid_amount' => $this->trans('Invalid refund amount.', [], 'Modules.Simpay.Admin'),
                    'missing_transaction' => $this->trans('No transaction found for refund.', [], 'Modules.Simpay.Admin'),
                    'api_error' => $this->trans('Refund request failed: :msg', [':msg' => $msg], 'Modules.Simpay.Admin'),
                    default => $this->trans('Refund request failed.', [], 'Modules.Simpay.Admin'),
                };
                $refundStatus = $this->displayError($refundNoticeMessage);
            }
        }

        if (Tools::isSubmit('simpay_refund_submit')) {
            $type = (string) Tools::getValue('simpay_refund_type');
            $amount = (float) str_replace(',', '.', (string) Tools::getValue('simpay_refund_amount'));

            $result = $refundService->requestRefund($order, $type, $amount);

            Tools::redirectAdmin(
                $this->context->link->getAdminLink('AdminOrders', true, [], [
                    'id_order' => (int) $order->id,
                    'vieworder' => 1,
                    'simpay_refund_done' => 1,
                    'simpay_refund_ok' => (int) ($result['success'] === true),
                    'simpay_result_code' => $result['code'] ?? '',
                    'simpay_result_message' => $result['message'] ?? '',
                ])
            );
        }

        $this->context->smarty->assign([
            'simpay_attempts' => $attempts,
            'simpay_logs' => $logs,
            'simpay_refunds' => $refunds,
            'simpay_module_dir' => $this->_path,
            'simpay_refund_action' => $this->context->link->getAdminLink('AdminOrders', true, [], [
                'id_order' => (int) $order->id,
                'vieworder' => 1,
            ]),
            'simpay_refund_status' => $refundStatus,
            'simpay_refund_notice_type' => $refundNoticeType,
            'simpay_refund_notice_message' => $refundNoticeMessage,
            'simpay_currency_sign' => $currency->sign,
            'simpay_refund_max_amount' => (float) $order->total_paid,
            'simpay_is_refund_possible' => $isRefundPossible
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/order/order_main.tpl');
    }

    private function shouldDisplayAdminTabs(array $params): bool
    {
        $orderId = (int) ($params['id_order'] ?? 0);
        if ($orderId <= 0) {
            return false;
        }

        $order = new Order($orderId);

        return Validate::isLoadedObject($order) && $order->module === $this->name;
    }

    public static function isUpdatableState(int $stateId): bool {
        $allowedStates = array_map('intval', [
            Configuration::get(self::CONFIG_OS_AWAITING),
            Configuration::get(self::CONFIG_OS_EXPIRED),
            Configuration::get('PS_OS_ERROR'),
            Configuration::get('PS_OS_CANCELED'),
        ]);

        return in_array($stateId, $allowedStates, true);
    }

    private function checkCurrency(Cart $cart): bool
    {
        $currencyOrder = new Currency($cart->id_currency);

        return 'PLN' === $currencyOrder->iso_code;
    }

    public function installPaymentAttemptTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'simpay_payment_attempt` (
            `id_simpay_payment_attempt` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `transaction_id` VARCHAR(64) NOT NULL,
            `channel` VARCHAR(64) DEFAULT NULL,
            `payment_type` VARCHAR(32) DEFAULT NULL,
            `status` VARCHAR(32) DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `commission_mode` VARCHAR(16) DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_simpay_payment_attempt`),
            UNIQUE KEY `uniq_simpay_transaction` (`transaction_id`),
            KEY `idx_simpay_order` (`id_order`),
            KEY `idx_simpay_cart` (`id_cart`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        return Db::getInstance()->execute($sql);
    }

    public function installRefundsTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'simpay_refunds` (
        `id_simpay_refund` VARCHAR(36) NOT NULL,
        `id_order` INT UNSIGNED DEFAULT NULL,
        `transaction_id` VARCHAR(64) NOT NULL,
        `refund_type` VARCHAR(32) DEFAULT NULL,
        `amount` INT DEFAULT NULL,
        `status` VARCHAR(32) DEFAULT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_simpay_refund`),
        KEY `idx_simpay_order` (`id_order`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    public function installPaymentLogTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'simpay_payment_log` (
            `id_simpay_payment_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `level` VARCHAR(16) NOT NULL,
            `message` VARCHAR(255) NOT NULL,
            `context` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_simpay_payment_log`),
            KEY `idx_simpay_log_order` (`id_order`),
            KEY `idx_simpay_log_level` (`level`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    public function installBlikAliasTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'simpay_blik_alias` (
            `id_simpay_blik_alias` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer` INT UNSIGNED NOT NULL,
            `alias_uuid` VARCHAR(64) DEFAULT NULL,
            `alias_value` VARCHAR(128) NOT NULL,
            `alias_label` VARCHAR(128) DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT \'pending\',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_simpay_blik_alias`),
            KEY `idx_simpay_blik_customer` (`id_customer`),
            KEY `idx_simpay_blik_status` (`status`),
            UNIQUE KEY `uniq_simpay_blik_value` (`alias_value`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Clear Symfony container cache to force re-registration of module services.
     */
    private function clearSymfonyCache(): void
    {
        try {
            if (class_exists('Tools') && method_exists('Tools', 'clearSf2Cache')) {
                Tools::clearSf2Cache();
            }
        } catch (\Throwable $e) {
            // Cache clearing is best-effort — don't break install/enable on failure
            PrestaShopLogger::addLog(
                'SimPay: Failed to clear Symfony cache: ' . $e->getMessage(),
                2
            );
        }
    }
}
