<?php

namespace Riskified\Decider\Validator;

use Psr\Log\InvalidArgumentException;

class FormatValidator implements Validator
{
    const DATE_FORMAT = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}/';

    public function validate($data)
    {
        if (!preg_match(static::DATE_FORMAT , $data['from']) ||
            !preg_match(static::DATE_FORMAT, $data['to'])
        ) {
            throw new InvalidArgumentException('This value has invalid format');
        }
    }
}