<?php

namespace AppBundle\Mailjet\Message;

use AppBundle\Entity\Invite;

final class InvitationMessage extends MailjetMessage
{
    public static function createFromInvite(Invite $invite): self
    {
        return new static(
            $invite->getUuid(),
            '108243',
            $invite->getEmail(),
            null,
            sprintf('%s vous invite à rejoindre En Marche.', self::escape($invite->getSenderFullName())),
            static::getTemplateVars($invite->getFirstName(), $invite->getLastName(), $invite->getMessage())
        );
    }

    private static function getTemplateVars(string $senderFirstName, string $senderLastName, string $targetMessage): array
    {
        return [
            'sender_firstname' => self::escape($senderFirstName),
            'sender_lastname' => self::escape($senderLastName),
            'target_message' => nl2br(self::escape($targetMessage)),
        ];
    }
}
