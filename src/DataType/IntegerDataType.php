<?php namespace OpenAPI\Consumer\DataType;

class IntegerDataType extends DataType
{
    protected function rules()
    {
        return array_merge(
            parent::rules(),
            ['integer']
        );
    }
}