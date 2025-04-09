<?php

declare(strict_types=1);

namespace BlueSnap;

use BlueSnap\PaymentMethods\PaymentMethodInterface;
use BlueSnap\PaymentMethods\PaymentMethods;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class BlueSnap extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod(new $paymentMethod(), $installContext->getContext());
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), new $paymentMethod());
        }

        if (!$uninstallContext->keepUserData()) {
            $this->dropBlueSnapTables();
        }

        parent::uninstall($uninstallContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), new $paymentMethod());
        }
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $paymentMethod());
        }
        parent::deactivate($deactivateContext);
    }

    private function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        $pluginIdProvider = $this->getDependency(PluginIdProvider::class);
        $pluginId         = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        if ($paymentMethodId) {
            $this->setPluginId($paymentMethodId, $pluginId, $context);
            return;
        }

        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId         = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        $paymentData = [
          'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
          'name'              => $paymentMethod->getName(),
          'description'       => $paymentMethod->getDescription(),
          'pluginId'          => $pluginId,
          'afterOrderEnabled' => true
        ];

        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodData = [
          'id'       => $paymentMethodId,
          'pluginId' => $pluginId,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context, PaymentMethodInterface $paymentMethod): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodId   = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
          'id'     => $paymentMethodId,
          'active' => $active,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler): ?string
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentCriteria   = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            $paymentMethodHandler
        ));

        $paymentIds = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    private function getDependency($name): mixed
    {
        return $this->container->get($name);
    }

    private function dropBlueSnapTables(): void
    {
        $connection = $this->container->get(Connection::class);

        // Drop all tables
        $connection->executeStatement(
            /** @lang text */
            'DROP TABLE IF EXISTS
        `bluesnap_payment_link`,
        `bluesnap_transaction`,
        `bluesnap_vaulted_shopper`;'
        );

        // Delete migrations
        $connection->executeStatement(
            /** @lang text */
            'DELETE FROM `migration` WHERE `class` LIKE :blue_snap OR `class` LIKE :vaulted_shopper;',
            [
              'blue_snap'       => '%BlueSnap%',
              'vaulted_shopper' => '%VaultedShopper%',
            ]
        );

        // Retrieve the mail type ID
        $mailTypeId = $connection->fetchOne(
            /** @lang text */
            'SELECT `id` FROM `mail_template_type` WHERE `technical_name` LIKE :payment_link',
            [
              'payment_link' => '%admin.payment.link%',
            ]
        );

        // Retrieve the template id
        $mailTemplateId = $connection->fetchOne(
            /** @lang text */
            'SELECT `id` FROM `mail_template` WHERE `mail_template_type_id` = :template_type_id',
            [
              'template_type_id' => $mailTypeId,
            ]
        );

        // Delete records from mail_template_translation
        $connection->executeStatement(
            /** @lang text */
            'DELETE FROM `mail_template_translation` WHERE `mail_template_id` = :template_id',
            [
              'template_id' => $mailTemplateId,
            ]
        );

        // Delete Type
        $connection->executeStatement(
            /** @lang text */
            'DELETE FROM `mail_template` WHERE `mail_template_type_id` = :template_type_id',
            [
              'template_type_id' => $mailTypeId,
            ]
        );

        // Delete record from mail_template_type
        $connection->executeStatement(
            /** @lang text */
            'DELETE FROM `mail_template_type` WHERE `technical_name` LIKE :payment_link',
            [
              'payment_link' => '%admin.payment.link%',
            ]
        );

        // Delete record from mail_template_type_translation
        $connection->executeStatement(
            /** @lang text */
            'DELETE FROM `mail_template_type_translation` WHERE `name` LIKE :payment_name',
            [
              'payment_name' => '%Admin Payment Link%',
            ]
        );
    }
}
