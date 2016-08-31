<?php namespace OpenAPI\Consumer\DataType;

class StringDataType extends DataType
{
    protected function rules()
    {
        return array_merge(
            parent::rules(),
            ['string']
        );
    }
}