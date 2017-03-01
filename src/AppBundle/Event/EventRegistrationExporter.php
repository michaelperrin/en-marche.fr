<?php

namespace AppBundle\Event;

use AppBundle\Entity\EventRegistration;
use AppBundle\Exception\EventException;

class EventRegistrationExporter
{
    public function export(array $registrations): string
    {
        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, ['N° d\'enregistrement', 'Prénom', 'Code postal']);

        foreach ($registrations as $registration) {
            if (!$registration instanceof EventRegistration) {
                throw new EventException('Invalid registration given');
            }

            fputcsv($handle, [
                $registration->getUuid()->toString(),
                $registration->getFirstName(),
                $registration->getPostalCode(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
