<?php

/**
 * Alternate versions of array_set / array_get and array_has that support defining a delimiter.
 * Since the OpenAPI specification can have array keys containing a dot, the regular functions don't always suffice
 */

if (!function_exists('array_set_delimiter')) {
    function array_set_delimiter(&$array, $key, $value, $delimiter = '.')
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode($delimiter, $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('array_get_delimiter')) {
    function array_get_delimiter($array, $key, $delimiter  = '.', $default = null)
    {
        if (!\Illuminate\Support\Arr::accessible($array)) {
            return value($default);
        }

        if (is_null($key)) {
            return $array;
        }

        if (\Illuminate\Support\Arr::exists($array, $key)) {
            return $array[$key];
        }

        foreach (explode($delimiter, $key) as $segment) {
            if (\Illuminate\Support\Arr::accessible($array) && \Illuminate\Support\Arr::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return value($default);
            }
        }

        return $array;
    }
}

if (!function_exists('array_has_delimiter')) {
    function array_has_delimiter($array, $key, $delimiter  = '.')
    {
        if (! $array) {
            return false;
        }

        if (is_null($key)) {
            return false;
        }

        if (\Illuminate\Support\Arr::exists($array, $key)) {
            return true;
        }

        foreach (explode($delimiter, $key) as $segment) {
            if (\Illuminate\Support\Arr::accessible($array) && \Illuminate\Support\Arr::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return false;
            }
        }

        return true;
    }
}
