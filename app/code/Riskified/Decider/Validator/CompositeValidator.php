<?php

namespace Riskified\Decider\Validator;

class CompositeValidator implements Validator
{
    /**
     * @var Validator[]
     */
    public $validators = [];

    /**
     * @param Validator[] $validators
     */
    public function __construct(array $validators = [])
    {
        foreach ($validators as $validator) {
            $this->addValidator($validator);
        }
    }

    /**
     * @param Validator $validator
     */
    public function addValidator(Validator $validator)
    {
        $this->validators[] = $validator;
    }

    public function validate($data)
    {
        foreach ($this->validators as $validator) {
            $validator->validate($data);
        }
    }
}