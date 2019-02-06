<?php

namespace Riskified\Decider\Validator;

class RangeValidator implements Validator
{
    public function validate($data)
    {
        if ($data['to'] < $data['from']) {
            throw new \Exception('Start date must be earlier than the end date');
        }
    }
}