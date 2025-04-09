<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1732747821AddPaymentLinkEmailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732747821;
    }

    public function update(Connection $connection): void
    {
        $mailTemplateTypeId = $this->createMailTemplateType($connection);

        $this->createMailTemplate($connection, $mailTemplateTypeId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $sql = <<<'SQL'
      SELECT `language`.`id`
      FROM `language`
      INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
      WHERE `locale`.`code` = :code
    SQL;

        $languageId = $connection->executeQuery($sql, ['code' => $locale])->fetchOne();
        if (!$languageId && $locale !== 'en-GB') {
            return null;
        }

        if (!$languageId) {
            return Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        }

        return $languageId;
    }

    private function createMailTemplateType(Connection $connection): string
    {
        $mailTemplateTypeId = Uuid::randomHex();

        $defaultLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
        $deLangId      = $this->getLanguageIdByLocale($connection, 'de-DE');

        $connection->insert('mail_template_type', [
          'id'                 => Uuid::fromHexToBytes($mailTemplateTypeId),
          'technical_name'     => 'admin.payment.link',
          'available_entities' => json_encode(['customer' => 'customer', "salesChannel" => "sales_channel"]),
          'created_at'         => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        if ($defaultLangId !== $deLangId) {
            $connection->insert('mail_template_type_translation', [
              'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
              'language_id'           => $defaultLangId,
              'name'                  => 'Admin Payment Link',
              'created_at'            => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        if ($defaultLangId !== Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)) {
            $connection->insert('mail_template_type_translation', [
              'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
              'language_id'           => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
              'name'                  => 'Admin Payment Link',
              'created_at'            => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        if ($deLangId) {
            $connection->insert('mail_template_type_translation', [
              'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
              'language_id'           => $deLangId,
              'name'                  => 'Admin Payment Link',
              'created_at'            => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        return $mailTemplateTypeId;
    }

    private function createMailTemplate(Connection $connection, string $mailTemplateTypeId): void
    {
        $mailTemplateId = Uuid::randomHex();

        $defaultLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
        $deLangId      = $this->getLanguageIdByLocale($connection, 'de-DE');

        $connection->insert('mail_template', [
          'id'                    => Uuid::fromHexToBytes($mailTemplateId),
          'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
          'system_default'        => true,
          'created_at'            => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        if ($defaultLangId !== $deLangId) {
            $connection->insert('mail_template_translation', [
              'mail_template_id' => Uuid::fromHexToBytes($mailTemplateId),
              'language_id'      => $defaultLangId,
              'sender_name'      => 'Shopware Administration',
              'subject'          => 'Payment Link for Your Order #{{ orderNumber }}',
              'description'      => '',
              'content_html'     => $this->getContentHtmlEn(),
              'content_plain'    => $this->getContentPlainEn(),
              'created_at'       => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        if ($defaultLangId !== Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)) {
            $connection->insert('mail_template_translation', [
              'mail_template_id' => Uuid::fromHexToBytes($mailTemplateId),
              'language_id'      => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
              'sender_name'      => 'Shopware Administration',
              'subject'          => 'Payment Link for Your Order #{{ orderNumber }}',
              'description'      => '',
              'content_html'     => $this->getContentHtmlEn(),
              'content_plain'    => $this->getContentPlainEn(),
              'created_at'       => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        if ($deLangId) {
            $connection->insert('mail_template_translation', [
              'mail_template_id' => Uuid::fromHexToBytes($mailTemplateId),
              'language_id'      => $deLangId,
              'sender_name'      => 'Shopware Administration',
              'subject'          => 'Payment Link for Your Order #{{ orderNumber }}',
              'description'      => '',
              'content_html'     => $this->getContentHtmlDe(),
              'content_plain'    => $this->getContentPlainDe(),
              'created_at'       => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }
    }

    private function getContentHtmlEn(): string
    {
        return <<<MAIL
    <div style="font-family:arial; font-size:12px;">
        <p>
            Dear {{ firstName }} {{ lastName }},<br/>
            <br/>
            Thank you for your order!
            <br/>
            Order Number: #{{ orderNumber }}
            <br/>
            <a href="{{ paymentLink }}">Payment Link</a><br/>
            <br/>
        </p>
    </div>
    MAIL;
    }

    private function getContentPlainEn(): string
    {
        return <<<MAIL
        Dear {{ firstName }} {{ lastName }},

        Order number: #{{ orderNumber }}

        Payment Link: {{ paymentLink }}
    MAIL;
    }

    private function getContentHtmlDe(): string
    {
        return <<<MAIL
      <div style="font-family:arial; font-size:12px;">
          <p>
              Sehr geehrte/r {{ firstName }} {{ lastName }},<br/>
              <br/>
                Bestellnummer: #{{ orderNumber }}
              <br/>
              <a href="{{ paymentLink }}">Zahlungslink</a><br/>
          </p>
      </div>
    MAIL;
    }

    private function getContentPlainDe(): string
    {
        return <<<MAIL
         Sehr geehrte/r {{ firstName }} {{ lastName }},

         Bestellnummer: #{{ orderNumber }}

         Zahlungslink: {{ paymentLink }}
        
      MAIL;
    }
}
