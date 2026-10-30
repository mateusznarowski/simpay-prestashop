<?php

declare(strict_types=1);

use SimPay\SDK\SimPay as SimPaySDK;
use SimPay\SDK\AmountVerifier;
use SimPay\SDK\PaymentStatus;
use SimPaypl\PrestaShop\Form\SimpayDataConfiguration;
use SimPaypl\PrestaShop\Helper\SimPayLogger;
use SimPaypl\PrestaShop\Service\SimPayBlikAliasService;
use SimPaypl\PrestaShop\Service\SimPayPaymentAttemptService;
use SimPaypl\PrestaShop\Service\SimPayRefundService;

final class SimpayNotifyModuleFrontController extends ModuleFrontController
{
    /** @var Simpay */
    public $module;

    /** @var SimPayPaymentAttemptService $attemptService */
    private $attemptService;

    /** @var SimPayRefundService $refundService */
    private $refundService;

    public function init(): void
    {
        parent::init();
        $this->attemptService = $this->module->getService(SimPayPaymentAttemptService::class);
        $this->refundService = $this->module->getService(SimPayRefundService::class);
    }

    public function postProcess(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            SimPayLogger::respond(
                405,
                $this->trans('Method not allowed', [], 'Modules.Simpay.Logs'),
                'NOT_POST'
            );
        }

        $raw = Tools::file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            SimPayLogger::respond(
                400,
                $this->trans('Invalid JSON', [], 'Modules.Simpay.Logs'),
                'BAD_JSON'
            );
        }

        $type = (string) ($payload['type'] ?? '');
        if (isset($payload['data']) && is_array($payload['data'])) {
            $transactionId = '';
            if ($type === 'transaction:status_changed') {
                $transactionId = (string) ($payload['data']['id'] ?? '');
                $order = Order::getByCartId($payload['data']['control'] ?? 0);
                if ($order && Validate::isLoadedObject($order)) {
                    $this->orderId = (int) $order->id;
                    SimPayLogger::setDefaultOrderId($this->orderId);
                }
            } elseif ($type === 'transaction_refund:status_changed') {
                $transactionId = (string) ($payload['data']['transaction']['id'] ?? '');
                $attempt = $this->attemptService->findByTransactionId($payload['data']['transaction']['id'] ?? '');
                $order = $this->resolveOrderForAttempt($attempt);
                if ($order && Validate::isLoadedObject($order)) {
                    $this->orderId = (int) $order->id;
                    SimPayLogger::setDefaultOrderId($this->orderId);
                }
            }

            SimPayLogger::info($this->trans('Webhook data received from server', [], 'Modules.Simpay.Logs'), [
                'type' => $payload['type'] ?? '',
                'status' => $payload['data']['status'] ?? '',
                'transaction_id' => $transactionId ?? ''
            ]);
        }

        // IP allowlist check (only log result)
        $simpay = $this->module->getService(SimPaySDK::class);
        if ((bool) Configuration::get(SimpayDataConfiguration::IPN_CHECK_IP)) {
            try {
                $simpay->handleIpn(
                    payload: $payload,
                    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
                    remoteIp: Tools::getRemoteAddr()
                );
            } catch (\SimPay\SDK\Exception\IpNotAllowedException $e) {
                SimPayLogger::respond(
                    403,
                    $this->trans('Invalid IP', [], 'Modules.Simpay.Logs'),
                    'BAD_IP'
                );
            } catch (\SimPay\SDK\Exception\IpnException $e) {
                SimPayLogger::respond(
                    400,
                    $e->getMessage(),
                    'IPN_VALIDATION_FAILED'
                );
            }
        } else {

            try {
                $simpay->handleIpn(
                    payload: $payload,
                    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
                    remoteIp: null // skip IP check
                );
            } catch (\SimPay\SDK\Exception\IpnException $e) {
                SimPayLogger::respond(
                    400,
                    $e->getMessage(),
                    'IPN_VALIDATION_FAILED'
                );
            }
        }

        $type = (string) ($payload['type'] ?? '');
        if ($type === 'transaction:status_changed') {
            $data = $payload['data'] ?? [];
            $this->handleTransactionStatusChangedEvent($data);
        }

        if ($type === 'transaction_refund:status_changed') {
            $data = $payload['data'] ?? [];
            $this->handleRefundStatusChangedEvent($data);
        }

        if ($type === 'blik:alias_status_changed') {
            $data = $payload['data'] ?? [];
            $this->handleBlikAliasStatusChanged($data);
        }

        if ($type === 'transaction_blik_level0:code_status_changed') {
            // Acknowledge but no action needed - transaction:status_changed handles order updates
            SimPayLogger::info($this->trans('BLIK Level 0 code status event received', [], 'Modules.Simpay.Logs'), [
                'status' => $payload['data']['status'] ?? '',
            ]);
        }

        SimPayLogger::respond(200, 'OK', 'DONE');
    }

    private function handleTransactionStatusChangedEvent(array $payload): void
    {
        $transactionId = (string) ($payload['id'] ?? '');
        if ($transactionId === '') {
            SimPayLogger::respond(
                400,
                $this->trans('Missing transaction id', [], 'Modules.Simpay.Logs'),
                'NO_TRANSACTION_ID'
            );
        }

        $attemptData = $this->attemptService->findByTransactionId($transactionId);
        $order = $this->resolveOrderForAttempt($attemptData);
        if (!$order || !Validate::isLoadedObject($order)) {
            SimPayLogger::error(
                $this->trans('Order not found for transaction', [], 'Modules.Simpay.Logs'),
                ['transaction' => $transactionId]
            );
            SimPayLogger::respond(
                400,
                $this->trans('Order not found', [], 'Modules.Simpay.Logs'),
                'ORDER_NOT_FOUND'
            );
        }

        if (!$this->module->isUpdatableState((int) $order->current_state)) {
            SimPayLogger::info(
                $this->trans('Order already processed, event ignored', [], 'Modules.Simpay.Logs'),
                ['order' => (int) $order->id]
            );
            SimPayLogger::respond(200, 'OK', 'ORDER_ALREADY_PROCESSED');
        }

        $status = (string) ($payload['status'] ?? '');
        $finalChannel = (string) ($payload['payment']['channel'] ?? '');

        if (!(bool) $attemptData['is_active']) {
            $this->attemptService->updateStatus($transactionId, $status, PaymentStatus::isFinal($status), $finalChannel);
            SimPayLogger::info(
                $this->trans('Inactive attempt, event ignored', [], 'Modules.Simpay.Logs'),
                ['transaction' => $transactionId]
            );
            SimPayLogger::respond(200, 'OK', 'INACTIVE_ATTEMPT');
        }

        $newState = $this->mapStatusToOrderState($status);
        if (null === $newState) {
            SimPayLogger::warning(
                $this->trans('Unknown status, event ignored', [], 'Modules.Simpay.Logs'),
                ['status' => $status]
            );
            SimPayLogger::respond(200, 'OK', 'UNKNOWN_STATUS');
        }

        if ($attemptData['status'] === $status) {
            SimPayLogger::info(
                $this->trans('Duplicate status, event ignored', [], 'Modules.Simpay.Logs'),
                ['status' => $status, 'transaction' => $transactionId]
            );
            SimPayLogger::respond(200, 'OK', 'DUPLICATE_STATUS');
        }

        $incoming = (float) ($payload['amount']['original_value'] ?? 0);
        // Compare against the amount due, not payments already recorded.
        $expected = (float) $order->getOrdersTotalPaid();
        if ($incoming > 0 && !AmountVerifier::isAmountSufficient($expected, $incoming)) {
            SimPayLogger::error(
                $this->trans('Amount too low', [], 'Modules.Simpay.Logs'),
                ['incoming' => $incoming, 'expected' => $expected]
            );
            SimPayLogger::respond(
                402,
                $this->trans('Invalid amount', [], 'Modules.Simpay.Logs'),
                'AMOUNT_LOW'
            );
        }

        try {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($newState, (int) $order->id, true);

            $orderState = new OrderState($newState);
            if (
                (Validate::isLoadedObject($orderState) && $orderState->send_email)
                && (bool) Configuration::get(SimpayDataConfiguration::REPAYMENT_ENABLED)
            ) {
                $history->addWithemail(true, [], Context::getContext());
            } else {
                $history->add();
            }

            $this->attemptService->updateStatus($transactionId, $status, PaymentStatus::isFinal($status), $finalChannel);

            // Update native PrestaShop order_payment with transaction ID (for BaseLinker, etc.)
            if (PaymentStatus::isPaid($status)) {
                $this->attemptService->syncOrderPaymentTransactionId($order, $transactionId);
            }

            SimPayLogger::info(
                $this->trans('Order status updated successfully', [], 'Modules.Simpay.Logs'),
                ['order' => (int) $order->id, 'new_state' => $newState]
            );
        } catch (\Throwable $e) {
            $context = [
                'order' => (int) $order->id,
                'status' => $status,
                'msg' => $e->getMessage(),
            ];

            SimPayLogger::error(
                $this->trans('Order update failed', [], 'Modules.Simpay.Logs'),
                $context
            );
            PrestaShopLogger::addLog(
                sprintf('SimPay notify failure (order #%d): %s', (int) $order->id, $e->getMessage()),
                3,
                0,
                'Order',
                (int) $order->id,
                true
            );

            SimPayLogger::respond(
                500,
                $this->trans('Order update failed', [], 'Modules.Simpay.Logs'),
                'ORDER_UPDATE_FAILED',
                $context
            );
        }
    }

    private function handleRefundStatusChangedEvent(array $payload): void
    {
        $refundId = (string) ($payload['id'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if ($refundId === '' || $status === '') {
            SimPayLogger::respond(
                400,
                $this->trans('Missing refund data', [], 'Modules.Simpay.Logs'),
                'REFUND_MISSING_DATA'
            );
        }

        $updated = $this->refundService->updateRefundStatus($refundId, $status);
        if (!$updated) {
            SimPayLogger::error(
                $this->trans('Refund not found for transaction', [], 'Modules.Simpay.Logs'),
                ['refund_id' => $refundId, 'status' => $status]
            );
            SimPayLogger::respond(
                404,
                $this->trans('Refund not found', [], 'Modules.Simpay.Logs'),
                'REFUND_NOT_FOUND'
            );
        }

        SimPayLogger::info(
            $this->trans('Refund status updated', [], 'Modules.Simpay.Logs'),
            ['refund_id' => $refundId, 'status' => $status]
        );

        SimPayLogger::respond(200, 'OK', 'REFUND_STATUS_UPDATED');
    }

    private function resolveOrderForAttempt(array $attempt): ?Order
    {
        $orderId = (int) ($attempt['id_order'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $order = new Order($orderId);

        return Validate::isLoadedObject($order) ? $order : null;
    }

    private function mapStatusToOrderState(string $status): ?int
    {
        if (PaymentStatus::isPaid($status)) {
            return (int) Configuration::get('PS_OS_PAYMENT');
        }

        return match ($status) {
            PaymentStatus::TRANSACTION_CANCELED => (int) Configuration::get('PS_OS_CANCELED'),
            PaymentStatus::TRANSACTION_FRAUD, PaymentStatus::TRANSACTION_FAILURE => (int) Configuration::get('PS_OS_ERROR'),
            PaymentStatus::TRANSACTION_EXPIRED => (int) Configuration::get(Simpay::CONFIG_OS_EXPIRED),
            PaymentStatus::TRANSACTION_REFUNDED => (int) Configuration::get('PS_OS_REFUND'),
            default => null,
        };
    }

    /**
     * Handle blik:alias_status_changed IPN event.
     *
     * When status is alias_active, save the alias UUID in database.
     * This enables OneClick payments for the customer.
     */
    private function handleBlikAliasStatusChanged(array $data): void
    {
        $aliasUuid = (string) ($data['id'] ?? '');
        $status = (string) ($data['status'] ?? '');
        $aliasValue = (string) ($data['value'] ?? '');

        if ($aliasUuid === '' || $status === '' || $aliasValue === '') {
            SimPayLogger::respond(
                400,
                $this->trans('Missing BLIK alias data', [], 'Modules.Simpay.Logs'),
                'ALIAS_MISSING_DATA'
            );
        }

        SimPayLogger::info($this->trans('BLIK alias status changed', [], 'Modules.Simpay.Logs'), [
            'alias_uuid' => $aliasUuid,
            'alias_value' => $aliasValue,
            'status' => $status,
        ]);

        /** @var SimPayBlikAliasService $aliasService */
        $aliasService = $this->module->getService(SimPayBlikAliasService::class);

        if ($status === 'alias_active') {
            $activated = $aliasService->activateAlias($aliasValue, $aliasUuid, $status);
            if ($activated) {
                SimPayLogger::info($this->trans('BLIK alias activated successfully', [], 'Modules.Simpay.Logs'), [
                    'alias_uuid' => $aliasUuid,
                ]);
            }
        } else {
            // Handle other statuses (alias_inactive, alias_expired, etc.)
            $aliasService->updateAliasStatus($aliasUuid, $status);
        }

        SimPayLogger::respond(200, 'OK', 'ALIAS_STATUS_UPDATED');
    }
}
