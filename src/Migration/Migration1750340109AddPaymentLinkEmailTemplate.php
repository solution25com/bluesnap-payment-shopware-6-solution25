<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1750340109AddPaymentLinkEmailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1750340109;
    }

    public function update(Connection $connection): void
    {
        $mailTemplateTypeId = $this->createMailTemplateType($connection);
        $this->createMailTemplate($connection, $mailTemplateTypeId);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes required.
    }

    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $sql = <<<'SQL'
            SELECT `language`.`id`
            FROM `language`
            INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
            WHERE `locale`.`code` = :code
        SQL;

        $languageId = $connection->fetchOne($sql, ['code' => $locale]);

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
        // Check if the mail template type already exists
        $existingId = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :technicalName',
            ['technicalName' => 'admin.payment.link']
        );

        if ($existingId) {
            return bin2hex($existingId);
        }

        $mailTemplateTypeId = Uuid::randomHex();

        $defaultLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
        $deLangId = $this->getLanguageIdByLocale($connection, 'de-DE');
        $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $connection->insert('mail_template_type', [
            'id' => Uuid::fromHexToBytes($mailTemplateTypeId),
            'technical_name' => 'admin.payment.link',
            'available_entities' => json_encode(['customer' => 'customer', 'salesChannel' => 'sales_channel']),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $this->insertMailTemplateTypeTranslation($connection, $mailTemplateTypeId, $defaultLangId);
        $this->insertMailTemplateTypeTranslation($connection, $mailTemplateTypeId, $systemLangId);
        if ($deLangId) {
            $this->insertMailTemplateTypeTranslation($connection, $mailTemplateTypeId, $deLangId);
        }

        return $mailTemplateTypeId;
    }

    private function insertMailTemplateTypeTranslation(Connection $connection, string $mailTemplateTypeId, $languageId): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM mail_template_type_translation WHERE mail_template_type_id = :id AND language_id = :lang',
            [
                'id' => Uuid::fromHexToBytes($mailTemplateTypeId),
                'lang' => $languageId, // already bytes
            ]
        );

        if ($exists) {
            return;
        }

        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
            'language_id' => $languageId, // already bytes
            'name' => 'Admin Payment Link',
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function createMailTemplate(Connection $connection, string $mailTemplateTypeId): void
    {
        $existingId = $connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId',
            ['typeId' => Uuid::fromHexToBytes($mailTemplateTypeId)]
        );

        if ($existingId) {
            return;
        }

        $mailTemplateId = Uuid::randomHex();

        $defaultLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
        $deLangId = $this->getLanguageIdByLocale($connection, 'de-DE');
        $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $connection->insert('mail_template', [
            'id' => Uuid::fromHexToBytes($mailTemplateId),
            'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
            'system_default' => true,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $this->insertMailTemplateTranslation($connection, $mailTemplateId, $defaultLangId, 'en');
        $this->insertMailTemplateTranslation($connection, $mailTemplateId, $systemLangId, 'en');
        if ($deLangId) {
            $this->insertMailTemplateTranslation($connection, $mailTemplateId, $deLangId, 'de');
        }
    }

    private function insertMailTemplateTranslation(Connection $connection, string $mailTemplateId, $languageId, string $langCode): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM mail_template_translation WHERE mail_template_id = :id AND language_id = :lang',
            [
                'id' => Uuid::fromHexToBytes($mailTemplateId),
                'lang' => $languageId, // already bytes
            ]
        );

        if ($exists) {
            return;
        }

        $contentHtml = $langCode === 'de' ? $this->getContentHtmlDe() : $this->getContentHtmlEn();
        $contentPlain = $langCode === 'de' ? $this->getContentPlainDe() : $this->getContentPlainEn();

        $connection->insert('mail_template_translation', [
            'mail_template_id' => Uuid::fromHexToBytes($mailTemplateId),
            'language_id' => $languageId, // already bytes
            'sender_name' => 'Shopware Administration',
            'subject' => 'Payment Link for Your Order #{{ orderNumber }}',
            'description' => '',
            'content_html' => $contentHtml,
            'content_plain' => $contentPlain,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
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
        <a href="{{ paymentLink }}" title="Pay now via secure payment link">Payment Link</a><br/>
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
        <a href="{{ paymentLink }}" title="Jetzt sicher über den Zahlungslink bezahlen">Zahlungslink</a><br/>
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
