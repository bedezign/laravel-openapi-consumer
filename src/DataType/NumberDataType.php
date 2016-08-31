<?php namespace OpenAPI\Consumer\DataType;

class NumberDataType extends DataType
{
    protected function rules()
    {
        return array_merge(
            parent::rules(),
            ['numeric']
        );
    }
}