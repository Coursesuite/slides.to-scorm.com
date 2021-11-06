<?php

namespace Mhor\MediaInfo\Checker;

class DateTimeChecker extends AbstractAttributeChecker
{
    /**
     * @param string $value
     *
     * @return \DateTime
     */
    public function create($value): \DateTime
    {
        return new \DateTime($value);
    }

    /**
     * @return array
     */
    public function getMembersFields(): array
    {
        return [
            'file_last_modification_date',
            'file_last_modification_date_local',
        ];
    }
}
